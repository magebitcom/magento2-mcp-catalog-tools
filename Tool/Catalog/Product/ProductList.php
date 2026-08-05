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
use Magebit\Mcp\Model\Tool\Schema\Preset\FieldSelection;
use Magebit\Mcp\Model\Tool\Schema\Preset\Filters;
use Magebit\Mcp\Model\Tool\Schema\Preset\Pagination;
use Magebit\Mcp\Model\Tool\Schema\Preset\Sort;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Model\Util\ResolverPipeline;
use Magebit\McpCatalogTools\Api\ProductFieldResolverInterface;
use Magebit\McpCatalogTools\Model\Search\ProductSearchCriteriaBuilder;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * MCP tool `catalog.product.list` — filtered + paged list of catalog products.
 */
class ProductList implements ToolInterface
{
    public const TOOL_NAME = 'catalog.product.list';
    public const ACL_RESOURCE = 'Magebit_McpCatalogTools::tool_catalog_product_list';

    /**
     * @param ProductRepositoryInterface $productRepository
     * @param ProductSearchCriteriaBuilder $searchBuilder
     * @param ResolverPipeline $pipeline
     * @param ProductFieldResolverInterface[] $fieldResolvers
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductSearchCriteriaBuilder $searchBuilder,
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
        return 'List Products';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Search catalog products with optional filters (sku, name, '
            . 'status, visibility, type_id, attribute_set_id, price range, '
            . 'qty range, category_id, website_id, created_at range, '
            . 'updated_at range, has_special_price) and paging. Each row is the same shape as '
            . '`catalog.product.get`; use `fields`/`exclude` to narrow.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(Filters::describing(
                'Filter clauses. Scalar or array values (array ⇒ IN); '
                . '`sku` also accepts a `*glob*` wildcard.',
                [
                    'sku' => ['type' => ['string', 'array'], 'description' => 'SKU: exact, `*glob*` wildcard, or array ⇒ IN.'],
                    'name' => ['type' => 'string', 'description' => 'Substring match on product name.'],
                    'status' => ['type' => 'boolean', 'description' => 'Enabled (true) or disabled (false).'],
                    'visibility' => ['type' => ['integer', 'array'], 'description' => 'Visibility id(s) (1-4).'],
                    'type_id' => ['type' => ['string', 'array'], 'description' => 'Product type code(s), e.g. "simple".'],
                    'attribute_set_id' => ['type' => ['integer', 'array'], 'description' => 'Attribute set id(s).'],
                    'category_id' => ['type' => ['integer', 'array'], 'description' => 'Category id(s) the product is assigned to.'],
                    'website_id' => ['type' => ['integer', 'array'], 'description' => 'Website id(s).'],
                    'price_from' => ['type' => 'number', 'description' => 'Minimum price.'],
                    'price_to' => ['type' => 'number', 'description' => 'Maximum price.'],
                    'qty_from' => ['type' => 'number', 'description' => 'Minimum stock quantity.'],
                    'qty_to' => ['type' => 'number', 'description' => 'Maximum stock quantity.'],
                    'created_at_from' => ['type' => 'string', 'description' => 'Created ISO date/datetime lower bound.'],
                    'created_at_to' => ['type' => 'string', 'description' => 'Created ISO date/datetime upper bound.'],
                    'updated_at_from' => ['type' => 'string', 'description' => 'Updated ISO date/datetime lower bound.'],
                    'updated_at_to' => ['type' => 'string', 'description' => 'Updated ISO date/datetime upper bound.'],
                    'has_special_price' => ['type' => 'boolean', 'description' => 'Only products with (true) / without (false) a special price set. Ignores special-price date windows.'],
                ]
            ))
            ->with(Sort::fields(ProductSearchCriteriaBuilder::SORTABLE_FIELDS, 'entity_id'))
            ->with(Pagination::maxPageSize(ProductSearchCriteriaBuilder::MAX_PAGE_SIZE))
            ->with(FieldSelection::describing(
                'Whitelist of resolver keys per row.',
                'Resolver keys to drop from each row. '
                . 'Exclude `custom_attributes` and `media` on large '
                . 'listings.'
            ))
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
        $criteria = $this->searchBuilder->build($arguments);
        $result = $this->productRepository->getList($criteria);

        $plan = $this->pipeline->plan($this->fieldResolvers, $arguments);

        $rows = [];
        foreach ($result->getItems() as $product) {
            $row = [];
            foreach ($plan as $resolver) {
                $row[$resolver->getKey()] = $resolver->resolve($product, $arguments);
            }
            $rows[] = $row;
        }

        $payload = [
            'items' => $rows,
            'total_count' => (int) $result->getTotalCount(),
            'page_size' => $criteria->getPageSize() ?? ProductSearchCriteriaBuilder::DEFAULT_PAGE_SIZE,
            'current_page' => $criteria->getCurrentPage() ?? 1,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode product list as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'row_count' => count($rows),
                'total_count' => (int) $result->getTotalCount(),
                'page' => $criteria->getCurrentPage(),
                'page_size' => $criteria->getPageSize(),
            ]
        );
    }
}
