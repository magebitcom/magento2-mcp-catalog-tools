# Extending `Magebit_McpCatalogTools`

Every read tool in this module composes its response from a DI-injected
array of **field resolvers**. Each resolver owns one named slice of the
output; 3rd parties add, replace, or remove slices from their own
`etc/di.xml` without touching this module.

The same pattern applies symmetrically to products and categories — each
with its own entity-scoped interface.

## Add a new field to `catalog.product.get` / `catalog.product.list`

### 1. Implement `ProductFieldResolverInterface`

```php
<?php
declare(strict_types=1);

namespace Vendor\Seo\Mcp\Resolver;

use Magebit\McpCatalogTools\Api\ProductFieldResolverInterface;
use Magento\Catalog\Api\Data\ProductInterface;

class CanonicalUrlResolver implements ProductFieldResolverInterface
{
    public function getKey(): string
    {
        return 'canonical_url';
    }

    public function getSortOrder(): int
    {
        // 100 = default; 50 renders earlier than built-ins, 150 later.
        return 45;
    }

    public function resolve(ProductInterface $product, array $args): array
    {
        return [
            'canonical_url' => (string) $product->getData('canonical_url'),
        ];
    }
}
```

### 2. Register the resolver in `etc/di.xml`

```xml
<type name="Magebit\McpCatalogTools\Tool\Catalog\Product\ProductGet">
    <arguments>
        <argument name="fieldResolvers" xsi:type="array">
            <item name="canonical_url" xsi:type="object">
                Vendor\Seo\Mcp\Resolver\CanonicalUrlResolver
            </item>
        </argument>
    </arguments>
</type>

<!-- Optional: also surface it on list rows. -->
<type name="Magebit\McpCatalogTools\Tool\Catalog\Product\ProductList">
    <arguments>
        <argument name="fieldResolvers" xsi:type="array">
            <item name="canonical_url" xsi:type="object">
                Vendor\Seo\Mcp\Resolver\CanonicalUrlResolver
            </item>
        </argument>
    </arguments>
</type>
```

DI-array items are merged across modules, so Magebit-shipped resolvers stay
in place — you only pay for what you add.

### 3. Run `bin/magento setup:upgrade && setup:di:compile`

No other changes needed. The next `catalog.product.get` call will include a
new `canonical_url` key.

### Opt-out from a caller

The two arguments every read tool accepts:

- `fields: ["identity", "pricing"]` — only render those keys.
- `exclude: ["media", "custom_attributes"]` — everything except those keys.

Useful when an LLM over-fetches or when a specific slice (like `media` on a
200-row listing) is expensive.

## Adding a filter translator

A filter translator handles one or more keys under a `list` tool's
`filters` argument. Built-in keys (handled inline by the search builders)
take priority; only unhandled keys fall through to translators.

Built-in filters cover the common columns on `ProductInterface` and
`CategoryInterface` — reach for a translator for custom EAV attributes,
cross-table joins, or derived predicates.

### Example — the shipped `has_special_price` filter

`catalog.product.list` answers "what is on sale?" through a translator
rather than a built-in key, because the predicate is a column *presence*
test rather than a value comparison:

```php
// Magebit/McpCatalogTools/Model/Search/FilterTranslator/HasSpecialPriceTranslator.php
namespace Magebit\McpCatalogTools\Model\Search\FilterTranslator;

use Magebit\McpCatalogTools\Api\ProductFilterTranslatorInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;

class HasSpecialPriceTranslator implements ProductFilterTranslatorInterface
{
    public function supports(string $key): bool
    {
        return $key === 'has_special_price';
    }

    /**
     * @throws LocalizedException when $value is not a boolean form.
     */
    public function translate(string $key, mixed $value, SearchCriteriaBuilder $builder): void
    {
        $builder->addFilter('special_price', true, $this->toBool($value) ? 'notnull' : 'null');
    }
}
```

