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
 * Website-assignment slice — the list of website ids this product is scoped to.
 */
class WebsitesResolver implements ProductFieldResolverInterface
{
    public const KEY = 'websites';

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
        return 55;
    }

    /**
     * @inheritDoc
     */
    public function resolve(ProductInterface $product, array $args): array
    {
        unset($args);
        $extension = $product->getExtensionAttributes();
        $ids = $extension !== null ? $extension->getWebsiteIds() : null;

        $out = [];
        if (is_array($ids)) {
            foreach ($ids as $id) {
                if (is_numeric($id)) {
                    $out[] = (int) $id;
                }
            }
        }

        return ['website_ids' => array_values(array_unique($out))];
    }
}
