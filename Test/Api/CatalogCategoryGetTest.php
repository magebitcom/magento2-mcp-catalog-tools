<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Test\Api;

use Magebit\Mcp\Model\JsonRpc\ErrorCode;
use Magebit\Mcp\Test\Api\McpTestCase;

/**
 * `catalog.category.get` end-to-end. The upstream `category.php` fixture
 * creates category id 333 under parent 2.
 */
class CatalogCategoryGetTest extends McpTestCase
{
    /**
     * @magentoApiDataFixture Magento/User/_files/user_with_role.php
     * @magentoApiDataFixture Magento/Catalog/_files/category.php
     */
    public function testGetByIdReturnsDefaultSlices(): void
    {
        $response = $this->toolsCall('catalog.category.get', ['id' => 333]);

        $this->assertJsonRpcSuccess($response);
        $payload = $this->decodePayload($response);

        foreach (['identity', 'state', 'tree', 'content', 'meta', 'products', 'timestamps'] as $key) {
            self::assertArrayHasKey($key, $payload, sprintf('Missing %s slice.', $key));
        }
        self::assertSame(333, $payload['identity']['entity_id'] ?? null);
        self::assertSame(2, $payload['tree']['parent_id'] ?? null);
    }

    /**
     * @magentoApiDataFixture Magento/User/_files/user_with_role.php
     * @magentoApiDataFixture Magento/Catalog/_files/category.php
     */
    public function testExcludeDropsProductsSlice(): void
    {
        $response = $this->toolsCall('catalog.category.get', [
            'id' => 333,
            'exclude' => ['products'],
        ]);

        $this->assertJsonRpcSuccess($response);
        $payload = $this->decodePayload($response);

        self::assertArrayNotHasKey('products', $payload);
        // Other default slices should still be present.
        self::assertArrayHasKey('identity', $payload);
    }

    /**
     * @magentoApiDataFixture Magento/User/_files/user_with_role.php
     */
    public function testReturnsErrorForMissingId(): void
    {
        $response = $this->toolsCall('catalog.category.get', ['id' => 99999999]);

        $this->assertJsonRpcError($response, ErrorCode::TOOL_EXECUTION_FAILED->value, 200);
    }

    /**
     * @phpstan-param array{status: int, headers: array<string, string>, body: array<string, mixed>|null} $response
     * @phpstan-return array<string, mixed>
     */
    private function decodePayload(array $response): array
    {
        $body = $response['body'];
        self::assertIsArray($body);
        $content = $body['result']['content'] ?? null;
        self::assertIsArray($content);
        $decoded = json_decode((string) ($content[0]['text'] ?? ''), true);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
