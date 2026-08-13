<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Model\Media;

use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Builds gallery entries from tool arguments, shared by the media add and
 * update tools.
 */
class MediaGalleryEntryBuilder
{
    public const ALLOWED_TYPES = ['image', 'small_image', 'thumbnail', 'swatch_image'];

    private const ALLOWED_MEDIA_TYPES = ['image', 'external-video'];

    /**
     * @param ProductAttributeMediaGalleryEntryInterfaceFactory $entryFactory
     */
    public function __construct(
        private readonly ProductAttributeMediaGalleryEntryInterfaceFactory $entryFactory
    ) {
    }

    /**
     * @param array $arguments
     * @phpstan-param array<string, mixed> $arguments
     * @return ProductAttributeMediaGalleryEntryInterface
     * @throws LocalizedException
     */
    public function build(array $arguments): ProductAttributeMediaGalleryEntryInterface
    {
        /** @var ProductAttributeMediaGalleryEntryInterface $entry */
        $entry = $this->entryFactory->create();

        $entry->setMediaType($this->mediaType($arguments));
        $entry->setLabel($this->label($arguments));
        $entry->setPosition($this->position($arguments));
        $entry->setDisabled($this->disabled($arguments));
        $entry->setTypes($this->types($arguments));

        return $entry;
    }

    /**
     * Apply only the keys present in the arguments onto an existing entry, so
     * an update leaves untouched fields alone.
     *
     * @param ProductAttributeMediaGalleryEntryInterface $entry
     * @param array $arguments
     * @phpstan-param array<string, mixed> $arguments
     * @return void
     * @throws LocalizedException
     */
    public function applyPatch(ProductAttributeMediaGalleryEntryInterface $entry, array $arguments): void
    {
        if (array_key_exists('label', $arguments)) {
            $entry->setLabel($this->label($arguments));
        }
        if (array_key_exists('position', $arguments)) {
            $entry->setPosition($this->position($arguments));
        }
        if (array_key_exists('disabled', $arguments)) {
            $entry->setDisabled($this->disabled($arguments));
        }
        if (array_key_exists('types', $arguments)) {
            $entry->setTypes($this->types($arguments));
        }
    }

    /**
     * @param array $arguments
     * @phpstan-param array<string, mixed> $arguments
     * @return string
     * @throws LocalizedException
     */
    private function mediaType(array $arguments): string
    {
        if (!array_key_exists('media_type', $arguments)) {
            return 'image';
        }

        $mediaType = $arguments['media_type'];
        if (!is_string($mediaType) || !in_array($mediaType, self::ALLOWED_MEDIA_TYPES, true)) {
            throw new LocalizedException(__(
                '"media_type" must be one of: %1.',
                implode(', ', self::ALLOWED_MEDIA_TYPES)
            ));
        }

        return $mediaType;
    }

    /**
     * @param array $arguments
     * @phpstan-param array<string, mixed> $arguments
     * @return string
     */
    private function label(array $arguments): string
    {
        $label = $arguments['label'] ?? '';

        return is_scalar($label) ? (string) $label : '';
    }

    /**
     * @param array $arguments
     * @phpstan-param array<string, mixed> $arguments
     * @return int
     */
    private function position(array $arguments): int
    {
        $position = $arguments['position'] ?? 0;

        return is_numeric($position) ? max(0, (int) $position) : 0;
    }

    /**
     * @param array $arguments
     * @phpstan-param array<string, mixed> $arguments
     * @return bool
     */
    private function disabled(array $arguments): bool
    {
        return isset($arguments['disabled']) && (bool) $arguments['disabled'];
    }

    /**
     * @param array $arguments
     * @phpstan-param array<string, mixed> $arguments
     * @return string[]
     * @throws LocalizedException
     */
    private function types(array $arguments): array
    {
        if (!array_key_exists('types', $arguments)) {
            return [];
        }

        $types = $arguments['types'];
        if (!is_array($types)) {
            throw new LocalizedException(__('"types" must be an array of image roles.'));
        }

        $resolved = [];
        foreach ($types as $type) {
            if (!is_string($type) || !in_array($type, self::ALLOWED_TYPES, true)) {
                throw new LocalizedException(__(
                    '"types" entries must be one of: %1.',
                    implode(', ', self::ALLOWED_TYPES)
                ));
            }
            $resolved[$type] = $type;
        }

        return array_values($resolved);
    }
}
