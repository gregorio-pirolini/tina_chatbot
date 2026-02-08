# more chat functions:
Option A — Load history from DB on open (ditch localStorage entirely)

So chat window always reflects server truth.

Option B — “New conversation” button = new conversation_id

Multiple parallel chats per user (like ChatGPT).

Option C — Hard reset button

Delete DB rows for this session id.

Option D — Rate limiting / abuse protection

One line in WP endpoint.

Option E — Move n8n → direct LLM later

Your WP endpoint is already the right place.