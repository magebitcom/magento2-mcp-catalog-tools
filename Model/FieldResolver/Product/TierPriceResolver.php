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
use Magento\Catalog\Api\Data\ProductTierPriceInterface;

/**
 * Tier-price slice — every tier from `$product->getTierPrices()`.
 *
 * Empty array when the product has no tier prices configured.
 */
class TierPriceResolver implements ProductFieldResolverInterface
{
    public const KEY = 'tier_prices';

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
        return 35;
    }

    /**
     * @inheritDoc
     */
    public function resolve(ProductInterface $product, array $args): array
    {
        unset($args);
        $tiers = $product->getTierPrices();
        if ($tiers === null) {
            return ['tier_prices' => []];
        }

        $rows = [];
        foreach ($tiers as $tier) {
            if (!$tier instanceof ProductTierPriceInterface) {
                continue;
            }
            $extension = $tier->getExtensionAttributes();
            $websiteId = $extension !== null ? $extension->getWebsiteId() : null;
            $rows[] = [
                'customer_group_id' => (int) $tier->getCustomerGroupId(),
                'qty' => (float) $tier->getQty(),
                'value' => (float) $tier->getValue(),
                'website_id' => $websiteId !== null ? (int) $websiteId : null,
            ];
        }

        return ['tier_prices' => $rows];
    }
}
