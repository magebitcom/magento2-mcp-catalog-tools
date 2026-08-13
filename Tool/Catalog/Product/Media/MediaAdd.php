<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Tool\Catalog\Product\Media;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpCatalogTools\Model\EntityFinder;
use Magebit\McpCatalogTools\Model\Media\ImagePayloadFactory;
use Magebit\McpCatalogTools\Model\Media\MediaGalleryEntryBuilder;
use Magento\Catalog\Api\ProductAttributeMediaGalleryManagementInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * MCP write tool `catalog.product.media.add`. Takes the image inline as base64,
 * the wire format MCP's file-input proposal (SEP-2356) converges on.
 */
class MediaAdd implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'catalog.product.media.add';
    public const ACL_RESOURCE = 'Magebit_McpCatalogTools::tool_catalog_product_media_add';

    /**
     * @param EntityFinder $entityFinder
     * @param ImagePayloadFactory $imagePayloadFactory
     * @param MediaGalleryEntryBuilder $entryBuilder
     * @param ProductAttributeMediaGalleryManagementInterface $galleryManagement
     */
    public function __construct(
        private readonly EntityFinder $entityFinder,
        private readonly ImagePayloadFactory $imagePayloadFactory,
        private readonly MediaGalleryEntryBuilder $entryBuilder,
        private readonly ProductAttributeMediaGalleryManagementInterface $galleryManagement
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Add Product Image';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Upload an image to a product gallery. Identify the product by '
            . '`id` or `sku`. `content_base64` is the raw image, base64-encoded '
            . '(a `data:image/jpeg;base64,...` URI is also accepted). JPEG, PNG '
            . 'and GIF on a stock Magento — the accepted set is whatever this '
            . 'store\'s image validator allows, and WebP is not in it by default. '
            . '8 MB max, and the type is detected from the bytes, not the '
            . 'filename. Use `types` to make the image the main product '
            . 'image, thumbnail, or swatch; each role belongs to one image at a '
            . 'time, so assigning it here removes it from whichever image held it '
            . 'before. Larger uploads may need the MCP server\'s '
            . '"Max Request Body" setting and the web server body limit raised.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('id', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->description('Numeric product id. Provide this or `sku`.'))
            ->string('sku', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->description('Product SKU. Provide this or `id`.'))
            ->rawProperty('content_base64', [
                'type' => 'string',
                'minLength' => 1,
                'description' => 'Base64-encoded image bytes, or an RFC 2397 data URI. '
                    . 'The accept list is the stock Magento set; this store may allow more.',
                'x-mcp-file' => [
                    'accept' => ['image/jpeg', 'image/png', 'image/gif'],
                    'maxSize' => ImagePayloadFactory::MAX_IMAGE_BYTES,
                ],
            ], true)
            ->string('filename', fn (StringBuilder $s) => $s
                ->maxLength(255)
                ->description('Suggested file name. The extension is re-derived from the detected type.'))
            ->string('label', fn (StringBuilder $s) => $s
                ->maxLength(255)
                ->description('Alt text shown on the storefront.'))
            ->integer('position', fn (IntegerBuilder $i) => $i
                ->minimum(0)
                ->description('Sort position in the gallery. Defaults to 0.'))
            ->boolean('disabled', fn (BooleanBuilder $b) => $b
                ->description('Hide the image from the storefront. Defaults to false.'))
            ->array('types', fn (ArrayBuilder $a) => $a
                ->ofStrings(fn (StringBuilder $s) => $s
                    ->enum(MediaGalleryEntryBuilder::ALLOWED_TYPES))
                ->uniqueItems()
                ->description('Image roles to assign: image, small_image, thumbnail, swatch_image.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return self::ACL_RESOURCE;
    }

    /**
     * @inheritDoc
     */
    public function getUnderlyingAclResource(): ?string
    {
        return 'Magento_Catalog::products';
    }

    /**
     * @inheritDoc
     */
    public function getWriteMode(): WriteMode
    {
        return WriteMode::WRITE;
    }

    /**
     * @inheritDoc
     */
    public function getConfirmationRequired(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): ToolResultInterface
    {
        $product = $this->entityFinder->productFrom($arguments);
        $sku = (string) $product->getSku();

        $payload = $arguments['content_base64'] ?? null;
        if (!is_string($payload)) {
            throw new LocalizedException(__('"content_base64" must be a string.'));
        }

        $filename = isset($arguments['filename']) && is_string($arguments['filename'])
            ? $arguments['filename']
            : null;

        $content = $this->imagePayloadFactory->create($payload, $filename);
        $entry = $this->entryBuilder->build($arguments);
        $entry->setContent($content);

        $entryId = $this->galleryManagement->create($sku, $entry);

        $created = $this->galleryManagement->get($sku, (int) $entryId);
        $file = $created->getFile();

        $result = [
            'entry_id' => (int) $entryId,
            'sku' => $sku,
            'file' => is_string($file) ? $file : null,
            'media_type' => (string) $created->getMediaType(),
            'label' => $created->getLabel(),
            'position' => (int) $created->getPosition(),
            'disabled' => (bool) $created->isDisabled(),
            'types' => array_values(array_map('strval', $created->getTypes())),
        ];

        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode media entry as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'sku' => $sku,
                'entry_id' => (int) $entryId,
                'mime_type' => (string) $content->getType(),
            ]
        );
    }
}
