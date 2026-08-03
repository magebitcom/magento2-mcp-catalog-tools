<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Test\Api;

use Magebit\Mcp\Test\Api\McpTestCase;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * `catalog.category.delete` end-to-end target.
 *
 * The tool relies on {@see \Magebit\McpCatalogTools\Model\SecureAreaScope}
 * to lift Magento's `RemoveAction\Pool` area-gate — without it the repository
 * delete fails outside `adminhtml`.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 * @magentoApiDataFixture Magento/Catalog/_files/category.php
 */
class CatalogCategoryDeleteTest extends McpTestCase
{
    protected function setUp(): void
    {
        $this->allowWrites ??= true;
        parent::setUp();
    }

    public function testDeletesById(): void
    {
        $response = $this->toolsCall('catalog.category.delete', [
            'id' => 333,
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
        self::assertTrue($payload['deleted'] ?? false);
        self::assertSame(333, (int) ($payload['entity_id'] ?? 0));

        // The category is actually gone — repository::get throws.
        /** @var CategoryRepositoryInterface $repo */
        $repo = Bootstrap::getObjectManager()->get(CategoryRepositoryInterface::class);
        $this->expectException(NoSuchEntityException::class);
        $repo->get(333);
    }
}
