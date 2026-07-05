# AI Agent tool

The module registers one AI Agent tool (an `ai` / `ai_agents` function call).

## Figma: List Design Pages

| | |
| --- | --- |
| **Plugin id** | `ai_figma:list_design_pages` |
| **Function name** | `ai_figma_list_design_pages` |
| **Group** | `information_tools` |
| **Permission** | *Use Figma design context* (`use ai figma design context`), or `administer ai agents` / `use Drupal Canvas AI` |

### Inputs

Both are optional:

| Input | Description |
| --- | --- |
| `figma_url` | A `figma.com` link — the file key is extracted from it. |
| `file_key` | A Figma file key. |

When neither is given, the tool falls back to the **Default Figma file key**
from [configuration](configuration.md). A link can be plain or `@`-prefixed, and
node ids in links use a dash (`1283-979`) which is converted to the colon form
(`1283:979`) the REST API expects.

### Output

Every top-level frame of the file, each with its node id and the canvas it
belongs to, so an AI agent can plan **one Canvas page per design frame** (and
skip style-guide / foundation canvases). Returned as YAML, for example:

```yaml
figma_file_key: RJkuWNHla1P8VYHa5z6dnL
design_pages:
  - node_id: '1:2'
    name: Homepage
    canvas: Designs
    type: FRAME
  - node_id: '1:48'
    name: About
    canvas: Designs
    type: FRAME
```

### Use it with the Canvas AI assistant

Add **Figma: List Design Pages** to the Canvas AI assistant's tool list, then
ask it — with a Figma link — to plan pages from the design. The assistant calls
the tool, reads the frame list, and proceeds one page at a time.
