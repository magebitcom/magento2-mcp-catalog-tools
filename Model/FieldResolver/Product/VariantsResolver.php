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
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\DataObject;

/**
 * Child-product slice for configurables (null otherwise): the parent's
 * `variant_ids` alone give clients no path to real variant prices, so this
 * resolves the children in one collection load and exposes sku + pricing.
 */
class VariantsResolver implements ProductFieldResolverInterface
{
    public const KEY = 'variants';

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
        return 80;
    }

    /**
     * @inheritDoc
     */
    public function resolve(ProductInterface $product, array $args): array
    {
        unset($args);

        if ((string) $product->getTypeId() !== Configurable::TYPE_CODE || !$product instanceof Product) {
            return ['variants' => null];
        }

        $typeInstance = $product->getTypeInstance();
        if (!$typeInstance instanceof Configurable) {
            return ['variants' => null];
        }

        $rows = [];
        foreach ($typeInstance->getUsedProducts($product) as $child) {
            $rows[] = [
                'id' => (int) $child->getId(),
                'sku' => (string) $child->getSku(),
                'name' => (string) $child->getName(),
                'price' => $this->positiveFloat($child->getPrice()),
                'special_price' => $this->specialPrice($child),
                'status' => (int) $child->getStatus(),
            ];
        }

        return ['variants' => $rows];
    }

    /**
     * Configurable children with no own price row report 0 — surface that as null.
     *
     * @param mixed $value
     * @return float|null
     */
    private function positiveFloat(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    /**
     * Children come off a collection load, so the raw attribute is present;
     * the EAV custom-attribute lookup is the fallback for repository-built rows.
     *
     * @param ProductInterface $child
     * @return float|null
     */
    private function specialPrice(ProductInterface $child): ?float
    {
        $value = $child instanceof DataObject ? $child->getData('special_price') : null;
        if ($value === null) {
            $value = $child->getCustomAttribute('special_price')?->getValue();
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
