<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Model\Category;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Attribute\ScopeOverriddenValue;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute as CatalogAttribute;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Store\Model\Store;

/**
 * A store-scoped save writes every store-scoped attribute the model carries,
 * not only the patched ones: `AbstractResource::_canUpdateAttribute()` routes
 * an unchanged, inherited value to an insert, so one `name` override pins all
 * of them. The admin form avoids this by nulling untouched fields through
 * `use_default[]`; this does the same for a tool patch.
 */
class PreserveInheritedValues
{
    /**
     * @param EavConfig $eavConfig
     * @param ScopeOverriddenValue $scopeOverriddenValue
     */
    public function __construct(
        private readonly EavConfig $eavConfig,
        private readonly ScopeOverriddenValue $scopeOverriddenValue
    ) {
    }

    /**
     * Null every store- or website-scoped attribute that is neither in `$patch`
     * nor already overridden at the category's store, so the save inserts only
     * the patch. Both scopes take the insert branch in
     * `AbstractResource::_canUpdateAttribute()`.
     *
     * @param CategoryInterface $category Loaded at the write scope.
     * @param array $patch
     * @phpstan-param array<string, mixed> $patch
     * @return void
     */
    public function apply(CategoryInterface $category, array $patch): void
    {
        if (!$category instanceof Category || !$category->getId()) {
            return;
        }
        $storeId = (int) $category->getStoreId();
        if ($storeId === Store::DEFAULT_STORE_ID) {
            return;
        }

        $patched = array_fill_keys(array_keys($patch), true)
            + array_fill_keys(array_keys((array) ($patch['custom_attributes'] ?? [])), true);

        // Core clears this cache only for products; a second save of the same
        // category in one process would otherwise read pre-save rows.
        $this->scopeOverriddenValue->clearAttributesValues(CategoryInterface::class, $category);

        foreach ($this->eavConfig->getEntityAttributes(Category::ENTITY) as $code => $attribute) {
            if (!$attribute instanceof CatalogAttribute
                || $attribute->isScopeGlobal()
                || $attribute->isStatic()
                || isset($patched[$code])
                || $this->scopeOverriddenValue->containsValue(CategoryInterface::class, $category, $code, $storeId)
            ) {
                continue;
            }
            $category->setData($code, null);
        }
    }
}
