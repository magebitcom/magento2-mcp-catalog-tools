<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Model\FieldResolver\Product;

use Magebit\McpCatalogTools\Api\ProductFieldResolverInterface;
use Magento\Catalog\Api\Data\ProductInterface;

/**
 * State slice — status, visibility.
 *
 * Both values are kept as raw ints (Magento catalog constants) rather than
 * remapped to strings, because the tool is a data API and not a UI — the AI
 * can read the Magento attribute definitions if it needs labels.
 */
class StateResolver implements ProductFieldResolverInterface
{
    public const KEY = 'state';

    /**
     * @inheritDoc
     */
    public function getKey(): string
    {
        return self::KEY;
    }

    /**
     * @inheritDoc
     */
    public function getSortOrder(): int
    {
        return 20;
    }

    /**
     * @inheritDoc
     */
    public function resolve(ProductInterface $product, array $args): array
    {
        unset($args);
        return [
            'status' => $product->getStatus() !== null ? (int) $product->getStatus() : null,
            'visibility' => $product->getVisibility() !== null ? (int) $product->getVisibility() : null,
        ];
    }
}
