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
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

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
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly EntityFinder $entityFinder,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductFieldApplier $fieldApplier,
        private readonly StoreManagerInterface $storeManager
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
            . 'will 404. A product\'s `type_id` and `attribute_set_id` are '
            . 'fixed at creation and cannot be changed here, mirroring the '
            . 'admin UI. Saves at global/default scope unless `store_code` '
            . 'targets a specific store view.';
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
            ->string('store_code', fn (StringBuilder $s) => $s
                ->description('Store view code for an intentional store-view override. '
                    . 'Omit (or pass "all") to save at global/default scope — the value '
                    . 'then applies to every store view without creating overrides.'))
            ->string('new_sku', fn (StringBuilder $s) => $s->minLength(1))
            ->string('name', fn (StringBuilder $s) => $s->minLength(1))
            ->number('price', fn (NumberBuilder $n) => $n)
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
        $storeId = $this->resolveStoreId($arguments);
        $product = $this->entityFinder->productFrom($arguments, $storeId);

        $patch = $arguments;
        if (array_key_exists('new_sku', $patch)) {
            $patch['sku'] = $patch['new_sku'];
            unset($patch['new_sku']);
        } else {
            unset($patch['sku']);
        }
        // `type_id` / `attribute_set_id` are immutable post-create (the admin
        // UI renders them read-only); swapping them in place orphans EAV /
        // type-specific rows. Strip them defensively even though they are no
        // longer in the input schema, so a future schema change can't
        // silently re-open the mutation.
        unset($patch['id'], $patch['type_id'], $patch['attribute_set_id'], $patch['store_code']);

        $this->fieldApplier->applyOptional($product, $patch);
        $this->fieldApplier->applyWebsites($product, $patch);
        $this->fieldApplier->applyCategoryIds($product, $patch);

        // `/mcp` runs in the frontend area, so an unscoped save would write
        // store-view override rows and detach the attributes from default
        // scope. Pin the scope resolved above (admin/0 unless `store_code`).
        if ($product instanceof Product) {
            $product->setStoreId($storeId);
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

    /**
     * Map the optional `store_code` argument to a store id; absent, empty or
     * "all" means global/default scope.
     *
     * @param array $arguments
     * @phpstan-param array<string, mixed> $arguments
     * @return int
     * @throws LocalizedException
     */
    private function resolveStoreId(array $arguments): int
    {
        $code = $arguments['store_code'] ?? null;
        if ($code === null || $code === '' || $code === 'all') {
            return Store::DEFAULT_STORE_ID;
        }
        if (!is_string($code)) {
            throw new LocalizedException(__('"store_code" must be a store view code string.'));
        }
        foreach ($this->storeManager->getStores(true) as $store) {
            if ($store->getCode() === $code) {
                return (int) $store->getId();
            }
        }
        throw new LocalizedException(__('Unknown store view code "%1".', $code));
    }
}
