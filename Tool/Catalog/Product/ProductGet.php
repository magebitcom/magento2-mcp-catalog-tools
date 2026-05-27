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
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Model\Util\ResolverPipeline;
use Magebit\McpCatalogTools\Api\ProductFieldResolverInterface;
use Magebit\McpCatalogTools\Model\EntityFinder;
use Magento\Framework\Exception\LocalizedException;

/**
 * MCP tool `catalog.product.get` — fetch one product by id or sku.
 */
class ProductGet implements ToolInterface
{
    public const TOOL_NAME = 'catalog.product.get';
    public const ACL_RESOURCE = 'Magebit_McpCatalogTools::tool_catalog_product_get';

    /**
     * @param EntityFinder $entityFinder
     * @param ResolverPipeline $pipeline
     * @param ProductFieldResolverInterface[] $fieldResolvers
     */
    public function __construct(
        private readonly EntityFinder $entityFinder,
        private readonly ResolverPipeline $pipeline,
        private readonly array $fieldResolvers = []
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
        return 'Get Product';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Fetch a single catalog product by numeric id or SKU. The '
            . 'response is composed from registered field resolvers — use '
            . '`fields` to narrow or `exclude` to drop slices (e.g. '
            . '`exclude: ["media", "custom_attributes"]` for a leaner payload). '
            . 'The `type_options` slice describes configurable-product '
            . 'configuration attributes (and variant entity ids) and '
            . 'bundle-product options (title, type, required flag, linked '
            . 'SKUs); use it to discover variants of a configurable or the '
            . 'item structure of a bundle.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('id', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->description('Numeric catalog_product_entity.entity_id.'))
            ->string('sku', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->description('Product SKU (the operator-facing identifier).'))
            ->array('fields', fn (ArrayBuilder $a) => $a
                ->ofStrings()
                ->description('Whitelist of resolver keys to include.'))
            ->array('exclude', fn (ArrayBuilder $a) => $a
                ->ofStrings()
                ->description('Resolver keys to drop from the default payload.'))
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
    public function getWriteMode(): WriteMode
    {
        return WriteMode::READ;
    }

    /**
     * @inheritDoc
     */
    public function getConfirmationRequired(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): ToolResultInterface
    {
        $product = $this->entityFinder->productFrom($arguments);

        $response = [];
        foreach ($this->pipeline->plan($this->fieldResolvers, $arguments) as $resolver) {
            $response[$resolver->getKey()] = $resolver->resolve($product, $arguments);
        }

        $json = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode product payload as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'product_id' => (int) $product->getId(),
                'sku' => (string) $product->getSku(),
                'type_id' => (string) $product->getTypeId(),
            ]
        );
    }
}
