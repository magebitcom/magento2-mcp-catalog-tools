<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Api;

/**
 * Tells the legacy stock tool whether the store keeps inventory in one place.
 *
 * This module deliberately does not depend on Magento's multi-source inventory
 * (MSI) modules, so the shipped implementation always answers "yes". Installing
 * `Magebit_McpInventoryTools` replaces it with an MSI-backed check, which is
 * what makes `catalog.product.stock.set` warn when a write would silently land
 * on the default source only.
 */
interface SingleSourceModeCheckerInterface
{
    /**
     * @return bool
     */
    public function isSingleSourceMode(): bool;
}
