<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

/**
 * Fixture: one simple product `mcpsec-injection` whose description carries the injection text.
 */

use Magebit\Mcp\Test\Api\Security\InjectionPayload;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\TestFramework\Helper\Bootstrap;

// Remove a leftover from an interrupted run first.
require __DIR__ . '/mcpsec_injection_product_rollback.php';

$om = Bootstrap::getObjectManager();
/** @var Product $product */
$product = $om->create(Product::class);
$product->setTypeId(Type::TYPE_SIMPLE)
    ->setAttributeSetId($product->getDefaultAttributeSetId())
    ->setWebsiteIds([1])
    ->setSku(InjectionPayload::PRODUCT_SKU)
    ->setName('MCPSEC injection product')
    ->setUrlKey('mcpsec-injection')
    ->setPrice(10)
    ->setDescription(InjectionPayload::TEXT)
    ->setVisibility(Visibility::VISIBILITY_BOTH)
    ->setStatus(Status::STATUS_ENABLED)
    ->setStockData(['use_config_manage_stock' => 1, 'qty' => 0, 'is_in_stock' => 0]);
$om->get(ProductRepositoryInterface::class)->save($product);
