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
use Magebit\McpCatalogTools\Model\Media\MediaGalleryEntryBuilder;
use Magento\Catalog\Api\ProductAttributeMediaGalleryManagementInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * MCP write tool `catalog.product.media.update`. PATCH-style: only the metadata
 * fields present in the arguments are touched. The image file itself is not
 * replaceable — remove the entry and add a new one.
 */
class MediaUpdate implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'catalog.product.media.update';
    public const ACL_RESOURCE = 'Magebit_McpCatalogTools::tool_catalog_product_media_update';

    /**
     * @param EntityFinder $entityFinder
     * @param MediaGalleryEntryBuilder $entryBuilder
     * @param ProductAttributeMediaGalleryManagementInterface $galleryManagement
     */
    public function __construct(
        private readonly EntityFinder $entityFinder,
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
        return 'Update Product Image';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Update the metadata of an existing gallery image — label, '
            . 'position, disabled flag, and role assignments. Identify the '
            . 'product by `id` or `sku` and the image by `entry_id` (returned by '
            . '`catalog.product.media.add`, and listed in the `media_gallery` '
            . 'slice of `catalog.product.get`). Only the fields you pass are '
            . 'changed. Replacing the image file itself is not supported — '
            . 'remove the entry and add a new one.';
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
            ->integer('entry_id', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->description('Gallery entry id to update.')
                ->required())
            ->string('label', fn (StringBuilder $s) => $s
                ->maxLength(255)
                ->description('Alt text shown on the storefront.'))
            ->integer('position', fn (IntegerBuilder $i) => $i
                ->minimum(0)
                ->description('Sort position in the gallery.'))
            ->boolean('disabled', fn (BooleanBuilder $b) => $b
                ->description('Hide the image from the storefront.'))
            ->array('types', fn (ArrayBuilder $a) => $a
                ->ofStrings(fn (StringBuilder $s) => $s
                    ->enum(MediaGalleryEntryBuilder::ALLOWED_TYPES))
                ->uniqueItems()
                ->description('Image roles to assign. Pass an empty array to clear all roles.'))
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
        $entryId = isset($arguments['entry_id']) && is_numeric($arguments['entry_id'])
            ? (int) $arguments['entry_id']
            : 0;

        $entry = $this->galleryManagement->get($sku, $entryId);
        $this->entryBuilder->applyPatch($entry, $arguments);

        $this->galleryManagement->update($sku, $entry);

        $updated = $this->galleryManagement->get($sku, $entryId);
        $file = $updated->getFile();

        $payload = [
            'entry_id' => $entryId,
            'sku' => $sku,
            'file' => is_string($file) ? $file : null,
            'label' => $updated->getLabel(),
            'position' => (int) $updated->getPosition(),
            'disabled' => (bool) $updated->isDisabled(),
            'types' => array_values(array_map('strval', $updated->getTypes())),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode media entry as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'sku' => $sku,
                'entry_id' => $entryId,
            ]
        );
    }
}
