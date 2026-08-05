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
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
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
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductInterfaceFactory $productFactory,
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
            ->integer('status', fn (IntegerBuilder $i) => $i->enum([1, 2])->required())
            ->integer('visibility', fn (IntegerBuilder $i) => $i->enum([1, 2, 3, 4])->required())
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
}
