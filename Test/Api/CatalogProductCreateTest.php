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
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * `catalog.product.create` end-to-end. Covers:
 *  - happy path: valid args produce a saved product, response carries entity_id
 *  - write-gate smoke test: token without allow_writes is rejected with -32012
 *
 * The site-wide `magebit_mcp/general/allow_writes` flag defaults to 1 via
 * etc/config.xml so flipping the per-token flag (via $allowWrites) is enough.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 */
class CatalogProductCreateTest extends McpTestCase
{
    /** @var string|null SKU minted by the test, deleted in tearDown. */
    private ?string $createdSku = null;

    protected function setUp(): void
    {
        // `??=` so per-test overrides (e.g. setting allowWrites = false in
        // testRejectedWhenTokenDisallowsWrites) survive a manual setUp() reissue.
        $this->allowWrites ??= true;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->createdSku !== null) {
            $om = Bootstrap::getObjectManager();
            /** @var Registry $registry */
            $registry = $om->get(Registry::class);
            $previous = $registry->registry('isSecureArea');
            // Magento's RemoveAction\Pool blocks deletion of catalog/Product
            // outside admin area; isSecureArea is the documented bypass used
            // by upstream fixture rollbacks.
            $registry->unregister('isSecureArea');
            $registry->register('isSecureArea', true);
            try {
                /** @var ProductRepositoryInterface $repo */
                $repo = $om->get(ProductRepositoryInterface::class);
                $repo->deleteById($this->createdSku);
            } catch (NoSuchEntityException) {
                // Already gone — nothing to clean up.
            } finally {
                $registry->unregister('isSecureArea');
                if ($previous !== null) {
                    $registry->register('isSecureArea', $previous);
                }
            }
            $this->createdSku = null;
        }
        parent::tearDown();
    }

    public function testCreatesSimpleProduct(): void
    {
        $unique = uniqid('', true);
        $sku = 'mcp-create-' . $unique;
        $this->createdSku = $sku;

        $response = $this->toolsCall('catalog.product.create', [
            'sku' => $sku,
            'name' => 'MCP Test Product',
            // Must be unique per run — Magento auto-generates url_key from
            // name otherwise, which collides with leftover url_rewrite rows
            // from prior runs whose tearDown deletes never made it through
            // the area-gate.
            'url_key' => 'mcp-create-' . $unique,
            'price' => 9.99,
            'attribute_set_id' => 4,
            'type_id' => 'simple',
            'status' => 1,
            'visibility' => 4,
            'weight' => 0.5,
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
        self::assertGreaterThan(0, $payload['entity_id'] ?? 0);
        self::assertSame($sku, $payload['sku'] ?? null);
        self::assertSame('simple', $payload['type_id'] ?? null);
    }

    public function testRejectedWhenTokenDisallowsWrites(): void
    {
        // Re-issue the bound token without write permission for this test only.
        $this->allowWrites = false;
        $this->tearDown();
        $this->setUp();

        $response = $this->toolsCall('catalog.product.create', [
            'sku' => 'mcp-write-gate-' . uniqid('', true),
            'name' => 'Should not be created',
            'price' => 1.00,
            'attribute_set_id' => 4,
            'type_id' => 'simple',
            'status' => 1,
            'visibility' => 4,
            'weight' => 0.1,
        ]);

        $this->assertJsonRpcError($response, ErrorCode::WRITE_NOT_ALLOWED->value, 200);
    }
}
