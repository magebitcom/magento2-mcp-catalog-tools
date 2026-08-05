<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Test\Unit\Model;

use Magebit\McpCatalogTools\Model\StoreScope;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class StoreScopeTest extends TestCase
{
    /** @var StoreManagerInterface&MockObject */
    private StoreManagerInterface $storeManager;

    private StoreScope $scope;

    /** @var array<int, string> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->calls = [];
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->scope = new StoreScope($this->storeManager);
    }

    public function testRunsCallableAtTargetScopeAndRestoresPrevious(): void
    {
        $this->currentStoreIs(1);
        $this->recordScopeSwitches();

        $result = $this->scope->run(0, function (): string {
            $this->calls[] = 'callable';
            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(['switch:0', 'callable', 'switch:1'], $this->calls);
    }

    public function testRestoresPreviousScopeWhenCallableThrows(): void
    {
        $this->currentStoreIs(2);

        // Mock expectations are verified after the test body, so they still
        // assert the restore even though the exception aborts the method.
        $this->storeManager->expects($this->exactly(2))
            ->method('setCurrentStore')
            ->withConsecutive([$this->identicalTo(0)], [$this->identicalTo(2)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $this->scope->run(0, function (): void {
            throw new RuntimeException('boom');
        });
    }

    public function testDoesNotSwitchWhenAlreadyAtTargetScope(): void
    {
        $this->currentStoreIs(0);
        $this->storeManager->expects($this->never())->method('setCurrentStore');

        $this->assertSame('done', $this->scope->run(0, fn (): string => 'done'));
    }

    /**
     * @param int $storeId
     * @return void
     */
    private function currentStoreIs(int $storeId): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($storeId);
        $this->storeManager->method('getStore')->willReturn($store);
    }

    /**
     * @return void
     */
    private function recordScopeSwitches(): void
    {
        $this->storeManager->method('setCurrentStore')
            ->willReturnCallback(function ($store): void {
                $this->calls[] = 'switch:' . (string) $store;
            });
    }
}
