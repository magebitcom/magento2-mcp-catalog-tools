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
use Magento\Framework\Api\AttributeInterface;

/**
 * Custom-attributes slice — EAV attribute values keyed by attribute code.
 *
 * Attributes already surfaced by dedicated resolvers (price, special_price,
 * tax_class_id, status, visibility, description, weight, etc.) are filtered
 * out to avoid duplicating data in the response.
 */
class CustomAttributesResolver implements ProductFieldResolverInterface
{
    public const KEY = 'custom_attributes';

    /**
     * Attribute codes already covered by dedicated resolvers. Kept out of the
     * `custom_attributes` map so consumers see each value in exactly one place.
     *
     * @var array<int, string>
     */
    private const COVERED_CODES = [
        'price',
        'special_price',
        'tax_class_id',
        'status',
        'visibility',
        'weight',
        'name',
        'sku',
    ];

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
        return 90;
    }

    /**
     * @inheritDoc
     */
    public function resolve(ProductInterface $product, array $args): array
    {
        unset($args);
        $attributes = $product->getCustomAttributes();
        if ($attributes === null) {
            return ['custom_attributes' => []];
        }

        $map = [];
        foreach ($attributes as $attribute) {
            if (!$attribute instanceof AttributeInterface) {
                continue;
            }
            $code = (string) $attribute->getAttributeCode();
            if ($code === '' || in_array($code, self::COVERED_CODES, true)) {
                continue;
            }
            $map[$code] = $attribute->getValue();
        }

        return ['custom_attributes' => $map];
    }
}
