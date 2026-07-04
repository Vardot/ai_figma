<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_figma\Unit;

use Drupal\ai_figma\FigmaContextClient;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\ai_figma\FigmaContextClient
 *
 * @group ai_figma
 */
class FigmaUrlParseTest extends UnitTestCase {

  /**
   * Data provider for ::testParseFigmaUrl().
   *
   * @return array<string, array{0:string, 1:string, 2:string}>
   *   Each case: url, expected file_key, expected node_id (colon form).
   */
  public static function urlProvider(): array {
    return [
      // A full /design/<key> URL with a dash-form node-id and extra query
      // params: the file key is parsed and the node id is normalised to the
      // colon form the REST API expects.
      'design url with node-id' => [
        'https://www.figma.com/design/RJkuWNHla1P8VYHa5z6dnL/VB?node-id=1-2&m=dev',
        'RJkuWNHla1P8VYHa5z6dnL',
        '1:2',
      ],
      // A legacy /file/<key> URL with no node id.
      'file url no node' => [
        'https://www.figma.com/file/abcDEF123456/Some-Name',
        'abcDEF123456',
        '',
      ],
      // A branch URL: the branch key (second capture) wins over the main key.
      // The pattern requires "/branch/<key>" to immediately follow the file
      // key, which is the canonical Figma branch-link shape.
      'branch url branch key wins' => [
        'https://www.figma.com/design/MAINKEY123456/branch/BRANCHKEY789?node-id=10-20',
        'BRANCHKEY789',
        '10:20',
      ],
      // An "@"-prefixed URL (AI prompts often paste links this way).
      'at-prefixed url' => [
        '@https://www.figma.com/design/PrefixedKey999/Name?node-id=33-44',
        'PrefixedKey999',
        '33:44',
      ],
      // A bare file key (>= 10 chars) with no figma.com host.
      'bare file key' => [
        'BareFileKey1234',
        'BareFileKey1234',
        '',
      ],
      // A non-Figma string yields an empty result (file key too short / no
      // host). "hello" is < 10 chars so the bare-key branch does not match.
      'non-figma string' => [
        'hello world this is not figma',
        '',
        '',
      ],
    ];
  }

  /**
   * @covers ::parseFigmaUrl
   *
   * @dataProvider urlProvider
   */
  public function testParseFigmaUrl(string $url, string $expected_file_key, string $expected_node_id): void {
    $result = FigmaContextClient::parseFigmaUrl($url);

    $this->assertIsArray($result);
    $this->assertArrayHasKey('file_key', $result);
    $this->assertArrayHasKey('node_id', $result);
    $this->assertSame($expected_file_key, $result['file_key']);
    $this->assertSame($expected_node_id, $result['node_id']);
  }

  /**
   * An empty string returns the empty result shape without error.
   *
   * @covers ::parseFigmaUrl
   */
  public function testParseEmptyString(): void {
    $this->assertSame(
      ['file_key' => '', 'node_id' => ''],
      FigmaContextClient::parseFigmaUrl(''),
    );
  }

}
