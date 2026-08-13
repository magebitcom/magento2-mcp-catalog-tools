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
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpCatalogTools\Model\EntityFinder;
use Magento\Catalog\Api\ProductAttributeMediaGalleryManagementInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * MCP write tool `catalog.product.media.remove`. Detaches the gallery entry and
 * deletes the underlying file; {@see self::getConfirmationRequired()} prompts
 * the operator.
 */
class MediaRemove implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'catalog.product.media.remove';
    public const ACL_RESOURCE = 'Magebit_McpCatalogTools::tool_catalog_product_media_remove';

    /**
     * @param EntityFinder $entityFinder
     * @param ProductAttributeMediaGalleryManagementInterface $galleryManagement
     */
    public function __construct(
        private readonly EntityFinder $entityFinder,
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
        return 'Remove Product Image';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Permanently remove an image from a product gallery. Identify the '
            . 'product by `id` or `sku` and the image by `entry_id`. The image '
            . 'file is deleted, not just unlinked.';
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
                ->description('Gallery entry id to remove.')
                ->required())
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
        $file = $entry->getFile();

        $removed = $this->galleryManagement->remove($sku, $entryId);

        $payload = [
            'removed' => (bool) $removed,
            'entry_id' => $entryId,
            'sku' => $sku,
            'file' => is_string($file) ? $file : null,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode remove result as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'sku' => $sku,
                'entry_id' => $entryId,
                'removed' => (bool) $removed,
            ]
        );
    }
}
