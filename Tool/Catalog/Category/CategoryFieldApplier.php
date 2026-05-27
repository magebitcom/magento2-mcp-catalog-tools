<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Tool\Catalog\Category;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Helper service shared by {@see CategoryCreate} and {@see CategoryUpdate}.
 * Preference this class to change the accepted-field list or validation rules.
 */
class CategoryFieldApplier
{
    /**
     * Apply every optional CategoryInterface field present in `$args` to
     * `$category`.
     *
     * @param CategoryInterface $category
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @return void
     * @throws LocalizedException
     */
    public function applyOptional(CategoryInterface $category, array $args): void
    {
        if (array_key_exists('name', $args) && is_string($args['name'])) {
            $category->setName($args['name']);
        }
        if (array_key_exists('parent_id', $args) && is_numeric($args['parent_id'])) {
            $category->setParentId((int) $args['parent_id']);
        }
        if (array_key_exists('is_active', $args)) {
            $category->setIsActive($this->coerceBool($args['is_active'], 'is_active'));
        }
        if (array_key_exists('include_in_menu', $args)) {
            $category->setIncludeInMenu($this->coerceBool($args['include_in_menu'], 'include_in_menu'));
        }
        if (array_key_exists('position', $args) && is_numeric($args['position'])) {
            $category->setPosition((int) $args['position']);
        }

        foreach ($this->scalarCustomAttributes() as $code) {
            if (array_key_exists($code, $args) && is_scalar($args[$code])) {
                $category->setCustomAttribute($code, $args[$code]);
            }
        }

        if (array_key_exists('custom_attributes', $args) && is_array($args['custom_attributes'])) {
            $reserved = $this->reservedCustomAttributeCodes();
            foreach ($args['custom_attributes'] as $code => $value) {
                if (!is_string($code) || $code === '') {
                    throw new LocalizedException(
                        __('custom_attributes keys must be non-empty strings.')
                    );
                }
                if (in_array($code, $reserved, true)) {
                    throw new LocalizedException(
                        __(
                            '"%1" cannot be set through custom_attributes; use the dedicated '
                            . 'top-level field, which validates the value.',
                            $code
                        )
                    );
                }
                if (!is_scalar($value) && $value !== null) {
                    throw new LocalizedException(
                        __('custom_attributes value for "%1" must be scalar or null.', $code)
                    );
                }
                $category->setCustomAttribute($code, $value);
            }
        }
    }

    /**
     * Coerce a boolean field, accepting only real booleans and canonical
     * 0/1 / "0"/"1". A `(bool)` cast would treat the string "false" as truthy,
     * so anything else fails loudly rather than silently.
     *
     * @param mixed $value
     * @param string $field
     * @return bool
     * @throws LocalizedException
     */
    protected function coerceBool(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }
        if ($value === 0 || $value === '0') {
            return false;
        }
        throw new LocalizedException(
            __('"%1" must be a boolean (true/false).', $field)
        );
    }

    /**
     * Attribute codes already handled by validated typed setters; routing them
     * through the untyped `custom_attributes` map would bypass that validation,
     * so they are rejected there.
     *
     * @return array<int, string>
     */
    protected function reservedCustomAttributeCodes(): array
    {
        return array_merge(
            [
                'name',
                'parent_id',
                'is_active',
                'include_in_menu',
                'position',
            ],
            $this->scalarCustomAttributes()
        );
    }

    /**
     * Top-level scalar fields that map directly to EAV custom attributes.
     *
     * Override in a subclass to extend the accepted-field set.
     *
     * @return array<int, string>
     */
    protected function scalarCustomAttributes(): array
    {
        return [
            'description',
            'url_key',
            'image',
            'display_mode',
            'page_layout',
            'meta_title',
            'meta_keywords',
            'meta_description',
            'is_anchor',
        ];
    }
}
