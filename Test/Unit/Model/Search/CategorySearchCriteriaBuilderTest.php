<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Test\Unit\Model\Search;

use Magebit\McpCatalogTools\Api\CategoryFilterTranslatorInterface;
use Magebit\McpCatalogTools\Model\Search\CategorySearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CategorySearchCriteriaBuilderTest extends TestCase
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

    public function testDefaultSortIsLevelAsc(): void
    {
        $this->sortBuilder->expects($this->once())->method('setField')->with('level');
        $this->sortBuilder->expects($this->once())->method('setDirection')->with(SortOrder::SORT_ASC);

        $this->builder()->build([]);
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

        $this->builder()->build(['filters' => ['name' => 'Gear']]);

        $this->assertContains(['name', '%Gear%', 'like'], $calls);
    }

    public function testIsActiveBooleanCoercesToIntFilter(): void
    {
        $this->criteriaBuilder->expects($this->atLeastOnce())
            ->method('addFilter')
            ->with('is_active', 0);

        $this->builder()->build(['filters' => ['is_active' => false]]);
    }

    public function testIncludeInMenuStringTrueCoerces(): void
    {
        $this->criteriaBuilder->expects($this->atLeastOnce())
            ->method('addFilter')
            ->with('include_in_menu', 1);

        $this->builder()->build(['filters' => ['include_in_menu' => 'true']]);
    }

    public function testParentIdArrayAddsInFilter(): void
    {
        $this->criteriaBuilder->expects($this->atLeastOnce())
            ->method('addFilter')
            ->with('parent_id', $this->equalTo([2, 3]), 'in');

        $this->builder()->build(['filters' => ['parent_id' => [2, 3]]]);
    }

    public function testLevelRangeSplitsIntoTwoFilters(): void
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
                'level_from' => 1,
                'level_to' => 3,
            ],
        ]);

        $this->assertContains(['level', '1', 'gteq'], $calls);
        $this->assertContains(['level', '3', 'lteq'], $calls);
    }

    public function testPageSizeCapsAtMax(): void
    {
        $this->criteriaBuilder->expects($this->once())
            ->method('setPageSize')
            ->with(CategorySearchCriteriaBuilder::MAX_PAGE_SIZE);

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
        $this->expectExceptionMessageMatches('/Unknown category filter: "wat"/');

        $this->builder()->build(['filters' => ['wat' => 'lol']]);
    }

    public function testTranslatorClaimsCustomKey(): void
    {
        $translator = $this->createMock(CategoryFilterTranslatorInterface::class);
        $translator->method('supports')->willReturnCallback(fn(string $k): bool => $k === 'custom_attr');
        $translator->expects($this->once())
            ->method('translate')
            ->with('custom_attr', 'val', $this->criteriaBuilder);

        $sut = new CategorySearchCriteriaBuilder(
            $this->criteriaBuilder,
            $this->sortBuilder,
            [$translator]
        );

        $sut->build(['filters' => ['custom_attr' => 'val']]);
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

        $this->builder()->build(['filters' => 'name=foo']);
    }

    private function builder(): CategorySearchCriteriaBuilder
    {
        return new CategorySearchCriteriaBuilder(
            $this->criteriaBuilder,
            $this->sortBuilder
        );
    }
}
