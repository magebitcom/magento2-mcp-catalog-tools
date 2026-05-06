<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Tool\Catalog\Product;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpCatalogTools\Model\EntityFinder;
use Magebit\McpCatalogTools\Model\SecureAreaScope;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * MCP write tool `catalog.product.delete`.
 *
 * Delete is non-reversible at the repository level — the AI client should
 * prompt the operator for confirmation, which {@see self::getConfirmationRequired()}
 * signals.
 */
class ProductDelete implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'catalog.product.delete';
    public const ACL_RESOURCE = 'Magebit_McpCatalogTools::tool_catalog_product_delete';

    /**
     * @param EntityFinder $entityFinder
     * @param ProductRepositoryInterface $productRepository
     * @param SecureAreaScope $secureAreaScope
     */
    public function __construct(
        private readonly EntityFinder $entityFinder,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SecureAreaScope $secureAreaScope
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
        return 'Delete Product';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Permanently delete a catalog product. Identify by `id` or `sku`.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('id', fn (IntegerBuilder $i) => $i->minimum(1))
            ->string('sku', fn (StringBuilder $s) => $s->minLength(1))
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
        $productId = (int) $product->getId();
        $sku = (string) $product->getSku();

        $deleted = $this->secureAreaScope->run(
            fn (): bool => $this->productRepository->delete($product)
        );

        $payload = [
            'deleted' => (bool) $deleted,
            'entity_id' => $productId,
            'sku' => $sku,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode delete result as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'entity_id' => $productId,
                'sku' => $sku,
                'deleted' => (bool) $deleted,
            ]
        );
    }
}
