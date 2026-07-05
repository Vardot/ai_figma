# Installation

## Install the module

```bash
composer require drupal/ai_figma
ddev drush en ai_figma -y
```

This also installs the dependencies `ai`, `ai_agents`, `key` and
`easy_encryption`, and creates an empty **Figma access token** Key for you.

## Connect your Figma token

The token is stored in the [Key](https://www.drupal.org/project/key) module —
the same way Drupal AI stores its provider keys — so it is encrypted at rest via
`easy_encryption`.

1. Create a read-only token at
   [figma.com → Settings → Security](https://www.figma.com/settings).
2. Paste it into the *Figma access token* Key at
   `/admin/config/system/keys`.
3. Clear the cache:

    ```bash
    ddev drush cr
    ```

4. Visit **Configuration → AI → AI Figma** (`/admin/config/ai/figma`), confirm
   the Key is selected, optionally set a default file key, and click
   **Test Figma connection**.

!!! tip "Check the status report"
    The status report (`/admin/reports/status`) shows an **AI Figma** line —
    *Figma token configured* once a token is resolved, or a warning with a link
    to the settings page while it is not.

## Uninstall

```bash
ddev drush pmu ai_figma -y
```

The Figma access token Key is removed with the module (it is an enforced
dependency), so the stored token does not linger.
