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
use Magento\Catalog\Api\Data\ProductLinkInterface;

/**
 * Linked-product slice — related / upsell / crosssell / associated links.
 *
 * Links are grouped by `link_type` so callers don't have to group them
 * themselves; each entry carries the linked SKU and the link's `position`.
 */
class LinksResolver implements ProductFieldResolverInterface
{
    public const KEY = 'links';

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
        return 70;
    }

    /**
     * @inheritDoc
     */
    public function resolve(ProductInterface $product, array $args): array
    {
        unset($args);
        $links = $product->getProductLinks();
        if ($links === null) {
            return ['links' => []];
        }

        $grouped = [];
        foreach ($links as $link) {
            if (!$link instanceof ProductLinkInterface) {
                continue;
            }
            $type = (string) $link->getLinkType();
            if (!isset($grouped[$type])) {
                $grouped[$type] = [];
            }
            $extension = $link->getExtensionAttributes();
            $position = null;
            if ($extension !== null && method_exists($extension, 'getPosition')) {
                $raw = $extension->getPosition();
                $position = $raw !== null ? (int) $raw : null;
            }
            $grouped[$type][] = [
                'linked_sku' => (string) $link->getLinkedProductSku(),
                'linked_type' => (string) $link->getLinkedProductType(),
                'position' => $position,
            ];
        }

        return ['links' => $grouped];
    }
}
