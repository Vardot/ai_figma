# AI Figma

[![Tests](https://github.com/Vardot/ai_figma/actions/workflows/test.yml/badge.svg)](https://github.com/Vardot/ai_figma/actions/workflows/test.yml)

Turn a Figma design into a ready-made Drupal Canvas page. Paste a Figma link and
the module builds a matching page from your site's own components, styled with the
design's colours and fonts and filled with its real text - no coding.

It works two ways:

- a simple admin form (**Figma to Drupal**), and
- inside the Drupal Canvas editor, via an **AI Agent tool** the Canvas AI
  assistant can call ("implement this design from Figma" with a link).

Under the hood it talks to the Figma REST API (`https://api.figma.com`) from
server-side PHP - the same data the Figma MCP server's `get_design_context`
surfaces, without an interactive OAuth flow.

## Requirements

- Drupal **11.3**
- `ai` (Drupal AI)
- `ai_agents`
- `key`
- A Figma personal access token (read scope).

## Install

```bash
ddev drush en ai_figma -y
```

## Connect your Figma token (stored like the AI keys)

The token is stored in the Key module (the **Figma access token** Key), the same
way Drupal AI stores its provider keys.

1. Paste your read-only Figma token at `/admin/config/system/keys`; with
   `easy_encryption` enabled it is encrypted at rest.
2. Clear the cache (e.g. `ddev drush cr`).
3. Visit **Configuration → AI → AI Figma** (`/admin/config/ai/figma`), confirm
   the **Figma token (Key)** is the ready-made *Figma access token* Key,
   optionally set a default file key, and click **Test Figma connection**.

The status report (`/admin/reports/status`) shows whether a token is resolved.

## Build a page from Figma (admin form)

**Configuration → AI → Varbase AI Figma → Figma to Drupal**
(`/admin/config/ai/figma/figma-to-drupal`): paste a Figma link, name the
page, optionally point at a page to replace, then **Build my page**. The builder
picks the section that best fits the design and fills your components with its text.

## Choose which components it uses

**Configuration → AI → Varbase AI Figma → **
components fill each part of a page (section, group, card, hero, CTA, heading,
text), set the default section, and drag to reorder the nesting. Everything is
configuration read live from the theme - nothing is hard-coded.

## Use it inside Drupal Canvas (AI Agent tool)

Add the tool to the Canvas AI assistant once:

1. Edit **Drupal Canvas AI Orchestrator**
   (`/admin/config/ai/agents/canvas_ai_orchestrator/edit/form`).
2. **Select tools**, tick **Figma: Get Design Context**, **Use selected tools**, **Save**.

Then, while editing a Canvas page, ask the AI assistant with a link:

> Implement this design from Figma.
> https://www.figma.com/design/&lt;fileKey&gt;/...?node-id=9653-1167

A link can be plain or "@"-prefixed; node ids in links use a dash and are converted
for you. The assistant builds the section from your existing theme components and
makes every component and text accessible (WCAG 2.1 AA).

## Tool reference

- Plugin id: `ai_figma:get_design_context`
- Function name: `ai_figma_get_design_context`
- Group: `information_tools`
- Inputs (all optional): `figma_url` (a link, wins over the fields), `file_key`,
  `node_id` (`1283:979` or `1283-979`).
- Output: `colors`, `typography`, `node_outline`, `content_texts` (the design's
  real text), `available_components` (the theme's live components), plus a
  build-with-existing-components instruction and an accessibility directive.

## Permissions

- **Use Figma design context** (`use ai figma design context`) - the AI tool and
  the node-ids page.
- **Administer AI Figma** (`administer ai figma`) - the settings and layout pages
  and the Figma token (trusted roles).
