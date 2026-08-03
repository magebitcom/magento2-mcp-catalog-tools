<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Test\Unit\Tool\Catalog\Product;

use Magebit\McpCatalogTools\Model\EntityFinder;
use Magebit\McpCatalogTools\Tool\Catalog\Product\ProductFieldApplier;
use Magebit\McpCatalogTools\Tool\Catalog\Product\ProductUpdate;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProductUpdateTest extends TestCase
{
    /** @var ProductRepositoryInterface&MockObject */
    private ProductRepositoryInterface $productRepository;

    /** @var StoreManagerInterface&MockObject */
    private StoreManagerInterface $storeManager;

    /** @var ProductFieldApplier&MockObject */
    private ProductFieldApplier $fieldApplier;

    private ProductUpdate $tool;

    /** @var array<int, string> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->fieldApplier = $this->createMock(ProductFieldApplier::class);
        $this->calls = [];

        $this->tool = new ProductUpdate(
            new EntityFinder(
                $this->productRepository,
                $this->createMock(CategoryRepositoryInterface::class)
            ),
            $this->productRepository,
            $this->fieldApplier,
            $this->storeManager
        );
    }

    public function testSchemaExposesStoreCode(): void
    {
        $schema = $this->tool->getInputSchema();
        $this->assertIsArray($schema['properties']);
        $this->assertArrayHasKey('store_code', $schema['properties']);
        $this->assertSame('string', $schema['properties']['store_code']['type']);
    }

    public function testLoadsAndSavesAtAdminScopeByDefault(): void
    {
        $product = $this->productMock(0);

        $this->productRepository->expects($this->once())
            ->method('get')
            ->with('sku-1', false, 0)
            ->willReturn($product);
        $this->productRepository->expects($this->once())
            ->method('save')
            ->with($product)
            ->willReturnCallback(function (Product $saved) {
                $this->calls[] = 'save';
                return $saved;
            });

        $this->tool->execute(['sku' => 'sku-1', 'meta_title' => 'Global title']);

        $this->assertSame(['setStoreId', 'save'], $this->calls);
    }

    public function testStoreCodeArgumentResolvesToThatStoreScope(): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('better_baseball');
        $store->method('getId')->willReturn(3);
        $this->storeManager->expects($this->once())
            ->method('getStores')
            ->with(true)
            ->willReturn([$store]);

        $product = $this->productMock(3);

        $this->productRepository->expects($this->once())
            ->method('get')
            ->with('sku-1', false, 3)
            ->willReturn($product);
        $this->productRepository->expects($this->once())
            ->method('save')
            ->willReturn($product);

        $patches = [];
        $this->fieldApplier->method('applyOptional')
            ->willReturnCallback(function ($productArg, array $patch) use (&$patches): void {
                $patches[] = $patch;
            });

        $this->tool->execute([
            'sku' => 'sku-1',
            'store_code' => 'better_baseball',
            'meta_title' => 'Store title',
        ]);

        $this->assertCount(1, $patches);
        $this->assertArrayNotHasKey('store_code', $patches[0]);
        $this->assertArrayHasKey('meta_title', $patches[0]);
    }

    public function testUnknownStoreCodeThrows(): void
    {
        $this->storeManager->method('getStores')->with(true)->willReturn([]);
        $this->productRepository->expects($this->never())->method('get');
        $this->productRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Unknown store view code "nope".');

        $this->tool->execute(['sku' => 'sku-1', 'store_code' => 'nope']);
    }

    /**
     * Product mock that records the scope it is switched to before save.
     *
     * @param int $expectedStoreId
     * @return Product&MockObject
     */
    private function productMock(int $expectedStoreId): Product
    {
        $product = $this->createMock(Product::class);
        $product->expects($this->once())
            ->method('setStoreId')
            ->with($expectedStoreId)
            ->willReturnCallback(function () use ($product) {
                $this->calls[] = 'setStoreId';
                return $product;
            });
        $product->method('getId')->willReturn(7);
        $product->method('getSku')->willReturn('sku-1');

        return $product;
    }
}
