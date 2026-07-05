# AI Figma

Read a Figma design's context from server-side PHP and hand it to Drupal's AI
Agent tools, so the Drupal Canvas AI assistant can work from the real design —
its colors, typography, structure, and text — instead of a screenshot.

The module is deliberately small. It provides two things:

- the **Figma connection** — a stored, read-only access token and a settings
  page, and
- an **AI Agent tool** that lists a design's page frames.

Under the hood it talks to the Figma REST API (`https://api.figma.com`) directly
— the same data the Figma MCP server's `get_design_context` surfaces — without
an interactive OAuth flow.

## How it fits together

```
Figma REST API  ──▶  FigmaContextClient (PHP)  ──▶  AI Agent tool
   (design)            token from the Key module      "Figma: List Design Pages"
                                                            │
                                                            ▼
                                              Drupal Canvas AI assistant
```

- The **token** lives in the [Key](https://www.drupal.org/project/key) module,
  encrypted at rest via
  [easy_encryption](https://www.drupal.org/project/easy_encryption).
- `FigmaContextClient` fetches and summarizes the design (colors, typography,
  node outline, real text, image assets).
- The **AI Agent tool** exposes that to `ai` / `ai_agents`, so an AI assistant
  can plan pages from the design.

## Next steps

- [Installation](installation.md) — install the module and connect a token.
- [Configuration](configuration.md) — the settings page, token, default file
  and API base.
- [AI Agent tool](ai-agent-tool.md) — the `ai_figma:list_design_pages` tool
  reference.

## Requirements

- Drupal **^11.2**
- `ai` (Drupal AI) and `ai_agents`
- `key` and `easy_encryption`
- A Figma personal access token (read scope)
