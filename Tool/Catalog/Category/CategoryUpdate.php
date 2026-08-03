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
use Magebit\McpCatalogTools\Model\EntityFinder;
use Magebit\McpCatalogTools\Model\StoreScope;
use Magento\Catalog\Api\CategoryManagementInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\Store;

/**
 * MCP write tool `catalog.category.update` (PATCH-style). Tree moves route
 * through {@see CategoryManagementInterface::move} because save() alone leaves
 * `path`/`level`/child counts stale on a `parent_id` change.
 */
class CategoryUpdate implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'catalog.category.update';
    public const ACL_RESOURCE = 'Magebit_McpCatalogTools::tool_catalog_category_update';

    /**
     * @param EntityFinder $entityFinder
     * @param CategoryRepositoryInterface $categoryRepository
     * @param CategoryManagementInterface $categoryManagement
     * @param CategoryFieldApplier $fieldApplier
     * @param StoreScope $storeScope
     */
    public function __construct(
        private readonly EntityFinder $entityFinder,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly CategoryManagementInterface $categoryManagement,
        private readonly CategoryFieldApplier $fieldApplier,
        private readonly StoreScope $storeScope
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
        return 'Update Category';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Partial update of a catalog category. Identify by `id`. Only '
            . 'fields you provide are touched. Changing `parent_id` moves '
            . 'the category in the tree (path / level rebuild) — use '
            . '`after_id` to control sibling ordering at the destination. '
            . 'Saves at global/default scope unless `store_id` targets a '
            . 'specific store view.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('id', fn (IntegerBuilder $i) => $i->minimum(1)->required())
            ->integer('store_id', fn (IntegerBuilder $i) => $i
                ->minimum(0)
                ->description('Store view id for an intentional store-view '
                    . 'override. Omit (or pass 0) to save at global/default '
                    . 'scope — the value then applies to every store view '
                    . 'without creating overrides.'))
            ->string('name', fn (StringBuilder $s) => $s->minLength(1))
            ->integer('parent_id', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->description('Move target. Triggers a tree rebuild via '
                    . 'CategoryManagement::move().'))
            ->integer('after_id', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->description('Optional sibling at the destination — the '
                    . 'moved category is placed immediately after it. '
                    . 'Only honoured when `parent_id` is also provided.'))
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
        $storeId = $this->entityFinder->storeIdFrom($arguments, Store::DEFAULT_STORE_ID);

        // `CategoryRepository::save()` derives the write scope from the store
        // manager's current store — not from the model — so the whole mutation
        // has to run inside the resolved scope. Without this, `/mcp`'s frontend
        // store view would receive store-view override rows.
        return $this->storeScope->run(
            $storeId,
            fn (): ToolResultInterface => $this->applyUpdate($arguments, $storeId)
        );
    }

    /**
     * Apply the patch and save. Runs inside the resolved store scope.
     *
     * @param array $arguments
     * @phpstan-param array<string, mixed> $arguments
     * @param int $storeId
     * @return ToolResultInterface
     * @throws LocalizedException
     */
    private function applyUpdate(array $arguments, int $storeId): ToolResultInterface
    {
        $category = $this->entityFinder->categoryFrom($arguments, $storeId);
        $categoryId = (int) $category->getId();
        $currentParentId = $category->getParentId() !== null
            ? (int) $category->getParentId()
            : null;

        $patch = $arguments;
        unset($patch['id'], $patch['store_id']);

        // Tree moves must go through CategoryManagement::move() — setParentId
        // + save() would leave path / level / children counts stale.
        $newParentId = null;
        if (array_key_exists('parent_id', $patch) && is_numeric($patch['parent_id'])) {
            $newParentId = (int) $patch['parent_id'];
        }
        $afterId = null;
        if (array_key_exists('after_id', $patch) && is_numeric($patch['after_id'])) {
            $afterId = (int) $patch['after_id'];
        }
        unset($patch['parent_id'], $patch['after_id']);

        if ($newParentId !== null && $newParentId !== $currentParentId) {
            $this->categoryManagement->move($categoryId, $newParentId, $afterId);
            // Reload so the field applier and response see the rebuilt path/level.
            $category = $this->entityFinder->categoryFrom(['id' => $categoryId], $storeId);
        }

        $this->fieldApplier->applyOptional($category, $patch);

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
            throw new LocalizedException(__('Failed to encode updated category as JSON.'));
        }

        $changed = array_keys($patch);
        if ($newParentId !== null && $newParentId !== $currentParentId) {
            $changed[] = 'parent_id';
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'entity_id' => (int) $saved->getId(),
                'name' => (string) $saved->getName(),
                'fields_changed' => $changed,
            ]
        );
    }
}
