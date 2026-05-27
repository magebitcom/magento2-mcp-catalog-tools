# Security Review — Magebit_McpCatalogTools

**Date:** 2026-05-26
**Reviewer:** Claude Opus 4.7 (multi-agent fan-out across 5 surfaces)
**Scope:** `app/code/Magebit/McpCatalogTools` — full module (10 tools, field-resolver pipeline, search-criteria builders, secure-area helper)
**Method:** Static analysis (5 parallel domain-focused subagents) + live exploit verification on the dockerised dev instance

The module's primary attack surface — bearer auth, JSON-RPC dispatch, ACL enforcement, audit logging, rate limiting — sits in the parent `Magebit_Mcp` module and is out of scope for this review. What this satellite contributes is a thin layer over Magento's catalog repositories: 4 read tools (with field-resolver-driven response shapes), 6 write tools (create / update / delete for products + categories), 2 search-criteria builders, and a `SecureAreaScope` helper for the deletion path. Across the SQL, DI, ACL, and read-side info-disclosure surfaces the implementation is tight — no SQL injection, no preference / plugin shenanigans, no orphan ACL resources, every write tool correctly implements `UnderlyingAclAwareInterface`, and the response shape stays in parity with what `Magento_Catalog::products` / `::categories` already permit. **One high-confidence finding survived re-review and was verified by a live exploit script** (type / attribute-set silent mutation on update). One additional medium-confidence finding (broader-than-advertised `custom_attributes` writes) is included because it diverges the tool's documented schema from its actual write surface.

---

## Vuln 1 — `catalog.product.update` silently mutates `type_id` and `attribute_set_id` post-create: `Tool/Catalog/Product/ProductUpdate.php:91-93`, `Tool/Catalog/Product/ProductFieldApplier.php:51-56`

* **Severity:** HIGH
* **Category:** business-logic bypass / data-integrity (admin-UI invariant violation)
* **Confidence:** 9/10 — verified by live exploit on 2026-05-26
* **Files:**
  * `Tool/Catalog/Product/ProductUpdate.php:91-93` — schema declares both `type_id` and `attribute_set_id` as freely settable
  * `Tool/Catalog/Product/ProductFieldApplier.php:51-56` — applier calls `setTypeId()` / `setAttributeSetId()` on the already-loaded product unconditionally
  * `Tool/Catalog/Product/ProductUpdate.php:149-166` — patch flow strips `id` and (when no `new_sku`) `sku`, but **does not strip `type_id` / `attribute_set_id`**

### Description

`ProductUpdate::getInputSchema()` advertises both `type_id` and `attribute_set_id` as optional patch fields. `ProductFieldApplier::applyOptional()` calls `setTypeId()` and `setAttributeSetId()` on the *already-loaded* product when those keys are present. Magento's admin Product Edit screen deliberately renders the Type and Attribute Set as immutable after creation: the Type dropdown is read-only, the Attribute Set selector is wrapped in a confirmation flow that re-creates rather than mutates. The repository layer (`ProductRepositoryInterface::save()`) has **no model-level guard against type swap** — it accepts whatever the caller writes. The MCP tool wraps the repository directly, so the admin-UI invariant is bypassed.

The published module README states the module's central invariant: *"Every write tool also implements `UnderlyingAclAwareInterface` … so they block calls from admins who wouldn't be allowed to perform the same action in the admin UI"* (`README.md:66-69`). The `Magento_Catalog::products` underlying ACL is binary — granted or not — and grants admin-UI *capability*, not the admin UI's read-only constraint on type / attribute set. So even a fully-allowlisted admin who would never see a writable Type field in the admin UI can issue a `catalog.product.update` that silently rewrites the row's type from `simple` to `configurable`.

