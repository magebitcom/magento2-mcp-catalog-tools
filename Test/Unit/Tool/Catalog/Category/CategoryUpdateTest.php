<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Test\Unit\Tool\Catalog\Category;

use Magebit\McpCatalogTools\Model\Category\PreserveInheritedValues;
use Magebit\McpCatalogTools\Model\EntityFinder;
use Magebit\McpCatalogTools\Model\StoreScope;
use Magebit\McpCatalogTools\Tool\Catalog\Category\CategoryFieldApplier;
use Magebit\McpCatalogTools\Tool\Catalog\Category\CategoryUpdate;
use Magento\Catalog\Api\CategoryManagementInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CategoryUpdateTest extends TestCase
{
    private const PARENT_BEFORE_MOVE = 2;
    private const PARENT_AFTER_MOVE = 7;
    private const PARENT_IN_OTHER_ROOT = 8;

    /** Paths as `catalog_category_entity.path` holds them; 906 is a second root. */
    private const PATHS = [
        5 => '1/2/5',
        self::PARENT_AFTER_MOVE => '1/2/7',
        self::PARENT_IN_OTHER_ROOT => '1/906/8',
    ];

    /** @var CategoryRepositoryInterface&MockObject */
    private CategoryRepositoryInterface $categoryRepository;

    /** @var CategoryManagementInterface&MockObject */
    private CategoryManagementInterface $categoryManagement;

    /** @var StoreManagerInterface&MockObject */
    private StoreManagerInterface $storeManager;

    /** @var PreserveInheritedValues&MockObject */
    private PreserveInheritedValues $preserveInheritedValues;

    private CategoryUpdate $tool;

    /** @var array<int, string> */
    private array $calls = [];

    /**
     * Stand-in for `CategoryRepository::$instances`, keyed the way the vendor
     * repository keys it: `[$categoryId][$storeId ?? 'all']`.
     *
     * @var array<int, array<int|string, CategoryInterface&MockObject>>
     */
    private array $repositoryCache = [];

    private bool $moved = false;

    /** Root category of every store view the test resolves. */
    private int $rootCategoryId = 2;

    private ?int $savedParentId = null;

    protected function setUp(): void
    {
        $this->calls = [];
        $this->repositoryCache = [];
        $this->moved = false;
        $this->savedParentId = null;
        $this->rootCategoryId = 2;

        $this->categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $this->categoryManagement = $this->createMock(CategoryManagementInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->preserveInheritedValues = $this->createMock(PreserveInheritedValues::class);

        $this->emulateRepositoryCache();
        $this->recordMoves();
        $this->recordSaves();

        // `/mcp` resolves a frontend store view; store 1 under root 2 stands in
        // for it. Any store asked for by id reports `$rootCategoryId`, so a check
        // that consults the current store instead of the requested one is caught.
        $currentStore = $this->createMock(Store::class);
        $currentStore->method('getId')->willReturn(1);
        $currentStore->method('getRootCategoryId')->willReturn(2);
        $this->storeManager->method('getStore')
            ->willReturnCallback(function ($storeId = null) use ($currentStore): Store {
                if ($storeId === null) {
                    return $currentStore;
                }
                $store = $this->createMock(Store::class);
                $store->method('getId')->willReturn((int) $storeId);
                $store->method('getRootCategoryId')->willReturnCallback(fn (): int => $this->rootCategoryId);
                return $store;
            });
        $this->storeManager->method('setCurrentStore')
            ->willReturnCallback(function ($store): void {
                $this->calls[] = 'switch:' . (string) $store;
            });

        $this->tool = new CategoryUpdate(
            new EntityFinder(
                $this->createMock(ProductRepositoryInterface::class),
                $this->categoryRepository
            ),
            $this->categoryRepository,
            $this->categoryManagement,
            $this->createMock(CategoryFieldApplier::class),
            new StoreScope($this->storeManager),
            $this->preserveInheritedValues,
            $this->storeManager
        );
    }

    public function testStoreIdDescriptionStatesGlobalDefault(): void
    {
        $schema = $this->tool->getInputSchema();
        $this->assertIsArray($schema['properties']);
        $description = $schema['properties']['store_id']['description'];
        $this->assertIsString($description);
        $this->assertStringContainsString('global', strtolower($description));
        $this->assertStringContainsString('global', strtolower($this->tool->getDescription()));
    }

    public function testDefaultsToGlobalScopeForLoadAndSave(): void
    {
        $this->tool->execute(['id' => 5, 'meta_title' => 'Global title']);

        $this->assertSame(['switch:0', 'get:0', 'save', 'switch:1'], $this->calls);
    }

    public function testExplicitStoreIdKeepsThatScope(): void
    {
        $this->tool->execute(['id' => 5, 'store_id' => 3, 'meta_title' => 'Store title']);

        $this->assertSame(['get:all', 'switch:3', 'get:3', 'save', 'switch:1'], $this->calls);
    }

    /**
     * A store view whose root category does not contain the category would get
     * an override row and a URL rewrite it can never show. Refuse before anything
     * is switched or written.
     */
    public function testStoreOutsideTheCategorysRootIsRefused(): void
    {
        $this->rootCategoryId = 906;
        $this->storeManager->expects($this->never())->method('setCurrentStore');
        $this->categoryRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('store_id 3 is under root category 906, which does not contain category 5.');

        $this->tool->execute(['id' => 5, 'store_id' => 3, 'meta_title' => 'Store title']);
    }

    /**
     * The move runs before the save, so the destination parent decides which
     * tree the store-scoped write lands in: moving a root-2 category into the
     * root-906 tree while naming it for a root-906 store is legitimate.
     */
    public function testMoveIntoTheStoresRootIsAllowed(): void
    {
        $this->rootCategoryId = 906;

        $this->tool->execute(['id' => 5, 'store_id' => 3, 'parent_id' => self::PARENT_IN_OTHER_ROOT]);

        $this->assertSame(['get:all', 'switch:3', 'get:all', 'move', 'get:3', 'save', 'switch:1'], $this->calls);
    }

    /**
     * The root id has to be a path segment, not a substring of one.
     */
    public function testRootIdMatchingOnlyAsASubstringIsStillRefused(): void
    {
        $this->rootCategoryId = 90;

        $this->expectException(LocalizedException::class);

        $this->tool->execute(['id' => 5, 'store_id' => 3, 'parent_id' => self::PARENT_IN_OTHER_ROOT]);
    }

    public function testMoveOutOfTheStoresRootIsRefused(): void
    {
        $this->rootCategoryId = 906;
        $this->categoryManagement->expects($this->never())->method('move');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('does not contain destination parent 7 of category 5.');

        $this->tool->execute(['id' => 5, 'store_id' => 3, 'parent_id' => self::PARENT_AFTER_MOVE]);
    }

    /**
     * The inherited-value reset must see the patch as applied, i.e. without
     * the identity and routing keys, and must run on the scoped instance that
     * is about to be saved.
     */
    public function testInheritedValuesAreResetOnTheSavedInstanceWithThePatch(): void
    {
        $this->preserveInheritedValues->expects($this->once())
            ->method('apply')
            ->with(
                $this->callback(fn (CategoryInterface $c): bool => $c === $this->repositoryCache[5][3]),
                ['meta_title' => 'Store title']
            )
            ->willReturnCallback(function (): void {
                $this->assertNotContains('save', $this->calls, 'reset ran after the save');
            });

        $this->tool->execute(['id' => 5, 'store_id' => 3, 'meta_title' => 'Store title']);
    }

    /**
     * A global write is not judged against the root of whatever store `/mcp`
     * resolved: a root-906 category must stay writable from a root-2 context.
     */
    public function testGlobalScopeIsNotCheckedAgainstTheCurrentStoresRoot(): void
    {
        $this->rootCategoryId = 906;

        $this->tool->execute(['id' => 5, 'meta_title' => 'Global title']);

        $this->assertSame(['switch:0', 'get:0', 'save', 'switch:1'], $this->calls);
    }

    /**
     * A move mutates only the unscoped (`'all'`) repository instance, so the
     * scoped instance must be loaded *after* the move — a scoped load taken
     * before it is a cache hit carrying pre-move tree columns, and saving it
     * writes them back, reverting the move.
     *
     * @return void
     */
    public function testMoveThenSaveDoesNotWriteBackPreMoveTreeColumns(): void
    {
        $result = $this->tool->execute(['id' => 5, 'parent_id' => self::PARENT_AFTER_MOVE]);

        $this->assertSame(
            self::PARENT_AFTER_MOVE,
            $this->savedParentId,
            'save() received a pre-move category — the move would be reverted.'
        );
        $this->assertSame(
            ['switch:0', 'get:all', 'move', 'get:0', 'save', 'switch:1'],
            $this->calls
        );

        $changed = $result->getAuditSummary()['fields_changed'];
        $this->assertIsArray($changed);
        $this->assertContains('parent_id', $changed);
    }

    public function testMoveAtAnExplicitStoreScopeIsAlsoFresh(): void
    {
        $this->tool->execute([
            'id' => 5,
            'store_id' => 3,
            'parent_id' => self::PARENT_AFTER_MOVE,
        ]);

        $this->assertSame(
            ['get:all', 'switch:3', 'get:all', 'move', 'get:3', 'save', 'switch:1'],
            $this->calls
        );
        $this->assertSame(self::PARENT_AFTER_MOVE, $this->savedParentId);
    }

    public function testParentIdMatchingTheCurrentParentDoesNotMove(): void
    {
        $this->categoryManagement->expects($this->never())->method('move');

        $result = $this->tool->execute(['id' => 5, 'parent_id' => self::PARENT_BEFORE_MOVE]);

        $this->assertSame(['switch:0', 'get:all', 'get:0', 'save', 'switch:1'], $this->calls);

        $changed = $result->getAuditSummary()['fields_changed'];
        $this->assertIsArray($changed);
        $this->assertNotContains('parent_id', $changed);
    }

    /**
     * Emulate the vendor repository's per-store instance cache: repeated `get()`
     * on one key returns the same object, while a first load on a cold key
     * reflects current DB state (post-move once the move has run).
     *
     * @return void
     */
    private function emulateRepositoryCache(): void
    {
        $this->categoryRepository->method('get')
            ->willReturnCallback(function ($categoryId, $storeId = null): CategoryInterface {
                $key = $storeId ?? 'all';
                $this->calls[] = 'get:' . (string) $key;
                if (!isset($this->repositoryCache[$categoryId][$key])) {
                    $this->repositoryCache[$categoryId][$key] = $this->categoryMock(
                        (int) $categoryId,
                        $this->moved ? self::PARENT_AFTER_MOVE : self::PARENT_BEFORE_MOVE
                    );
                }

                return $this->repositoryCache[$categoryId][$key];
            });
    }

    /**
     * @return void
     */
    private function recordMoves(): void
    {
        $this->categoryManagement->method('move')
            ->willReturnCallback(function (): bool {
                $this->calls[] = 'move';
                $this->moved = true;
                return true;
            });
    }

    /**
     * @return void
     */
    private function recordSaves(): void
    {
        $this->categoryRepository->method('save')
            ->willReturnCallback(function (CategoryInterface $category): CategoryInterface {
                $this->calls[] = 'save';
                $this->savedParentId = $category->getParentId() !== null
                    ? (int) $category->getParentId()
                    : null;
                return $category;
            });
    }

    /**
     * @param int $id
     * @param int $parentId
     * @return CategoryInterface&MockObject
     */
    private function categoryMock(int $id, int $parentId): CategoryInterface
    {
        $category = $this->createMock(CategoryInterface::class);
        $category->method('getId')->willReturn($id);
        $category->method('getName')->willReturn('Shoes');
        $category->method('getParentId')->willReturn($parentId);
        $category->method('getLevel')->willReturn(2);
        $category->method('getPath')->willReturn(self::PATHS[$id] ?? '1/2/' . $id);

        return $category;
    }
}
