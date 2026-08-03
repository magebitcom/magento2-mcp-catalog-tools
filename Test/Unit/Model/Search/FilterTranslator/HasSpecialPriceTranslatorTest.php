<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Test\Unit\Model\Search\FilterTranslator;

use Magebit\McpCatalogTools\Model\Search\FilterTranslator\HasSpecialPriceTranslator;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class HasSpecialPriceTranslatorTest extends TestCase
{
    /**
     * @var HasSpecialPriceTranslator
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private HasSpecialPriceTranslator $translator;

    protected function setUp(): void
    {
        $this->translator = new HasSpecialPriceTranslator();
    }

    public function testSupportsOnlyItsKey(): void
    {
        $this->assertTrue($this->translator->supports('has_special_price'));
        $this->assertFalse($this->translator->supports('sku'));
        $this->assertFalse($this->translator->supports('special_price'));
        $this->assertFalse($this->translator->supports(''));
    }

    public function testTruthyValueFiltersSpecialPriceNotNull(): void
    {
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->expects($this->once())->method('addFilter')
            ->with('special_price', true, 'notnull');
        $this->translator->translate('has_special_price', true, $builder);
    }

    public function testFalsyValueFiltersSpecialPriceNull(): void
    {
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->expects($this->once())->method('addFilter')
            ->with('special_price', true, 'null');
        $this->translator->translate('has_special_price', false, $builder);
    }

    /**
     * @param mixed $value
     * @param string $expectedCondition
     * @return void
     * @throws LocalizedException
     * @dataProvider coercibleValuesProvider
     */
    public function testScalarBooleanFormsAreCoerced(mixed $value, string $expectedCondition): void
    {
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->expects($this->once())->method('addFilter')
            ->with('special_price', true, $expectedCondition);
        $this->translator->translate('has_special_price', $value, $builder);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function coercibleValuesProvider(): array
    {
        return [
            'string true' => ['true', 'notnull'],
            'string yes' => ['yes', 'notnull'],
            'string 1' => ['1', 'notnull'],
            'int 1' => [1, 'notnull'],
            'string false' => ['false', 'null'],
            'string no' => ['no', 'null'],
            'string 0' => ['0', 'null'],
            'int 0' => [0, 'null'],
        ];
    }

    /**
     * @param mixed $value
     * @return void
     * @throws LocalizedException
     * @dataProvider invalidValuesProvider
     */
    public function testNonBooleanValueThrows(mixed $value): void
    {
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->expects($this->never())->method('addFilter');

        $this->expectException(LocalizedException::class);
        $this->translator->translate('has_special_price', $value, $builder);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidValuesProvider(): array
    {
        return [
            'word' => ['maybe'],
            'empty string' => [''],
            'array' => [[true]],
            'null' => [null],
            'object' => [new \stdClass()],
        ];
    }
}
