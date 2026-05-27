<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Api;

use Magento\Framework\Api\SearchCriteriaBuilder;

/**
 * Extension point for the `catalog.product.list` filter argument. Translators
 * are consulted in DI order; the first whose `supports()` returns true claims
 * the key. Unclaimed, non-built-in keys fail with `INVALID_PARAMS`.
 */
interface ProductFilterTranslatorInterface
{
    /**
     * True when this translator handles the given filter key.
     *
     * @param string $key
     * @return bool
     */
    public function supports(string $key): bool;

    /**
     * Apply the given filter key+value to the supplied builder.
     *
     * @param string $key
     * @param mixed $value
     * @param SearchCriteriaBuilder $builder
     * @return void
     */
    public function translate(string $key, mixed $value, SearchCriteriaBuilder $builder): void;
}
