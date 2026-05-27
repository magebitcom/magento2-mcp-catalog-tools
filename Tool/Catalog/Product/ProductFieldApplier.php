<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Tool\Catalog\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product as ProductModel;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;

/**
 * Helper service shared by {@see ProductCreate} and {@see ProductUpdate}.
 *
 * Both tools accept the same set of optional write-through fields plus a few
 * structured sub-payloads (custom attributes, website assignment, category
 * assignment). The logic lives here so the tools stay focused on their
 * top-level orchestration.
 *
 * Registered as a normal DI service — third parties can preference this
 * class to extend the accepted-field list, override the custom-attribute
 * scalar set, or change the validation rules.
 */
class ProductFieldApplier
{
    /**
     * Apply every optional ProductInterface field present in `$args` to `$product`.
     *
     * Required fields (`sku`, `name`, `price`, `attribute_set_id`, `type_id`,
     * `status`, `visibility`) are the caller's responsibility — this method is
     * safe to call on either a brand-new or already-loaded product.
     *
     * @param ProductInterface $product
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @return void
     * @throws LocalizedException
     */
    public function applyOptional(ProductInterface $product, array $args): void
    {
        if (array_key_exists('sku', $args) && is_string($args['sku']) && $args['sku'] !== '') {
            $product->setSku($args['sku']);
        }
        if (array_key_exists('name', $args) && is_string($args['name'])) {
            $product->setName($args['name']);
        }
        if (array_key_exists('attribute_set_id', $args) && is_numeric($args['attribute_set_id'])) {
            $product->setAttributeSetId((int) $args['attribute_set_id']);
        }
        if (array_key_exists('type_id', $args) && is_string($args['type_id'])) {
            $product->setTypeId($args['type_id']);
        }
        if (array_key_exists('status', $args) && is_numeric($args['status'])) {
            $product->setStatus((int) $args['status']);
        }
        if (array_key_exists('visibility', $args) && is_numeric($args['visibility'])) {
            $product->setVisibility((int) $args['visibility']);
        }
        if (array_key_exists('price', $args) && is_numeric($args['price'])) {
            $product->setPrice((float) $args['price']);
        }
        if (array_key_exists('weight', $args) && is_numeric($args['weight'])) {
            $product->setWeight((float) $args['weight']);
        }

        foreach ($this->scalarCustomAttributes() as $code) {
            if (array_key_exists($code, $args) && is_scalar($args[$code])) {
                $product->setCustomAttribute($code, $args[$code]);
            }
        }

        if (array_key_exists('custom_attributes', $args) && is_array($args['custom_attributes'])) {
            $reserved = $this->reservedCustomAttributeCodes();
            foreach ($args['custom_attributes'] as $code => $value) {
                if (!is_string($code) || $code === '') {
                    throw new LocalizedException(
                        __('custom_attributes keys must be non-empty strings.')
                    );
                }
                if (in_array($code, $reserved, true)) {
                    throw new LocalizedException(
                        __(
                            '"%1" cannot be set through custom_attributes; use the dedicated '
                            . 'top-level field, which validates the value.',
                            $code
                        )
                    );
                }
                if (!is_scalar($value) && $value !== null) {
                    throw new LocalizedException(
                        __('custom_attributes value for "%1" must be scalar or null.', $code)
                    );
                }
                $product->setCustomAttribute($code, $value);
            }
        }
    }

    /**
     * Apply `website_ids` onto the product's extension attributes.
     *
     * @param ProductInterface $product
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @return void
     */
    public function applyWebsites(ProductInterface $product, array $args): void
    {
        if (!array_key_exists('website_ids', $args) || !is_array($args['website_ids'])) {
            return;
        }
        $ids = [];
        foreach ($args['website_ids'] as $id) {
            if (is_numeric($id) && (int) $id >= 0) {
                $ids[] = (int) $id;
            }
        }
        $extension = $product->getExtensionAttributes();
        if ($extension !== null) {
            $extension->setWebsiteIds(array_values(array_unique($ids)));
            $product->setExtensionAttributes($extension);
        }
    }

    /**
     * Apply `category_ids` to the product when present in `$args`.
     * @param ProductInterface $product
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @return void
     * @throws LocalizedException
     */
    public function applyCategoryIds(ProductInterface $product, array $args): void
    {
        if (!array_key_exists('category_ids', $args)) {
            return;
        }
        if (!is_array($args['category_ids'])) {
            throw new LocalizedException(__('category_ids must be an array of integers.'));
        }
        $ids = [];
        foreach ($args['category_ids'] as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique($ids));

        if ($product instanceof ProductModel) {
            $product->setCategoryIds($ids);
            return;
        }
        if ($product instanceof DataObject) {
            $product->setData('category_ids', $ids);
            return;
        }
        throw new LocalizedException(
            __(
                'category_ids cannot be applied: product implementation %1 supports neither '
                . 'setCustomAttribute() nor setData().',
                get_class($product)
            )
        );
    }

    /**
     * @return array<int, string>
     */
    protected function reservedCustomAttributeCodes(): array
    {
        return [
            // Typed setters / structured sub-payloads.
            ProductInterface::SKU,
            ProductInterface::NAME,
            ProductInterface::PRICE,
            ProductInterface::WEIGHT,
            ProductInterface::STATUS,
            ProductInterface::VISIBILITY,
            ProductInterface::ATTRIBUTE_SET_ID,
            ProductInterface::TYPE_ID,
            'website_ids',
            'category_ids',
            // Scalar fields exposed (and validated) as top-level schema props.
            'url_key',
            'tax_class_id',
            'description',
            'short_description',
            'meta_title',
            'meta_keywords',
            'meta_description',
        ];
    }

    /**
     * Top-level scalar fields that map directly to EAV custom attributes.
     *
     * Override in a subclass to extend the accepted-field set.
     *
     * @return array<int, string>
     */
    protected function scalarCustomAttributes(): array
    {
        return [
            'description',
            'short_description',
            'url_key',
            'tax_class_id',
            'meta_title',
            'meta_keywords',
            'meta_description',
            'special_price',
            'special_from_date',
            'special_to_date',
            'cost',
            'country_of_manufacture',
        ];
    }
}
