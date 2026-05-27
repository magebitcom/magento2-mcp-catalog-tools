<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Model;

use Magento\Framework\Registry;

/**
 * Run a callable with the `isSecureArea` registry flag set, restoring it after.
 * Magento's RemoveAction validator blocks catalog deletes unless the flag is
 * true, and `/mcp` runs in the `frontend` area where it isn't set.
 */
class SecureAreaScope
{
    /**
     * @param Registry $registry
     */
    public function __construct(
        private readonly Registry $registry
    ) {
    }

    /**
     * Execute `$callable` with `isSecureArea = true`, restoring the previous value.
     *
     * @param callable $callable
     * @phpstan-template T
     * @phpstan-param callable(): T $callable
     * @return mixed
     * @phpstan-return T
     */
    public function run(callable $callable): mixed
    {
        $previous = $this->registry->registry('isSecureArea');
        $this->registry->unregister('isSecureArea');
        $this->registry->register('isSecureArea', true);
        try {
            return $callable();
        } finally {
            $this->registry->unregister('isSecureArea');
            if ($previous !== null) {
                $this->registry->register('isSecureArea', $previous);
            }
        }
    }
}
