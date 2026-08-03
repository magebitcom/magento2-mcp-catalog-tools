<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Test\Unit\Tool\Catalog\Category;

use Magebit\McpCatalogTools\Model\EntityFinder;
use Magebit\McpCatalogTools\Model\StoreScope;
use Magebit\McpCatalogTools\Tool\Catalog\Category\CategoryFieldApplier;
use Magebit\McpCatalogTools\Tool\Catalog\Category\CategoryUpdate;
use Magento\Catalog\Api\CategoryManagementInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CategoryUpdateTest extends TestCase
{
    /** @var CategoryRepositoryInterface&MockObject */
    private CategoryRepositoryInterface $categoryRepository;

    /** @var CategoryManagementInterface&MockObject */
    private CategoryManagementInterface $categoryManagement;

    /** @var StoreManagerInterface&MockObject */
    private StoreManagerInterface $storeManager;

    private CategoryUpdate $tool;

    /** @var array<int, string> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->calls = [];
        $this->categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $this->categoryManagement = $this->createMock(CategoryManagementInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);

        // `/mcp` resolves a frontend store view — store 1 stands in for it.
        $currentStore = $this->createMock(StoreInterface::class);
        $currentStore->method('getId')->willReturn(1);
        $this->storeManager->method('getStore')->willReturn($currentStore);
        $this->storeManager->method('setCurrentStore')
            ->willReturnCallback(function ($store): void {
                $this->calls[] = 'switch:' . (string) $store;
            });

        $this->tool = new CategoryUpdate(
            new EntityFinder(
                $this->createMock(ProductRepositoryInterface::class),
                $this->categoryRepository
            ),
            $this->categoryRepository,
            $this->categoryManagement,
            $this->createMock(CategoryFieldApplier::class),
            new StoreScope($this->storeManager)
        );
    }

    public function testStoreIdDescriptionStatesGlobalDefault(): void
    {
        $schema = $this->tool->getInputSchema();
        $this->assertIsArray($schema['properties']);
        $description = $schema['properties']['store_id']['description'];
        $this->assertIsString($description);
        $this->assertStringContainsString('global', strtolower($description));
        $this->assertStringContainsString('global', strtolower($this->tool->getDescription()));
    }

    public function testDefaultsToGlobalScopeForLoadAndSave(): void
    {
        $category = $this->categoryMock();

        $this->categoryRepository->expects($this->once())
            ->method('get')
            ->with(5, $this->identicalTo(0))
            ->willReturnCallback(function () use ($category) {
                $this->calls[] = 'get';
                return $category;
            });
        $this->categoryRepository->expects($this->once())
            ->method('save')
            ->with($category)
            ->willReturnCallback(function (CategoryInterface $saved) {
                $this->calls[] = 'save';
                return $saved;
            });

        $this->tool->execute(['id' => 5, 'meta_title' => 'Global title']);

        $this->assertSame(['switch:0', 'get', 'save', 'switch:1'], $this->calls);
    }

    public function testExplicitStoreIdKeepsThatScope(): void
    {
        $category = $this->categoryMock();

        $this->categoryRepository->expects($this->once())
            ->method('get')
            ->with(5, $this->identicalTo(3))
            ->willReturnCallback(function () use ($category) {
                $this->calls[] = 'get';
                return $category;
            });
        $this->categoryRepository->expects($this->once())
            ->method('save')
            ->willReturnCallback(function (CategoryInterface $saved) {
                $this->calls[] = 'save';
                return $saved;
            });

        $this->tool->execute(['id' => 5, 'store_id' => 3, 'meta_title' => 'Store title']);

        $this->assertSame(['switch:3', 'get', 'save', 'switch:1'], $this->calls);
    }

    public function testTreeMoveReloadsAtTheResolvedScope(): void
    {
        $category = $this->categoryMock();

        $this->categoryRepository->expects($this->exactly(2))
            ->method('get')
            ->with(5, $this->identicalTo(0))
            ->willReturn($category);
        $this->categoryManagement->expects($this->once())
            ->method('move')
            ->with(5, 7, null)
            ->willReturn(true);
        $this->categoryRepository->expects($this->once())
            ->method('save')
            ->willReturn($category);

        $result = $this->tool->execute(['id' => 5, 'parent_id' => 7]);

        $changed = $result->getAuditSummary()['fields_changed'];
        $this->assertIsArray($changed);
        $this->assertContains('parent_id', $changed);
    }

    /**
     * @return CategoryInterface&MockObject
     */
    private function categoryMock(): CategoryInterface
    {
        $category = $this->createMock(CategoryInterface::class);
        $category->method('getId')->willReturn(5);
        $category->method('getName')->willReturn('Shoes');
        $category->method('getParentId')->willReturn(2);
        $category->method('getLevel')->willReturn(2);

        return $category;
    }
}
