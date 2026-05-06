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
 * Run a callable with the `isSecureArea` registry flag set.
 *
 * Magento's {@see \Magento\Framework\Model\ActionValidator\RemoveAction} blocks
 * deletion of catalog Product / Category models unless `isSecureArea` is true
 * in {@see Registry}. `/mcp` runs in the `frontend` area, so write tools must
 * lift the guard around the repository delete call and restore the previous
 * value afterwards.
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
