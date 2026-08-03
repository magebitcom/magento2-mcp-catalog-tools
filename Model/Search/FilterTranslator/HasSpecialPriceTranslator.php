<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Model\Search\FilterTranslator;

use Magebit\McpCatalogTools\Api\ProductFilterTranslatorInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;

/**
 * Adds the `has_special_price` key to `catalog.product.list` filters. Matches on
 * `special_price` presence only — it does not evaluate the
 * special_from_date/special_to_date window.
 */
class HasSpecialPriceTranslator implements ProductFilterTranslatorInterface
{
    public const FILTER_KEY = 'has_special_price';

    /**
     * @inheritDoc
     */
    public function supports(string $key): bool
    {
        return $key === self::FILTER_KEY;
    }

    /**
     * @inheritDoc
     * @throws LocalizedException
     */
    public function translate(string $key, mixed $value, SearchCriteriaBuilder $builder): void
    {
        $builder->addFilter('special_price', true, $this->toBool($value) ? 'notnull' : 'null');
    }

    /**
     * Normalise the filter value to a strict boolean, accepting the canonical
     * scalar forms (`1`/`0`, `"true"`/`"false"`, `"yes"`/`"no"`).
     *
     * @param mixed $value
     * @return bool
     * @throws LocalizedException
     */
    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = is_scalar($value) && (string) $value !== ''
            ? filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            : null;

        if ($normalized === null) {
            throw new LocalizedException(__('Filter "%1" must be boolean.', self::FILTER_KEY));
        }

        return $normalized;
    }
}
