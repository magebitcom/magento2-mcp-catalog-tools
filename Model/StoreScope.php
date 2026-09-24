<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
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
     * @throws LocalizedException When no store view carries `$storeId`.
     * @throws \Throwable Anything raised by `$callable`, after the scope is restored.
     */
    public function run(int $storeId, callable $callable): mixed
    {
        $previous = (int) $this->storeManager->getStore()->getId();
        if ($previous === $storeId) {
            return $callable();
        }

        // `setCurrentStore()` is a bare assignment, so an unknown id is only
        // noticed once something asks for the current store again: over HTTP
        // that is the error renderer and the session writer, which re-enter
        // until the memory limit is hit. Resolve the store while the current
        // one is still valid.
        try {
            $this->storeManager->getStore($storeId);
        } catch (NoSuchEntityException $e) {
            throw new LocalizedException(__('store_id %1 is not a known store view.', $storeId), $e);
        }

        $this->storeManager->setCurrentStore($storeId);
        try {
            return $callable();
        } finally {
            $this->storeManager->setCurrentStore($previous);
        }
    }
}
