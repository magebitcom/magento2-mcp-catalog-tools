<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Model\Search;

use Magebit\McpCatalogTools\Api\ProductFilterTranslatorInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Exception\LocalizedException;

/**
 * Translates the MCP `catalog.product.list` filter schema into a
 * {@see SearchCriteriaInterface} suitable for
 * `ProductRepositoryInterface::getList()`.
 *
 * Built-in keys (handled inline):
 *   - `sku`                     exact, array ⇒ `IN`, or `*glob*` ⇒ `like`
 *   - `name`                    `%like%` substring match
 *   - `status`                  coerced to 0/1 equality (enabled / disabled)
 *   - `visibility`              scalar or array ⇒ `IN`
 *   - `type_id`                 scalar or array ⇒ `IN`
 *   - `attribute_set_id`        scalar or array ⇒ `IN`
 *   - `price_from` / `price_to` inclusive range
 *   - `qty_from` / `qty_to`     inclusive range (joined against stock index)
 *   - `category_id`             scalar or array ⇒ `IN` on `category_ids`
 *   - `website_id`              scalar or array ⇒ `IN` on `website_ids`
 *   - `created_at_from` / `_to` inclusive range on `created_at`
 *   - `updated_at_from` / `_to` inclusive range on `updated_at`
 *
 * Anything else is delegated to the DI-injected
 * {@see ProductFilterTranslatorInterface[]} array. Unhandled keys throw
 * {@see LocalizedException}.
 *
 * Paging clamped at {@see self::MAX_PAGE_SIZE} because LLM callers routinely
 * over-fetch; sort field restricted to {@see self::SORTABLE_FIELDS}.
 */
class ProductSearchCriteriaBuilder
{
    public const MAX_PAGE_SIZE = 100;
    public const DEFAULT_PAGE_SIZE = 25;

    /** @var array<int, string> */
    public const SORTABLE_FIELDS = [
        'entity_id',
        ProductInterface::SKU,
        ProductInterface::NAME,
        ProductInterface::PRICE,
        ProductInterface::CREATED_AT,
        ProductInterface::UPDATED_AT,
    ];

    /**
     * @param SearchCriteriaBuilder $criteriaBuilder
     * @param SortOrderBuilder $sortBuilder
     * @param ProductFilterTranslatorInterface[] $filterTranslators
     */
    public function __construct(
        private readonly SearchCriteriaBuilder $criteriaBuilder,
        private readonly SortOrderBuilder $sortBuilder,
        private readonly array $filterTranslators = []
    ) {
    }

    /**
     * Translate the MCP filter payload into a `SearchCriteriaInterface`.
     *
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @return SearchCriteriaInterface
     * @throws LocalizedException
     */
    public function build(array $args): SearchCriteriaInterface
    {
        $filtersRaw = $args['filters'] ?? [];
        if (!is_array($filtersRaw)) {
            throw new LocalizedException(__('Filter payload must be an object.'));
        }

        foreach ($filtersRaw as $key => $value) {
            if (!is_string($key) || $key === '') {
                throw new LocalizedException(__('Filter keys must be non-empty strings.'));
            }
            $this->applyFilter($key, $value);
        }

        $this->applySort($args);
        $this->applyPaging($args);

        return $this->criteriaBuilder->create();
    }

    /**
     * Route a single filter entry to its builtin handler or a registered translator.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     * @throws LocalizedException
     */
    private function applyFilter(string $key, mixed $value): void
    {
        switch ($key) {
            case ProductInterface::SKU:
                $this->addSkuFilter($value);
                return;

            case ProductInterface::NAME:
                $this->addLikeFilter(ProductInterface::NAME, $value);
                return;

            case ProductInterface::STATUS:
                $this->addBooleanFilter(ProductInterface::STATUS, $value);
                return;

            case ProductInterface::VISIBILITY:
            case ProductInterface::TYPE_ID:
            case ProductInterface::ATTRIBUTE_SET_ID:
                $this->addEqualsOrIn($key, $value);
                return;

            case 'category_id':
                $this->addEqualsOrIn('category_ids', $value);
                return;

            case 'website_id':
                $this->addEqualsOrIn('website_ids', $value);
                return;

            case 'price_from':
                $this->addRangeBoundary(ProductInterface::PRICE, 'gteq', $value);
                return;
            case 'price_to':
                $this->addRangeBoundary(ProductInterface::PRICE, 'lteq', $value);
                return;

            case 'qty_from':
                $this->addRangeBoundary('qty', 'gteq', $value);
                return;
            case 'qty_to':
                $this->addRangeBoundary('qty', 'lteq', $value);
                return;

            case 'created_at_from':
                $this->addRangeBoundary(ProductInterface::CREATED_AT, 'gteq', $value);
                return;
            case 'created_at_to':
                $this->addRangeBoundary(ProductInterface::CREATED_AT, 'lteq', $value);
                return;

            case 'updated_at_from':
                $this->addRangeBoundary(ProductInterface::UPDATED_AT, 'gteq', $value);
                return;
            case 'updated_at_to':
                $this->addRangeBoundary(ProductInterface::UPDATED_AT, 'lteq', $value);
                return;
        }

        foreach ($this->filterTranslators as $translator) {
            if ($translator->supports($key)) {
                $translator->translate($key, $value, $this->criteriaBuilder);
                return;
            }
        }

        throw new LocalizedException(__('Unknown product filter: "%1".', $key));
    }

