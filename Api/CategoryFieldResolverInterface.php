<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Api;

use Magebit\Mcp\Api\FieldResolverInterface;
use Magento\Catalog\Api\Data\CategoryInterface;

/**
 * Contract for a contributor to `catalog.category.list` / `catalog.category.get` output.
 *
 * Each resolver owns one named slice; 3rd parties add or override slices by
 * registering their implementation in `etc/di.xml` under the tool's
 * `fieldResolvers` argument. See `docs/EXTENDING.md`.
 */
interface CategoryFieldResolverInterface extends FieldResolverInterface
{
    /**
     * Produce the response fragment for the given category.
     *
     * @param CategoryInterface $category
     * @param array $args Raw tool-call arguments; resolvers may peek to adjust shape.
     * @phpstan-param array<string, mixed> $args
     * @return array<int|string, mixed>
     */
    public function resolve(CategoryInterface $category, array $args): array;
}
