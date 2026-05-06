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
 * Meta slice — meta_title, meta_keywords, meta_description.
 */
class MetaResolver implements CategoryFieldResolverInterface
{
    public const KEY = 'meta';

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
    public function resolve(CategoryInterface $category, array $args): array
    {
        unset($args);
        return [
            'meta_title' => $this->stringAttr($category, 'meta_title'),
            'meta_keywords' => $this->stringAttr($category, 'meta_keywords'),
            'meta_description' => $this->stringAttr($category, 'meta_description'),
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
