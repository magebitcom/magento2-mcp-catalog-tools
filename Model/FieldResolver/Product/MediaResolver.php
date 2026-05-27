<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Model\FieldResolver\Product;

use Magebit\McpCatalogTools\Api\ProductFieldResolverInterface;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface;
use Magento\Catalog\Api\Data\ProductInterface;

class MediaResolver implements ProductFieldResolverInterface
{
    public const KEY = 'media';

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
        return 60;
    }

    /**
     * @inheritDoc
     */
    public function resolve(ProductInterface $product, array $args): array
    {
        unset($args);
        $entries = $product->getMediaGalleryEntries();
        if ($entries === null) {
            return ['media_gallery' => []];
        }

        $rows = [];
        foreach ($entries as $entry) {
            if (!$entry instanceof ProductAttributeMediaGalleryEntryInterface) {
                continue;
            }
            $entryId = $entry->getId();
            $label = $entry->getLabel();
            $file = $entry->getFile();
            $rows[] = [
                'id' => is_numeric($entryId) ? (int) $entryId : null,
                'media_type' => (string) $entry->getMediaType(),
                'label' => is_scalar($label) && (string) $label !== '' ? (string) $label : null,
                'position' => (int) $entry->getPosition(),
                'disabled' => (bool) $entry->isDisabled(),
                'types' => array_values(array_map('strval', $entry->getTypes())),
                'file' => is_scalar($file) && (string) $file !== '' ? (string) $file : null,
            ];
        }

        return ['media_gallery' => $rows];
    }
}
