<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Test\Unit\Model\Category;

use Magebit\McpCatalogTools\Model\Category\PreserveInheritedValues;
use Magento\Catalog\Model\Attribute\ScopeOverriddenValue;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Model\Config as EavConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PreserveInheritedValuesTest extends TestCase
{
    /** @var ScopeOverriddenValue&MockObject */
    private ScopeOverriddenValue $overridden;

    /** @var Category&MockObject */
    private Category $category;

    private PreserveInheritedValues $preserve;

    private bool $cacheCleared = false;

    protected function setUp(): void
    {
        $this->cacheCleared = false;
        $eavConfig = $this->createMock(EavConfig::class);
        $this->overridden = $this->createMock(ScopeOverriddenValue::class);
        $this->category = $this->createMock(Category::class);
        $this->category->method('getId')->willReturn(42);
        $this->preserve = new PreserveInheritedValues($eavConfig, $this->overridden);

        // name and meta_title are store-scoped, url_key is store-scoped but already
        // overridden at the store, position is global, path is store-scoped but static.
        $eavConfig->method('getEntityAttributes')->willReturn([
            'name' => $this->attribute(false, false),
            'meta_title' => $this->attribute(false, false),
            'url_key' => $this->attribute(false, false),
            'position' => $this->attribute(true, false),
            'path' => $this->attribute(false, true),
        ]);
        // The cache must be cleared before the first read, so the read callback
        // asserts that the clear already ran.
        $this->overridden->method('clearAttributesValues')
            ->willReturnCallback(function (): void {
                $this->cacheCleared = true;
            });
        $this->overridden->method('containsValue')
            ->willReturnCallback(function ($type, $entity, string $code, $store): bool {
                $this->assertTrue($this->cacheCleared, 'cache read before it was cleared');
                return $code === 'url_key';
            });
    }

    public function testNullsOnlyInheritedAttributesOutsideThePatch(): void
    {
        $this->category->method('getStoreId')->willReturn(6);
        $this->category->expects($this->once())->method('setData')->with('meta_title', null);

        $this->preserve->apply($this->category, ['name' => 'EE name']);

        $this->assertTrue($this->cacheCleared);
    }

    public function testCustomAttributeKeysCountAsPatched(): void
    {
        $this->category->method('getStoreId')->willReturn(6);
        $this->category->expects($this->once())->method('setData')->with('name', null);

        $this->preserve->apply($this->category, ['custom_attributes' => ['meta_title' => 'EE meta']]);
    }

    public function testGlobalScopeIsLeftAlone(): void
    {
        $this->category->method('getStoreId')->willReturn(0);
        $this->category->expects($this->never())->method('setData');
        $this->overridden->expects($this->never())->method('clearAttributesValues');

        $this->preserve->apply($this->category, ['name' => 'Global name']);
    }

    /**
     * @param bool $global
     * @param bool $static
     * @return Attribute&MockObject
     */
    private function attribute(bool $global, bool $static): Attribute
    {
        $attribute = $this->createMock(Attribute::class);
        $attribute->method('isScopeGlobal')->willReturn($global);
        $attribute->method('isStatic')->willReturn($static);
        return $attribute;
    }
}
