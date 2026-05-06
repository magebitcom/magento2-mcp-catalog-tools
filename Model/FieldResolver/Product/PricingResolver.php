<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Model\FieldResolver\Product;

use Magebit\McpCatalogTools\Api\ProductFieldResolverInterface;
use Magento\Catalog\Api\Data\ProductInterface;

/**
 * Pricing slice — price, special_price, tax_class_id, weight.
 *
 * Values are read raw from the product data bag; no currency conversion or
 * tax calculation is applied.
 */
class PricingResolver implements ProductFieldResolverInterface
{
    public const KEY = 'pricing';

    /**
     * @inheritDoc
     */
    public function getKey(): string
    {
        return self::KEY;
    }

    /**
     * @inheritDoc
     */
    public function getSortOrder(): int
    {
        return 30;
    }

    /**
     * @inheritDoc
     */
    public function resolve(ProductInterface $product, array $args): array
    {
        unset($args);
        return [
            'price' => $product->getPrice() !== null ? (float) $product->getPrice() : null,
            'special_price' => $this->numericAttr($product, 'special_price'),
            'tax_class_id' => $this->intAttr($product, 'tax_class_id'),
            'weight' => $product->getWeight() !== null ? (float) $product->getWeight() : null,
        ];
    }

    /**
     * Read a numeric EAV attribute as float, returning null when absent or non-numeric.
     *
     * @param ProductInterface $product
     * @param string $code
     * @return float|null
     */
    private function numericAttr(ProductInterface $product, string $code): ?float
    {
        $attr = $product->getCustomAttribute($code);
        if ($attr === null) {
            return null;
        }
        $value = $attr->getValue();
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Read a numeric EAV attribute as int, returning null when absent or non-numeric.
     *
     * @param ProductInterface $product
     * @param string $code
     * @return int|null
     */
    private function intAttr(ProductInterface $product, string $code): ?int
    {
        $attr = $product->getCustomAttribute($code);
        if ($attr === null) {
            return null;
        }
        $value = $attr->getValue();
        return is_numeric($value) ? (int) $value : null;
    }
}
