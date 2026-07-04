<?php

declare(strict_types=1);

namespace Drupal\ai_figma;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Fetches and summarises Figma design context for AI Agent tools.
 *
 * Talks to the Figma REST API (https://api.figma.com). This is the same data
 * surfaced by the Figma MCP server's get_design_context, but reachable from
 * server-side PHP without an interactive OAuth flow. The access token is
 * resolved from the Key module (kept encrypted at rest via easy_encryption).
 */
class FigmaContextClient {

  /**
   * Constructs the client.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected $keyRepository = NULL,
  ) {}

  /**
   * Resolves the Figma access token from the configured Key.
   *
   * The token is stored only in the Key module - the same way Drupal AI keeps
   * its provider keys - so it benefits from encryption at rest (the
   * easy_encryption provider). There is no environment-variable path.
   *
   * @return string
   *   The token, or empty string if none configured.
   */
  public function getToken(): string {
    if (!$this->keyRepository) {
      return '';
    }
    $key_id = (string) $this->configFactory->get('ai_figma.settings')->get('figma_token_key');
    if ($key_id === '') {
      return '';
    }
    $key = $this->keyRepository->getKey($key_id);
    return $key ? (string) $key->getKeyValue() : '';
  }

  /**
   * Parses a Figma design/file URL into a file key and node id.
   *
   * Accepts forms like:
   *   https://www.figma.com/design/<fileKey>/<name>?node-id=<node>&m=dev
   *   https://www.figma.com/file/<fileKey>/<name>?node-id=<node>
   *
   * @param string $url
   *   A figma.com URL (or a bare file key).
   *
   * @return array
   *   ['file_key' => string, 'node_id' => string]. node_id uses a colon.
   */
  public static function parseFigmaUrl(string $url): array {
    $out = ['file_key' => '', 'node_id' => ''];
    $url = trim($url);
    if ($url === '') {
      return $out;
    }
    // AI prompts commonly "@"-prefix the link, e.g.
    // "Implement this design from Figma. @https://www.figma.com/design/…".
    // Drop a "@" that prefixes the URL or a bare file key so it still parses.
    $url = str_replace('@http', 'http', $url);
    $url = ltrim($url, '@');
    // figma.com/design|file|board|make/<key>/...  - file keys can contain
    // letters, digits, "_" and "-". When the URL points at a branch
    // (/design/<key>/branch/<branchKey>/...), the branch key is the one the
    // REST API expects, so it wins.
    if (preg_match('#figma\.com/(?:design|file|board|make)/([A-Za-z0-9_-]+)(?:/branch/([A-Za-z0-9_-]+))?#', $url, $m)) {
      $out['file_key'] = !empty($m[2]) ? $m[2] : $m[1];
    }
    elseif (preg_match('#^[A-Za-z0-9_-]{10,}$#', $url)) {
      // A bare file key was passed.
      $out['file_key'] = $url;
    }
    if (preg_match('#[?&]node-id=([^&]+)#', $url, $m)) {
      $out['node_id'] = str_replace('-', ':', urldecode($m[1]));
    }
    return $out;
  }

  /**
   * Returns the configured default Figma file key.
   */
  public function getDefaultFileKey(): string {
    return (string) $this->configFactory
      ->get('ai_figma.settings')
      ->get('default_file_key');
  }

  /**
   * Resolves a safe Figma API base URL.
   *
   * The base is admin-configurable, and every request sends the secret token in
   * an X-Figma-Token header - so a hostile or mistyped base could exfiltrate
   * the token or trigger SSRF. Defence in depth: only an http(s) URL with a
   * host is accepted; anything else (file://, gopher://, no host, ...) is
   * rejected and the request falls back to the public Figma API. Rejections
   * are logged.
   *
   * @return string
   *   A validated base URL with no trailing slash.
   */
  protected function apiBase(): string {
    $default = 'https://api.figma.com';
    $configured = trim((string) ($this->configFactory
      ->get('ai_figma.settings')
      ->get('figma_api_base') ?: $default));
    if ($configured === '') {
      return $default;
    }
    $parts = parse_url($configured);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], TRUE) || empty($parts['host'])) {
      $this->loggerFactory->get('ai_figma')->warning(
        'Ignoring invalid figma_api_base @base (must be an http(s) URL); using @default.',
        ['@base' => $configured, '@default' => $default],
      );
      return $default;
    }
    return rtrim($configured, '/');
  }

  /**
   * Fetches one or more nodes from a Figma file.
   *
   * @param string $file_key
   *   The Figma file key.
   * @param string $node_id
   *   A node id (e.g. "1283:979" or "1283-979"). Empty fetches file top level.
   *
   * @return array
   *   Decoded JSON from the Figma API.
   *
   * @throws \RuntimeException
   *   When no token is configured or the request fails.
   */
  public function fetchNodes(string $file_key, string $node_id = ''): array {
    $token = $this->getToken();
    if ($token === '') {
      throw new \RuntimeException('No Figma access token configured. Add your Figma token to the Figma Key at /admin/config/system/keys, then select it at /admin/config/ai/figma.');
    }
    $base = $this->apiBase();

    // Figma node ids in URLs use a colon; URLs sometimes carry a dash form.
    $normalized_node = str_replace('-', ':', trim($node_id));

    // A real node id looks like "12:34" (component instances: "I12:34;56:78").
    // Callers (e.g. an AI agent) sometimes pass a canvas/frame *name* such as
    // "Designs" or "Homepage" instead - the API would answer 400. Resolve the
    // name to a node id by scanning the file's top levels; fall back to the
    // whole file when nothing matches.
    if ($normalized_node !== '' && !preg_match('/^I?[0-9]+:[0-9]+(;[0-9]+:[0-9]+)*$/', $normalized_node)) {
      $normalized_node = $this->resolveNodeIdByName($file_key, trim($node_id));
    }

    if ($normalized_node !== '') {
      $url = $base . '/v1/files/' . rawurlencode($file_key) . '/nodes?ids=' . rawurlencode($normalized_node);
    }
    else {
      $url = $base . '/v1/files/' . rawurlencode($file_key) . '?depth=2';
    }

    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => [
          'X-Figma-Token' => $token,
          'Accept' => 'application/json',
        ],
        'timeout' => 30,
      ]);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('ai_figma')
        ->error('Figma API request failed: @msg', ['@msg' => $e->getMessage()]);
      throw new \RuntimeException('Figma API request failed: ' . $e->getMessage());
    }

    $body = (string) $response->getBody();
    $data = json_decode($body, TRUE);
    if (!is_array($data)) {
      throw new \RuntimeException('Figma API returned an unparseable response.');
    }
    return $data;
  }

  /**
   * Resolves a canvas/frame name to its node id.
   *
   * Scans the file's document children (canvases) and their children (top
   * frames), matching the name case-insensitively.
   *
   * @param string $file_key
   *   The Figma file key.
   * @param string $name
   *   The node name to look for, e.g. "Designs" or "Homepage".
   *
   * @return string
   *   The node id (colon form), or '' when no node carries that name.
   */
  public function resolveNodeIdByName(string $file_key, string $name): string {
    $needle = mb_strtolower(trim($name));
    if ($needle === '') {
      return '';
    }
    $data = $this->fetchNodes($file_key);
    $document = $data['document'] ?? [];
    foreach (($document['children'] ?? []) as $canvas) {
      if (mb_strtolower(trim((string) ($canvas['name'] ?? ''))) === $needle && !empty($canvas['id'])) {
        return (string) $canvas['id'];
      }
      foreach (($canvas['children'] ?? []) as $frame) {
        if (mb_strtolower(trim((string) ($frame['name'] ?? ''))) === $needle && !empty($frame['id'])) {
          return (string) $frame['id'];
        }
      }
    }
    $this->loggerFactory->get('ai_figma')
      ->warning('No Figma node named "@name" found in file @key; falling back to the whole file.', [
        '@name' => $name,
        '@key' => $file_key,
      ]);
    return '';
  }

  /**
   * Walks a node tree and extracts design tokens into a flat summary.
   *
   * @param array $data
   *   Response from fetchNodes().
   *
   * @return array
   *   Structured summary: colors, typography, node outline.
   */
  public function summarizeTokens(array $data): array {
    $colors = [];
    $typography = [];
    $outline = [];
    $texts = [];
    $root_name = '';

    // Collect document roots from either /nodes or /files response shapes.
    $roots = [];
    if (isset($data['nodes']) && is_array($data['nodes'])) {
      foreach ($data['nodes'] as $entry) {
        if (isset($entry['document'])) {
          $roots[] = $entry['document'];
        }
      }
    }
    elseif (isset($data['document'])) {
      $roots[] = $data['document'];
    }

    $walk = function (array $node, int $depth) use (&$walk, &$colors, &$typography, &$outline, &$texts): void {
      $name = (string) ($node['name'] ?? '');
      $type = (string) ($node['type'] ?? '');
      if ($name !== '' && $depth <= 4) {
        $outline[] = str_repeat('  ', $depth) . $type . ': ' . $name;
      }

      // Solid fills → colors.
      foreach (($node['fills'] ?? []) as $fill) {
        if (($fill['type'] ?? '') === 'SOLID' && isset($fill['color'])) {
          $hex = self::rgbaToHex($fill['color'], $fill['opacity'] ?? 1.0);
          $colors[$hex] = $colors[$hex] ?? ($name ?: $type);
        }
      }

      // Text node → typography + the real text content.
      if ($type === 'TEXT') {
        if (isset($node['style'])) {
          $s = $node['style'];
          $key = ($s['fontFamily'] ?? '?') . ' ' . ($s['fontWeight'] ?? '') . ' ' . ($s['fontSize'] ?? '') . 'px';
          $typography[$key] = $typography[$key] ?? ($name ?: 'text');
        }
        $chars = trim((string) ($node['characters'] ?? ''));
        if ($chars !== '') {
          $size = (float) ($node['style']['fontSize'] ?? 0);
          $texts[] = [
            'text' => $chars,
            'name' => $name,
            'size' => $size,
          ];
        }
      }

      foreach (($node['children'] ?? []) as $child) {
        if (is_array($child)) {
          $walk($child, $depth + 1);
        }
      }
    };

    foreach ($roots as $root) {
      if (is_array($root)) {
        $root_name = $root_name !== '' ? $root_name : (string) ($root['name'] ?? '');
        $walk($root, 0);
      }
    }

    return [
      'colors' => $colors,
      'typography' => $typography,
      'outline' => array_slice($outline, 0, 200),
      'texts' => $texts,
      'root_name' => $root_name,
    ];
  }

  /**
   * Fetches a whole Figma file document (all nodes).
   *
   * @param string $file_key
   *   The Figma file key.
   *
   * @return array
   *   Decoded JSON from /v1/files/:key.
   */
  public function fetchFile(string $file_key): array {
    $token = $this->getToken();
    if ($token === '') {
      throw new \RuntimeException('No Figma access token configured.');
    }
    $url = $this->apiBase() . '/v1/files/' . rawurlencode($file_key);

    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => ['X-Figma-Token' => $token, 'Accept' => 'application/json'],
        'timeout' => 60,
      ]);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('ai_figma')
        ->error('Figma file request failed: @msg', ['@msg' => $e->getMessage()]);
      throw new \RuntimeException('Figma file request failed: ' . $e->getMessage());
    }

    $data = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($data)) {
      throw new \RuntimeException('Figma API returned an unparseable response.');
    }
    return $data;
  }

  /**
   * Fetches rendered image URLs for nodes via the Figma Images API.
   *
   * @param string $file_key
   *   The Figma file key.
   * @param array $node_ids
   *   Node ids (colon form). Names are NOT resolved here.
   * @param string $format
   *   One of png, jpg, svg or pdf.
   * @param float $scale
   *   Render scale (0.01–4).
   *
   * @return array
   *   Map of node id => temporary S3 image URL ('' when render failed).
   */
  public function fetchImages(string $file_key, array $node_ids, string $format = 'png', float $scale = 2.0): array {
    $token = $this->getToken();
    if ($token === '') {
      throw new \RuntimeException('No Figma access token configured.');
    }
    $ids = implode(',', array_map(static fn(string $id): string => str_replace('-', ':', trim($id)), $node_ids));
    $url = $this->apiBase() . '/v1/images/' . rawurlencode($file_key)
      . '?ids=' . rawurlencode($ids) . '&format=' . rawurlencode($format) . '&scale=' . $scale;

    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => ['X-Figma-Token' => $token, 'Accept' => 'application/json'],
        'timeout' => 60,
      ]);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('ai_figma')
        ->error('Figma images request failed: @msg', ['@msg' => $e->getMessage()]);
      throw new \RuntimeException('Figma images request failed: ' . $e->getMessage());
    }

    $data = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($data) || !empty($data['err'])) {
      throw new \RuntimeException('Figma images API error: ' . ($data['err'] ?? 'unparseable response'));
    }
    return array_map(static fn($v): string => (string) $v, $data['images'] ?? []);
  }

  /**
   * Fetches the download URLs of the design's foundation images (image fills).
   *
   * These are the real uploaded images placed in the design (logos, photos,
   * illustrations), keyed by their Figma imageRef. Use this with
   * collectImageRefs() to download only the image assets a node actually uses.
   *
   * @param string $file_key
   *   The Figma file key.
   *
   * @return array
   *   Map of imageRef => temporary download URL (S3). Empty when none.
   */
  public function fetchImageFills(string $file_key): array {
    $token = $this->getToken();
    if ($token === '') {
      throw new \RuntimeException('No Figma access token configured.');
    }
    $url = $this->apiBase() . '/v1/files/' . rawurlencode($file_key) . '/images';
    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => ['X-Figma-Token' => $token, 'Accept' => 'application/json'],
        'timeout' => 60,
      ]);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('ai_figma')
        ->error('Figma image-fills request failed: @msg', ['@msg' => $e->getMessage()]);
      throw new \RuntimeException('Figma image-fills request failed: ' . $e->getMessage());
    }
    $data = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($data) || !empty($data['error'])) {
      throw new \RuntimeException('Figma image-fills API error.');
    }
    return array_map(static fn($v): string => (string) $v, $data['meta']['images'] ?? []);
  }

  /**
   * Collects the imageRefs of every IMAGE fill in a node subtree.
   *
   * Walks the node(s) and gathers the Figma imageRef of each image fill, with
   * the name of the nearest named node as a human label, so foundation images
   * can be downloaded and turned into media.
   *
   * @param array $nodes
   *   A Figma node (or the `nodes` map / document) to walk.
   *
   * @return array
   *   List of ['ref' => string, 'name' => string], de-duplicated by ref.
   */
  public function collectImageRefs(array $nodes): array {
    $found = [];
    // Only treat image fills on leaf shape nodes as foundation assets. An
    // image fill on a FRAME / GROUP / COMPONENT / INSTANCE / SECTION is almost
    // always a flattened screenshot of a design chunk, not a real asset, so we
    // skip those - the caller gets photos/logos/illustrations, not component
    // screenshots.
    $leaf = ['RECTANGLE', 'ELLIPSE', 'VECTOR', 'STAR', 'REGULAR_POLYGON', 'LINE', 'BOOLEAN_OPERATION'];
    // Generic auto-names that are not human readable; ignore them when naming.
    $generic = '/^(rectangle|ellipse|vector|line|image|images?|frame|group|star|polygon|union|subtract|intersect|exclude|mask|fill|layer|shape|bg|background)[\s_-]*\d*$/i';
    $clean = static function (string $n): string {
      $n = trim(preg_replace('/[\s_-]+/', ' ', $n));
      return $n;
    };
    $walk = function (array $node, string $inherited) use (&$walk, &$found, $leaf, $generic, $clean): void {
      $raw = trim((string) ($node['name'] ?? ''));
      // A meaningful node name becomes the inherited label for descendants.
      $meaningful = ($raw !== '' && !preg_match($generic, $raw)) ? $clean($raw) : '';
      $inherited = $meaningful !== '' ? $meaningful : $inherited;
      // Skip node/screen captures: a foundation asset is a photo/logo placed
      // in the design, not a screenshot of a node or of the design itself.
      $isCapture = (bool) preg_match('/screen[\s_-]*shot|\bscreen[\s_-]*grab|\bcapture\b/i', $raw . ' ' . $inherited);
      if (!$isCapture && in_array((string) ($node['type'] ?? ''), $leaf, TRUE)) {
        foreach (($node['fills'] ?? []) as $fill) {
          if (($fill['type'] ?? '') === 'IMAGE' && !empty($fill['imageRef'])) {
            $ref = (string) $fill['imageRef'];
            // Prefer this shape's own readable name, else the nearest
            // meaningful ancestor, else a plain fallback.
            $label = $meaningful !== '' ? $meaningful : ($inherited !== '' ? $inherited : 'Design image');
            $found[$ref] = $found[$ref] ?? ['ref' => $ref, 'name' => $label];
          }
        }
      }
      foreach (($node['children'] ?? []) as $child) {
        if (is_array($child)) {
          $walk($child, $inherited);
        }
      }
    };
    // Accept a raw document, a single node, or a /v1/files/:key/nodes response.
    if (isset($nodes['nodes']) && is_array($nodes['nodes'])) {
      foreach ($nodes['nodes'] as $entry) {
        if (isset($entry['document']) && is_array($entry['document'])) {
          $walk($entry['document'], '');
        }
      }
    }
    elseif (isset($nodes['document']) && is_array($nodes['document'])) {
      $walk($nodes['document'], '');
    }
    else {
      $walk($nodes, '');
    }
    return array_values($found);
  }

  /**
   * Lists the design's top-level pages: canvases and their direct frames.
   *
   * @param string $file_key
   *   The Figma file key.
   *
   * @return array
   *   List of ['node_id' => string, 'name' => string, 'canvas' => string,
   *   'type' => string] for every direct frame child of every canvas.
   */
  public function listDesignPages(string $file_key): array {
    $data = $this->fetchNodes($file_key);
    $pages = [];
    foreach (($data['document']['children'] ?? []) as $canvas) {
      $canvas_name = trim((string) ($canvas['name'] ?? ''));
      foreach (($canvas['children'] ?? []) as $frame) {
        if (($frame['type'] ?? '') !== 'FRAME') {
          continue;
        }
        $pages[] = [
          'node_id' => (string) ($frame['id'] ?? ''),
          'name' => trim((string) ($frame['name'] ?? '')),
          'canvas' => $canvas_name,
          'type' => (string) ($frame['type'] ?? ''),
        ];
      }
    }
    return $pages;
  }

  /**
   * Walks a file/nodes response and indexes every node.
   *
   * @param array $data
   *   Response from fetchFile() or fetchNodes().
   *
   * @return array
   *   List of rows, each: node_id, title, type, token, variable.
   *   - token: a hex color for solid fills, a font spec for text, else ''.
   *   - variable: a suggested machine variable name (deduplicated).
   */
  public function indexNodes(array $data): array {
    $roots = [];
    if (isset($data['document'])) {
      $roots[] = $data['document'];
    }
    if (isset($data['nodes']) && is_array($data['nodes'])) {
      foreach ($data['nodes'] as $entry) {
        if (isset($entry['document'])) {
          $roots[] = $entry['document'];
        }
      }
    }

    $rows = [];
    $used = [];

    $walk = function (array $node) use (&$walk, &$rows, &$used): void {
      $id = (string) ($node['id'] ?? '');
      $name = (string) ($node['name'] ?? '');
      $type = (string) ($node['type'] ?? '');

      $token = '';
      foreach (($node['fills'] ?? []) as $fill) {
        if (($fill['type'] ?? '') === 'SOLID' && isset($fill['color'])) {
          $token = self::rgbaToHex($fill['color'], $fill['opacity'] ?? 1.0);
          break;
        }
      }
      if ($token === '' && $type === 'TEXT' && isset($node['style'])) {
        $s = $node['style'];
        $token = trim(($s['fontFamily'] ?? '') . ' ' . ($s['fontWeight'] ?? '') . ' ' . (($s['fontSize'] ?? '') !== '' ? $s['fontSize'] . 'px' : ''));
      }

      if ($id !== '') {
        $rows[] = [
          'node_id' => $id,
          'title' => $name !== '' ? $name : '(unnamed)',
          'type' => $type,
          'token' => $token,
          'variable' => self::suggestVariableName($name, $type, $used),
        ];
      }

      foreach (($node['children'] ?? []) as $child) {
        if (is_array($child)) {
          $walk($child);
        }
      }
    };

    foreach ($roots as $root) {
      if (is_array($root)) {
        $walk($root);
      }
    }
    return $rows;
  }

  /**
   * Suggests a deduplicated machine variable name from a layer name.
   */
  protected static function suggestVariableName(string $name, string $type, array &$used): string {
    $slug = strtolower($name !== '' ? $name : $type);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim((string) $slug, '-');
    if ($slug === '') {
      $slug = 'node';
    }
    $base = '--vb-' . $slug;
    $candidate = $base;
    $i = 2;
    while (isset($used[$candidate])) {
      $candidate = $base . '-' . $i++;
    }
    $used[$candidate] = TRUE;
    return $candidate;
  }

  /**
   * Converts a Figma rgba color (0..1 floats) to a hex string.
   */
  protected static function rgbaToHex(array $color, float $opacity = 1.0): string {
    $r = (int) round(($color['r'] ?? 0) * 255);
    $g = (int) round(($color['g'] ?? 0) * 255);
    $b = (int) round(($color['b'] ?? 0) * 255);
    $hex = sprintf('#%02x%02x%02x', $r, $g, $b);
    if ($opacity < 1.0) {
      $hex .= sprintf(' (%.0f%%)', $opacity * 100);
    }
    return $hex;
  }

}
