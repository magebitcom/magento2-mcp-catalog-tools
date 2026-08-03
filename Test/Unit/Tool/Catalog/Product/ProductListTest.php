<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Test\Unit\Tool\Catalog\Product;

use Magebit\Mcp\Model\Util\ResolverPipeline;
use Magebit\McpCatalogTools\Model\Search\ProductSearchCriteriaBuilder;
use Magebit\McpCatalogTools\Tool\Catalog\Product\ProductList;
use Magento\Catalog\Api\ProductRepositoryInterface;
use PHPUnit\Framework\TestCase;

class ProductListTest extends TestCase
{
    private ProductList $tool;

    protected function setUp(): void
    {
        $this->tool = new ProductList(
            $this->createMock(ProductRepositoryInterface::class),
            $this->createMock(ProductSearchCriteriaBuilder::class),
            $this->createMock(ResolverPipeline::class),
            []
        );
    }

    public function testSchemaDeclaresTypedFilterKeys(): void
    {
        $schema = $this->tool->getInputSchema();
        $this->assertIsArray($schema['properties']);
        $filters = $schema['properties']['filters'];
        $this->assertIsArray($filters);
        $this->assertTrue($filters['additionalProperties']);
        $props = $filters['properties'];
        $this->assertIsArray($props);

        $expected = [
            'sku' => ['string', 'array'],
            'name' => 'string',
            'status' => 'boolean',
            'visibility' => ['integer', 'array'],
            'type_id' => ['string', 'array'],
            'attribute_set_id' => ['integer', 'array'],
            'category_id' => ['integer', 'array'],
            'website_id' => ['integer', 'array'],
            'price_from' => 'number',
            'price_to' => 'number',
            'qty_from' => 'number',
            'qty_to' => 'number',
            'created_at_from' => 'string',
            'created_at_to' => 'string',
            'updated_at_from' => 'string',
            'updated_at_to' => 'string',
        ];
        $this->assertSame(array_keys($expected), array_keys($props));
        foreach ($expected as $key => $type) {
            $prop = $props[$key];
            $this->assertIsArray($prop);
            $this->assertSame($type, $prop['type'], $key);
            $this->assertNotEmpty($prop['description'], $key);
        }
    }
}
