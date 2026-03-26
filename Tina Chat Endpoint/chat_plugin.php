<?php

/*
Plugin Name: Tina Chat Endpoint
Description: Stores chat history in DB and proxies messages to n8n.
Version: 0.1
*/

if (!defined('ABSPATH')) exit;

function tina_count_history($session_id, $conversation_id = 'default') {
  global $wpdb;
  $table = $wpdb->prefix . 'tina_chat_history';

  return (int) $wpdb->get_var(
    $wpdb->prepare(
      "SELECT COUNT(*) FROM $table WHERE session_id = %s AND conversation_id = %s",
      $session_id,
      $conversation_id
    )
  );
}

function tina_create_summary_table() {
  global $wpdb;

  $table = $wpdb->prefix . 'tina_chat_summary';
  $charset_collate = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE IF NOT EXISTS $table (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL,
    conversation_id VARCHAR(64) NOT NULL DEFAULT 'default',
    summary TEXT NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_session_conv (session_id, conversation_id)
  ) $charset_collate;";

  require_once ABSPATH . 'wp-admin/includes/upgrade.php';
  dbDelta($sql);
}

function tina_load_summary($session_id, $conversation_id = 'default') {
  global $wpdb;
  $table = $wpdb->prefix . 'tina_chat_summary';

  return $wpdb->get_var(
    $wpdb->prepare(
      "SELECT summary FROM $table WHERE session_id = %s AND conversation_id = %s",
      $session_id,
      $conversation_id
    )
  ) ?: '';
}

function tina_save_summary($session_id, $conversation_id, $summary) {
  global $wpdb;
  $table = $wpdb->prefix . 'tina_chat_summary';

  $wpdb->replace($table, [
    'session_id' => $session_id,
    'conversation_id' => $conversation_id,
    'summary' => $summary,
  ], ['%s','%s','%s']);
}


add_action('wp_footer', function () {
  if (!is_front_page()) return;
  ?>
  <div id="tina-chat">
    <button id="chatToggle">💬</button>

    <div id="chatWindow">
      <div id="TinaHeader">
        <img
          src="<?php echo esc_url( plugin_dir_url(__FILE__) . 'assets/tina-avatar.jpg' ); ?>"
          alt="Tina"
        >

        <div>
          <strong>Tina</strong><br>
          <span>Tina Digital Host</span>
        </div>

        <button id="tinaReset">Reset</button>
      </div>

      <div id="chatMessages"></div>

      <input id="chatInput" placeholder="Nachricht...">
    </div>
  </div>
  <?php
}, 50);

add_shortcode('tina_chat_page', function () {
  ob_start(); ?>
  <div class="tina-page-chat">
    <div class="tina-page-chat__inner">
      <div class="tina-page-chat__header">
        <div class="tina-page-chat__profile">
          <img
            src="<?php echo esc_url( plugin_dir_url(__FILE__) . 'assets/tina-avatar.jpg' ); ?>"
            alt="Tina"
            class="tina-page-chat__avatar"
          >
          <div>
            <strong>Tina</strong><br>
            <span>Tina Digital Host</span>
          </div>
        </div>

        <button id="tinaReset" class="tina-page-chat__reset">Reset</button>
      </div>

      <div id="chatMessages" class="tina-page-chat__messages"></div>

      <div class="tina-page-chat__inputbar">
         <textarea
  id="chatInput"
  class="tina-page-chat__input"
  placeholder="Nachricht..."
  rows="1"
></textarea>
      </div>
    </div>
  </div>
  <?php
  return ob_get_clean();
});

add_action('wp_enqueue_scripts', function () {

  wp_enqueue_style(
    'tina-chat-css',
    plugin_dir_url(__FILE__) . 'assets/tina-chat.css',
    [],
    '1.0'
  );

  wp_enqueue_script(
    'tina-chat-js',
    plugin_dir_url(__FILE__) . 'assets/tina-chat.js',
    [],
    '1.0',
    true
  );

  wp_localize_script('tina-chat-js', 'TINA_CONFIG', [
    'nonce'      => wp_create_nonce('wp_rest'),
    'chatUrl'    => rest_url('tina/v1/chat'),
    'historyUrl' => rest_url('tina/v1/history'),
    'resetUrl' => rest_url('tina/v1/reset'),
    'isLoggedIn' => is_user_logged_in(),
  ]);
});

