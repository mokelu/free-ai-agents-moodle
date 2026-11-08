# Free AI and Agents — Moodle block (`block_aiagents`)

A Moodle **block** plugin that brings the same idea as the *Free AI and Agents*
Obsidian app into the Moodle ecosystem: an AI chat that runs on a shared free
token pool, with **no account, no signup and no API key needed to start**, plus
an optional bring-your-own-key path.

This is a real Moodle plugin (frankenstyle component `block_aiagents`). It is
installed as a block inside Moodle, not a standalone page.

## What it does

- **Free AI chat** inside any course, the Dashboard or any page the block is added to.
- **Model picker** populated live from the free pool catalogue (`/v1/models`).
- **Conversation history** stored per user in the Moodle database and re-openable.
- **Bring-your-own key** — a user key routes straight to the provider and skips the free allowance.
- **Friendly quota handling** — when the pool is exhausted the widget points to usefree.space.

## Requirements

- Moodle **4.1+** (branch `2022112800`).
- PHP with the `curl` extension.
- Outbound HTTPS access to the pool base URL (default `https://api.usefree.space/v1`).

## Install

1. Copy this folder to `{your/moodle/dirroot}/blocks/aiagents`.
2. Log in as an administrator and go to **Site administration → Notifications**
   (or run `php admin/cli/upgrade.php`) to finish the database install.
3. Add the block to a page via **Turn editing on → Add a block → Free AI and Agents**,
   or enable it site-wide through the block manager.
4. Optionally configure **Site administration → Plugins → Blocks → Free AI and Agents**:
   - `Free pool base URL`
   - `Default model`
   - `Max tokens per reply`
   - `Assistant system prompt`

## Bring-your-own key

The per-user key is stored as the `block_aiagents_apikey` user preference. When it is
set, requests are sent with an `Authorization: Bearer` header and bypass the shared
free allowance.

## How the free allowance works

Requests are proxied **server-side** by PHP (never from the browser), tagged with a
stable per-user `X-Device-Id`, so the pool can apply a monthly free token budget.
When the budget is used up the widget shows the pool's reset guidance and links to
usefree.space.

## Privacy

- Chats are stored in Moodle (`block_aiagents_conversation`, `block_aiagents_message`) and are covered by the Privacy API.
- No client-side telemetry.
- The model catalogue is cached for one hour.

## Layout

```
version.php                 Plugin metadata
block_aiagents.php          Block class
settings.php                Admin settings
db/                         access.php, services.php, install.xml, upgrade.php
classes/local/pool.php      OpenAI-compatible pool client (server side)
classes/local/history.php   Conversation/message storage
classes/external/           AJAX endpoints (get_state, get_messages, request_ai, delete_conversation)
classes/output/renderer.php Widget renderer
classes/privacy/provider.php Privacy API
amd/                        Front-end chat module (src + build)
templates/chat.mustache     Widget skeleton
styles.css                  Chat styling
pix/                        Block icon
```

## License

GPL v3 or later, matching Moodle plugin conventions.
