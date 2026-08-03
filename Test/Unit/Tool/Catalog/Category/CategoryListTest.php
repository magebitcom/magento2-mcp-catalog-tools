<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Test\Unit\Tool\Catalog\Category;

use Magebit\Mcp\Model\Util\ResolverPipeline;
use Magebit\McpCatalogTools\Model\Search\CategorySearchCriteriaBuilder;
use Magebit\McpCatalogTools\Tool\Catalog\Category\CategoryList;
use Magento\Catalog\Api\CategoryListInterface;
use PHPUnit\Framework\TestCase;

class CategoryListTest extends TestCase
{
    private CategoryList $tool;

    protected function setUp(): void
    {
        $this->tool = new CategoryList(
            $this->createMock(CategoryListInterface::class),
            $this->createMock(CategorySearchCriteriaBuilder::class),
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
            'name' => 'string',
            'is_active' => 'boolean',
            'include_in_menu' => 'boolean',
            'parent_id' => ['integer', 'array'],
            'level_from' => 'integer',
            'level_to' => 'integer',
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
