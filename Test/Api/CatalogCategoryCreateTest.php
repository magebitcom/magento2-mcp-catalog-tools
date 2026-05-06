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
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * `catalog.category.create` end-to-end. Covers happy path under default root
 * (parent_id 2) plus the per-token write-gate smoke check.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 */
class CatalogCategoryCreateTest extends McpTestCase
{
    /** @var int|null Category id minted by the test, deleted in tearDown. */
    private ?int $createdCategoryId = null;

    protected function setUp(): void
    {
        $this->allowWrites ??= true;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->createdCategoryId !== null) {
            $om = Bootstrap::getObjectManager();
            /** @var Registry $registry */
            $registry = $om->get(Registry::class);
            $previous = $registry->registry('isSecureArea');
            // RemoveAction\Pool blocks deletion of catalog/Category outside
            // admin area; isSecureArea is the documented bypass used by
            // upstream fixture rollbacks.
            $registry->unregister('isSecureArea');
            $registry->register('isSecureArea', true);
            try {
                /** @var CategoryRepositoryInterface $repo */
                $repo = $om->get(CategoryRepositoryInterface::class);
                $repo->deleteByIdentifier($this->createdCategoryId);
            } catch (NoSuchEntityException) {
                // Already gone — nothing to clean up.
            } finally {
                $registry->unregister('isSecureArea');
                if ($previous !== null) {
                    $registry->register('isSecureArea', $previous);
                }
            }
            $this->createdCategoryId = null;
        }
        parent::tearDown();
    }

    public function testCreatesCategoryUnderDefaultRoot(): void
    {
        $name = 'MCP Test Cat ' . uniqid('', true);

        $response = $this->toolsCall('catalog.category.create', [
            'name' => $name,
            'parent_id' => 2,
            'is_active' => true,
            'include_in_menu' => true,
        ]);

        $this->assertJsonRpcSuccess($response);

        $body = $response['body'];
        self::assertIsArray($body);
        $content = $body['result']['content'] ?? null;
        self::assertIsArray($content);
        $payload = json_decode((string) ($content[0]['text'] ?? ''), true);
        self::assertIsArray($payload);

        $entityId = (int) ($payload['entity_id'] ?? 0);
        self::assertGreaterThan(0, $entityId);
        $this->createdCategoryId = $entityId;

        self::assertSame($name, $payload['name'] ?? null);
        self::assertSame(2, $payload['parent_id'] ?? null);
    }

    public function testRejectedWhenTokenDisallowsWrites(): void
    {
        $this->allowWrites = false;
        $this->tearDown();
        $this->setUp();

        $response = $this->toolsCall('catalog.category.create', [
            'name' => 'Should not be created',
            'parent_id' => 2,
        ]);

        $this->assertJsonRpcError($response, ErrorCode::WRITE_NOT_ALLOWED->value, 200);
    }
}
