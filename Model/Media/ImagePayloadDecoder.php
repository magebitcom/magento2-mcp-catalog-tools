<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Model\Media;

use Magento\Framework\Api\Data\ImageContentInterface;
use Magento\Framework\Api\Data\ImageContentInterfaceFactory;
use Magento\Framework\Api\ImageContentValidator;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;

/**
 * Turns a base64 tool argument into a validated {@see ImageContentInterface}.
 *
 * Accepts either a bare base64 string or an RFC 2397 data URI, the wire format
 * MCP's file-input proposal (SEP-2356) settles on. The declared mime type is
 * never trusted — the type is sniffed from the decoded bytes.
 *
 * Which types are acceptable is decided by {@see ImageContentValidator}, the
 * same service the gallery save runs afterwards, so a store that widens the
 * allowlist through di.xml widens this tool with it.
 */
class ImagePayloadDecoder
{
    public const MAX_IMAGE_BYTES = 8388608;

    /**
     * Canonical extension per mime type, used only to name the stored file.
     * The validator owns acceptance; this map owns spelling.
     */
    public const EXTENSION_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    private const MAX_FILENAME_LENGTH = 90;

    /**
     * @param ImageContentInterfaceFactory $imageContentFactory
     * @param ImageContentValidator $imageContentValidator
     */
    public function __construct(
        private readonly ImageContentInterfaceFactory $imageContentFactory,
        private readonly ImageContentValidator $imageContentValidator
    ) {
    }

    /**
     * @param string $payload Bare base64, or a `data:<mime>;base64,<data>` URI.
     * @param string|null $filename Client-supplied name; extension is re-derived from the sniffed type.
     * @return ImageContentInterface
     * @throws LocalizedException
     */
    public function create(string $payload, ?string $filename = null): ImageContentInterface
    {
        $encoded = $this->stripDataUri(trim($payload));
        if ($encoded === '') {
            throw new LocalizedException(__('Image content is empty.'));
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- decoding the payload is the point.
        $binary = base64_decode($encoded, true);
        if ($binary === false) {
            throw new LocalizedException(__('Image content is not valid base64.'));
        }

        $byteCount = strlen($binary);
        if ($byteCount === 0) {
            throw new LocalizedException(__('Image content decoded to zero bytes.'));
        }
        if ($byteCount > self::MAX_IMAGE_BYTES) {
            throw new LocalizedException(__(
                'Image is %1 KB; the limit is %2 KB.',
                (int) ceil($byteCount / 1024),
                (int) (self::MAX_IMAGE_BYTES / 1024)
            ));
        }

        $mimeType = $this->sniffMimeType($binary);

        /** @var ImageContentInterface $content */
        $content = $this->imageContentFactory->create();
        $content->setBase64EncodedData(base64_encode($binary));
        $content->setType($mimeType);
        $content->setName($this->normalizeFilename($filename, $this->extensionFor($mimeType)));

        $this->assertMagentoAccepts($content, $mimeType);

        return $content;
    }

    /**
     * Run Magento's own gallery validator up front, so an unsupported type is
     * reported here — naming the type — rather than as an opaque failure during
     * the save.
     *
     * @param ImageContentInterface $content
     * @param string $mimeType
     * @return void
     * @throws LocalizedException
     */
    private function assertMagentoAccepts(ImageContentInterface $content, string $mimeType): void
    {
        try {
            $this->imageContentValidator->isValid($content);
        } catch (InputException $e) {
            throw new LocalizedException(
                __('Image type "%1" is not accepted by this store: %2', $mimeType, $e->getMessage()),
                $e
            );
        }
    }

    /**
     * @param string $payload
     * @return string
     */
    private function stripDataUri(string $payload): string
    {
        if (!str_starts_with($payload, 'data:')) {
            return $payload;
        }

        $separator = strpos($payload, ',');
        if ($separator === false) {
            return '';
        }

        return substr($payload, $separator + 1);
    }

    /**
     * Identify the image from its own bytes. `getimagesizefromstring()` also
     * proves the payload actually decodes as an image, which is what stops a
     * script being smuggled in under an image extension.
     *
     * @param string $binary
     * @return string
     * @throws LocalizedException
     */
    private function sniffMimeType(string $binary): string
    {
        // Malformed input is expected here; it is reported as a tool error below.
        // phpcs:ignore Generic.PHP.NoSilencedErrors
        $properties = @getimagesizefromstring($binary);
        if ($properties === false) {
            throw new LocalizedException(__('Image content is not a readable image.'));
        }

        return strtolower($properties['mime']);
    }

    /**
     * @param string $mimeType
     * @return string
     */
    private function extensionFor(string $mimeType): string
    {
        if (isset(self::EXTENSION_BY_MIME[$mimeType])) {
            return self::EXTENSION_BY_MIME[$mimeType];
        }

        $subtype = substr($mimeType, strpos($mimeType, '/') + 1);
        $subtype = (string) preg_replace('/[^a-z0-9]+/', '', $subtype);

        return $subtype !== '' ? $subtype : 'img';
    }

    /**
     * @param string|null $filename
     * @param string $extension
     * @return string
     */
    private function normalizeFilename(?string $filename, string $extension): string
    {
        // Split rather than basename() — locale-independent, and it drops both
        // separator styles so no path component survives into the stored name.
        $segments = explode('/', str_replace('\\', '/', (string) $filename));
        $base = (string) end($segments);
        $base = (string) preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $base);
        $base = strtolower((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $base));
        $base = trim($base, '.-');

        if ($base === '') {
            $base = 'image';
        }

        return substr($base, 0, self::MAX_FILENAME_LENGTH) . '.' . $extension;
    }
}