add_action('rest_api_init', function () {
  register_rest_route('tina/v1', '/chat', [
    'methods'  => 'POST',
    'callback' => 'tina_chat_handler',
    'permission_callback' => '__return_true',
  ]);

  register_rest_route('tina/v1', '/history', [
    'methods'  => 'GET',
    'callback' => 'tina_history_handler',
    'permission_callback' => '__return_true',
  ]);

register_rest_route('tina/v1', '/reset', [
  'methods'  => 'POST',
  'callback' => 'tina_reset_handler',
  'permission_callback' => '__return_true',
]);

});

function tina_reset_handler(WP_REST_Request $request) {
  global $wpdb;

  $history_table = $wpdb->prefix . 'tina_chat_history';
  $summary_table = $wpdb->prefix . 'tina_chat_summary';

  $data = $request->get_json_params();
  if (!is_array($data)) $data = [];

  $session_id = tina_get_session_id($data);

  if (!$session_id) {
    return new WP_REST_Response(['error' => 'Missing session_id'], 400);
  }

  // Delete chat history
  $wpdb->delete(
    $history_table,
    ['session_id' => $session_id],
    ['%s']
  );

  // 🔥 Delete summary too
  $wpdb->delete(
    $summary_table,
    ['session_id' => $session_id],
    ['%s']
  );

  return new WP_REST_Response(['success' => true], 200);
}

function tina_history_handler(WP_REST_Request $request) {
  $conversation_id = sanitize_text_field($request->get_param('conversation_id') ?? 'default');
  $conversation_id = substr($conversation_id, 0, 64);

  $limit = intval($request->get_param('limit') ?? 30);
  $limit = max(1, min(200, $limit));

  $session_id = sanitize_text_field($request->get_param('session_id') ?? '');
  $session_id = substr($session_id, 0, 64);

  // If missing: allow logged-in users to fetch their own history
  if (!$session_id && is_user_logged_in()) {
    $uid = get_current_user_id();
    $token = get_user_meta($uid, 'tina_chat_token', true);

    if (!is_string($token) || strlen($token) < 20) {
      $token = bin2hex(random_bytes(16));
      update_user_meta($uid, 'tina_chat_token', $token);
    }

    $session_id = 'wp_' . $token;
  }

  if (!$session_id) {
    return new WP_REST_Response(['error' => 'Missing session_id'], 400);
  }

  $history = tina_load_history($session_id, $conversation_id, $limit);

  return new WP_REST_Response([
    'session_id' => $session_id,
    'conversation_id' => $conversation_id,
    'history' => $history
  ], 200);
}





function tina_get_session_id($data) {

  // Logged-in users: stable, cross-device, unguessable token
  if (is_user_logged_in()) {
    $uid = get_current_user_id();
    $token = get_user_meta($uid, 'tina_chat_token', true);

    if (!is_string($token) || strlen($token) < 20) {
      $token = bin2hex(random_bytes(16)); // 32 hex chars
      update_user_meta($uid, 'tina_chat_token', $token);
    }

    return 'wp_' . $token;
  }

  // Guest mode (frontend sends UUID)
  $session_id = isset($data['session_id']) ? sanitize_text_field($data['session_id']) : '';
  $session_id = substr($session_id, 0, 64);

  if (!$session_id) {
    $session_id = wp_generate_uuid4();
  }

  return $session_id;
}


function tina_load_history($session_id, $conversation_id = 'default', $limit = 20) {
  global $wpdb;
  $table = $wpdb->prefix . 'tina_chat_history';

  $limit = max(1, min(200, intval($limit)));

  // Get last N in correct order
  $rows = $wpdb->get_results(
    $wpdb->prepare(
      "SELECT role, content
       FROM $table
       WHERE session_id = %s AND conversation_id = %s
       ORDER BY created_at DESC, id DESC
       LIMIT $limit",
      $session_id, $conversation_id
    ),
    ARRAY_A
  );

  $rows = array_reverse($rows);

  // Map to the structure you want (n8n or OpenAI style)
  $history = [];
  foreach ($rows as $r) {
    $history[] = [
      'role' => $r['role'],
      'content' => $r['content'],
    ];
  }
  return $history;
}

