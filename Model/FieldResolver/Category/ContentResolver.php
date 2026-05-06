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
 * Content slice — description, image, display_mode.
 */
class ContentResolver implements CategoryFieldResolverInterface
{
    public const KEY = 'content';

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
    public function resolve(CategoryInterface $category, array $args): array
    {
        unset($args);
        return [
            'description' => $this->stringAttr($category, 'description'),
            'image' => $this->stringAttr($category, 'image'),
            'display_mode' => $this->stringAttr($category, 'display_mode'),
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
