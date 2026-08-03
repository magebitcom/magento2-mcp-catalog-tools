<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Test\Unit\Tool\Catalog\Category;

use Magebit\Mcp\Model\Util\ResolverPipeline;
use Magebit\McpCatalogTools\Model\EntityFinder;
use Magebit\McpCatalogTools\Tool\Catalog\Category\CategoryGet;
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
}
