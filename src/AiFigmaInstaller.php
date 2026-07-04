<?php

declare(strict_types=1);

namespace Drupal\ai_figma;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Component\Plugin\PluginManagerInterface;

/**
 * Install/runtime wiring for the basic AI Figma connection module.
 *
 * The module ships only the Figma connection (client + settings + the `figma`
 * Key). This installer provisions that Key and exposes seedContextItems() as a
 * helper the varbase_ai_figma integration calls; the orchestrator tool-wiring
 * and AI Context ownership live in varbase_ai_figma, which carries the
 * build/assistant tools.
 */
final class AiFigmaInstaller {

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly PluginManagerInterface $keyProviderManager,
  ) {}

  /**
   * Creates the `figma` Key the module reads its token from (empty value).
   */
  public function installFigmaKey(): void {
    $config = $this->configFactory->getEditable('ai_figma.settings');
    $key_id = (string) ($config->get('figma_token_key') ?: 'figma');
    try {
      $storage = $this->entityTypeManager->getStorage('key');
      if (!$storage->load($key_id)) {
        $provider = $this->keyProviderAvailable('easy_encrypted') ? 'easy_encrypted' : 'config';
        $storage->create([
          'id' => $key_id,
          'dependencies' => ['enforced' => ['module' => ['ai_figma']]],
          'label' => 'Figma access token',
          'description' => 'Figma personal access token used by AI Figma. Paste your read-only token here; it is kept in the Key module (encrypted at rest when easy_encryption is enabled).',
          'key_type' => 'authentication',
          'key_type_settings' => [],
          'key_provider' => $provider,
          'key_provider_settings' => $provider === 'config' ? ['base64_encoded' => FALSE] : [],
          'key_input' => 'text_field',
          'key_input_settings' => ['base64_encoded' => FALSE],
        ])->save();
      }
      $config->set('figma_token_source', 'key')->set('figma_token_key', $key_id)->save();
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('ai_figma')->warning('Could not auto-create the Figma Key on install: @msg', ['@msg' => $e->getMessage()]);
    }
  }

  /**
   * Whether a Key provider plugin id is available on this site.
   */
  public function keyProviderAvailable(string $plugin_id): bool {
    try {
      return $this->keyProviderManager->hasDefinition($plugin_id);
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Seeds (or updates) the editable Figma AI Context items from config.
   *
   * The rule TEXT lives in config (ai_figma.settings), never in PHP. No-op when
   * ai_context is absent. Items with empty content are skipped.
   *
   * @param bool $update
   *   When TRUE, overwrite the content + scope of an existing item; when FALSE,
   *   only create missing items (leave author edits intact).
   */
  public function seedContextItems(bool $update = FALSE): void {
    // During a recipe apply, ai_context can be installed earlier in the same
    // batch; refresh the entity-type definitions so ai_context_item is visible
    // (a stale cache would make hasDefinition() return FALSE and skip seeding).
    $this->entityTypeManager->clearCachedDefinitions();
    if (!$this->moduleHandler->moduleExists('ai_context') || !$this->entityTypeManager->hasDefinition('ai_context_item')) {
      return;
    }
    // Reset the cached config so a build_rules value written earlier in the
    // same request (e.g. varbase_ai_figma's applyProfile during a recipe
    // apply) is read fresh; otherwise the items can be skipped as "empty" and
    // never seed.
    $this->configFactory->reset('ai_figma.settings');
    $config = $this->configFactory->get('ai_figma.settings');
    $scope = (array) ($config->get('context_scope') ?: ['global' => ['global']]);
    $items = [
      'Figma Build Rules' => [
        'description' => 'Rules the Figma design-context tool and Canvas AI agents follow when building pages and components from a Figma design.',
        'purpose' => 'Read by the varbase_ai_figma:get_design_context tool as the build instruction for Figma-to-Canvas builds. Edit here to change how designs are built.',
        'content' => (string) $config->get('build_rules'),
      ],
      'Figma Accessibility Rules' => [
        'description' => 'WCAG 2.1 AA accessibility rules for everything built from a Figma design.',
        'purpose' => 'Read by the varbase_ai_figma:get_design_context tool as the accessibility instruction for Figma-to-Canvas builds.',
        'content' => (string) $config->get('accessibility_rules'),
      ],
      'Figma Component Mapping Governance' => [
        'description' => 'Governs how the AI maps a Figma design onto existing components: reuse/extend before creating, never create without approval, report design-vs-theme differences.',
        'purpose' => 'Delivered to the Canvas AI agents so component-mapping governance is applied site-wide. Optional - present only when a setup provides the text.',
        'content' => (string) $config->get('mapping_governance'),
      ],
    ];
    try {
      $storage = $this->entityTypeManager->getStorage('ai_context_item');
      foreach ($items as $label => $fields) {
        if ($fields['content'] === '') {
          continue;
        }
        $existing = $storage->loadByProperties(['label' => $label]);
        $entity = $existing ? reset($existing) : NULL;
        if ($entity && !$update) {
          continue;
        }
        if ($entity) {
          $entity->set('content', ['value' => $fields['content'], 'format' => 'plain_text']);
          $entity->set('scope', $scope);
          $entity->save();
          continue;
        }
        $storage->create([
          'type' => 'default',
          'status' => TRUE,
          'uid' => 1,
          'label' => $label,
          'description' => ['value' => $fields['description'], 'format' => 'plain_text'],
          'purpose' => ['value' => $fields['purpose'], 'format' => 'plain_text'],
          'content' => ['value' => $fields['content'], 'format' => 'plain_text'],
          'scope' => $scope,
        ])->save();
      }
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('ai_figma')->warning('Could not seed AI Context items: @msg', ['@msg' => $e->getMessage()]);
    }
  }

}
