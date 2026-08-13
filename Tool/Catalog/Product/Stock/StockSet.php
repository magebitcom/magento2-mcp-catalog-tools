<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Tool\Catalog\Product\Stock;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\NumberBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\ObjectBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpCatalogTools\Api\SingleSourceModeCheckerInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\LocalizedException;
use Throwable;

/**
 * MCP write tool `catalog.product.stock.set`. Bulk legacy stock writes through
 * {@see StockRegistryInterface}. On a multi-source store this lands on the
 * default source only, so each item carries a warning pointing at
 * `inventory.source_item.set`.
 */
class StockSet implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'catalog.product.stock.set';
    public const ACL_RESOURCE = 'Magebit_McpCatalogTools::tool_catalog_product_stock_set';

    public const MAX_ITEMS = 200;

    private const MULTI_SOURCE_WARNING = 'This store uses multiple inventory sources; '
        . 'the write was applied to the default source only. Use '
        . '`inventory.source_item.set` to target a specific source.';

    /**
     * @param StockRegistryInterface $stockRegistry
     * @param SingleSourceModeCheckerInterface $singleSourceModeChecker
     */
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
        private readonly SingleSourceModeCheckerInterface $singleSourceModeChecker
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
        return 'Set Product Stock';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Set stock levels and stock settings for one or more products, '
            . 'by SKU. Only the fields you pass on each item are changed. '
            . 'Processes up to ' . self::MAX_ITEMS . ' items per call and reports '
            . 'per-item success or failure, so one bad SKU does not fail the '
            . 'batch. This writes legacy stock, which on a multi-source store '
            . 'resolves to the default source — use `inventory.source_item.set` '
            . 'to address a specific warehouse.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->array('items', fn (ArrayBuilder $a) => $a
                ->ofObjects(fn (ObjectBuilder $o) => $o
                    ->string('sku', fn (StringBuilder $s) => $s
                        ->minLength(1)
                        ->description('Product SKU.')
                        ->required())
                    ->number('qty', fn (NumberBuilder $n) => $n
                        ->description('Quantity on hand.'))
                    ->boolean('is_in_stock', fn (BooleanBuilder $b) => $b
                        ->description('Stock availability flag.'))
                    ->boolean('manage_stock', fn (BooleanBuilder $b) => $b
                        ->description('Whether Magento tracks quantity for this product.'))
                    ->integer('backorders', fn (IntegerBuilder $i) => $i
                        ->enum([0, 1, 2])
                        ->description('0 = no backorders, 1 = allow, 2 = allow and notify.'))
                    ->number('min_qty', fn (NumberBuilder $n) => $n
                        ->description('Quantity at which the product goes out of stock.'))
                    ->number('notify_stock_qty', fn (NumberBuilder $n) => $n
                        ->description('Low-stock notification threshold.'))
                    ->number('min_sale_qty', fn (NumberBuilder $n) => $n
                        ->minimum(0)
                        ->description('Minimum quantity allowed per order.'))
                    ->number('max_sale_qty', fn (NumberBuilder $n) => $n
                        ->minimum(0)
                        ->description('Maximum quantity allowed per order.')))
                ->minItems(1)
                ->maxItems(self::MAX_ITEMS)
                ->description('Stock changes to apply.')
                ->required())
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
        $items = $arguments['items'] ?? null;
        if (!is_array($items) || $items === []) {
            throw new LocalizedException(__('"items" must be a non-empty array.'));
        }

        $warning = $this->singleSourceModeChecker->isSingleSourceMode() ? null : self::MULTI_SOURCE_WARNING;

        $results = [];
        $saved = 0;
        $failed = 0;

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $results[] = [
                    'index' => (int) $index,
                    'sku' => null,
                    'status' => 'failed',
                    'message' => 'Item must be an object.',
                ];
                $failed++;
                continue;
            }

            $sku = isset($item['sku']) && is_scalar($item['sku']) ? (string) $item['sku'] : '';
            if ($sku === '') {
                $results[] = [
                    'index' => (int) $index,
                    'sku' => null,
                    'status' => 'failed',
                    'message' => 'Item is missing "sku".',
                ];
                $failed++;
                continue;
            }

            try {
                $results[] = $this->applyItem((int) $index, $sku, $item, $warning);
                $saved++;
            } catch (Throwable $e) {
                $results[] = [
                    'index' => (int) $index,
                    'sku' => $sku,
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ];
                $failed++;
            }
        }

        $payload = [
            'saved' => $saved,
            'failed' => $failed,
            'results' => $results,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode stock result as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'saved' => $saved,
                'failed' => $failed,
                'item_count' => count($items),
            ]
        );
    }

    /**
     * @param int $index
     * @param string $sku
     * @param array $item
     * @phpstan-param array<string, mixed> $item
     * @param string|null $warning
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    private function applyItem(int $index, string $sku, array $item, ?string $warning): array
    {
        $stockItem = $this->stockRegistry->getStockItemBySku($sku);
        $this->applyFields($stockItem, $item);

        $this->stockRegistry->updateStockItemBySku($sku, $stockItem);

        $result = [
            'index' => $index,
            'sku' => $sku,
            'status' => 'saved',
            'qty' => (float) $stockItem->getQty(),
            'is_in_stock' => (bool) $stockItem->getIsInStock(),
        ];

        if ($warning !== null) {
            $result['warning'] = $warning;
        }

        return $result;
    }

    /**
     * Setting any field explicitly also clears the matching `use_config_*` flag;
     * otherwise Magento keeps reading the global default and the write is a
     * no-op on the storefront.
     *
     * @param StockItemInterface $stockItem
     * @param array $item
     * @phpstan-param array<string, mixed> $item
     * @return void
     */
    private function applyFields(StockItemInterface $stockItem, array $item): void
    {
        if (isset($item['qty']) && is_numeric($item['qty'])) {
            $stockItem->setQty((float) $item['qty']);
        }
        if (array_key_exists('is_in_stock', $item)) {
            $stockItem->setIsInStock((bool) $item['is_in_stock']);
        }
        if (array_key_exists('manage_stock', $item)) {
            $stockItem->setUseConfigManageStock(false);
            $stockItem->setManageStock((bool) $item['manage_stock']);
        }
        if (isset($item['backorders']) && is_numeric($item['backorders'])) {
            $stockItem->setUseConfigBackorders(false);
            $stockItem->setBackorders((int) $item['backorders']);
        }
        if (isset($item['min_qty']) && is_numeric($item['min_qty'])) {
            $stockItem->setUseConfigMinQty(false);
            $stockItem->setMinQty((float) $item['min_qty']);
        }
        if (isset($item['notify_stock_qty']) && is_numeric($item['notify_stock_qty'])) {
            $stockItem->setUseConfigNotifyStockQty(false);
            $stockItem->setNotifyStockQty((float) $item['notify_stock_qty']);
        }
        if (isset($item['min_sale_qty']) && is_numeric($item['min_sale_qty'])) {
            // Magento types this one as int while its siblings take bool.
            $stockItem->setUseConfigMinSaleQty(0);
            $stockItem->setMinSaleQty((float) $item['min_sale_qty']);
        }
        if (isset($item['max_sale_qty']) && is_numeric($item['max_sale_qty'])) {
            $stockItem->setUseConfigMaxSaleQty(false);
            $stockItem->setMaxSaleQty((float) $item['max_sale_qty']);
        }
    }
}
