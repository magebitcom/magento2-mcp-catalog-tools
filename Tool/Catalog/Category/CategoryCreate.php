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
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * MCP write tool `catalog.category.create`. Required fields are `name` and a
 * valid `parent_id` (2 is the default root category in a vanilla install).
 */
class CategoryCreate implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'catalog.category.create';
    public const ACL_RESOURCE = 'Magebit_McpCatalogTools::tool_catalog_category_create';

    /**
     * @param CategoryRepositoryInterface $categoryRepository
     * @param CategoryInterfaceFactory $categoryFactory
     * @param CategoryFieldApplier $fieldApplier
     */
    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly CategoryInterfaceFactory $categoryFactory,
        private readonly CategoryFieldApplier $fieldApplier
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
        return 'Create Category';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Create a new catalog category. Required: `name` and '
            . '`parent_id`. Use `catalog.category.list` with '
            . '`filters: {level_to: 1}` to discover root category ids first.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('name', fn (StringBuilder $s) => $s->minLength(1)->required())
            ->integer('parent_id', fn (IntegerBuilder $i) => $i->minimum(1)->required())
            ->boolean('is_active', fn (BooleanBuilder $b) => $b)
            ->boolean('include_in_menu', fn (BooleanBuilder $b) => $b)
            ->integer('position', fn (IntegerBuilder $i) => $i->minimum(0))
            ->string('url_key', fn (StringBuilder $s) => $s)
            ->string('description', fn (StringBuilder $s) => $s)
            ->string('image', fn (StringBuilder $s) => $s)
            ->string('display_mode', fn (StringBuilder $s) => $s)
            ->string('page_layout', fn (StringBuilder $s) => $s)
            ->string('meta_title', fn (StringBuilder $s) => $s)
            ->string('meta_keywords', fn (StringBuilder $s) => $s)
            ->string('meta_description', fn (StringBuilder $s) => $s)
            ->boolean('is_anchor', fn (BooleanBuilder $b) => $b)
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
        return 'Magento_Catalog::categories';
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
        $category = $this->categoryFactory->create();

        $this->fieldApplier->applyOptional($category, $arguments);

        $saved = $this->categoryRepository->save($category);

        $payload = [
            'entity_id' => (int) $saved->getId(),
            'name' => (string) $saved->getName(),
            'parent_id' => $saved->getParentId() !== null ? (int) $saved->getParentId() : null,
            'level' => $saved->getLevel() !== null ? (int) $saved->getLevel() : null,
            'is_active' => $saved->getIsActive() !== null ? (bool) $saved->getIsActive() : null,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode created category as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'entity_id' => (int) $saved->getId(),
                'name' => (string) $saved->getName(),
                'parent_id' => $saved->getParentId() !== null ? (int) $saved->getParentId() : null,
            ]
        );
    }
}
