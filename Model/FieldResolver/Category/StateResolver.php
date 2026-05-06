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

/**
 * State slice — is_active, include_in_menu.
 */
class StateResolver implements CategoryFieldResolverInterface
{
    public const KEY = 'state';

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
        return 20;
    }

    /**
     * @inheritDoc
     */
    public function resolve(CategoryInterface $category, array $args): array
    {
        unset($args);
        $isActive = $category->getIsActive();
        $includeInMenu = $category->getIncludeInMenu();

        return [
            'is_active' => $isActive !== null ? (bool) $isActive : null,
            'include_in_menu' => $includeInMenu !== null ? (bool) $includeInMenu : null,
        ];
    }
}
