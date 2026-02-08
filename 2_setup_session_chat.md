# set session chat 

we want save history for each user, so that tina answrers to the right persons and doesnt mix chats.

## Step 1 (n8n): create/use session_id

Add one Code node right after Webhook:
Name: session id
Mode: Run once for each item


```js
// Read session_id from webhook body (curl sends it there)
const sid =
  ($json.body?.session_id ??
   $json.session_id ??
   "").toString().trim();

// If none provided, create one
if (!sid) {
  $json.session_id =
    Date.now().toString(36) +
    Math.random().toString(36).slice(2, 10);
} else {
  $json.session_id = sid;
}

return $json;

```







rest chat!
function resetTinaChat() {
  localStorage.removeItem('Tina_chat');
  localStorage.removeItem('tina_session_id');
  tinaSessionId = crypto.randomUUID();
  localStorage.setItem('tina_session_id', tinaSessionId);
  chatState = { history: [], lastGreet: null };
  msgs.innerHTML = "";
}






--------------------------------------------------

test with: 
curl -X POST "http://localhost:5678/webhook/tina2026" -H "Content-Type: application/json" -d "{\"message\":\"I try to debug code error it is still faulty\",\"session_id\":\"abc123\"}"

curl -X POST "http://localhost:5678/webhook/tina2026" -H "Content-Type: application/json" -d "{\"message\":\"what am i trying to do?\",\"session_id\":\"abc123\"}"


## 3 WordPress user detection

function tina_resolve_identity(): array {
    // In WordPress context
    if (function_exists('is_user_logged_in') && is_user_logged_in()) {
        $u = wp_get_current_user();
        return [
            'type' => 'wp',
            'id'   => (string)$u->ID,
        ];
    }

    return [
        'type' => 'guest',
        'id'   => tina_get_guest_token(),
    ];
}

If this endpoint is not inside WP, tell me and I’ll adapt it (but since you mentioned “WordPress logged in”, I assume it is).

## 4) Add a "conversation key" (optional but super useful)
-------------------------------------------------------

This lets you have **multiple conversations per user** later (reset button, different pages, etc.).

-   If you don't need it yet: set `conversation_id = 'default'`.

-   If you want "one chat per page": use the page slug or a passed `chat_id`.

function tina_conversation_id(): string {
    // simplest: one chat per user total
    return 'default';

    // alternative idea later:
    // return !empty($_GET['chat_id']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['chat_id']) : 'default';
}


5) How your add_history should store it

When you receive a message:

$identity = tina_resolve_identity();
$conversationId = tina_conversation_id();

// Example payload fields you pass to your DB insert:
$identityType = $identity['type'];   // 'wp' or 'guest'
$identityId   = $identity['id'];     // wp user id OR guest token


So your history table can be keyed by:

identity_type

identity_id

conversation_id

6) Database schema (minimal + future-proof)
CREATE TABLE tina_chat_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  identity_type VARCHAR(10) NOT NULL,     -- 'wp' or 'guest'
  identity_id   VARCHAR(64) NOT NULL,     -- wp id or token
  conversation_id VARCHAR(64) NOT NULL DEFAULT 'default',
  role ENUM('user','assistant','system') NOT NULL,
  content TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_lookup (identity_type, identity_id, conversation_id, created_at)
);

7) Load history for n8n/OpenAI

When building the messages array:

function tina_load_history(PDO $pdo, string $identityType, string $identityId, string $conversationId, int $limit = 20): array {
    $sql = "SELECT role, content
            FROM tina_chat_history
            WHERE identity_type = :t AND identity_id = :i AND conversation_id = :c
            ORDER BY id DESC
            LIMIT :lim";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':t', $identityType);
    $stmt->bindValue(':i', $identityId);
    $stmt->bindValue(':c', $conversationId);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

    // Convert to OpenAI-style messages
    $messages = [];
    foreach ($rows as $r) {
        $messages[] = ['role' => $r['role'], 'content' => $r['content']];
    }
    return $messages;
}


Then append the new user message and send to n8n.