<?php

/*
Plugin Name: Tina Chat Endpoint
Description: Stores chat history in DB and proxies messages to n8n.
Version: 0.1
*/

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
  register_rest_route('tina/v1', '/chat', [
    'methods'  => 'POST',
    'callback' => 'tina_chat_handler',
    'permission_callback' => '__return_true',
  ]);
});

function tina_get_session_id($data) {
  // Your frontend already sends session_id; trust that as the primary key.
  $session_id = isset($data['session_id']) ? sanitize_text_field($data['session_id']) : '';

  // Fallback: if logged in and session_id missing, build wp_#
  if (!$session_id && is_user_logged_in()) {
    $session_id = 'wp_' . get_current_user_id();
  }

  // Last resort: generate one (but ideally frontend always sends)
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
  // Put your n8n production webhook URL here:
  // $n8n_url = 'https://YOUR_N8N_DOMAIN/webhook/tina';
  $n8n_url = 'http://127.0.0.1:5678/webhook/tina2026';

  $response = wp_remote_post($n8n_url, [
    'headers' => ['Content-Type' => 'application/json'],
    'timeout' => 60,
    'body'    => wp_json_encode($payload),
  ]);

  if (is_wp_error($response)) {
    return ['ok' => false, 'error' => $response->get_error_message()];
  }

  $code = wp_remote_retrieve_response_code($response);
  $body = wp_remote_retrieve_body($response);
  $json = json_decode($body, true);

  if ($code < 200 || $code >= 300) {
    return ['ok' => false, 'error' => "n8n HTTP $code", 'raw' => $body];
  }

  // You decide what n8n returns; assume { reply: "..." }
  if (!is_array($json)) {
    return ['ok' => false, 'error' => 'n8n returned non-JSON', 'raw' => $body];
  }

  return ['ok' => true, 'data' => $json];
}

function tina_chat_handler(WP_REST_Request $request) {
  $data = $request->get_json_params();
  if (!is_array($data)) $data = [];

  $message = isset($data['message']) ? trim(wp_strip_all_tags($data['message'])) : '';
  if ($message === '') {
    return new WP_REST_Response(['error' => 'Empty message'], 400);
  }

  $session_id = tina_get_session_id($data);
  $conversation_id = isset($data['conversation_id']) ? sanitize_text_field($data['conversation_id']) : 'default';

  // Load last N messages
  $history = tina_load_history($session_id, $conversation_id, 20);

  // Save user message first
  tina_insert_turn($session_id, $conversation_id, 'user', $message);

  // Call n8n with history + new message
  // $payload = [
  //   'session_id' => $session_id,
  //   'conversation_id' => $conversation_id,
  //   'message' => $message,
  //   'history' => $history,
  // ];

  $payload = [
  'session_id' => $session_id,
  'conversation_id' => $conversation_id,
  'message' => $message,
  'messages' => $history, // <-- rename to messages
];

  $n8n = tina_call_n8n($payload);

  if (!$n8n['ok']) {
    // Store an assistant error message optionally
    $errText = "Sorry — backend error: " . ($n8n['error'] ?? 'Unknown');
    tina_insert_turn($session_id, $conversation_id, 'assistant', $errText);
    return new WP_REST_Response(['session_id' => $session_id, 'reply' => $errText, 'debug' => $n8n], 502);
  }

  $reply = $n8n['data']['reply'] ?? '';
  if (!is_string($reply) || trim($reply) === '') {
    $reply = "Sorry — I didn’t get a reply from n8n.";
  }

  // Save assistant reply
  tina_insert_turn($session_id, $conversation_id, 'assistant', $reply);

  return new WP_REST_Response([
    'session_id' => $session_id,
    'conversation_id' => $conversation_id,
    'reply' => $reply,
  ], 200);
}
