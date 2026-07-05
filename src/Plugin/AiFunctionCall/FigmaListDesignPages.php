<?php

declare(strict_types=1);

namespace Drupal\ai_figma\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\ai_figma\FigmaContextClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * AI Agent tool: list the page frames of a Figma design.
 *
 * The first step of a full-site build: returns every top-level frame with its
 * node id so the agent can plan one Canvas page per design frame.
 */
#[FunctionCall(
  id: 'ai_figma:list_design_pages',
  function_name: 'ai_figma_list_design_pages',
  name: 'Figma: List Design Pages',
  description: 'Lists the page frames of a Figma design file - every top-level frame with its node id and the canvas it belongs to. Use this FIRST when asked to build a full site from a Figma file: plan one Canvas page per design frame (skip style-guide/foundation canvases), then read the design context for each frame node to build it.',
  group: 'information_tools',
  module_dependencies: ['ai_figma'],
  context_definitions: [
    'figma_url' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Figma URL'),
      description: new TranslatableMarkup('A Figma link; the file key is extracted from it. Leave empty to use the configured default file.'),
      required: FALSE,
    ),
    'file_key' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Figma file key'),
      description: new TranslatableMarkup('The Figma file key. Leave empty to extract from figma_url or use the configured default.'),
      required: FALSE,
    ),
  ],
)]
class FigmaListDesignPages extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The Figma context client.
   */
  protected FigmaContextClient $figmaClient;

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The collected readable output.
   */
  protected string $result = '';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
      $container->get('plugin.manager.ai_data_type_converter'),
    );
    $instance->figmaClient = $container->get('ai_figma.client');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    if (
      !$this->currentUser->hasPermission('use ai figma design context')
      && !$this->currentUser->hasPermission('administer ai agents')
      && !$this->currentUser->hasPermission('use Drupal Canvas AI')
    ) {
      throw new \Exception('You do not have permission to read Figma design context.');
    }

    $file_key = trim((string) $this->getContextValue('file_key'));
    $url = trim((string) $this->getContextValue('figma_url'));
    if ($file_key === '' && $url !== '') {
      $file_key = FigmaContextClient::parseFigmaUrl($url)['file_key'];
    }
    if ($file_key === '') {
      $file_key = $this->figmaClient->getDefaultFileKey();
    }
    if ($file_key === '') {
      $this->result = 'No Figma file key given or configured.';
      return;
    }

    $pages = $this->figmaClient->listDesignPages($file_key);
    $this->result = Yaml::dump([
      'figma_file_key' => $file_key,
      'design_pages' => $pages,
      'hint' => 'Frames on a "Designs"-like canvas are the site pages. Build each one from its node_id: read the design context for that frame, create the section components, and place them on a Canvas page. Skip foundation/icon/style canvases.',
    ], 4, 2);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
