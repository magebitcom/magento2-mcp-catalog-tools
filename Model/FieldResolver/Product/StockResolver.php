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
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Stock slice — qty, is_in_stock, manage_stock. Stock is not on
 * `ProductInterface`, so it is read from {@see StockRegistryInterface} default
 * stock (stock_id = 1); MSI aggregation is left to a 3rd-party resolver.
 */
class StockResolver implements ProductFieldResolverInterface
{
    public const KEY = 'stock';

    /**
     * @param StockRegistryInterface $stockRegistry
     */
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry
    ) {
    }

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
        return 40;
    }

    /**
     * @inheritDoc
     */
    public function resolve(ProductInterface $product, array $args): array
    {
        unset($args);
        $sku = (string) $product->getSku();
        if ($sku === '') {
            return ['stock' => null];
        }

        try {
            $stockItem = $this->stockRegistry->getStockItemBySku($sku);
        } catch (NoSuchEntityException) {
            return ['stock' => null];
        }

        return [
            'stock' => [
                'qty' => (float) $stockItem->getQty(),
                'is_in_stock' => (bool) $stockItem->getIsInStock(),
                'manage_stock' => (bool) $stockItem->getManageStock(),
                'min_qty' => (float) $stockItem->getMinQty(),
                'backorders' => (int) $stockItem->getBackorders(),
            ],
        ];
    }
}
