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
 * `catalog.product.update` end-to-end. Patches `name` on the upstream
 * `simple` fixture and verifies the response reflects the change.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 * @magentoApiDataFixture Magento/Catalog/_files/product_simple.php
 */
class CatalogProductUpdateTest extends McpTestCase
{
    protected function setUp(): void
    {
        $this->allowWrites ??= true;
        parent::setUp();
    }

    public function testUpdatesNameBySku(): void
    {
        $newName = 'Renamed via MCP ' . uniqid('', true);

        $response = $this->toolsCall('catalog.product.update', [
            'sku' => 'simple',
            'name' => $newName,
        ]);

        $this->assertJsonRpcSuccess($response);

        $body = $response['body'];
        self::assertIsArray($body);
        $result = $body['result'] ?? null;
        self::assertIsArray($result);
        $content = $result['content'] ?? null;
        self::assertIsArray($content);
        $payload = json_decode((string) ($content[0]['text'] ?? ''), true);
        self::assertIsArray($payload);
        self::assertSame('simple', $payload['sku'] ?? null);
        self::assertSame($newName, $payload['name'] ?? null);
        self::assertGreaterThan(0, $payload['entity_id'] ?? 0);
    }
}
