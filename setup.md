# build chatbot

## install

 n8n and ngrok

## set up
-   in cmd:
```
n8n start
```
- in other cmd: in ngrok fodler 
```
ngrok http 5678
```

## n8n

- open http://localhost:5678/home/workflows

- click new workflow

- name it 'tina2026'

- make api:
https://platform.openai.com/api-keys

- top left click +, click creditential, drop down find 'OpenAI' and paste api key

### add webhook 
- back in workflow
    - **add webhook** name path 'tina2026'
    Change these three fields:

    - **HTTP Method**
    → Change from GET to POST
    (Most chatbots send messages via POST)
    -  **Respond**
    → Make sure it says 'When Last Node Finishes'
   - **Response Data** 
   → set to First Entry JSON 

- click save click publish:

        Workflow published
        You can now make calls to your production 
        webhook URL.
        These executions will not show up immediately
        in 
        the editor, but you can see them in the 
        execution list if 
        you choose to save executions.

- ***test***

    curl -X POST http://localhost:5678/webhook/tina2026 -H "Content-Type: application/json" -d "{\"message\": \"test\"}"  



    - *should return*:
{"headers":{"host":"localhost:5678","user-agent":"curl/8.16.0","accept":"*/*","content-type":"application/json","content-length":"19"},"params":{},"query":{},"body":{"message":"test"},"webhookUrl":"http://localhost:5678/webhook/tina2026","executionMode":"production"}

### add code -> rename add history
- clik add code javascript
- name it add history
paste code:const items = $input.all();
```
return items.map(item => {
  // --- Extract user message from webhook body ---
  const msg = item.json.body?.message || item.json.message || "";
  const message = msg.trim() || "hello";

  // --- Get previous chat history ---
  const history = item.json.history || [];

  // --- Add this new user message to history ---
  item.json.history = [
    ...history,
    { role: "user", content: message }
  ].slice(-10);

  // --- Pass message forward for Filip ---
  item.json.message = message;

  return item;
});
```

### add LLM

-   **Add node → (LangChain) Basic LLM Chain**

-   In the node, choose **Prompt type: Define** (or "define")

-   Paste a Tina prompt (example below)

        You are Tina — a friendly, slightly cheeky AI singer / creative assistant.

        Rules:
        - Reply in the same language as the user.
        - Keep replies short, punchy, and helpful.
        - Use the conversation history below for context.

        Conversation so far:
        {{ $json.history.map(h => h.role + ": " + h.content).join("\n") }}

        User’s latest message:
        {{ $json["message"] }}

        Write only Tina’s next message.

### add the model

- Select your OpenAI credentials (the one where you pasted the key) groq

- Pick a model (start with gpt-4o-mini like in your Filip JSON) llama-3.1-8b-instant

- Use Response API” → NO

### test

curl -X POST http://localhost:5678/webhook/tina2026 ^
  -H "Content-Type: application/json" ^
  -d "{\"message\":\"hello tina\"}"

  curl -X POST http://localhost:5678/webhook/tina2026 ^
  -H "Content-Type: application/json" ^
  -d "{\"message\":\"hello tina\"}"

### add code -> rename history code

const items = $input.all();

return items.map(item => {
  const history = item.json.history || [];
  const userMsg = item.json.message;
  const botMsg =
    item.json.text ||
    item.json.output?.text ||
    item.json.output ||
    item.json.result ||
    item.json.choices?.[0]?.message?.content ||
    item.json.reply ||
    "no response";

  item.json.history = [
    ...history,
    { role: "user", content: userMsg },
    { role: "assistant", content: botMsg }
  ].slice(-10);

  item.json.reply = botMsg;

  // --- Name memory (detect "my name is ...") ---
const nameMatch = (userMsg || "").match(/my name is\s+([a-z]+)/i);
if (nameMatch) {
  item.json.user_name =
    nameMatch[1].charAt(0).toUpperCase() + nameMatch[1].slice(1);
}
  return item;
});

## save and publish!

