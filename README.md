# AI Figma

[![pipeline status](https://git.drupalcode.org/project/ai_figma/badges/1.0.x/pipeline.svg)](https://git.drupalcode.org/project/ai_figma/-/pipelines)

Read a Figma design's context from server-side PHP and hand it to Drupal's AI
Agent tools, so the Drupal Canvas AI assistant can work from the real design —
its colors, typography, structure, and text — instead of a screenshot.

This module is deliberately small. It provides two things:

- the **Figma connection** — a stored read-only access token and a settings
  page, and
- an **AI Agent tool** that lists a design's page frames.

Under the hood it talks to the Figma REST API (`https://api.figma.com`) directly
— the same data the Figma MCP server's `get_design_context` surfaces — without
an interactive OAuth flow.

## Requirements

- Drupal **^11.2**
- `ai` (Drupal AI) and `ai_agents`
- `key` and `easy_encryption`
- A Figma personal access token (read scope)

## Install

```bash
composer require drupal/ai_figma
ddev drush en ai_figma -y
```

## Connect your Figma token

The token is stored in the Key module — the same way Drupal AI stores its
provider keys — so it is encrypted at rest via `easy_encryption`. Installing the
module creates an empty **Figma access token** Key for you.

1. Create a read-only token at
   [figma.com → Settings → Security](https://www.figma.com/settings).
2. Paste it into the *Figma access token* Key at `/admin/config/system/keys`.
3. Clear the cache (`ddev drush cr`).
4. Visit **Configuration → AI → AI Figma** (`/admin/config/ai/figma`), confirm
   the Key is selected, optionally set a default file key, and click
   **Test Figma connection**.

The status report (`/admin/reports/status`) shows whether a token is resolved.

## The AI Agent tool

**Figma: List Design Pages**

- Plugin id: `ai_figma:list_design_pages`
- Function name: `ai_figma_list_design_pages`
- Group: `information_tools`
- Inputs (both optional): `figma_url` (a figma.com link — the file key is
  extracted from it) and `file_key`. When neither is given it falls back to the
  configured default file key.
- Output: every top-level frame of the file with its node id and the canvas it
  belongs to, so an AI agent can plan one Canvas page per design frame.

A link can be plain or `@`-prefixed; node ids in links use a dash and are
converted to the colon form the REST API expects.

To make the tool available to the Canvas AI assistant, add it to the assistant's
tool list, then ask it — with a Figma link — to plan pages from the design.

## Settings

**Configuration → AI → AI Figma** (`/admin/config/ai/figma`):

- **Figma token (Key)** — the Key holding your token.
- **Default Figma file key** — the long id in a `figma.com/design/<fileKey>/…`
  URL, used when a tool is called without one.
- **Figma API base URL** — override only for a proxy or a self-hosted,
  Figma-compatible API. Any non-`http(s)` value is rejected (SSRF hardening),
  since the secret token is sent to this host on every request.
- **Test Figma connection** — probes the API with the saved token and default
  file key.

## Permissions

- **Use Figma design context** (`use ai figma design context`) — run the AI
  tool.
- **Administer AI Figma** (`administer ai figma`) — the settings page and the
  Figma token (trusted roles).

## Documentation

Full documentation: <https://project.pages.drupalcode.org/ai_figma/>
