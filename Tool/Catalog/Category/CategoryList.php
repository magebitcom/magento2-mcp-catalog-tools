<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Tool\Catalog\Category;

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
use Magebit\McpCatalogTools\Api\CategoryFieldResolverInterface;
use Magebit\McpCatalogTools\Model\Search\CategorySearchCriteriaBuilder;
use Magento\Catalog\Api\CategoryListInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * MCP tool `catalog.category.list` — filtered + paged list of categories.
 * Runs through {@see CategoryListInterface} (CategoryRepository has no getList).
 */
class CategoryList implements ToolInterface
{
    public const TOOL_NAME = 'catalog.category.list';
    public const ACL_RESOURCE = 'Magebit_McpCatalogTools::tool_catalog_category_list';

    /**
     * @param CategoryListInterface $categoryList
     * @param CategorySearchCriteriaBuilder $searchBuilder
     * @param ResolverPipeline $pipeline
     * @param CategoryFieldResolverInterface[] $fieldResolvers
     */
    public function __construct(
        private readonly CategoryListInterface $categoryList,
        private readonly CategorySearchCriteriaBuilder $searchBuilder,
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
        return 'List Categories';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Search catalog categories with optional filters (name, '
            . 'is_active, include_in_menu, parent_id, level range). Each row '
            . 'is the same shape as `catalog.category.get`; use '
            . '`fields`/`exclude` to narrow.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(Filters::describing(
                'Filter clauses. Built-in keys: name '
                . '(substring), is_active (bool), include_in_menu '
                . '(bool), parent_id (scalar | array), level_from, '
                . 'level_to.'
            ))
            ->with(Sort::fields(CategorySearchCriteriaBuilder::SORTABLE_FIELDS, 'level', 'asc'))
            ->with(Pagination::maxPageSize(CategorySearchCriteriaBuilder::MAX_PAGE_SIZE))
            ->with(FieldSelection::default())
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
        $result = $this->categoryList->getList($criteria);

        $plan = $this->pipeline->plan($this->fieldResolvers, $arguments);

        $rows = [];
        foreach ($result->getItems() as $category) {
            $row = [];
            foreach ($plan as $resolver) {
                $row[$resolver->getKey()] = $resolver->resolve($category, $arguments);
            }
            $rows[] = $row;
        }

        $payload = [
            'items' => $rows,
            'total_count' => (int) $result->getTotalCount(),
            'page_size' => $criteria->getPageSize() ?? CategorySearchCriteriaBuilder::DEFAULT_PAGE_SIZE,
            'current_page' => $criteria->getCurrentPage() ?? 1,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode category list as JSON.'));
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
