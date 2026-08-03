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
 * `catalog.product.get` end-to-end:
 *  - by-sku happy path with default field resolvers
 *  - `fields` whitelist narrows the response shape
 *  - "both id and sku" → TOOL_EXECUTION_FAILED (EntityFinder)
 *  - unknown SKU → TOOL_EXECUTION_FAILED (EntityFinder)
 */
class CatalogProductGetTest extends McpTestCase
{
    /**
     * @magentoApiDataFixture Magento/User/_files/user_with_role.php
     * @magentoApiDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testGetBySkuReturnsDefaultSlices(): void
    {
        $response = $this->toolsCall('catalog.product.get', ['sku' => 'simple']);

        $this->assertJsonRpcSuccess($response);
        $payload = $this->decodePayload($response);

        // Default resolvers wired in di.xml: identity, state, pricing,
        // tier_prices, stock, categories, websites, media, links,
        // type_options, custom_attributes, timestamps. Spot-check the always-on
        // ones rather than over-specifying — extension modules add their own.
        foreach (['identity', 'state', 'pricing', 'stock', 'timestamps'] as $key) {
            self::assertArrayHasKey($key, $payload, sprintf('Missing %s slice.', $key));
        }
        $identity = $payload['identity'] ?? null;
        self::assertIsArray($identity);
        self::assertSame('simple', $identity['sku'] ?? null);
    }

    /**
     * @magentoApiDataFixture Magento/User/_files/user_with_role.php
     * @magentoApiDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testFieldsWhitelistNarrowsResponse(): void
    {
        $response = $this->toolsCall('catalog.product.get', [
            'sku' => 'simple',
            'fields' => ['identity', 'state'],
        ]);

        $this->assertJsonRpcSuccess($response);
        $payload = $this->decodePayload($response);

        self::assertSame(['identity', 'state'], array_keys($payload));
    }

    /**
     * @magentoApiDataFixture Magento/User/_files/user_with_role.php
     * @magentoApiDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testRejectsBothIdAndSku(): void
    {
        $response = $this->toolsCall('catalog.product.get', [
            'id' => 1,
            'sku' => 'simple',
        ]);

        // EntityFinder enforces "exactly one of id/sku" via LocalizedException,
        // which the dispatcher translates to TOOL_EXECUTION_FAILED.
        $this->assertJsonRpcError($response, ErrorCode::TOOL_EXECUTION_FAILED->value, 200);
    }

    /**
     * @magentoApiDataFixture Magento/User/_files/user_with_role.php
     */
    public function testReturnsErrorForMissingSku(): void
    {
        $response = $this->toolsCall('catalog.product.get', [
            'sku' => 'this-sku-definitely-does-not-exist-9999',
        ]);

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
        $result = $body['result'] ?? null;
        self::assertIsArray($result);
        $content = $result['content'] ?? null;
        self::assertIsArray($content);
        $decoded = json_decode((string) ($content[0]['text'] ?? ''), true);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
