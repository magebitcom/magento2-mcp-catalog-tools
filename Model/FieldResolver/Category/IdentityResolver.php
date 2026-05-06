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
 * Row identity slice — entity_id, name, url_key, url_path.
 */
class IdentityResolver implements CategoryFieldResolverInterface
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
    public function resolve(CategoryInterface $category, array $args): array
    {
        unset($args);
        return [
            'entity_id' => (int) $category->getId(),
            'name' => (string) $category->getName(),
            'url_key' => $this->stringAttr($category, 'url_key'),
            'url_path' => $this->stringAttr($category, 'url_path'),
        ];
    }

    /**
     * Read a string-valued EAV attribute, returning null when absent or non-scalar.
     *
     * @param CategoryInterface $category
     * @param string $code
     * @return string|null
     */
    private function stringAttr(CategoryInterface $category, string $code): ?string
    {
        $attr = $category->getCustomAttribute($code);
        if ($attr === null) {
            return null;
        }
        $value = $attr->getValue();
        return is_scalar($value) ? (string) $value : null;
    }
}
