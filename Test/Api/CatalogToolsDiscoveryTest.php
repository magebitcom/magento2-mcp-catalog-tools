<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Test\Api;

use Magebit\Mcp\Test\Api\McpTestCase;

/**
 * Verifies every catalog tool is reachable through `tools/list` end-to-end.
 *
 * Catches regressions where a tool class exists but its di.xml entry has been
 * removed, the ACL resource isn't granted to adminUser, or the satellite
 * module is misconfigured.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 */
class CatalogToolsDiscoveryTest extends McpTestCase
{
    /** @var array<int, string> */
    private const READ_TOOLS = [
        'catalog.product.list',
        'catalog.product.get',
        'catalog.category.list',
        'catalog.category.get',
    ];

    /** @var array<int, string> */
    private const WRITE_TOOLS = [
        'catalog.product.create',
        'catalog.product.update',
        'catalog.product.delete',
        'catalog.category.create',
        'catalog.category.update',
        'catalog.category.delete',
    ];

    public function testAllCatalogReadToolsAppearInToolsList(): void
    {
        $response = $this->toolsList();
        $this->assertJsonRpcSuccess($response);

        $body = $response['body'];
        self::assertIsArray($body);
        $tools = $body['result']['tools'] ?? null;
        self::assertIsArray($tools);
        $names = array_column($tools, 'name');

        foreach (self::READ_TOOLS as $expected) {
            self::assertContains($expected, $names, sprintf('tools/list missing %s.', $expected));
        }
    }

    public function testAllCatalogWriteToolsAppearWhenTokenAllowsWrites(): void
    {
        // Default token has allow_writes=false, which causes write tools to be
        // filtered from tools/list (see Magebit_Mcp WriteGateTest). Re-issue the
        // token with allow_writes=true so the write tools surface.
        $this->allowWrites = true;
        $this->tearDown();
        $this->setUp();

        $response = $this->toolsList();
        $this->assertJsonRpcSuccess($response);

        $body = $response['body'];
        self::assertIsArray($body);
        $tools = $body['result']['tools'] ?? null;
        self::assertIsArray($tools);
        $names = array_column($tools, 'name');

        foreach (self::WRITE_TOOLS as $expected) {
            self::assertContains($expected, $names, sprintf('tools/list missing %s.', $expected));
        }
    }
}