```xml
<!-- Magebit/McpCatalogTools/etc/di.xml -->
<type name="Magebit\McpCatalogTools\Model\Search\ProductSearchCriteriaBuilder">
    <arguments>
        <argument name="filterTranslators" xsi:type="array">
            <item name="has_special_price" xsi:type="object">
                Magebit\McpCatalogTools\Model\Search\FilterTranslator\HasSpecialPriceTranslator
            </item>
        </argument>
    </arguments>
</type>
```

It matches on `special_price` presence only — it does not evaluate the
`special_from_date` / `special_to_date` window, so a product with an
expired or future special price still counts as "has one".

Your own translator follows the same two steps from a third-party module:
implement the interface, then merge one `<item>` into the same
`filterTranslators` argument. DI-array items merge across modules, so the
shipped translators stay registered.

Translator interfaces, by tool family:

- `Magebit\McpCatalogTools\Api\ProductFilterTranslatorInterface` — wired
  into `Model\Search\ProductSearchCriteriaBuilder`, serves
  `catalog.product.list`.
- `Magebit\McpCatalogTools\Api\CategoryFilterTranslatorInterface` — wired
  into `Model\Search\CategorySearchCriteriaBuilder`, serves
  `catalog.category.list`.

Both receive a `Magento\Framework\Api\SearchCriteriaBuilder`.

Translators are consulted in DI order. The first translator whose
`supports()` returns `true` for a given key handles it; subsequent
translators do not see that key. Unsupported filter keys fail fast with
`INVALID_PARAMS` rather than being silently ignored — your translator must
claim the key via `supports()`.

Finally, declare the new key in the tool's `getInputSchema()` `filters`
properties map (or leave it undeclared and rely on
`additionalProperties: true`) so MCP clients discover it.

## Per-entity resolver interfaces

| Entity | Interface | Built-in tool uses it |
|---|---|---|
| Product | `ProductFieldResolverInterface` | `catalog.product.get`, `catalog.product.list` |
| Category | `CategoryFieldResolverInterface` | `catalog.category.get`, `catalog.category.list` |

Both extend the base module's `Magebit\Mcp\Api\FieldResolverInterface`
marker, so the same `ResolverPipeline` in `Magebit_Mcp` sorts + filters
them for every tool.

## Write-tool ACL layering

Every write tool in this module implements both `ToolInterface` and
`Magebit\Mcp\Api\UnderlyingAclAwareInterface`. The handler enforces two
ACL checks per call:

1. The tool's own MCP-scoped resource — e.g.
   `Magebit_McpCatalogTools::tool_catalog_product_create`.
2. The underlying Magento resource returned by
   `getUnderlyingAclResource()` — `Magento_Catalog::products` for all
   product writes, `Magento_Catalog::categories` for all category writes.

Both must pass. This preserves "MCP cannot do what the admin UI cannot" —
an MCP-only role denied `Magento_Catalog::products` cannot create, update,
or delete products via MCP, even if the MCP-specific resource was granted
by accident.

We reuse Magento's own catalog ACLs rather than invent finer-grained
resources; if you need one (e.g. "allow updates but not deletes for this
MCP client"), model it with a dedicated admin role and grant only the
MCP-side resources (`tool_catalog_product_update` without
`tool_catalog_product_delete`) — the underlying Magento ACL still gates
both uniformly.

## Custom field types / computed slices

Resolvers are free to call into any service or repository during
`resolve()` — DI is wired up by the time the tool dispatches, so heavier
slices (e.g. "resolve configurable-product children", "fetch MSI
source-level inventory") can run on demand. Keep them isolated per key so
callers can drop them with `exclude`.

The bundled `StockResolver` reads the default stock (stock_id = 1) via
`StockRegistryInterface::getStockItemBySku()`. Shops on MSI that want
source-scoped quantities should register a replacement resolver with the
same `stock` key and a lower `getSortOrder()`, or a new resolver under a
different key like `inventory_sources`.
