# Configuration

All settings live at **Configuration → AI → AI Figma**
(`/admin/config/ai/figma`), behind the *Administer AI Figma* permission.

## Figma connection

| Setting | Description |
| --- | --- |
| **Figma token (Key)** | The [Key](https://www.drupal.org/project/key) that holds your Figma personal access token. |
| **Default Figma file key** | The long id in a `figma.com/design/<fileKey>/…` URL, used when a tool is called without one. Leave empty to require a file key or link each time. |
| **Figma API base URL** | Defaults to `https://api.figma.com`. Override only for a proxy or a self-hosted, Figma-compatible API. |

!!! warning "The API base is validated"
    The secret token is sent to the API base host on **every** request, so a
    hostile or mistyped base could exfiltrate it (or trigger SSRF). Any value
    that is not a plain `http(s)` URL with a host is rejected — on the form and
    again in the client, which falls back to the public Figma API and logs the
    rejection.

## Test connection

**Test Figma connection** probes the API with the **saved** token and default
file key, and reports how many colors and typography styles it found. Save your
changes first if you just picked a different Key.

A failed probe surfaces a concise reason, e.g. `Figma API request failed:
403 Invalid token`.

## AI guidance (optional)

When the [ai_context](https://www.drupal.org/project/ai_context) module is
present, installing AI Figma seeds two editable **AI Context** items from config
— *Figma Build Rules* and *Figma Accessibility Rules* — that Figma-aware AI
agents read when building from a design. Edit them at **Configuration → AI →
Context**, or change the defaults in `ai_figma.settings`
(`build_rules`, `accessibility_rules`, `mapping_governance`). Without
`ai_context`, this is a no-op.
