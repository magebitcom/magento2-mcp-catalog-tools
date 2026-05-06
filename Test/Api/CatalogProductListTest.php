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
 * `catalog.product.list` end-to-end:
 *  - happy path: tool returns the expected paged envelope; rows carry default
 *    field-resolver slices (identity, state, pricing, stock, timestamps)
 *  - error path: unknown filter key trips ProductSearchCriteriaBuilder and
 *    surfaces as TOOL_EXECUTION_FAILED (LocalizedException → -32011 in
 *    ToolsCallHandler)
 *
 * Uses the Magento sample-data products that already exist in the shared dev
 * DB rather than a fixture. ProductRepository::getList applies a hardcoded
 * visibility filter + a default-website join that isn't reliably satisfied by
 * upstream `_files/product_simple.php` in api-functional mode (no rollback
 * file ships, and visibility/website indexing wasn't observed end-to-end via
 * /mcp). The sample products do satisfy those filters by construction, so
 * we lean on them.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 */
class CatalogProductListTest extends McpTestCase
{
    public function testListReturnsPagedEnvelopeWithDefaultSlices(): void
    {
        $response = $this->toolsCall('catalog.product.list', [
            'page_size' => 5,
        ]);

        $this->assertJsonRpcSuccess($response);
        $payload = $this->decodePayload($response);

        self::assertArrayHasKey('items', $payload);
        self::assertArrayHasKey('total_count', $payload);
        self::assertArrayHasKey('page_size', $payload);
        self::assertArrayHasKey('current_page', $payload);
        self::assertIsArray($payload['items']);
        self::assertGreaterThan(0, $payload['total_count']);
        self::assertSame(5, $payload['page_size']);
        self::assertSame(1, $payload['current_page']);
        self::assertLessThanOrEqual(5, count($payload['items']));
        self::assertNotEmpty($payload['items'], 'Expected at least one product row from sample data.');

        $first = $payload['items'][0];
        self::assertIsArray($first);
        // List wires only the lean resolver subset (identity/state/pricing/stock/timestamps).
        foreach (['identity', 'state', 'pricing', 'stock', 'timestamps'] as $key) {
            self::assertArrayHasKey($key, $first, sprintf('Row missing %s slice.', $key));
        }
        self::assertArrayHasKey('sku', $first['identity']);
    }

    public function testListRejectsUnknownFilterKey(): void
    {
        $response = $this->toolsCall('catalog.product.list', [
            'filters' => ['nonexistent_field' => 'x'],
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
        $content = $body['result']['content'] ?? null;
        self::assertIsArray($content);
        self::assertNotEmpty($content);
        $first = $content[0];
        self::assertIsArray($first);
        self::assertSame('text', $first['type'] ?? null);
        $decoded = json_decode((string) ($first['text'] ?? ''), true);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
