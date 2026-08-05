<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Test\Unit\Tool\Catalog\Category;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Util\ResolverPipeline;
use Magebit\McpCatalogTools\Model\EntityFinder;
use Magebit\McpCatalogTools\Tool\Catalog\Category\CategoryGet;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use PHPUnit\Framework\TestCase;

class CategoryGetTest extends TestCase
{
    private CategoryGet $tool;

    protected function setUp(): void
    {
        $this->tool = new CategoryGet(
            $this->createMock(EntityFinder::class),
            $this->createMock(ResolverPipeline::class),
            []
        );
    }

    public function testSchemaAcceptsCategoryIdAsAliasOfId(): void
    {
        $schema = $this->tool->getInputSchema();
        $this->assertIsArray($schema['properties']);
        $props = $schema['properties'];

        $this->assertArrayHasKey('category_id', $props);
        $this->assertIsArray($props['category_id']);
        $this->assertSame('integer', $props['category_id']['type']);
        $this->assertSame(1, $props['category_id']['minimum']);
        $this->assertNotEmpty($props['category_id']['description']);

        $required = $schema['required'] ?? [];
        $this->assertIsArray($required);
        $this->assertNotContains('id', $required);
    }

    public function testDescriptionMentionsBothArgNames(): void
    {
        $description = $this->tool->getDescription();
        $this->assertStringContainsString('id', $description);
        $this->assertStringContainsString('category_id', $description);
    }

    public function testExecuteResolvesCategoryFromCategoryIdAlias(): void
    {
        $category = $this->createMock(CategoryInterface::class);
        $category->method('getId')->willReturn(33);
        $category->method('getName')->willReturn('Alias Category');
        $category->method('getLevel')->willReturn(2);

        $categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $categoryRepository->expects($this->once())
            ->method('get')
            ->with(33, $this->identicalTo(0))
            ->willReturn($category);

        $entityFinder = new EntityFinder(
            $this->createMock(ProductRepositoryInterface::class),
            $categoryRepository
        );

        $tool = new CategoryGet($entityFinder, new ResolverPipeline(), []);

        $result = $tool->execute(['category_id' => 33]);

        $this->assertInstanceOf(ToolResultInterface::class, $result);
        $this->assertSame(33, $result->getAuditSummary()['category_id']);
    }

    public function testDefaultsToAdminScopeAsDocumented(): void
    {
        $category = $this->createMock(CategoryInterface::class);
        $category->method('getId')->willReturn(33);

        $categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $categoryRepository->expects($this->once())
            ->method('get')
            ->with(33, $this->identicalTo(0))
            ->willReturn($category);

        $tool = new CategoryGet(
            new EntityFinder(
                $this->createMock(ProductRepositoryInterface::class),
                $categoryRepository
            ),
            new ResolverPipeline(),
            []
        );

        $tool->execute(['id' => 33]);
    }

    public function testExplicitStoreIdStillWins(): void
    {
        $category = $this->createMock(CategoryInterface::class);
        $category->method('getId')->willReturn(33);

        $categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $categoryRepository->expects($this->once())
            ->method('get')
            ->with(33, 4)
            ->willReturn($category);

        $tool = new CategoryGet(
            new EntityFinder(
                $this->createMock(ProductRepositoryInterface::class),
                $categoryRepository
            ),
            new ResolverPipeline(),
            []
        );

        $tool->execute(['id' => 33, 'store_id' => 4]);
    }
}
