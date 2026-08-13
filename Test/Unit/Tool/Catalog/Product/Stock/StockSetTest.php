<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Test\Unit\Tool\Catalog\Product\Stock;

use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpCatalogTools\Api\SingleSourceModeCheckerInterface;
use Magebit\McpCatalogTools\Tool\Catalog\Product\Stock\StockSet;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;

class StockSetTest extends TestCase
{
    public function testToolMetadata(): void
    {
        $tool = $this->tool($this->createMock(StockRegistryInterface::class), true);

        $this->assertSame('catalog.product.stock.set', $tool->getName());
        $this->assertSame(
            'Magebit_McpCatalogTools::tool_catalog_product_stock_set',
            $tool->getAclResource()
        );
        $this->assertSame(WriteMode::WRITE, $tool->getWriteMode());
        $this->assertTrue($tool->getConfirmationRequired());
        $this->assertSame('Magento_Catalog::products', $tool->getUnderlyingAclResource());
    }

    public function testSchemaHasNoCompositionKeywords(): void
    {
        $schema = $this->tool($this->createMock(StockRegistryInterface::class), true)->getInputSchema();

        $this->assertSame('object', $schema['type']);
        foreach (['oneOf', 'anyOf', 'allOf', '$ref'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $schema);
        }
    }

    public function testWritesEachItemAndReportsCounts(): void
    {
        $registry = $this->createMock(StockRegistryInterface::class);
        $registry->method('getStockItemBySku')->willReturnCallback(
            fn (): StockItemInterface => $this->stockItem()
        );
        $registry->expects($this->exactly(2))->method('updateStockItemBySku');

        $result = $this->tool($registry, true)->execute([
            'items' => [
                ['sku' => 'a', 'qty' => 5],
                ['sku' => 'b', 'qty' => 0, 'is_in_stock' => false],
            ],
        ]);

        $payload = $this->decode($result->getContent());
        $this->assertSame(2, $payload['saved']);
        $this->assertSame(0, $payload['failed']);
    }

    /**
     * A SKU that does not exist must not abort the rest of the batch.
     */
    public function testOneBadSkuDoesNotFailTheBatch(): void
    {
        $registry = $this->createMock(StockRegistryInterface::class);
        $registry->method('getStockItemBySku')->willReturnCallback(
            function (string $sku): StockItemInterface {
                if ($sku === 'missing') {
                    throw new NoSuchEntityException(__('No such product.'));
                }
                return $this->stockItem();
            }
        );

        $result = $this->tool($registry, true)->execute([
            'items' => [
                ['sku' => 'good', 'qty' => 5],
                ['sku' => 'missing', 'qty' => 1],
            ],
        ]);

        $payload = $this->decode($result->getContent());
        $this->assertSame(1, $payload['saved']);
        $this->assertSame(1, $payload['failed']);
        $this->assertSame('failed', $this->resultRow($payload, 1)['status']);
    }

    public function testNoWarningInSingleSourceMode(): void
    {
        $registry = $this->createMock(StockRegistryInterface::class);
        $registry->method('getStockItemBySku')->willReturn($this->stockItem());

        $result = $this->tool($registry, true)->execute([
            'items' => [['sku' => 'a', 'qty' => 5]],
        ]);

        $payload = $this->decode($result->getContent());
        $this->assertArrayNotHasKey('warning', $this->resultRow($payload, 0));
    }

    /**
     * On a multi-source store the legacy write silently lands on the default
     * source, so the caller has to be told.
     */
    public function testWarnsWhenStoreIsMultiSource(): void
    {
        $registry = $this->createMock(StockRegistryInterface::class);
        $registry->method('getStockItemBySku')->willReturn($this->stockItem());

        $result = $this->tool($registry, false)->execute([
            'items' => [['sku' => 'a', 'qty' => 5]],
        ]);

        $payload = $this->decode($result->getContent());
        $row = $this->resultRow($payload, 0);
        $this->assertArrayHasKey('warning', $row);
        $warning = $row['warning'];
        $this->assertIsString($warning);
        $this->assertStringContainsString('inventory.source_item.set', $warning);
    }

    /**
     * Setting a field explicitly has to clear its use_config flag, otherwise
     * Magento keeps serving the global default.
     */
    public function testClearsUseConfigFlagWhenFieldIsSet(): void
    {
        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getQty')->willReturn(0.0);
        $stockItem->method('getIsInStock')->willReturn(true);
        $stockItem->expects($this->once())->method('setUseConfigBackorders')->with(false);
        $stockItem->expects($this->once())->method('setBackorders')->with(1);

        $registry = $this->createMock(StockRegistryInterface::class);
        $registry->method('getStockItemBySku')->willReturn($stockItem);

        $this->tool($registry, true)->execute([
            'items' => [['sku' => 'a', 'backorders' => 1]],
        ]);
    }

    /**
     * @param StockRegistryInterface $registry
     * @param bool $singleSource
     * @return StockSet
     */
    private function tool(StockRegistryInterface $registry, bool $singleSource): StockSet
    {
        $checker = $this->createMock(SingleSourceModeCheckerInterface::class);
        $checker->method('isSingleSourceMode')->willReturn($singleSource);

        return new StockSet($registry, $checker);
    }

    /**
     * @return StockItemInterface
     */
    private function stockItem(): StockItemInterface
    {
        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getQty')->willReturn(5.0);
        $stockItem->method('getIsInStock')->willReturn(true);

        return $stockItem;
    }

    /**
     * @param array $content
     * @phpstan-param array<int, array<string, mixed>> $content
     * @return array<string, mixed>
     */
    private function decode(array $content): array
    {
        $text = $content[0]['text'] ?? null;
        $this->assertIsString($text);

        $decoded = json_decode($text, true);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array $payload
     * @phpstan-param array<string, mixed> $payload
     * @param int $index
     * @return array<string, mixed>
     */
    private function resultRow(array $payload, int $index): array
    {
        $results = $payload['results'] ?? null;
        $this->assertIsArray($results);

        $row = $results[$index] ?? null;
        $this->assertIsArray($row);

        /** @var array<string, mixed> $row */
        return $row;
    }
}
