<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Model\Stock;

use Magebit\McpCatalogTools\Api\SingleSourceModeCheckerInterface;

/**
 * Default checker: assumes single-source. Overridden by
 * `Magebit_McpInventoryTools` when MSI is in play.
 */
class AssumeSingleSourceMode implements SingleSourceModeCheckerInterface
{
    /**
     * @inheritDoc
     */
    public function isSingleSourceMode(): bool
    {
        return true;
    }
}