The collateral damage of a silent type swap is severe: EAV value rows from the previous type are orphaned, the cached `_typeInstance` on the product model is nulled but no new type-specific support rows exist (no `catalog_product_super_link`, no bundle selections), inventory reservations are misclassified, the storefront price renderer dereferences a missing variant collection and 500s, and downstream indexers (catalog rule, stock, search) silently misclassify the row on next reindex. An attacker who can call this tool against a high-traffic product can take that product offline (storefront exception every time it's rendered) while keeping the row visible in the admin grid — a stealthier and harder-to-diagnose denial-of-product than a delete.

### Live exploit (verified 2026-05-26)

Script: `plans/verification-scripts/verify_type_id_mutation.php` — loads the Magento bootstrap, calls the same `ProductFieldApplier::applyOptional()` path the MCP tool uses, then `ProductRepositoryInterface::save()`, then performs a fresh repository reload to confirm persistence.

```
$ docker compose exec -T -u www-data php php /var/www/html/app/code/Magebit/McpCatalogTools/plans/verification-scripts/verify_type_id_mutation.php
[1] pre-check OK: SKU mcp-vuln-probe-001 not present
[2] created: id=2080 sku=mcp-vuln-probe-001 type_id=simple attribute_set_id=4
[3] post-update on the same in-memory object: type_id=configurable attribute_set_id=9
[4] FRESH RELOAD from repository: type_id=configurable attribute_set_id=9
*** CONFIRMED: type_id mutated from 'simple' to 'configurable' via catalog.product.update path ***
*** CONFIRMED: attribute_set_id mutated from 4 to 9 via catalog.product.update path ***
[5] cleanup: deleted probe entity_id=2080
[6] cleanup verified: SKU mcp-vuln-probe-001 no longer exists
DONE: all state restored
```

DB cross-check post-cleanup:

```sql
SELECT COUNT(*) FROM catalog_product_entity WHERE sku LIKE 'mcp-vuln%';
-- 0
```

### Threat model

An attacker is any operator who has been granted `Magebit_McpCatalogTools::tool_catalog_product_update` AND `Magento_Catalog::products` (the latter is held by essentially any merchant-side admin who can edit products). Token + `allow_writes` gates apply, but a normal write-enabled token issued for legitimate product-update work also unlocks this. The capability cannot be unlocked from the admin UI itself; this is a strict superset of admin-UI write power and therefore a strict violation of the module's stated invariant.

Real-world exploit shapes:
- **Stealth product takedown** — flip a high-revenue product's `type_id` to `configurable`. Admin grid still shows the product; storefront 500s on every page load.
- **Catalog corruption** — flip a configurable's `type_id` to `simple`. Variant rows in `catalog_product_super_link` orphan, the product's variant page goes blank, but the configurable row keeps its full price and image — looks fine in admin.
- **Attribute-set escape** — swap to an attribute set the original attribute did not belong to; EAV value rows become invisible to the new attribute-set's queries while remaining in the table, leaving "ghost" data that survives a re-swap back.

### Fix

In `ProductUpdate::execute()`, strip `type_id` and `attribute_set_id` from the patch before calling `applyOptional()`, alongside the existing `id` / `sku` stripping:

```php
unset($patch['id'], $patch['type_id'], $patch['attribute_set_id']);
```

And drop the two fields from `getInputSchema()` so the surface contract matches the actual write-set. If a legitimate type-change workflow ever materializes, route it through a dedicated `catalog.product.change_type` tool that re-validates the source / target combination the same way the admin UI would.

`ProductCreate` (which sets these on a fresh model) is not affected — that's the only legitimate place to set them.

---

## Vuln 2 — `custom_attributes` map writes any EAV attribute regardless of the documented top-level allowlist: `Tool/Catalog/Product/ProductFieldApplier.php:76-90`, `Tool/Catalog/Category/CategoryFieldApplier.php` (analogous block)

* **Severity:** MEDIUM
* **Category:** least-privilege / schema-vs-implementation drift
* **Confidence:** 7/10 — static analysis only, did not exploit
* **Files:**
  * `Tool/Catalog/Product/ProductFieldApplier.php:70-90` — top-level `scalarCustomAttributes()` allowlist (advertised) vs. unbounded `custom_attributes` loop
  * `Tool/Catalog/Product/ProductCreate.php:99-103`, `Tool/Catalog/Product/ProductUpdate.php:106-110` — `custom_attributes` declared with `additionalProperties: true`

### Description

`ProductFieldApplier::applyOptional()` has two write paths for EAV attributes:

1. **`scalarCustomAttributes()`** — a hard-coded set of 12 codes (`description`, `short_description`, `url_key`, `tax_class_id`, `meta_title`, `meta_keywords`, `meta_description`, `special_price`, `special_from_date`, `special_to_date`, `cost`, `country_of_manufacture`). The class-level docblock implies this is the *whitelist* for EAV writes.
2. **`custom_attributes` map** — accepts *every* string key with a scalar/null value, and calls `$product->setCustomAttribute($code, $value)` unconditionally.

`setCustomAttribute()` itself filters by `getCustomAttributesCodes()` — which on a `Product` resolves to every `eav_attribute` row that isn't in the static `ProductInterface::ATTRIBUTES` blocklist (sku, name, price, weight, status, visibility, attribute_set_id, type_id, created_at, updated_at, media_gallery, tier_price). That blocklist is exactly the 12 hard-coded *interface fields* — every merchant-defined EAV attribute (including private internal attributes the tool author never advertised as writable) is **outside** that blocklist and therefore writable.

So the practical surface of `custom_attributes` is: *every user-defined EAV attribute installed on the merchant's products*. The `scalarCustomAttributes()` allowlist is purely documentation — at runtime the schema's `additionalProperties: true` makes it advisory.

Why this is a finding even though Magento's REST `POST /V1/products` does the same thing: the MCP module advertises itself as a curated tool surface. The schema explicitly enumerates writable scalars, and the only legitimate use of `custom_attributes` per the README and class docblocks is "EAV attributes not covered by the top-level schema." Operators reading the schema would reasonably expect that opt-in to be bounded. It isn't. Any merchant who has installed sensitive product attributes (margin codes, B2B-only flags, dropship supplier ids, internal SEO tracking codes) gets them silently exposed to write through this tool.

Note: the agent's original exploit narrative claiming `custom_attributes.category_ids` would write category assignments is **incorrect**. `category_ids` is a denormalized list managed at the resource-model level (via `catalog_category_product`), not an EAV attribute — `setCustomAttribute('category_ids', $v)` silently no-ops because `category_ids` is not in `getCustomAttributesCodes()`. The real issue is the broader EAV write surface, not that specific code.

### Threat model

Same operator population as Vuln 1: anyone with the `tool_catalog_product_update` ACL plus a write-enabled token. The exposure depends on which EAV attributes the merchant has installed — on a stock Magento install with no custom EAV attributes there is no exploitable delta (every attribute the tool can write is either on `scalarCustomAttributes()` or is functionally equivalent). On any real merchant install with bespoke product attributes, the delta is the full set of those bespoke attributes.

### Fix

Replace the unbounded `custom_attributes` loop with an explicit allow-set (the same shape as `scalarCustomAttributes()` — extend or replace per tool) and change the schema's `additionalProperties: true` to a named enumeration so the documented contract matches the actual write surface. Two reasonable shapes:

* **Static allow-set:** add a configured DI array of permitted custom-attribute codes; reject any key outside the list with a clear `LocalizedException`.
* **Dynamic allow-set:** intersect incoming keys with `eav_attribute` rows where `is_user_defined=1 AND is_visible=1` for the entity type, then exclude an admin-configurable blacklist of sensitive codes (`cost` is a candidate, depending on policy).

The same change applies to `CategoryFieldApplier::applyOptional()` for the symmetric category-update path.

---

## Items reviewed and not flagged

### ACL / authorization surface
- All 10 tool `getAclResource()` return values exactly match the entries in `etc/acl.xml`. Naming convention `Magebit_McpCatalogTools::tool_catalog_*_*` is consistent throughout.
- All 6 write tools (`ProductCreate`, `ProductUpdate`, `ProductDelete`, `CategoryCreate`, `CategoryUpdate`, `CategoryDelete`) return `WriteMode::WRITE`. All 4 read tools return `WriteMode::READ`. The `allow_writes` gate is correctly engaged on every mutating tool.
- All 6 write tools implement `UnderlyingAclAwareInterface`; product writes return `Magento_Catalog::products` and category writes return `Magento_Catalog::categories` — both align with the admin-UI nodes in `vendor/magento/module-catalog/etc/acl.xml`.
- All 6 write tools return `getConfirmationRequired(): true` so MCP clients (Claude Desktop, Inspector) prompt the operator before firing destructive calls.
- `Magebit\Mcp\Model\JsonRpc\Handler\ToolsCallHandler` enforces both `getAclResource()` and (when non-null) `getUnderlyingAclResource()` via `Magento\Framework\Authorization::isAllowed()`. Missing / mistyped ACL ids fail closed (denied), not open.
- `Model/SecureAreaScope.php` engages `isSecureArea` only around the actual `delete()` call inside `ProductDelete::execute()` and `CategoryDelete::execute()`, captures the previous registry value, unregisters cleanly before re-registering (avoids `Registry::register()`'s "already exists" throw), and restores in a `finally` block. Exceptions during the wrapped delete cannot leave the FPM worker in an elevated-secure state. It does not register `Magento_Backend::admin` privileges or swap the design area — the dispatcher's ACL has already fired before `execute()` runs.
- `EntityFinder::categoryFrom()` accepts an optional `store_id` and forwards it to `CategoryRepositoryInterface::get($id, $storeId)` — this only affects which store-scoped attribute values come back, not whether the row is visible. No website-scope bypass.

### SQL / ORM / storage surface
- Zero raw SQL in production code. `grep` for `Zend_Db`, `ResourceConnection`, `$select->`, `$connection->query`, `->fetch*`, `addExpressionFieldToSelect`, `joinLeft`/`joinInner`, `->order(`, `->columns(`, `->where(` (excluding `Test/`): no production hits. Every read goes through `SearchCriteriaBuilder` → `ProductRepositoryInterface::getList` / `CategoryListInterface::getList`.
- Zero `addFieldToFilter` calls — the classic Magento "operator key as injection vector" anti-pattern is absent.
- Every `conditionType` / operator string in `Model/Search/ProductSearchCriteriaBuilder.php` and `CategorySearchCriteriaBuilder.php` is a literal (`'like'`, `'in'`, `'gteq'`, `'lteq'`). The only "variable" operator (`addRangeBoundary($field, $condition, $value)`) is called only with hard-coded `'gteq'` / `'lteq'`. User payload cannot influence the operator.
- Filter field names come from a fixed `switch` on the user-supplied key; the field name passed to `$criteriaBuilder->addFilter()` is always a class constant on a `Magento\Catalog\Api\Data\*Interface` or a project literal (`'category_id'`, `'website_id'`, `'qty'`). User key controls which case fires, never which column is queried.
- Sort field is allowlisted: `ProductSearchCriteriaBuilder::SORTABLE_FIELDS` (line 341) and `CategorySearchCriteriaBuilder::SORTABLE_FIELDS` (line 246) gate `sort_by` through `in_array(..., true)`. `sort_dir` is constrained to `'asc'` / `'desc'` after `strtolower()`. Page size is clamped at `MAX_PAGE_SIZE = 100`.
- The recently-fixed bug (commit `8028c67`) — `category_id` / `website_id` now route through Magento's native `ProductCategoryFilter` / `ProductWebsiteFilter` rather than custom translators. No other filter in the switch table is still on the old custom-translator path.

### Business-logic / write-tool surface (post Vuln 1/2)
- Top-level scalar keys in `ProductFieldApplier::applyOptional()` are an explicit allowlist with per-key type checks (`is_string`, `is_numeric`) — internal columns like `entity_id`, `row_id`, `created_at` are not in the allowlist and cannot land there.
- `setCustomAttribute()`'s upstream filter (Magento's own `getCustomAttributesCodes()` minus `ProductInterface::ATTRIBUTES`) does block the classical "set `entity_id` to escape" attacks — `entity_id`, `created_at`, `updated_at`, `attribute_set_id` (on the create path), `sku`, `type_id`, `media_gallery`, `tier_price` cannot be written through `custom_attributes`. (`vendor/magento/module-catalog/Api/Data/ProductInterface.php` const `ATTRIBUTES`.)
- `EntityFinder::requireOneOf()` correctly enforces XOR between `id` / `sku` for products and the `id` requirement for categories. `pickNumeric()` rejects negative, zero, fractional, and non-digit strings; `pickString()` rejects empty strings; `resolveStoreId()` distinguishes "not provided" from "0 (admin scope)" rather than silently coercing.
- SKU rename via `new_sku` does not silently win collision races: `ProductRepository::extractRequiredData()` normalizes SKUs (lowercase + trim) before insert, and the unique index on `catalog_product_entity.sku` makes the second writer fail rather than overwrite. Whitespace / case-variant rename to collide with an existing SKU surfaces as a duplicate-key exception.
- `ProductUpdate::execute()` strips `id` and (when no `new_sku` is provided) `sku` from the patch before applying — `entity_id` cannot be smuggled through the patch path.
- `CategoryUpdate::execute()` correctly routes a `parent_id` change through `CategoryManagementInterface::move()` rather than `setParentId()` + save, preserving tree integrity (`path`, `level`, descendant counts). `parent_id` and `after_id` are stripped from the patch before `applyOptional()` so the field applier can't accidentally double-write.
- `CategoryFieldApplier::coerceBool()` is strict — rejects `"false"`, `"true"`, `2`, `null`; accepts only real booleans and the canonical `0`/`1`/`"0"`/`"1"` forms.
- `ProductFieldApplier::applyCategoryIds()` integer-coerces, drops non-positive values, dedupes, and falls through to a clear `LocalizedException` if the product implementation is neither `Product` nor a `DataObject` descendant — no silent data loss.
- `status` / `visibility` are constrained at the JSON-schema layer (`enum([1,2])` and `enum([1,2,3,4])`) — `status=0` or `visibility=99` is rejected upstream of `applyOptional()`.
- `ProductDelete` / `CategoryDelete` resolve the entity through `EntityFinder` first; non-existent / invalid ids surface as a `NoSuchEntityException`-wrapped `LocalizedException` rather than a silent 200. No existence-oracle ambiguity beyond what the underlying repository already leaks.

### DI / events / plugins / virtual-types surface
- `etc/di.xml` declares **zero** `<preference>` elements — no core Magento interface (`ProductRepositoryInterface`, `AuthorizationInterface`, etc.) is being swapped.
- `etc/di.xml` declares **zero** `<plugin>` elements — nothing wraps `ToolRegistry`, `Dispatcher`, `TokenAuthenticator`, or any core service. No `around` plugins that could swallow `$proceed`.
- `etc/di.xml` declares **zero** `<virtualType>` declarations.
- No `etc/events.xml` — module registers zero observers; no `catalog_product_save_after` or similar side-effect hooks.
- No area-specific DI (`etc/adminhtml/di.xml`, `etc/frontend/di.xml`, `etc/webapi_rest/di.xml`, etc.). Tool classes are wired only in the global area, not exposed via admin UI controllers or storefront paths.
- No `etc/webapi.xml` — tool classes are not exposed as REST endpoints; they are reachable only through `Magebit_Mcp`'s `POST /mcp` JSON-RPC controller.
- All 10 tool keys in `<type name="Magebit\Mcp\Model\Tool\ToolRegistry">` match the `TOOL_NAME` constant returned by each class's `getName()`. ACL-validation CLI gate (`magebit:mcp:tools:validate-acl`) would catch any mismatch at CI time.
- `registration.php` is a one-liner: `ComponentRegistrar::register(MODULE, 'Magebit_McpCatalogTools', __DIR__);` — no eval, autoload manipulation, or `spl_autoload_register`.

### Read-side info-disclosure / data exposure
- `CustomAttributesResolver` skips already-covered codes to avoid duplication, then dumps `getCustomAttributes()`. Includes the `cost` attribute — but admin Product Edit > Advanced Pricing modal shows `cost` to any admin with `Catalog::products` ACL, so this is admin-UI parity, not a leak. B2B / Commerce per-company attribute restrictions are a Commerce-only concern out of OSS scope.
- `MediaResolver` returns gallery entries with `disabled=1` — admin gallery UI shows the same with a "Hide from Product Page" indicator. Parity.
- `StateResolver` (Category) / `ContentResolver` / `MetaResolver` return data for `is_active=0` categories — admin tree shows inactive categories. Parity.
- `ProductsResolver` (CategoryGet) dumps all assigned product ids — admin Category Edit > Products tab shows all assigned products. Parity. (Size concern is DoS-adjacent and excluded.)
- `CategoriesResolver` (ProductGet) exposes all category assignments — admin product edit also exposes all category assignments. Parity.
- `TierPriceResolver` exposes tiers across all websites — admin Advanced Pricing modal shows the same with a `website_id` column. Parity.
- All four read tools surface errors via `LocalizedException` — no raw exception messages or stack frames returned to clients.

---

## Suggested fix order (rough phased approach)

1. **Phase 1 — Vuln 1: lock `type_id` and `attribute_set_id` on update.** Scope: 2 unset lines in `ProductUpdate::execute()` + 2 schema-field removals in `getInputSchema()`. Risk: backwards-incompatible only for callers who relied on a documented-as-supported but admin-UI-impossible operation; reasonable to ship in a minor version with a CHANGELOG callout. Patch sketch: append to the existing `unset($patch['id'])` line.

2. **Phase 2 — Vuln 2: replace unbounded `custom_attributes` loop with an explicit allow-set.** Scope: rework `applyOptional()`'s `custom_attributes` branch in both `ProductFieldApplier` and `CategoryFieldApplier` to use a DI-configurable allow-list; update the JSON schema to enumerate accepted keys explicitly (drop `additionalProperties: true`). Risk: backwards-incompatible for callers writing custom merchant attributes — provide a documented override hook so satellite modules / projects can extend the allow-set without forking. Ship in the same minor as Vuln 1.

3. **Phase 3 — Optional hardening, defense-in-depth, no known exploit on OSS.** Consider Adobe Commerce GWS / website-scope enforcement on `applyWebsites()` if the module ever ships in a Commerce environment. The current OSS posture matches OSS admin UI (which also doesn't enforce per-admin website restrictions at the repository layer), so this is documentation rather than code today. If pursued, intersect `website_ids` with `Magento\AdminGws\Model\Role::getWebsiteIds()` when that class is present, reject the call when caller specified websites outside their role.

---

## Live-exploit state notes

Single mutation script executed during this review: `plans/verification-scripts/verify_type_id_mutation.php`.

**State touched**
- INSERT `catalog_product_entity` row for SKU `mcp-vuln-probe-001` (entity_id 2080)
- UPDATE on the same row (`type_id`: `simple` → `configurable`, `attribute_set_id`: 4 → 9)
- DELETE on the same row via `ProductRepositoryInterface::delete()` inside a `SecureAreaScope` block

**State restoration**
- Probe row deleted by the script's `finally`-shaped cleanup step. Script verified via `ProductRepositoryInterface::get($sku)` raising `NoSuchEntityException` post-delete.
- DB cross-check (separate query against the `db` container) confirmed `SELECT COUNT(*) FROM catalog_product_entity WHERE sku LIKE 'mcp-vuln%'` returns 0 after the script ran.
- AUTO_INCREMENT on `catalog_product_entity.entity_id` advanced by 1 (2080 → 2081 for the next insert). Not reset — Magento's catalog table does not assume contiguous ids, and resetting would require deeper write access than the verification policy allows.
- No admin users, tokens, config rows, ACL rules, or roles created, modified, or deleted during verification.
- No log files written outside `var/log/magebit_mcp.log` (and that file would record the script as a non-MCP-path PHP invocation, not as an MCP tool call — the script bypassed the HTTP layer).

The verification script is committable; future re-runs against a patched module should fail at step [4] with `type_id=simple` (unchanged) once Vuln 1 is fixed.
