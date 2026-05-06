<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Api;

use Magebit\Mcp\Api\FieldResolverInterface;
use Magento\Catalog\Api\Data\ProductInterface;

/**
 * Contract for a contributor to `catalog.product.list` / `catalog.product.get` output.
 *
 * Each resolver owns one named slice; 3rd parties add or override slices by
 * registering their implementation in `etc/di.xml` under the tool's
 * `fieldResolvers` argument. See `docs/EXTENDING.md`.
 */
interface ProductFieldResolverInterface extends FieldResolverInterface
{
    /**
     * Produce the response fragment for the given product.
     *
     * @param ProductInterface $product
     * @param array $args Raw tool-call arguments; resolvers may peek to adjust shape.
     * @phpstan-param array<string, mixed> $args
     * @return array<int|string, mixed>
     */
    public function resolve(ProductInterface $product, array $args): array;
}