    /**
     * SKU filter: scalar ⇒ equals, array ⇒ IN, value containing `*` or `%` ⇒
     * `like` with `*` translated to `%`.
     *
     * @param mixed $value
     * @return void
     * @throws LocalizedException
     */
    private function addSkuFilter(mixed $value): void
    {
        if (is_array($value)) {
            $this->addEqualsOrIn(ProductInterface::SKU, $value);
            return;
        }
        if (!is_scalar($value) || (string) $value === '') {
            throw new LocalizedException(__('Filter "sku" requires a non-empty value.'));
        }
        $str = (string) $value;
        if (str_contains($str, '*') || str_contains($str, '%')) {
            $this->criteriaBuilder->addFilter(
                ProductInterface::SKU,
                str_replace('*', '%', $str),
                'like'
            );
            return;
        }
        $this->criteriaBuilder->addFilter(ProductInterface::SKU, $str);
    }

    /**
     * Apply a `%value%` substring filter on `$field`.
     *
     * @param string $field
     * @param mixed $value
     * @return void
     * @throws LocalizedException
     */
    private function addLikeFilter(string $field, mixed $value): void
    {
        if (!is_scalar($value) || (string) $value === '') {
            throw new LocalizedException(__('Filter "%1" requires a non-empty value.', $field));
        }
        $this->criteriaBuilder->addFilter($field, '%' . (string) $value . '%', 'like');
    }

    /**
     * Apply a 0/1 equality filter, accepting bool, int, or canonical string forms.
     *
     * @param string $field
     * @param mixed $value
     * @return void
     * @throws LocalizedException
     */
    private function addBooleanFilter(string $field, mixed $value): void
    {
        if (is_bool($value)) {
            $this->criteriaBuilder->addFilter($field, $value ? 1 : 0);
            return;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            $this->criteriaBuilder->addFilter($field, $value);
            return;
        }
        if (is_string($value)) {
            $lower = strtolower($value);
            if (in_array($lower, ['1', 'true', 'yes'], true)) {
                $this->criteriaBuilder->addFilter($field, 1);
                return;
            }
            if (in_array($lower, ['0', 'false', 'no'], true)) {
                $this->criteriaBuilder->addFilter($field, 0);
                return;
            }
        }
        throw new LocalizedException(__('Filter "%1" must be boolean.', $field));
    }

    /**
     * Apply a scalar `=` filter, or `IN` filter when given an array.
     *
     * @param string $field
     * @param mixed $value
     * @return void
     * @throws LocalizedException
     */
    private function addEqualsOrIn(string $field, mixed $value): void
    {
        if (is_array($value)) {
            $list = array_values(array_filter(
                $value,
                static fn($v): bool => is_scalar($v) && (string) $v !== ''
            ));
            if ($list === []) {
                return;
            }
            $this->criteriaBuilder->addFilter($field, $list, 'in');
            return;
        }
        if (!is_scalar($value) || (string) $value === '') {
            throw new LocalizedException(__('Filter "%1" requires a non-empty value.', $field));
        }
        $this->criteriaBuilder->addFilter($field, $value);
    }

    /**
     * Apply one side (`gteq` / `lteq`) of an inclusive range filter.
     *
     * @param string $field
     * @param string $condition
     * @param mixed $value
     * @return void
     * @throws LocalizedException
     */
    private function addRangeBoundary(string $field, string $condition, mixed $value): void
    {
        if (!is_scalar($value) || (string) $value === '') {
            throw new LocalizedException(__('Range boundary for "%1" must be scalar.', $field));
        }
        $this->criteriaBuilder->addFilter($field, (string) $value, $condition);
    }

    /**
     * Apply the sort clause from `sort_by` / `sort_dir`, defaulting to
     * `entity_id DESC` ("most recent first").
     *
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @return void
     * @throws LocalizedException
     */
    private function applySort(array $args): void
    {
        $sortBy = $args['sort_by'] ?? 'entity_id';
        if (!is_string($sortBy) || $sortBy === '') {
            throw new LocalizedException(__('"sort_by" must be a non-empty string.'));
        }
        if (!in_array($sortBy, self::SORTABLE_FIELDS, true)) {
            throw new LocalizedException(__(
                '"sort_by" must be one of: %1.',
                implode(', ', self::SORTABLE_FIELDS)
            ));
        }

        $dirRaw = $args['sort_dir'] ?? 'desc';
        $dir = is_string($dirRaw) ? strtolower($dirRaw) : 'desc';
        if ($dir !== 'asc' && $dir !== 'desc') {
            throw new LocalizedException(__('"sort_dir" must be "asc" or "desc".'));
        }

        $this->sortBuilder->setField($sortBy);
        $this->sortBuilder->setDirection($dir === 'asc' ? SortOrder::SORT_ASC : SortOrder::SORT_DESC);
        $this->criteriaBuilder->addSortOrder($this->sortBuilder->create());
    }

    /**
     * Apply `page` / `page_size`, clamped to {@see self::MAX_PAGE_SIZE}.
     *
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @return void
     * @throws LocalizedException
     */
    private function applyPaging(array $args): void
    {
        $pageRaw = $args['page'] ?? 1;
        $page = is_numeric($pageRaw) ? max(1, (int) $pageRaw) : 1;

        $sizeRaw = $args['page_size'] ?? self::DEFAULT_PAGE_SIZE;
        if (!is_numeric($sizeRaw)) {
            throw new LocalizedException(__('"page_size" must be numeric.'));
        }
        $size = max(1, (int) $sizeRaw);
        if ($size > self::MAX_PAGE_SIZE) {
            $size = self::MAX_PAGE_SIZE;
        }

        $this->criteriaBuilder->setCurrentPage($page);
        $this->criteriaBuilder->setPageSize($size);
    }
}
