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
 * Tree slice — parent_id, level, path, position, children_count. `path` is the
 * canonical "1/2/3/42" representation Magento uses internally, not breadcrumbs.
 */
class TreeResolver implements CategoryFieldResolverInterface
{
    public const KEY = 'tree';

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
    public function resolve(CategoryInterface $category, array $args): array
    {
        unset($args);
        $parentId = $category->getParentId();
        $childrenCount = $category instanceof CategoryModel ? $category->getChildrenCount() : null;

        return [
            'parent_id' => $parentId !== null ? (int) $parentId : null,
            'level' => $category->getLevel() !== null ? (int) $category->getLevel() : null,
            'path' => $category->getPath() !== null ? (string) $category->getPath() : null,
            'position' => $category->getPosition() !== null ? (int) $category->getPosition() : null,
            'children_count' => $childrenCount !== null ? (int) $childrenCount : null,
        ];
    }
}
