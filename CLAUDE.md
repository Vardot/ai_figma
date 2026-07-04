# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`ai_figma` — a Drupal 11 module (`drupal/ai_figma`, maintained by Vardot). Reads live Figma design context via the Figma REST API from server-side PHP and exposes it as **AI Agent tools** so the Drupal Canvas AI assistant can build Canvas pages from the active theme's own components. Config-driven, theme- and framework-agnostic.

Scope split: this module ships **only the basic Figma connection** (client + settings + Key). The Canvas-AI orchestrator tool-wiring, the build/assistant tools, and AI Context ownership live in a **separate `varbase_ai_figma`** module that depends on this one. Do not add build/assistant logic here.

## Commands

PHP is a Drupal module — it is not run standalone; it is installed into a Drupal 11 site (Core / Drupal CMS / Varbase 11).

```bash
# Enable the module in a site
ddev drush en ai_figma -y
ddev drush cr

# PHP coding standards (Drupal + DrupalPractice), matching CI
phpcs -n --standard=Drupal,DrupalPractice \
  --extensions=php,module,inc,install,profile,theme,yml \
  --ignore="*/node_modules/*,*/vendor/*,*/tests/fixtures/*" .

# PHPUnit — Unit tests run against a built site's core phpunit config
vendor/bin/phpunit -c web/core/phpunit.xml.dist path/to/module/tests/src/Unit
# Single test
vendor/bin/phpunit -c web/core/phpunit.xml.dist --filter testParseFigmaUrl path/to/module/tests/src/Unit/FigmaUrlParseTest.php

composer validate --no-check-all --no-check-publish
```

Node/JS side is the **webship-js (Playwright + Cucumber-js) BDD suite** in `tests/` that drives an already-running site:

```bash
yarn install
LAUNCH_URL=https://your-site.ddev.site yarn test         # run the BDD suite (chromium)
yarn test:firefox   # or :webkit / :chromium
yarn test:headed    # HEADLESS=false, SLOW_MO=800 for watching
yarn lint:js        # eslint tests/step-definitions
yarn generate-reports
```

The suite has **no `@javascript` and no `@ai` scenarios** (no live LLM calls) — every CI leg is an always-green functional lane needing no provider key. CI (`.github/workflows/test.yml`) runs eslint, cspell (advisory), `composer validate`, PHPCS, PHPStan (level 1), PHPUnit, and the webship-js matrix across Drupal Core / Drupal CMS / Varbase 11.

## Architecture

**`FigmaContextClient`** (`src/FigmaContextClient.php`, service `ai_figma.client`) — the whole Figma integration. Key responsibilities:
- **Token**: resolved only from the **Key module** (`getToken()`), never an env var. Kept encrypted at rest via `easy_encryption`.
- **URL parsing**: `parseFigmaUrl()` (static) extracts `file_key` + `node_id` from figma.com `/design|file|board|make/` URLs; handles `@`-prefixed links, branch URLs (branch key wins), and dash→colon node-id normalization.
- **API base hardening**: `apiBase()` accepts an admin-configurable `figma_api_base` but rejects anything that isn't an http(s) URL with a host (SSRF / token-exfil defence), falling back to `https://api.figma.com` and logging.
- **Fetch + summarize**: `fetchNodes()` (resolves a frame/canvas *name* to a node id when a non-id is passed), `fetchFile()`, `fetchImages()`, `fetchImageFills()`; `summarizeTokens()` / `indexNodes()` walk the node tree to extract colors, typography, outline and the design's real text; `collectImageRefs()` finds foundation image assets (skips flattened screenshots).

**AI Agent tools** live in `src/Plugin/AiFunctionCall/` using the `#[FunctionCall]` attribute (`drupal/ai`). `FigmaListDesignPages` (`ai_figma:list_design_pages`) lists a file's top-level frames so an agent can plan one Canvas page per frame. Tools gate on the `use ai figma design context` permission (or `administer ai agents` / `use Drupal Canvas AI`).

**`AiFigmaInstaller`** (`src/AiFigmaInstaller.php`, service `ai_figma.installer`, aliased to its FQCN so `#[Hook]` classes autowire it). `hook_install` calls `installFigmaKey()` to provision the `figma` Key (empty value, `easy_encrypted` provider when available, else `config`). `seedContextItems()` is a helper `varbase_ai_figma` calls to write the build/accessibility/governance rules as `ai_context_item` entities — no-op when `ai_context` is absent.

**Config is the source of truth — nothing about layouts, component choices, content roles, or the AI rule prompts is hard-coded in PHP.** `config/install/ai_figma.settings.yml` (schema in `config/schema/`) holds: `figma_token_key`, `figma_api_base`, `default_file_key`, the `build_rules` / `accessibility_rules` / `mapping_governance` prompt text sent to the Canvas AI agent, `content_roles` (how text is read from Figma nodes), and `layouts` / `component_choices` / `builder_order` (how sections map onto theme components). To change build behaviour, edit config (or the seeded AI Context items), not PHP.

## Conventions

- `declare(strict_types=1);` on every PHP file; typed properties and constructor promotion throughout.
- Drupal coding standards (PHPCS `Drupal,DrupalPractice`) are enforced in CI — match the surrounding docblock style.
- `core_version_requirement: ^11.2`; PHP `>=8.3` (CI runs 8.4).
- User-facing rule/prompt text belongs in config or AI Context items, keyed by intent; keep it generic and theme-agnostic (no Bootstrap/Varbase assumptions in this module).
