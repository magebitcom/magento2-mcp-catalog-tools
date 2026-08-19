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
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\NumberBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\Store;

/**
 * MCP write tool `catalog.product.create`.
 */
class ProductCreate implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'catalog.product.create';
    public const ACL_RESOURCE = 'Magebit_McpCatalogTools::tool_catalog_product_create';

    /**
     * @param ProductRepositoryInterface $productRepository
     * @param ProductInterfaceFactory $productFactory
     * @param ProductFieldApplier $fieldApplier
     * @param StockRegistryInterface $stockRegistry
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductInterfaceFactory $productFactory,
        private readonly ProductFieldApplier $fieldApplier,
        private readonly StockRegistryInterface $stockRegistry
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
        return 'Create Product';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Create a new catalog product. Required: `sku`, `name`, '
            . '`price`, `attribute_set_id`, `type_id`, `status`, `visibility`. '
            . '`weight` is required for physical products. `custom_attributes` '
            . 'is a map of attribute_code => value for any EAV attributes '
            . 'not covered by the top-level schema. `website_ids` and '
            . '`category_ids` are arrays of ids. Values are stored at '
            . 'global/default scope.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('sku', fn (StringBuilder $s) => $s->minLength(1)->required())
            ->string('name', fn (StringBuilder $s) => $s->minLength(1)->required())
            ->number('price', fn (NumberBuilder $n) => $n->required())
            ->integer('attribute_set_id', fn (IntegerBuilder $i) => $i->minimum(1)->required())
            ->string('type_id', fn (StringBuilder $s) => $s->minLength(1)->required())
            ->integer('status', fn (IntegerBuilder $i) => $i
                ->enum([1, 2])
                ->description('1 = enabled, 2 = disabled.')
                ->required())
            ->integer('visibility', fn (IntegerBuilder $i) => $i
                ->enum([1, 2, 3, 4])
                ->description('1 = not visible individually, 2 = catalog, 3 = search, '
                    . '4 = catalog and search.')
                ->required())
            ->number('weight', fn (NumberBuilder $n) => $n)
            ->number('qty', fn (NumberBuilder $n) => $n
                ->description('Initial quantity on hand. Omit to leave stock untouched.'))
            ->boolean('is_in_stock', fn (BooleanBuilder $b) => $b
                ->description('Initial stock availability. Defaults to true when `qty` is above zero.'))
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
        $product = $this->productFactory->create();

        $this->fieldApplier->applyOptional($product, $arguments);
        $this->fieldApplier->applyWebsites($product, $arguments);
        $this->fieldApplier->applyCategoryIds($product, $arguments);

        // `/mcp` runs in the frontend area; without pinning the scope a new
        // product's values land on the resolved store view, leaving optional
        // attributes with no global value at all.
        if ($product instanceof Product) {
            $product->setStoreId(Store::DEFAULT_STORE_ID);
        }

        $saved = $this->productRepository->save($product);

        $stock = $this->applyInitialStock((string) $saved->getSku(), $arguments);

        $payload = [
            'entity_id' => (int) $saved->getId(),
            'sku' => (string) $saved->getSku(),
            'name' => (string) $saved->getName(),
            'type_id' => (string) $saved->getTypeId(),
            'status' => $saved->getStatus() !== null ? (int) $saved->getStatus() : null,
            'visibility' => $saved->getVisibility() !== null ? (int) $saved->getVisibility() : null,
            'stock' => $stock,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode created product as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'entity_id' => (int) $saved->getId(),
                'sku' => (string) $saved->getSku(),
                'type_id' => (string) $saved->getTypeId(),
            ]
        );
    }

    /**
     * Seed the new product's stock row. Runs after the product save because the
     * stock registry addresses items by SKU, which only exists once saved.
     *
     * @param string $sku
     * @param array $arguments
     * @phpstan-param array<string, mixed> $arguments
     * @return array<string, mixed>|null
     */
    private function applyInitialStock(string $sku, array $arguments): ?array
    {
        $hasQty = isset($arguments['qty']) && is_numeric($arguments['qty']);
        $hasIsInStock = array_key_exists('is_in_stock', $arguments);

        if ($sku === '' || (!$hasQty && !$hasIsInStock)) {
            return null;
        }

        $stockItem = $this->stockRegistry->getStockItemBySku($sku);

        if ($hasQty) {
            $stockItem->setQty((float) $arguments['qty']);
        }
        $stockItem->setIsInStock(
            $hasIsInStock ? (bool) $arguments['is_in_stock'] : ($hasQty && (float) $arguments['qty'] > 0)
        );

        $this->stockRegistry->updateStockItemBySku($sku, $stockItem);

        return [
            'qty' => (float) $stockItem->getQty(),
            'is_in_stock' => (bool) $stockItem->getIsInStock(),
        ];
    }
}
