<?php

declare(strict_types=1);

namespace Drupal\ai_figma\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ai_figma\FigmaContextClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures the Varbase AI Figma.
 */
class SettingsForm extends ConfigFormBase {

  private const SETTINGS = 'ai_figma.settings';

  /**
   * The Figma context client.
   */
  protected FigmaContextClient $figmaClient;

  /**
   * The Key module repository, when available.
   *
   * @var object|null
   */
  protected $keyRepository = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->figmaClient = $container->get('ai_figma.client');
    $instance->keyRepository = $container->has('key.repository')
      ? $container->get('key.repository')
      : NULL;
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_figma_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::SETTINGS);

    // --- Figma connection (token + default file) --------------------------
    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('Figma connection'),
      '#open' => TRUE,
      '#description' => $this->t('How the module authenticates to the Figma REST API and which file it reads by default.'),
    ];
    $key_options = ['' => $this->t('- Select -')];
    $has_keys = FALSE;
    if ($this->keyRepository) {
      foreach ($this->keyRepository->getKeys() as $key) {
        $key_options[$key->id()] = $key->label();
        $has_keys = TRUE;
      }
    }
    $form['connection']['figma_token_key'] = [
      '#type' => 'select',
      '#title' => $this->t('Figma token (Key)'),
      '#options' => $key_options,
      '#default_value' => $config->get('figma_token_key') ?: '',
      '#description' => $has_keys
        ? $this->t('The Key that holds your Figma personal access token. Create a token at <a href=":figma" target="_blank">figma.com → Settings → Security</a> (read-only is enough), then store it in the <a href=":keys">Figma access token Key</a> (kept encrypted at rest via easy_encryption, the same way Drupal AI stores its provider keys).', [
          ':figma' => 'https://www.figma.com/settings',
          ':keys' => '/admin/config/system/keys',
        ])
        : $this->t('No keys defined yet. Add one at <a href=":url">Configuration → System → Keys</a> holding your Figma personal access token (the <em>Environment</em> key type can read it from a server variable).', [':url' => '/admin/config/system/keys']),
    ];
    $form['connection']['default_file_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default Figma file key'),
      '#default_value' => $config->get('default_file_key') ?: '',
      '#description' => $this->t('Used when a tool, command or the builder is called without a file key. This is the long id in a <code>figma.com/design/&lt;fileKey&gt;/…</code> URL. Leave empty to require an explicit file key or link each time.'),
    ];
    $form['connection']['figma_api_base'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Figma API base URL'),
      '#default_value' => $config->get('figma_api_base') ?: 'https://api.figma.com',
      '#description' => $this->t('Override only for a proxy or a self-hosted Figma-compatible API. Default: <code>https://api.figma.com</code>.'),
    ];

    // --- Test connection --------------------------------------------------
    $form['test'] = [
      '#type' => 'details',
      '#title' => $this->t('Test connection'),
      '#open' => TRUE,
      '#description' => $this->t('Probes the Figma API with the <em>saved</em> token and default file key. Save your changes first if you just picked a different Key.'),
    ];
    $form['test']['test_connection'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test Figma connection'),
      '#submit' => ['::testConnection'],
      '#limit_validation_errors' => [],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    // The token is sent to this base on every request, so reject anything that
    // is not a plain http(s) URL (no file://, no scheme-less host) up front.
    $base = trim((string) $form_state->getValue('figma_api_base'));
    if ($base !== '') {
      $parts = parse_url($base);
      $scheme = strtolower((string) ($parts['scheme'] ?? ''));
      if (!in_array($scheme, ['http', 'https'], TRUE) || empty($parts['host'])) {
        $form_state->setErrorByName('figma_api_base', $this->t('The Figma API base URL must be a full http(s) URL, e.g. <code>https://api.figma.com</code>.'));
      }
    }
  }

  /**
   * Submit handler: probes the Figma API with current configuration.
   */
  public function testConnection(array &$form, FormStateInterface $form_state): void {
    $client = $this->figmaClient;
    $file_key = $form_state->getValue('default_file_key') ?: $client->getDefaultFileKey();

    if ($client->getToken() === '') {
      $this->messenger()->addError($this->t('No Figma token resolved. Pick a Key holding your Figma token first, then save.'));
      return;
    }
    if ($file_key === '') {
      $this->messenger()->addError($this->t('Set a default Figma file key to test against.'));
      return;
    }
    try {
      $data = $client->fetchNodes($file_key, '');
      $summary = $client->summarizeTokens($data);
      $this->messenger()->addStatus($this->t('Connected to Figma file %key. Found %colors colors and %type typography styles.', [
        '%key' => $file_key,
        '%colors' => count($summary['colors']),
        '%type' => count($summary['typography']),
      ]));
    }
    catch (\Throwable $e) {
      $this->getLogger('ai_figma')->error('Figma connection test failed (file @key): @msg', [
        '@key' => $file_key,
        '@msg' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Figma connection failed: @msg', ['@msg' => $e->getMessage()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(self::SETTINGS)
      // The token always comes through the Key module.
      ->set('figma_token_source', 'key')
      ->set('figma_token_key', $form_state->getValue('figma_token_key'))
      ->set('figma_api_base', $form_state->getValue('figma_api_base'))
      ->set('default_file_key', $form_state->getValue('default_file_key'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
