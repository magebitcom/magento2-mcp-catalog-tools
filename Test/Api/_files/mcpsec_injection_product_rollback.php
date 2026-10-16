<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

/**
 * Rollback: delete only the `mcpsec-injection` product.
 */

use Magebit\Mcp\Test\Api\Security\InjectionPayload;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\TestFramework\Helper\Bootstrap;

$om = Bootstrap::getObjectManager();
/** @var Registry $registry */
$registry = $om->get(Registry::class);
$registry->unregister('isSecureArea');
$registry->register('isSecureArea', true);

/** @var ProductRepositoryInterface $repository */
$repository = $om->get(ProductRepositoryInterface::class);
try {
    $repository->delete($repository->get(InjectionPayload::PRODUCT_SKU, false, null, true));
} catch (NoSuchEntityException) {
    // Nothing to delete.
}

$registry->unregister('isSecureArea');
$registry->register('isSecureArea', false);
