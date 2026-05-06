<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Test\Unit\Model\Search;

use Magebit\McpCatalogTools\Api\ProductFilterTranslatorInterface;
use Magebit\McpCatalogTools\Model\Search\ProductSearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProductSearchCriteriaBuilderTest extends TestCase
{
    /**
     * @var SearchCriteriaBuilder&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private SearchCriteriaBuilder&MockObject $criteriaBuilder;

    /**
     * @var SortOrderBuilder&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private SortOrderBuilder&MockObject $sortBuilder;

    /**
     * @var SortOrder&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private SortOrder&MockObject $sortOrder;

    protected function setUp(): void
    {
        $this->criteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->sortBuilder = $this->createMock(SortOrderBuilder::class);
        $this->sortOrder = $this->createMock(SortOrder::class);
        $this->sortBuilder->method('setField')->willReturnSelf();
        $this->sortBuilder->method('setDirection')->willReturnSelf();
        $this->sortBuilder->method('create')->willReturn($this->sortOrder);

        $this->criteriaBuilder->method('addFilter')->willReturnSelf();
        $this->criteriaBuilder->method('addSortOrder')->willReturnSelf();
        $this->criteriaBuilder->method('setCurrentPage')->willReturnSelf();
        $this->criteriaBuilder->method('setPageSize')->willReturnSelf();
        $this->criteriaBuilder->method('create')
            ->willReturn($this->createStub(SearchCriteriaInterface::class));
    }

    public function testDefaultSortIsEntityIdDesc(): void
    {
        $this->sortBuilder->expects($this->once())->method('setField')->with('entity_id');
        $this->sortBuilder->expects($this->once())->method('setDirection')->with(SortOrder::SORT_DESC);

        $this->builder()->build([]);
    }

    public function testExactSkuAddsEqualsFilter(): void
    {
        $this->criteriaBuilder->expects($this->atLeastOnce())
            ->method('addFilter')
            ->with($this->equalTo('sku'), $this->equalTo('24-MB01'));

        $this->builder()->build(['filters' => ['sku' => '24-MB01']]);
    }

    public function testGlobSkuAddsLikeFilter(): void
    {
        $calls = [];
        $this->criteriaBuilder->expects($this->atLeastOnce())
            ->method('addFilter')
            ->willReturnCallback(function (string $field, mixed $value, ?string $cond = null) use (&$calls) {
                $calls[] = [$field, $value, $cond];
                return $this->criteriaBuilder;
            });

        $this->builder()->build(['filters' => ['sku' => '24-*']]);

        $this->assertContains(['sku', '24-%', 'like'], $calls);
    }

    public function testArraySkuAddsInFilter(): void
    {
        $this->criteriaBuilder->expects($this->atLeastOnce())
            ->method('addFilter')
            ->with('sku', $this->equalTo(['24-MB01', '24-MB04']), 'in');

        $this->builder()->build(['filters' => ['sku' => ['24-MB01', '24-MB04']]]);
    }

    public function testNameAddsWildcardLikeFilter(): void
    {
        $calls = [];
        $this->criteriaBuilder->expects($this->atLeastOnce())
            ->method('addFilter')
            ->willReturnCallback(function (string $field, mixed $value, ?string $cond = null) use (&$calls) {
                $calls[] = [$field, $value, $cond];
                return $this->criteriaBuilder;
            });

        $this->builder()->build(['filters' => ['name' => 'Joust']]);

        $this->assertContains(['name', '%Joust%', 'like'], $calls);
    }

    public function testStatusBooleanCoercesToIntFilter(): void
    {
        $this->criteriaBuilder->expects($this->atLeastOnce())
            ->method('addFilter')
            ->with('status', 1);

        $this->builder()->build(['filters' => ['status' => true]]);
    }

    public function testStatusStringTrueCoerces(): void
    {
        $this->criteriaBuilder->expects($this->atLeastOnce())
            ->method('addFilter')
            ->with('status', 1);

        $this->builder()->build(['filters' => ['status' => 'true']]);
    }

    public function testStatusRejectsNonBoolean(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/"status" must be boolean/');

        $this->builder()->build(['filters' => ['status' => 'maybe']]);
    }

    public function testVisibilityArrayAddsInFilter(): void
    {
        $this->criteriaBuilder->expects($this->atLeastOnce())
            ->method('addFilter')
            ->with('visibility', $this->equalTo([2, 4]), 'in');

        $this->builder()->build(['filters' => ['visibility' => [2, 4]]]);
    }

    public function testPriceRangeSplitsIntoTwoFilters(): void
    {
        $calls = [];
        $this->criteriaBuilder->expects($this->atLeast(2))
            ->method('addFilter')
            ->willReturnCallback(function (string $field, mixed $value, ?string $cond = null) use (&$calls) {
                $calls[] = [$field, $value, $cond];
                return $this->criteriaBuilder;
            });

        $this->builder()->build([
            'filters' => [
                'price_from' => '10',
                'price_to' => '99.99',
            ],
        ]);

        $this->assertContains(['price', '10', 'gteq'], $calls);
        $this->assertContains(['price', '99.99', 'lteq'], $calls);
    }

    public function testCreatedAtRangeSplitsIntoTwoFilters(): void
    {
        $calls = [];
        $this->criteriaBuilder->expects($this->atLeast(2))
            ->method('addFilter')
            ->willReturnCallback(function (string $field, mixed $value, ?string $cond = null) use (&$calls) {
                $calls[] = [$field, $value, $cond];
                return $this->criteriaBuilder;
            });

        $this->builder()->build([
            'filters' => [
                'created_at_from' => '2025-01-01',
                'created_at_to' => '2025-12-31',
            ],
        ]);

        $this->assertContains(['created_at', '2025-01-01', 'gteq'], $calls);
        $this->assertContains(['created_at', '2025-12-31', 'lteq'], $calls);
    }

    public function testCategoryIdMapsToCategoryIdsField(): void
    {
        $this->criteriaBuilder->expects($this->atLeastOnce())
            ->method('addFilter')
            ->with('category_ids', $this->equalTo(3));

        $this->builder()->build(['filters' => ['category_id' => 3]]);
    }

    public function testWebsiteIdMapsToWebsiteIdsField(): void
    {
        $this->criteriaBuilder->expects($this->atLeastOnce())
            ->method('addFilter')
            ->with('website_ids', $this->equalTo([1, 2]), 'in');

        $this->builder()->build(['filters' => ['website_id' => [1, 2]]]);
    }

    public function testPageSizeCapsAtMax(): void
    {
        $this->criteriaBuilder->expects($this->once())
            ->method('setPageSize')
            ->with(ProductSearchCriteriaBuilder::MAX_PAGE_SIZE);

        $this->builder()->build(['page_size' => 500]);
    }

    public function testPageDefaultsToOne(): void
    {
        $this->criteriaBuilder->expects($this->once())
            ->method('setCurrentPage')
            ->with(1);

        $this->builder()->build([]);
    }

    public function testUnknownFilterThrows(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Unknown product filter: "wat"/');

        $this->builder()->build(['filters' => ['wat' => 'lol']]);
    }

    public function testTranslatorClaimsCustomKey(): void
    {
        $translator = $this->createMock(ProductFilterTranslatorInterface::class);
        $translator->method('supports')->willReturnCallback(fn(string $k): bool => $k === 'color');
        $translator->expects($this->once())
            ->method('translate')
            ->with('color', 'red', $this->criteriaBuilder);

        $sut = new ProductSearchCriteriaBuilder(
            $this->criteriaBuilder,
            $this->sortBuilder,
            [$translator]
        );

        $sut->build(['filters' => ['color' => 'red']]);
    }

    public function testInvalidSortFieldThrows(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/"sort_by" must be one of/');

        $this->builder()->build(['sort_by' => 'not_a_real_field']);
    }

    public function testInvalidSortDirThrows(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/"sort_dir" must be/');

        $this->builder()->build(['sort_dir' => 'sideways']);
    }

    public function testFiltersMustBeArray(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Filter payload must be an object/');

        $this->builder()->build(['filters' => 'sku=foo']);
    }

    private function builder(): ProductSearchCriteriaBuilder
    {
        return new ProductSearchCriteriaBuilder(
            $this->criteriaBuilder,
            $this->sortBuilder
        );
    }
}
