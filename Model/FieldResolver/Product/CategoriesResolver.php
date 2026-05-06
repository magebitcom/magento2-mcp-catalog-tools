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
use Magento\Catalog\Model\Category as CategoryModel;
use Magento\Catalog\Model\Product as ProductModel;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;

/**
 * Category-assignment slice — the list of categories this product belongs to.
 *
 * Emits `category_ids` (flat int array) and `categories` ({id, name} records).
 * A category id that resolves in `category_ids` but is missing from
 * `categories` indicates a stale link to a deleted category.
 */
class CategoriesResolver implements ProductFieldResolverInterface
{
    public const KEY = 'categories';

    /**
     * @param CategoryCollectionFactory $categoryCollectionFactory
     */
    public function __construct(
        private readonly CategoryCollectionFactory $categoryCollectionFactory
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
        return 50;
    }

    /**
     * @inheritDoc
     */
    public function resolve(ProductInterface $product, array $args): array
    {
        unset($args);
        if (!$product instanceof ProductModel) {
            return ['category_ids' => [], 'categories' => []];
        }

        $ids = [];
        foreach ($product->getCategoryIds() as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique($ids));

        return [
            'category_ids' => $ids,
            'categories' => $this->loadNames($ids),
        ];
    }

    /**
     * Resolve `[id, name]` pairs for the given category ids in a single query.
     *
     * @param array $ids
     * @phpstan-param array<int, int> $ids
     * @return array<int, array{id: int, name: string}>
     */
    private function loadNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToSelect('name');
        $collection->addIdFilter($ids);

        $byId = [];

        /** @var CategoryModel $category */
        foreach ($collection as $category) {
            $rowId = $category->getId();
            if (!is_numeric($rowId)) {
                continue;
            }
            $byId[(int) $rowId] = (string) $category->getName();
        }

        $out = [];
        foreach ($ids as $id) {
            if (!array_key_exists($id, $byId)) {
                continue;
            }
            $out[] = ['id' => $id, 'name' => $byId[$id]];
        }
        return $out;
    }
}
