document.addEventListener("DOMContentLoaded", () => {
  // --- Config ---
   console.log('chat function loaded')
    const REST_NONCE = TINA_CONFIG?.nonce || "";
    const WP_CHAT_URL = TINA_CONFIG?.chatUrl || "/wp-json/tina/v1/chat";
    const WP_HISTORY_URL = TINA_CONFIG?.historyUrl || "/wp-json/tina/v1/history";
    const IS_LOGGED_IN = TINA_CONFIG?.isLoggedIn === true;

    const HISTORY_LIMIT = 30;

  // --- DOM ---
  const toggle = document.getElementById("chatToggle");
const win = document.getElementById("chatWindow");
const input = document.getElementById("chatInput");
const msgs = document.getElementById("chatMessages");

// page mode = full chat page exists without toggle
const isPageMode = !!document.querySelector(".tina-chat-page");
// need input + messages in both modes
if (!input || !msgs) return;

// in widget mode we also need toggle + window
if (!isPageMode && (!toggle || !win)) return;

  // --- State ---
  let tinaSessionId = null; // null for logged-in users (server chooses stable token)
  let chatOpened = false;
  let sending = false;

  const greetings = [
  "Hey, I’m Tina — welcome to my website! Ready to make today awesome?",
  "Hi there ✨ I’m Tina. What are you curious about today?",
  "Hello! I’m Tina, your digital host. How can I help?",
  "Hey 👋 Glad you’re here. Want to explore something together?",
  "Hi! I’m Tina. Ask me anything — I’m listening.",
  "Welcome! I’m Tina. What brings you here today?",
  "Hey hey 💬 Tina here. What’s on your mind?",
  "Hi! Feel free to chat — I’m here to help.",
  "Hello 👋 Need info, ideas, or just a quick answer?",
  "Hey! Let’s make this interesting. What would you like to know?"
];

document.getElementById("tinaReset")?.addEventListener("click", async () => {
  if (!confirm("Start a new conversation with Tina?")) return;

  try {
    const payload = {};
    if (tinaSessionId) payload.session_id = tinaSessionId;

    await fetch(TINA_CONFIG.resetUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "X-WP-Nonce": REST_NONCE
      },
      body: JSON.stringify(payload)
    });

    msgs.innerHTML = "";
  } catch (e) {
    appendLine("Tina", "Could not reset conversation.", { style: "color:red;" });
  }
});


  // Decide session behavior once on load
  window.addEventListener("load", () => {
    const isLoggedIn = (window.wpLoggedIn === true);

    if (isLoggedIn) {
      // Logged-in users: do NOT send session_id; server maps to per-user token
      tinaSessionId = null;
    } else {
      // Guests: stable UUID stored in localStorage
      tinaSessionId = localStorage.getItem("tina_session_id");
      if (!tinaSessionId) {
        tinaSessionId = crypto.randomUUID();
        localStorage.setItem("tina_session_id", tinaSessionId);
      }
    }

    // Optional debug
    // console.log("Tina session:", tinaSessionId, "loggedIn:", isLoggedIn);
    input.value = "";
  });

  // --- Helpers ---
  function scrollToBottom() {
    msgs.scrollTop = msgs.scrollHeight;
  }

  function appendLine(who, text, opts = {}) {
    const div = document.createElement("div");
    if (opts.id) div.id = opts.id;
    if (opts.style) div.style.cssText = opts.style;

    const b = document.createElement("b");
    b.textContent = who + ":";
    div.appendChild(b);
    div.append(" " + String(text ?? ""));
    msgs.appendChild(div);
    scrollToBottom();
  }

  function renderHistory(history) {
    msgs.innerHTML = "";
    history.forEach(msg => {
      const who = (msg.role === "assistant") ? "Tina" : "Du";
      appendLine(who, msg.content);
    });
  }

  async function loadHistoryFromDb() {
    let url = `${WP_HISTORY_URL}?limit=${HISTORY_LIMIT}`;
    if (tinaSessionId) {
      url += `&session_id=${encodeURIComponent(tinaSessionId)}`;
    }

    const res = await fetch(url, {
      credentials: "same-origin",
      headers: { "X-WP-Nonce": REST_NONCE }
    });

    // If rate-limited or forbidden, fail gracefully
    if (!res.ok) return [];

    const data = await res.json();
    return Array.isArray(data.history) ? data.history : [];
  }

  async function sendMessage(text) {
    const payload = { message: text };
    if (tinaSessionId) payload.session_id = tinaSessionId;

    const res = await fetch(WP_CHAT_URL, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "X-WP-Nonce": REST_NONCE
      },
      body: JSON.stringify(payload)
    });

    // If rate limited (429) or forbidden (403), show a friendly message
    if (res.status === 429) {
      return { ok: false, error: "Rate limited — wait a moment and try again." };
    }
    if (res.status === 403) {
      return { ok: false, error: "Session expired — refresh the page and try again." };
    }
    if (!res.ok) {
      return { ok: false, error: "Server error — try again in a moment." };
    }

    // We don't actually need the reply here because we re-load history,
    // but we parse it to avoid unhandled promise states.
    await res.json().catch(() => null);
    return { ok: true };
  }

  // --- UI events ---
  async function openChatAndLoadHistory() {
  const history = await loadHistoryFromDb();

  if (history.length > 0) {
    renderHistory(history);
  } else {
    const greeting = greetings[Math.floor(Math.random() * greetings.length)];
    appendLine("Tina", greeting, { style: "color:#444;margin:4px 0;" });
  }
}

if (isPageMode) {
  chatOpened = true;
  openChatAndLoadHistory();
} else if (toggle && win) {
  toggle.onclick = async () => {
    const isOpen = (win.style.display === "block");
    win.style.display = isOpen ? "none" : "block";

    if (!isOpen && !chatOpened) {
      chatOpened = true;
      await openChatAndLoadHistory();
    }
  };
}

  input.addEventListener("keydown", async (e) => {
  // Shift + Enter = new line
  if (e.key === "Enter" && e.shiftKey) {
    return;
  }

  // Only react to Enter
  if (e.key !== "Enter") return;

  // Enter = send message
  e.preventDefault();

  if (!input.value.trim() || sending) return;

  const text = input.value.trim();
  input.value = "";
  input.style.height = "auto";

  appendLine("Du", text);
  input.disabled = true;
  sending = true;

  try {
    await new Promise(r => setTimeout(r, 800));

    const typingId = "typing";
    appendLine("Tina", "is typing...", {
      id: typingId,
      style: "color:#777;font-style:italic;"
    });

    const result = await sendMessage(text);

    if (!result.ok) {
      document.getElementById(typingId)?.remove();
      appendLine("Tina", result.error, { style: "color:red;" });
    } else {
      const history = await loadHistoryFromDb();
      document.getElementById(typingId)?.remove();
      renderHistory(history);
    }
  } catch (err) {
    document.getElementById("typing")?.remove();
    appendLine("Tina", "(connection error)", { style: "color:red;" });
  }

  await new Promise(r => setTimeout(r, 800));
  sending = false;
  input.disabled = false;
  input.focus();
});

function appendLine(who, text, opts = {}) {
  const div = document.createElement("div");
  div.className = who === "Tina" ? "tina-msg tina-msg--ai" : "tina-msg tina-msg--user";

  if (opts.id) div.id = opts.id;

  div.textContent = text;

  msgs.appendChild(div);
  scrollToBottom();
}

input.addEventListener("input", () => {
  input.style.height = "auto";
  input.style.height = input.scrollHeight + "px";
});

  window.addEventListener("pageshow", () => { input.value = ""; });
}); // <-- ends here, NO () after
