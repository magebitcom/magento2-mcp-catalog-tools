<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Model;

use Magento\Store\Model\StoreManagerInterface;

/**
 * Run a callable with the store manager's current store switched, restoring it
 * after. `CategoryRepository::save()` reads the scope off the store manager
 * rather than the model, so pinning `store_id` on the entity is not enough.
 */
class StoreScope
{
    /**
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Execute `$callable` with `$storeId` as the current store.
     *
     * @param int $storeId
     * @param callable $callable
     * @phpstan-template T
     * @phpstan-param callable(): T $callable
     * @return mixed
     * @phpstan-return T
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Throwable Anything raised by `$callable`, after the scope is restored.
     */
    public function run(int $storeId, callable $callable): mixed
    {
        $previous = (int) $this->storeManager->getStore()->getId();
        if ($previous === $storeId) {
            return $callable();
        }

        $this->storeManager->setCurrentStore($storeId);
        try {
            return $callable();
        } finally {
            $this->storeManager->setCurrentStore($previous);
        }
    }
}
