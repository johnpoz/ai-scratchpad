=== AI Scratchpad ===
Contributors: johnpoz
Tags: ai, mcp, abilities, notes, journal
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A session journal and durable notes kept on the site itself, so every AI assistant picks up where the last one left off.

== Description ==

Every AI session starts from nothing. You explain the same conventions, the same gotchas, the same half-finished work, every single time. AI Scratchpad fixes that by keeping the memory on the site instead of in the chat.

It stores two things:

* **A journal.** Append-only records of what an assistant did and, more importantly, why. Handoffs, changes, decisions, gotchas, and questions only a human can answer. Entries are never edited, only amended, so the record of what was believed at the time survives.
* **Notes.** Durable pages addressed by a path, like `conventions/how-we-work-here` or `gotchas/pods-rest`. Edited in place with a revision count. Pin the ones an assistant should always read.

The plugin registers ten abilities on the WordPress Abilities API. It is not an MCP server itself: a separate MCP server plugin on the site, such as Easy MCP AI or the official WordPress MCP Adapter, exposes them to your AI client, so this works with whatever connector you already run. There is nothing to configure on the AI side beyond one instruction: call bootstrap first.

= What The Assistant Sees =

One call to `bootstrap` returns the active handoff from the last session, every pinned note in full, the paths of all other notes, recent journal entries, anything still waiting on a human, and a fingerprint of the site (WordPress version, PHP version, theme, active plugins). That is usually everything needed to resume work.

= The Ten Abilities =

* `bootstrap` - read the scratchpad. Call this first.
* `write-entry` - append one journal record.
* `amend` - add to an entry without rewriting history.
* `write-note` - create or replace a note at a path.
* `edit-note` - change part of a note.
* `move-note` - move or archive a note.
* `get` - read one entry or note in full.
* `search` - search entries and notes together, including by object touched.
* `export` - export the whole scratchpad.
* `delete` - permanently delete. Off by default.

= Safety =

* Every ability requires `manage_options`. This is a site operations tool, not a public API.
* Writes that look like they contain a password, API key, or token are refused by default, with an error that tells the assistant to store a pointer instead of the secret.
* Deletion is off by default. Archiving is always available and is reversible.
* Handoffs retire automatically: writing a new one archives the old one, so there is exactly one current state of play.

== Installation ==

1. Upload the plugin and activate it. Make sure the site also runs an MCP server plugin, such as Easy MCP AI or the WordPress MCP Adapter.
2. If your MCP connector keeps an allowlist of tools or abilities, enable the AI Scratchpad abilities there. Write abilities are usually off by default.
3. Restart your AI client so it picks up the new tools, and start a new conversation.
4. Tell your assistant, once, to call `bootstrap` before doing anything else on the site. Better still, write that instruction into a pinned note.

== Frequently Asked Questions ==

= Does this send my content anywhere? =

No. Everything lives in one table in your own database. The plugin makes no outbound requests.

= What if I do not run an MCP server? =

Install one to reach the abilities from an AI client: Easy MCP AI and the official WordPress MCP Adapter both publish Abilities API abilities. Without one, the Journal and Notes screens in the admin still work on their own, so the plugin is usable by a person with no AI attached at all.

= What happened to the built-in server? =

Versions 0.3.0 to 0.4.2 could run their own MCP server and OAuth sign-in server. 0.5.0 removed both, because a dedicated MCP plugin handles the connection and authentication side better than a notes plugin should try to. Upgrading removes the old server's settings, its registered clients and its token table. The journal, the notes and the Credentials and Deletion permissions are untouched.

= Can a human write in it too? =

Yes. Comment on any entry or note from the admin screens, and the next assistant sees it at the top of its next bootstrap, ahead of everything else, until one answers. Editing a note by hand bumps its revision and records your name, and the assistant is told a person corrected it.

== Changelog ==

= 0.5.1 =
* New README. The plugin's home is now https://github.com/johnpoz/ai-scratchpad, and the plugin header points there. The zip now includes the license. No code, setting or ability changed.

= 0.5.0 =
* Removed the built-in MCP server and its sign-in server. The abilities are unchanged and are published through the WordPress Abilities API for any MCP server plugin to expose.
* Gone with them: the /wp-json/ai-scratchpad/v1/mcp endpoint, the OAuth registration, consent, token and revocation endpoints, the discovery documents, connector tokens, the per-tool switches, the Connected clients list, and the bundled WordPress MCP Adapter in vendor/.
* Upgrading removes the old server's settings, its registered OAuth clients, any pending authorization codes and its token table, once. The journal and notes table and the Credentials and Deletion permissions are left exactly as they were.
* Database version 4.

= 0.4.2 =
* Security. A connector token is now accepted only at the plugin's own MCP endpoint. Before, the token was resolved on every request, which made it a site-wide administrator sign-in for anything reachable with an Authorization header.
* Security. The sign-in server, including client registration, the consent screen and the discovery documents, is now off whenever the built-in server is off. Before, it answered regardless of the switch.
* The consent screen now says what a token is: the approving administrator's access, over the MCP endpoint only.
* Uninstalling the plugin now removes its two tables and its options.
* Fixed. Resolving a token could recurse without end when another plugin's capability filter asked for the current user (Admin Menu Editor, User Switching), ending in a memory fatal on every bearer request.

