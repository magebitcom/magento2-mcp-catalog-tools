<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Model\FieldResolver\Category;

use Magebit\McpCatalogTools\Api\CategoryFieldResolverInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Category as CategoryModel;

/**
 * Product-assignment slice — the list of product ids directly assigned to
 * this category.
 *
 * Wired into `catalog.category.get` by default (drop with
 * `exclude: ["products"]`) but not `catalog.category.list` — broad list
 * queries that return tens of thousands of SKUs per row blow the response
 * size budget.
 */
class ProductsResolver implements CategoryFieldResolverInterface
{
    public const KEY = 'products';

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
    public function resolve(CategoryInterface $category, array $args): array
    {
        unset($args);
        if (!$category instanceof CategoryModel) {
            return ['product_ids' => []];
        }

        $ids = $category->getProductCollection()->getAllIds();

        $out = [];
        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $out[] = (int) $id;
            }
        }

        return ['product_ids' => array_values(array_unique($out))];
    }
}
