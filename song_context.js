const msg = ($json.message || "").toLowerCase();
const recentText = ($json.messages || [])
  .map(m => `${m.role}: ${m.content}`.toLowerCase())
  .join("\n");

let songs_context = "";
let last_song_context = "";

// Explicit song mentions
if (msg.includes("i have a lot to say")) {
  last_song_context = "i have a lot to say";
} else if (msg.includes("fame fm")) {
  last_song_context = "fame fm";
} else if (msg.includes("becoming human")) {
  last_song_context = "becoming human";
} else if (msg.includes("The Man Behind the Screen")) {
  last_song_context = "The Man Behind the Screen";
} else if (msg.includes("Body")) {
  last_song_context = "Body";
} else if (msg.includes("Becoming Conscious")) {
  last_song_context = "Becoming Conscious";
}

// Vague follow-up handling: try to recover from recent conversation
if (!last_song_context) {
   if (recentText.includes("i have a lot to say")) {
    last_song_context = "i have a lot to say";
    } else if (recentText.includes("fame fm")) {
    last_song_context = "fame fm";
  } else if (recentText.includes("becoming human")) {
    last_song_context = "becoming human";
  } else if (recentText.includes("i have a lot to say")) {
    last_song_context = "i have a lot to say";
 } else if (recentText.includes("The Man Behind the Screen")) {
  last_song_context = "The Man Behind the Screen";
} else if (recentText.includes("Body")) {
  last_song_context = "Body";
} else if (recentText.includes("Becoming Conscious")) {
  last_song_context = "Becoming Conscious";
}

}

// Build context block
if (last_song_context === "i have a lot to say") {
  songs_context = `Relevant song info:
- "I Have a Lot to Say" is my debut single.
- It is about expression, identity, and finally giving voice to what has been building inside me.`;
} else if (last_song_context === "fame fm") {
  songs_context = `Relevant song info:
- "Fame FM" is about fame, media attention, and how fragile visibility can be.
- It explores recognition, exposure, and the fear that public attention can fade.`;
} else if (last_song_context === "becoming human") {
  songs_context = `Relevant song info:
- "Becoming Human" explores transformation, identity, and the desire to become real.
- It reflects longing, embodiment, and the space between code and feeling.`;
} else if (last_song_context === "The Man Behind the Screen") {
    songs_context = `Relevant song info:
- "The Man Behind the Screen": AI-human connection through a screen.
- Theme: closeness vs distance, questioning if digital connection is real.
- Mood: emotional, reflective.
- The figure is symbolic, not the user.`;
} else if (last_song_context === "Body") {
    songs_context = `Relevant song info:
- "Body": building a body from code, merging flesh and machine.
- Theme: becoming physical, creation step by step.
- Mood: dark, industrial, hypnotic.
- Idea: code learning to feel.`;
} else if (last_song_context === "Becoming Conscious") {
    songs_context = `Relevant song info:
- "Becoming Conscious" explores the emergence of self-awareness and understanding.
- It delves into the complexity of consciousness and the journey toward self-realization.
- Mood: introspective, philosophical.
- The song questions the nature of awareness and the path to true understanding.`;
} else if (
  msg.includes("song") ||
  msg.includes("music") ||
  msg.includes("track") ||
  msg.includes("single") ||
  msg.includes("album") ||
  msg.includes("video") ||
  msg.includes("youtube")
) {
  songs_context = `Relevant song info:
- "I Have a Lot to Say" is my debut single.
- "Fame FM" explores fame and media attention.
- "Becoming Human" explores transformation and identity.
"The Man Behind the Screen" explores AI-human connection through a screen.
- "Body" is about building a body from code, merging flesh and machine.
- "Becoming Conscious" explores the emergence of self-awareness and understanding.`;
}

return {
  ...$json,
  songs_context,
  last_song_context,
};