= 0.4.1 =
* The sign-in server's issuer now carries a path, https://your-site/wp-json/ai-scratchpad/v1, and serves OpenID Connect discovery at <issuer>/.well-known/openid-configuration. Clients that read a path issuer try that address, which sits under /wp-json/ and reaches WordPress on every host. With a bare-host issuer, 0.4.0 sent clients to the site root, which hosts like SiteGround answer themselves before WordPress runs; the client then fell back to a guessed registration address and got a 404 page. Found with mcp-remote on a live site.
* No CDN or host configuration is needed any more for discovery. The settings screen now gives the one address to check.

= 0.4.0 =
* The plugin is now its own sign-in server, so its endpoint can be added to an AI account as a connector by web address. That connector lives on the account rather than in one desktop client's configuration file, which means the scratchpad works from a browser and a phone on every device you are signed in on.
* OAuth 2.1 with Dynamic Client Registration, PKCE with S256 required, refresh tokens rotated on every use, and a revocation endpoint. No Client ID Metadata Document support, because resolving one would mean an outbound request and this plugin makes none.
* Administrators only. Every tool here already requires administrator access, so issuing a token to anyone else would be a promise the tools would refuse to keep. A token stops working the moment the user loses that access.
* An unauthenticated request to the MCP endpoint now answers 401 with a WWW-Authenticate header pointing at the plugin's protected resource metadata. Without that header no client can discover where to sign in, which is why the endpoint could not be connected before.
* Approving a connection shows a consent screen naming the client, the address it will return you to, how many tools it will reach, and whether deletion is among them.
* New Connected clients list on the settings screen: which client, which user approved it, when, when it was last used, and a Revoke button that takes effect immediately.
* Discovery documents are served under /wp-json/, which PHP always sees, and also at the site root for hosts that route /.well-known/ into WordPress. Some hosts answer /.well-known/ at the web server before WordPress runs, and on those the root paths have to be routed to the /wp-json/ copies at the CDN. The settings screen gives both addresses and says how to check which case you are in.
* Tokens are stored hashed, never in the clear, in a new table.

= 0.3.1 =
* The built-in MCP server can now publish some tools and not others. Once the server is switched on, the settings screen lists every tool with its own checkbox, a plain-English line saying what it does, and whether it reads or writes.
* On a fresh install every tool is ticked. The server itself is still off by default and still refuses anyone without administrator access, so ticking ten boxes to get a working setup would have been busywork.
* A tool added by a future update arrives unticked on a site that has saved its choices, so an upgrade never quietly grants something the site owner did not choose.
* The Credentials and Deletion permissions still win over these boxes. Ticking Delete does nothing while Deletion is off, and the settings screen says so next to the box.
* The list only appears when the built-in server is on, and a save from a screen without it leaves the stored choices alone rather than clearing them.

= 0.3.0 =
* Added an optional built-in MCP server, off by default. It bundles the official WordPress MCP Adapter and publishes this plugin's abilities directly, for sites that do not already run an MCP server. It exposes the ai-scratchpad namespace and nothing else, and the transport refuses anyone without administrator access.
* The adapter ships a general-purpose default server carrying discover-abilities, get-ability-info and execute-ability. That is a remote-execution surface for every ability on the site, so it is suppressed whenever this plugin is the one starting the adapter. A site running the standalone MCP Adapter plugin keeps whatever that plugin publishes; this plugin defers to it and only adds its own server.
* Minimum WordPress raised to 6.9, which is where the Abilities API lands and what the adapter requires. Earlier releases claimed 6.8, which was wrong.
* Trimmed the write-entry tool definition back under 2 KB after two releases of additions pushed it over.

= 0.2.3 =
* Added `resolves` to `write-entry` and `amend`: clears the `waiting_on` on an earlier entry once the thing has actually been done, so a satisfied request stops appearing at the top of every session. Without it, `waiting_on` was write-once and accumulated forever by design.

= 0.2.2 =
* Fixed: acknowledging a comment silently did nothing when the connector delivered the id list as a JSON string. The ids are now normalized before they are cast.

= 0.2.1 =
* Fixed: the `acknowledges` input was rejected before it reached the plugin, because the schema required integers and some connectors send the ids as strings.

= 0.2.0 =
* Both settings are now permissions: ticked means allowed. `block_credentials` becomes `allow_credentials`, and existing sites are migrated on upgrade. In 0.1.0 one switch was a restriction and the other a permission, so allowing both meant ticking one box and clearing the other, which is how a site ends up with its credential protection off by accident.
* Notes are grouped into a folder tree, derived from path prefixes. Added an archived filter and a last-human-edit column.
* Journal gains an Active / Archived / All filter with counts.
* Humans can comment on any entry or note from the admin. Open comments are surfaced at the top of the next bootstrap and stay there until an assistant answers them.
* `write-entry` and `amend` accept an `acknowledges` array of comment ids, so answering a comment needs no extra tool.
* Editing a note in the admin bumps the revision and records who made the change; assistants see it as `last_edited_by_human`.
* The credential guard now applies to admin-side edits as well as to assistants.
* Database version 2: three new columns and an index on parent_id.

= 0.1.0 =
* First release. Ten abilities, journal and notes storage, credential guard, admin screens for Journal and Notes, settings for credential blocking, deletion, and bootstrap size.
