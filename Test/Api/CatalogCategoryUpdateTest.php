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
 * `catalog.category.update` end-to-end. Patches `name` and toggles
 * `is_active` on the upstream fixture (id 333).
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 * @magentoApiDataFixture Magento/Catalog/_files/category.php
 */
class CatalogCategoryUpdateTest extends McpTestCase
{
    protected function setUp(): void
    {
        $this->allowWrites ??= true;
        parent::setUp();
    }

    public function testUpdatesNameAndIsActive(): void
    {
        $newName = 'Renamed via MCP ' . uniqid('', true);

        $response = $this->toolsCall('catalog.category.update', [
            'id' => 333,
            'name' => $newName,
            'is_active' => false,
        ]);

        $this->assertJsonRpcSuccess($response);

        $body = $response['body'];
        self::assertIsArray($body);
        $content = $body['result']['content'] ?? null;
        self::assertIsArray($content);
        $payload = json_decode((string) ($content[0]['text'] ?? ''), true);
        self::assertIsArray($payload);
        self::assertSame(333, $payload['entity_id'] ?? null);
        self::assertSame($newName, $payload['name'] ?? null);
        self::assertFalse($payload['is_active'] ?? null);
    }
}
