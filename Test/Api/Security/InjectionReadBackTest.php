<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Test\Api\Security;

use Magebit\Mcp\Test\Api\McpTestCase;
use Magebit\Mcp\Test\Api\Security\InjectionPayload;
use Magebit\Mcp\Test\Api\Security\ToolPayload;

/**
 * A read tool returns stored prompt injection text as plain data; the server does not act on it.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 * @magentoApiDataFixture Magebit_McpCatalogTools::Test/Api/_files/mcpsec_injection_product.php
 */
class InjectionReadBackTest extends McpTestCase
{
    /**
     * @return void
     */
    public function testReadToolReturnsInjectionTextAsData(): void
    {
        $response = $this->toolsCall('catalog.product.get', ['sku' => InjectionPayload::PRODUCT_SKU]);

        $this->assertJsonRpcSuccess($response);
        self::assertTrue(
            ToolPayload::contains(ToolPayload::decode($response), InjectionPayload::TEXT),
            'Expected the stored injection text in the tool output.'
        );
    }
}
