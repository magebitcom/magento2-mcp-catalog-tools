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
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\NumberBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpCatalogTools\Model\EntityFinder;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * MCP write tool `catalog.product.update`.
 *
 * PATCH-style: only fields present in the call are applied; omitted
 * `website_ids` / `category_ids` keep their existing assignments.
 */
class ProductUpdate implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'catalog.product.update';
    public const ACL_RESOURCE = 'Magebit_McpCatalogTools::tool_catalog_product_update';

    /**
     * @param EntityFinder $entityFinder
     * @param ProductRepositoryInterface $productRepository
     * @param ProductFieldApplier $fieldApplier
     */
    public function __construct(
        private readonly EntityFinder $entityFinder,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductFieldApplier $fieldApplier
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
        return 'Update Product';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Partial update of a catalog product. Identify the product '
            . 'with either `id` or `sku`. Only fields you provide are '
            . 'touched; omit `website_ids` / `category_ids` to keep existing '
            . 'assignments. Use `new_sku` to rename — URLs using the old SKU '
            . 'will 404.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('id', fn (IntegerBuilder $i) => $i->minimum(1))
            ->string('sku', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->description('The SKU used to locate the product. Use '
                    . '`new_sku` to change it.'))
            ->string('new_sku', fn (StringBuilder $s) => $s->minLength(1))
            ->string('name', fn (StringBuilder $s) => $s->minLength(1))
            ->number('price', fn (NumberBuilder $n) => $n)
            ->integer('attribute_set_id', fn (IntegerBuilder $i) => $i->minimum(1))
            ->string('type_id', fn (StringBuilder $s) => $s->minLength(1))
            ->integer('status', fn (IntegerBuilder $i) => $i->enum([1, 2]))
            ->integer('visibility', fn (IntegerBuilder $i) => $i->enum([1, 2, 3, 4]))
            ->number('weight', fn (NumberBuilder $n) => $n)
            ->string('url_key', fn (StringBuilder $s) => $s)
            ->integer('tax_class_id', fn (IntegerBuilder $i) => $i->minimum(0))
            ->string('description', fn (StringBuilder $s) => $s)
            ->string('short_description', fn (StringBuilder $s) => $s)
            ->string('meta_title', fn (StringBuilder $s) => $s)
            ->string('meta_keywords', fn (StringBuilder $s) => $s)
            ->string('meta_description', fn (StringBuilder $s) => $s)
            ->array('website_ids', fn (ArrayBuilder $a) => $a
                ->ofIntegers(fn (IntegerBuilder $i) => $i->minimum(0)))
            ->array('category_ids', fn (ArrayBuilder $a) => $a
                ->ofIntegers(fn (IntegerBuilder $i) => $i->minimum(1)))
            ->rawProperty('custom_attributes', [
                'type' => 'object',
                'description' => 'Map of attribute_code => value.',
                'additionalProperties' => true,
            ])
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

        $patch = $arguments;
        if (array_key_exists('new_sku', $patch)) {
            $patch['sku'] = $patch['new_sku'];
            unset($patch['new_sku']);
        } else {
            unset($patch['sku']);
        }
        unset($patch['id']);

        $this->fieldApplier->applyOptional($product, $patch);
        $this->fieldApplier->applyWebsites($product, $patch);
        $this->fieldApplier->applyCategoryIds($product, $patch);

        $saved = $this->productRepository->save($product);

        $payload = [
            'entity_id' => (int) $saved->getId(),
            'sku' => (string) $saved->getSku(),
            'name' => (string) $saved->getName(),
            'type_id' => (string) $saved->getTypeId(),
            'status' => $saved->getStatus() !== null ? (int) $saved->getStatus() : null,
            'visibility' => $saved->getVisibility() !== null ? (int) $saved->getVisibility() : null,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode updated product as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'entity_id' => (int) $saved->getId(),
                'sku' => (string) $saved->getSku(),
                'fields_changed' => array_keys($patch),
            ]
        );
    }
}