function tina_insert_turn($session_id, $conversation_id, $role, $content) {
  global $wpdb;
  $table = $wpdb->prefix . 'tina_chat_history';

  $wpdb->insert($table, [
    'session_id' => $session_id,
    'conversation_id' => $conversation_id,
    'role' => $role,
    'content' => $content,
  ], ['%s','%s','%s','%s']);
}

function tina_call_n8n($payload) {
  $n8n_url = 'https://n8n.gregoriopirolini.com/webhook/tina2026';
  $headers = ['Content-Type' => 'application/json'];

  if (defined('TINA_N8N_SECRET') && TINA_N8N_SECRET) {
    $headers['X-Tina-Secret'] = TINA_N8N_SECRET;
  }

  $response = wp_remote_post($n8n_url, [
    'headers' => $headers,
    'timeout' => 60,
    'body'    => wp_json_encode($payload),
  ]);

  if (is_wp_error($response)) {
    error_log('TINA wp_remote_post error: ' . $response->get_error_message());
    return ['ok' => false, 'error' => $response->get_error_message()];
  }

  $code = wp_remote_retrieve_response_code($response);
  $body = wp_remote_retrieve_body($response);

  error_log('TINA n8n HTTP code: ' . $code);
  error_log('TINA n8n raw body: ' . $body);

  $json = json_decode($body, true);

  if ($code < 200 || $code >= 300) {
    return ['ok' => false, 'error' => "Hmm… something glitched for a second. Try again?", 'raw' => $body];
  }

  if (!is_array($json)) {
    error_log('TINA n8n returned non-JSON');
    return ['ok' => false, 'error' => 'n8n returned non-JSON', 'raw' => $body];
  }

  return ['ok' => true, 'data' => $json];
}



function tina_chat_handler(WP_REST_Request $request) {
  $data = $request->get_json_params();
  if (!is_array($data)) $data = [];

  $message = isset($data['message']) ? trim((string)$data['message']) : '';
  $message = wp_strip_all_tags($message);

  if ($message === '') {
    return new WP_REST_Response(['error' => 'Empty message'], 400);
  }
  if (strlen($message) > 8000) {
    return new WP_REST_Response(['error' => 'Message too long'], 413);
  }

  $session_id = tina_get_session_id($data); // already clamped to 64
  $conversation_id = isset($data['conversation_id']) ? sanitize_text_field($data['conversation_id']) : 'default';
  $conversation_id = substr($conversation_id, 0, 64);

  // Load last N messages (history BEFORE inserting user turn)

  $history = tina_load_history($session_id, $conversation_id, 20);
  $summary = tina_load_summary($session_id, $conversation_id);
  $history_count = tina_count_history($session_id, $conversation_id);
  // Save user message
  tina_insert_turn($session_id, $conversation_id, 'user', $message);

  // Send to n8n
 $payload = [
  'session_id' => $session_id,
  'conversation_id' => $conversation_id,
  'message' => $message,
  'messages' => $history,
  'summary' => $summary,
  'history_count' => $history_count,
];

  $n8n = tina_call_n8n($payload);

  if (!$n8n['ok']) {
    $errText = "Sorry — backend error: " . ($n8n['error'] ?? 'Unknown');
    tina_insert_turn($session_id, $conversation_id, 'assistant', $errText);
    return new WP_REST_Response(['session_id' => $session_id, 'reply' => $errText], 502);
  }


$data = $n8n['data'] ?? [];

// n8n may return either:
// 1) a plain object: { "reply": "...", "summary": "..." }
// 2) an array with one object: [ { "reply": "...", "summary": "..." } ]
if (isset($data[0]) && is_array($data[0])) {
  $data = $data[0];
}

$reply = $data['reply'] ?? '';
if (!is_string($reply) || trim($reply) === '') {
  $reply = "すみません。n8nからは返信が来ませんでした。";
}
error_log('すみません。TINA parsed reply: ' . $reply);
$new_summary = $data['summary'] ?? null;
if (is_string($new_summary) && trim($new_summary) !== '') {
  tina_save_summary($session_id, $conversation_id, $new_summary);
}


  tina_insert_turn($session_id, $conversation_id, 'assistant', $reply);

  return new WP_REST_Response([
    'session_id' => $session_id,
    'conversation_id' => $conversation_id,
    'reply' => $reply,
  ], 200);
}

register_activation_hook(__FILE__, 'tina_create_summary_table');