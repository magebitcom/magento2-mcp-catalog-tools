<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Test\Unit\Model\FieldResolver\Product;

use Magebit\McpCatalogTools\Model\FieldResolver\Product\VariantsResolver;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type\Simple;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use PHPUnit\Framework\TestCase;

class VariantsResolverTest extends TestCase
{
    private VariantsResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new VariantsResolver();
    }

    public function testKeyAndSortOrder(): void
    {
        $this->assertSame('variants', $this->resolver->getKey());
        $this->assertSame(80, $this->resolver->getSortOrder());
    }

    public function testNonConfigurableResolvesToNull(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getTypeId')->willReturn('simple');

        $this->assertSame(['variants' => null], $this->resolver->resolve($product, []));
    }

    public function testConfigurableTypeIdOnAPlainDataObjectResolvesToNull(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getTypeId')->willReturn(Configurable::TYPE_CODE);

        $this->assertSame(['variants' => null], $this->resolver->resolve($product, []));
    }

    public function testForeignTypeInstanceResolvesToNull(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getTypeId')->willReturn(Configurable::TYPE_CODE);
        $product->method('getTypeInstance')->willReturn($this->createMock(Simple::class));

        $this->assertSame(['variants' => null], $this->resolver->resolve($product, []));
    }

    public function testConfigurableMapsChildSkusAndPrices(): void
    {
        $first = $this->createMock(Product::class);
        $first->method('getId')->willReturn('431');
        $first->method('getSku')->willReturn('MS04-XS-Black');
        $first->method('getName')->willReturn('Gobi HeatTec Tee-XS-Black');
        $first->method('getPrice')->willReturn(29.0);
        $first->method('getStatus')->willReturn('1');
        $first->method('getData')->with('special_price')->willReturn('19.5');

        $second = $this->createMock(Product::class);
        $second->method('getId')->willReturn('432');
        $second->method('getSku')->willReturn('MS04-XS-Blue');
        $second->method('getName')->willReturn('Gobi HeatTec Tee-XS-Blue');
        $second->method('getPrice')->willReturn(0.0);
        $second->method('getStatus')->willReturn('2');
        $second->method('getData')->with('special_price')->willReturn(null);

        $parent = $this->createMock(Product::class);
        $parent->method('getTypeId')->willReturn(Configurable::TYPE_CODE);

        $typeInstance = $this->createMock(Configurable::class);
        $typeInstance->expects($this->once())
            ->method('getUsedProducts')
            ->with($parent)
            ->willReturn([$first, $second]);
        $parent->method('getTypeInstance')->willReturn($typeInstance);

        $this->assertSame(
            [
                'variants' => [
                    [
                        'id' => 431,
                        'sku' => 'MS04-XS-Black',
                        'name' => 'Gobi HeatTec Tee-XS-Black',
                        'price' => 29.0,
                        'special_price' => 19.5,
                        'status' => 1,
                    ],
                    [
                        'id' => 432,
                        'sku' => 'MS04-XS-Blue',
                        'name' => 'Gobi HeatTec Tee-XS-Blue',
                        'price' => null,
                        'special_price' => null,
                        'status' => 2,
                    ],
                ],
            ],
            $this->resolver->resolve($parent, [])
        );
    }

    public function testConfigurableWithoutChildrenResolvesToEmptyArray(): void
    {
        $parent = $this->createMock(Product::class);
        $parent->method('getTypeId')->willReturn(Configurable::TYPE_CODE);

        $typeInstance = $this->createMock(Configurable::class);
        $typeInstance->method('getUsedProducts')->willReturn([]);
        $parent->method('getTypeInstance')->willReturn($typeInstance);

        $this->assertSame(['variants' => []], $this->resolver->resolve($parent, []));
    }
}
