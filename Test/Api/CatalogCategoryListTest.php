<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Test\Api;

use Magebit\Mcp\Test\Api\McpTestCase;

/**
 * `catalog.category.list` end-to-end. The upstream `category.php` fixture
 * creates category id 333 under the default root (parent_id 2).
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 * @magentoApiDataFixture Magento/Catalog/_files/category.php
 */
class CatalogCategoryListTest extends McpTestCase
{
    public function testListsCategoriesUnderDefaultRoot(): void
    {
        $response = $this->toolsCall('catalog.category.list', [
            'filters' => ['parent_id' => 2],
        ]);

        $this->assertJsonRpcSuccess($response);
        $payload = $this->decodePayload($response);

        self::assertArrayHasKey('items', $payload);
        self::assertArrayHasKey('total_count', $payload);
        self::assertGreaterThanOrEqual(1, $payload['total_count']);

        $items = $payload['items'] ?? null;
        self::assertIsArray($items);
        $parentIds = array_column(array_column($items, 'tree'), 'parent_id');
        self::assertNotEmpty($parentIds);
        foreach ($parentIds as $parentId) {
            self::assertSame(2, $parentId, 'parent_id filter should not leak into other branches.');
        }
    }

    public function testFiltersByLevel(): void
    {
        $response = $this->toolsCall('catalog.category.list', [
            'filters' => ['level_to' => 1],
        ]);

        $this->assertJsonRpcSuccess($response);
        $payload = $this->decodePayload($response);

        self::assertIsArray($payload['items'] ?? null);
        foreach ($payload['items'] as $row) {
            $level = $row['tree']['level'] ?? null;
            self::assertIsInt($level);
            self::assertLessThanOrEqual(1, $level);
        }
    }

    /**
     * @phpstan-param array{status: int, headers: array<string, string>, body: array<string, mixed>|null} $response
     * @phpstan-return array<string, mixed>
     */
    private function decodePayload(array $response): array
    {
        $body = $response['body'];
        self::assertIsArray($body);
        $result = $body['result'] ?? null;
        self::assertIsArray($result);
        $content = $result['content'] ?? null;
        self::assertIsArray($content);
        $decoded = json_decode((string) ($content[0]['text'] ?? ''), true);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
