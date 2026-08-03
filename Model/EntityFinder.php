<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Model;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

class EntityFinder
{
    /**
     * @param ProductRepositoryInterface $productRepository
     * @param CategoryRepositoryInterface $categoryRepository
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryRepositoryInterface $categoryRepository
    ) {
    }

    /**
     * Resolve a product from `id`/`product_id` or `sku` tool args. `$storeId`
     * null keeps the repository's own scope resolution (current store view).
     *
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @param int|null $storeId
     * @return ProductInterface
     * @throws LocalizedException
     */
    public function productFrom(array $args, ?int $storeId = null): ProductInterface
    {
        $id = $this->pickNumeric($args, ['id', 'product_id']);
        $sku = $this->pickString($args, ['sku']);
        $this->requireOneOf($id, $sku, 'id/product_id', 'sku');

        if ($id !== null) {
            try {
                return $this->productRepository->getById($id, false, $storeId);
            } catch (NoSuchEntityException $e) {
                throw new LocalizedException(__('Product %1 not found.', $id), $e);
            }
        }

        try {
            return $this->productRepository->get((string) $sku, false, $storeId);
        } catch (NoSuchEntityException $e) {
            throw new LocalizedException(__('Product with SKU "%1" not found.', (string) $sku), $e);
        }
    }

    /**
     * Resolve a category from `id`/`category_id` tool args. `$fallbackStoreId`
     * applies when the args carry no `store_id`; null keeps the repository's
     * own scope resolution (current store view).
     *
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @param int|null $fallbackStoreId
     * @return CategoryInterface
     * @throws LocalizedException
     */
    public function categoryFrom(array $args, ?int $fallbackStoreId = null): CategoryInterface
    {
        $id = $this->pickNumeric($args, ['id', 'category_id']);
        if ($id === null) {
            throw new LocalizedException(__('One of "id" or "category_id" is required.'));
        }

        $storeId = $this->resolveStoreId($args) ?? $fallbackStoreId;

        try {
            return $storeId !== null
                ? $this->categoryRepository->get($id, $storeId)
                : $this->categoryRepository->get($id);
        } catch (NoSuchEntityException $e) {
            throw new LocalizedException(__('Category %1 not found.', $id), $e);
        }
    }

    /**
     * The effective store scope for a call: explicit `store_id` wins, else `$default`.
     *
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @param int $default
     * @return int
     * @throws LocalizedException
     */
    public function storeIdFrom(array $args, int $default): int
    {
        return $this->resolveStoreId($args) ?? $default;
    }

    /**
     * Resolve the optional store context for category lookup. A malformed
     * `store_id` raises rather than coercing to admin scope, so a typo can't
     * silently route a store-scoped read through admin (0 is honoured as admin).
     *
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @return int|null
     * @throws LocalizedException
     */
    private function resolveStoreId(array $args): ?int
    {
        if (!array_key_exists('store_id', $args)) {
            return null;
        }
        $raw = $args['store_id'];
        if (is_int($raw) && $raw >= 0) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            return (int) $raw;
        }
        throw new LocalizedException(
            __('store_id must be a non-negative integer.')
        );
    }

    /**
     * Pick the first present key from `$candidates` that holds a positive int.
     *
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @param array $candidates
     * @phpstan-param array<int, string> $candidates
     * @return int|null
     */
    private function pickNumeric(array $args, array $candidates): ?int
    {
        foreach ($candidates as $key) {
            if (!array_key_exists($key, $args)) {
                continue;
            }
            $value = $args[$key];
            if (is_int($value) && $value > 0) {
                return $value;
            }
            if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
                return (int) $value;
            }
        }
        return null;
    }

    /**
     * Pick the first present key from `$candidates` that holds a non-empty string.
     *
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @param array $candidates
     * @phpstan-param array<int, string> $candidates
     * @return string|null
     */
    private function pickString(array $args, array $candidates): ?string
    {
        foreach ($candidates as $key) {
            if (!array_key_exists($key, $args)) {
                continue;
            }
            $value = $args[$key];
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    /**
     * Enforce the "exactly one of" rule for id / natural-key lookups.
     *
     * @param int|null $id
     * @param string|null $naturalKey
     * @param string $idLabel
     * @param string $naturalKeyLabel
     * @return void
     * @throws LocalizedException
     */
    private function requireOneOf(
        ?int $id,
        ?string $naturalKey,
        string $idLabel,
        string $naturalKeyLabel
    ): void {
        if ($id === null && $naturalKey === null) {
            throw new LocalizedException(
                __('One of "%1" or "%2" is required.', $idLabel, $naturalKeyLabel)
            );
        }
        if ($id !== null && $naturalKey !== null) {
            throw new LocalizedException(
                __('Provide either "%1" or "%2", not both.', $idLabel, $naturalKeyLabel)
            );
        }
    }
}
