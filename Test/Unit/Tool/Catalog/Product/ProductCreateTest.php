<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Test\Unit\Tool\Catalog\Product;

use Magebit\McpCatalogTools\Tool\Catalog\Product\ProductCreate;
use Magebit\McpCatalogTools\Tool\Catalog\Product\ProductFieldApplier;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use PHPUnit\Framework\TestCase;

class ProductCreateTest extends TestCase
{
    public function testCreatesAtGlobalScope(): void
    {
        $calls = [];

        $product = $this->createMock(Product::class);
        $product->expects($this->once())
            ->method('setStoreId')
            ->with(0)
            ->willReturnCallback(function () use ($product, &$calls) {
                $calls[] = 'setStoreId';
                return $product;
            });
        $product->method('getId')->willReturn(11);
        $product->method('getSku')->willReturn('sku-new');

        $factory = $this->createMock(ProductInterfaceFactory::class);
        $factory->method('create')->willReturn($product);

        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('save')
            ->with($product)
            ->willReturnCallback(function (Product $saved) use (&$calls) {
                $calls[] = 'save';
                return $saved;
            });

        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->expects($this->never())->method('updateStockItemBySku');

        $tool = new ProductCreate(
            $repository,
            $factory,
            $this->createMock(ProductFieldApplier::class),
            $stockRegistry
        );

        $tool->execute(['sku' => 'sku-new', 'name' => 'New', 'price' => 1.0]);

        $this->assertSame(['setStoreId', 'save'], $calls);
    }

    public function testSeedsStockWhenQtyIsGiven(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(12);
        $product->method('getSku')->willReturn('sku-stocked');

        $factory = $this->createMock(ProductInterfaceFactory::class);
        $factory->method('create')->willReturn($product);

        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('save')->willReturn($product);

        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->expects($this->once())->method('setQty')->with(7.0);
        $stockItem->expects($this->once())->method('setIsInStock')->with(true);
        $stockItem->method('getQty')->willReturn(7.0);
        $stockItem->method('getIsInStock')->willReturn(true);

        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->method('getStockItemBySku')->with('sku-stocked')->willReturn($stockItem);
        $stockRegistry->expects($this->once())
            ->method('updateStockItemBySku')
            ->with('sku-stocked', $stockItem);

        $tool = new ProductCreate(
            $repository,
            $factory,
            $this->createMock(ProductFieldApplier::class),
            $stockRegistry
        );

        $result = $tool->execute([
            'sku' => 'sku-stocked',
            'name' => 'Stocked',
            'price' => 1.0,
            'qty' => 7,
        ]);

        $text = $result->getContent()[0]['text'];
        $this->assertIsString($text);
        $this->assertStringContainsString('"is_in_stock": true', $text);
    }
}
