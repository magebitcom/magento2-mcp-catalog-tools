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

        $tool = new ProductCreate(
            $repository,
            $factory,
            $this->createMock(ProductFieldApplier::class)
        );

        $tool->execute(['sku' => 'sku-new', 'name' => 'New', 'price' => 1.0]);

        $this->assertSame(['setStoreId', 'save'], $calls);
    }
}
