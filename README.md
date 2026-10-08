# AI Scratchpad

## First: A Note From The Author

This plugin was created by John P. The guy who brought the world HTMLHelp.com,
Woopra, GeekBeat.TV, and OneMansBlog. The plugin is, and always will be, FREE.
As in Beer.

- There will never be a "PRO" version of this. It's already pro.
- Your usage will never be tracked.
- I will ask nothing whatsoever of you. Except maybe to pay it forward.
- The code is 100% open and reviewable on GitHub.
- I encourage and welcome improvements for all the world to use!

## What It Is

Every time you start a new session with an AI, it wakes up with amnesia.
AI Scratchpad is a notepad for your AI, kept right on your WordPress site:
a journal of what it did and why, plus notes it reads at the start of every
session. One call and it knows where you left off.

To be clear: this is not a notepad for you. You have sticky notes, a phone, and
a brain. This one's for your AI. You're welcome to read it, but it's not your
diary.

By John Pozadzides. Free, GPLv2 or later.

## What It Stores

- **A journal.** What your AI changed and why: handoffs, changes, decisions,
  gotchas, and questions only you can answer. Entries are never rewritten,
  only amended.
- **Notes.** Durable pages like `conventions/how-we-work-here`. Pin the ones
  your AI should read every session.

One call to `bootstrap` returns the last handoff, every pinned note, the list
of everything else it has written down, and anything waiting on you.

## Safety

- **Admins only.** Every ability checks `manage_options`.
- **No secrets.** Writes that look like passwords, API keys or tokens are
  refused, and your AI is told to store a pointer instead.
- **No deleting by default.** Archiving is always available and reversible.
- **Nothing leaves your site.** No outbound requests, ever.

## Works With AI Godmode

[AI Godmode](https://github.com/johnpoz/ai-godmode) gives your AI the keys to
the site. AI Scratchpad gives it a memory of what it did with them.

## Requirements

- WordPress 6.9 or later
- PHP 7.4 or later
- An MCP bridge plugin, such as the WordPress MCP Adapter or Easy MCP AI

## Install

1. Download the latest zip from [Releases](../../releases).
2. In WordPress, go to Plugins, Add New, Upload Plugin, and activate it.
3. Tick the AI Scratchpad abilities in your MCP bridge plugin's tool list.
4. Tell your AI to call `bootstrap` first. Pin that in a note so you only say it once.
5. Send a "Thanks John P.!" to @johnpoz for making your life better.

## License

GPLv2 or later. See [LICENSE](LICENSE).
