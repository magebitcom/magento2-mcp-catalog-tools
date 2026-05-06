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
 * Row identity slice — entity_id, sku, type_id, attribute_set_id, name.
 */
class IdentityResolver implements ProductFieldResolverInterface
{
    public const KEY = 'identity';

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
        return 10;
    }

    /**
     * @inheritDoc
     */
    public function resolve(ProductInterface $product, array $args): array
    {
        unset($args);
        return [
            'entity_id' => (int) $product->getId(),
            'sku' => (string) $product->getSku(),
            'name' => (string) $product->getName(),
            'type_id' => (string) $product->getTypeId(),
            'attribute_set_id' => (int) $product->getAttributeSetId(),
        ];
    }
}
