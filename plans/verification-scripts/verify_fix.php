<?php
/**
 * Post-fix verification for SECURITY_REVIEW_2026-05-26.md Vuln 1 + Vuln 2.
 *
 * Vuln 1: catalog.product.update must NOT mutate type_id / attribute_set_id.
 *   - drives the real ProductUpdate::execute() path
 *   - asserts a type/attribute-set swap attempt is ignored, name change applies
 *
 * Vuln 2: custom_attributes must reject codes that have a dedicated validated
 *   field (would otherwise bypass typed validation), while still accepting a
 *   genuinely-uncovered EAV attribute.
 *
 * Creates and deletes a single probe product; restores all state.
 */
declare(strict_types=1);

require __DIR__ . '/../../../../../bootstrap.php';

use Magento\Framework\App\Bootstrap;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\LocalizedException;
use Magebit\McpCatalogTools\Tool\Catalog\Product\ProductFieldApplier;
use Magebit\McpCatalogTools\Tool\Catalog\Product\ProductUpdate;
use Magebit\McpCatalogTools\Model\SecureAreaScope;

$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

$repo = $om->get(ProductRepositoryInterface::class);
$factory = $om->get(ProductInterfaceFactory::class);
$applier = $om->get(ProductFieldApplier::class);
$update = $om->get(ProductUpdate::class);
$secureArea = $om->get(SecureAreaScope::class);

$sku = 'mcp-fix-probe-001';
$fail = 0;

try {
    $repo->get($sku);
    fwrite(STDERR, "ABORT: {$sku} already exists\n");
    exit(2);
} catch (NoSuchEntityException) {
}

// Seed a simple product (attr set 4).
$p = $factory->create();
$applier->applyOptional($p, [
    'sku' => $sku, 'name' => 'Fix Probe', 'attribute_set_id' => 4,
    'type_id' => 'simple', 'status' => 1, 'visibility' => 4, 'price' => 5.0, 'weight' => 1.0,
]);
$created = $repo->save($p);
echo "seed: id={$created->getId()} type_id={$created->getTypeId()} attr_set={$created->getAttributeSetId()}\n";

// --- Vuln 1: attempt type/attr-set swap through the real update tool ---
$newName = 'Fix Probe Renamed';
$update->execute([
    'sku' => $sku,
    'name' => $newName,
    'type_id' => 'configurable',   // must be ignored
    'attribute_set_id' => 9,        // must be ignored
]);
$after = $repo->get($sku, false, null, true);
echo "after update: type_id={$after->getTypeId()} attr_set={$after->getAttributeSetId()} name={$after->getName()}\n";

if ((string) $after->getTypeId() !== 'simple') {
    echo "FAIL[v1]: type_id changed to {$after->getTypeId()}\n"; $fail++;
} else {
    echo "PASS[v1]: type_id stayed 'simple'\n";
}
if ((int) $after->getAttributeSetId() !== 4) {
    echo "FAIL[v1]: attribute_set_id changed to {$after->getAttributeSetId()}\n"; $fail++;
} else {
    echo "PASS[v1]: attribute_set_id stayed 4\n";
}
if ((string) $after->getName() !== $newName) {
    echo "FAIL[v1]: legitimate name update did not apply\n"; $fail++;
} else {
    echo "PASS[v1]: legitimate name update applied (update tool still works)\n";
}

// --- Vuln 2: reserved code via custom_attributes must throw ---
try {
    $applier->applyOptional($after, ['custom_attributes' => ['tax_class_id' => '0; bypass']]);
    echo "FAIL[v2]: custom_attributes['tax_class_id'] was accepted (validation bypass)\n"; $fail++;
} catch (LocalizedException $e) {
    echo "PASS[v2]: reserved code rejected — " . $e->getMessage() . "\n";
}

// --- Vuln 2: scalars with NO dedicated schema field (cost, country_of_manufacture)
//     must remain settable via custom_attributes — they have no other entry point. ---
foreach (['cost', 'country_of_manufacture'] as $code) {
    try {
        $applier->applyOptional($after, ['custom_attributes' => [$code => 'LV']]);
        echo "PASS[v2]: '{$code}' (no schema field) still accepted via custom_attributes\n";
    } catch (LocalizedException $e) {
        echo "FAIL[v2]: '{$code}' wrongly rejected (regression) — " . $e->getMessage() . "\n"; $fail++;
    }
}
// A truly uncovered attribute code (not in any reserved list) must pass the guard.
try {
    $applier->applyOptional($after, ['custom_attributes' => ['some_uncovered_attr' => 'x']]);
    echo "PASS[v2]: uncovered EAV code passes the guard (escape hatch intact)\n";
} catch (LocalizedException $e) {
    echo "FAIL[v2]: uncovered code wrongly rejected — " . $e->getMessage() . "\n"; $fail++;
}

// --- cleanup ---
$secureArea->run(fn () => $repo->delete($after));
try {
    $repo->get($sku);
    fwrite(STDERR, "WARNING: probe still present after delete\n"); $fail++;
} catch (NoSuchEntityException) {
    echo "cleanup: {$sku} deleted\n";
}

echo $fail === 0 ? "ALL CHECKS PASSED\n" : "{$fail} CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);
