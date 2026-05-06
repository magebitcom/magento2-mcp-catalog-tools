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
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpCatalogTools\Model\EntityFinder;
use Magebit\McpCatalogTools\Model\SecureAreaScope;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * MCP write tool `catalog.category.delete`.
 *
 * Delete cascades to every descendant in the tree — Magento does not allow
 * partial deletes of a subtree. The AI client should prompt the operator
 * for confirmation, which {@see self::getConfirmationRequired()} signals.
 */
class CategoryDelete implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'catalog.category.delete';
    public const ACL_RESOURCE = 'Magebit_McpCatalogTools::tool_catalog_category_delete';

    /**
     * @param EntityFinder $entityFinder
     * @param CategoryRepositoryInterface $categoryRepository
     * @param SecureAreaScope $secureAreaScope
     */
    public function __construct(
        private readonly EntityFinder $entityFinder,
        private readonly CategoryRepositoryInterface $categoryRepository,
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
        return 'Delete Category';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Permanently delete a catalog category and its descendants. '
            . 'Identify by `id`. NOTE: deletion cascades to every category '
            . 'nested under this node.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('id', fn (IntegerBuilder $i) => $i->minimum(1)->required())
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
        $category = $this->entityFinder->categoryFrom($arguments);
        $categoryId = (int) $category->getId();
        $name = (string) $category->getName();

        $deleted = $this->secureAreaScope->run(
            fn (): bool => $this->categoryRepository->delete($category)
        );

        $payload = [
            'deleted' => (bool) $deleted,
            'entity_id' => $categoryId,
            'name' => $name,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode delete result as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'entity_id' => $categoryId,
                'name' => $name,
                'deleted' => (bool) $deleted,
            ]
        );
    }
}
