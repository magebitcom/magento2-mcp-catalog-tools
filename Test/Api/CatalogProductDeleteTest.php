<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Test\Api;

use Magebit\Mcp\Test\Api\McpTestCase;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * `catalog.product.delete` end-to-end. The upstream `simple` fixture is the
 * deletion target.
 *
 * The tool relies on {@see \Magebit\McpCatalogTools\Model\SecureAreaScope}
 * to lift Magento's `RemoveAction\Pool` area-gate (`/mcp` runs in `frontend`,
 * so without `isSecureArea` the repository delete throws StateException).
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 * @magentoApiDataFixture Magento/Catalog/_files/product_simple.php
 */
class CatalogProductDeleteTest extends McpTestCase
{
    protected function setUp(): void
    {
        $this->allowWrites ??= true;
        parent::setUp();
    }

    public function testDeletesBySku(): void
    {
        $response = $this->toolsCall('catalog.product.delete', [
            'sku' => 'simple',
        ]);

        $this->assertJsonRpcSuccess($response);

        $body = $response['body'];
        self::assertIsArray($body);
        $content = $body['result']['content'] ?? null;
        self::assertIsArray($content);
        $payload = json_decode((string) ($content[0]['text'] ?? ''), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['deleted'] ?? false);
        self::assertSame('simple', $payload['sku'] ?? null);
        self::assertGreaterThan(0, (int) ($payload['entity_id'] ?? 0));

        // The product is actually gone — repository::get throws.
        /** @var ProductRepositoryInterface $repo */
        $repo = Bootstrap::getObjectManager()->get(ProductRepositoryInterface::class);
        $this->expectException(NoSuchEntityException::class);
        $repo->get('simple');
    }
}
