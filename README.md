# Magento2 MCP - Catalog Tools

This is a sub-module for the [Magento2 MCP module](https://github.com/magebitcom/magento2-mcp-module)

----

Catalog-domain MCP tools for `Magebit_Mcp`. Exposes catalog **products** and
**categories** — paginated reads, single-entity reads with field-resolver-driven
shape, and writes (create / update / delete) wired through Magento service
contracts.

Each tool is a thin wrapper over the corresponding Magento service contract
(`ProductRepositoryInterface`, `CategoryRepositoryInterface`,
`CategoryListInterface`, `StockRegistryInterface`,
`CategoryManagementInterface`). Read responses are composed from field
resolvers that 3rd-party modules can extend; writes go through the same
repositories the admin UI uses, so server-side validation and reindex hooks
fire identically.

## Install

```bash
composer require magebitcom/magento2-mcp-catalog-tools
bin/magento module:enable Magebit_McpCatalogTools
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

## Tool catalog

### Products (read)

| Tool | What it does |
|---|---|
| `catalog.product.list` | Paginated product search; filter by sku (exact / `*glob*` / array), name substring, status, visibility, type_id, attribute_set_id, price range, qty range, category_id, website_id, created_at range, updated_at range, `has_special_price` (boolean; presence-only, ignores the special-price date window). |
| `catalog.product.get` | Single product by numeric id or SKU. Default response includes identity, state, pricing, tier prices, stock, categories (ids + names), websites, media gallery, links, configurable / bundle option metadata, configurable child variants (`variants` — id, sku, name, price, special_price, status per child), custom attributes, and timestamps; narrow with `fields` / `exclude`. |

### Categories (read)

| Tool | What it does |
|---|---|
| `catalog.category.list` | Paginated category search; filter by name substring, is_active, include_in_menu, parent_id, level range. |
| `catalog.category.get` | Single category by numeric id; tree metadata, content, meta, state, plus the product ids assigned to the category (drop with `exclude: ["products"]`). Reads at global/default scope unless `store_id` is given. |

### Products (write)

Write tools require the global `magebit_mcp/general/allow_writes` flag **and**
the token's own `allow_writes` flag to be `1`. All writes require explicit
confirmation so MCP clients prompt before firing.

| Tool | Confirm? | What it does |
|---|---|---|
| `catalog.product.create` | yes | Create a product. Required: sku, name, price, attribute_set_id, type_id, status, visibility (plus weight for physical types). Accepts top-level scalars (description, url_key, tax_class_id, meta_*) plus `custom_attributes`, `website_ids`, `category_ids`. Values are saved at global/default scope. |
| `catalog.product.update` | yes | PATCH-style update by id or sku; only fields you provide are touched. Use `new_sku` to rename. Saves at global/default scope; pass `store_code` for a deliberate store-view override. |
| `catalog.product.delete` | yes | Permanently delete a product. |

### Stock (write)

| Tool | Confirm? | What it does |
|---|---|---|
| `catalog.product.stock.set` | yes | Set stock levels and settings for up to 200 SKUs per call: `qty`, `is_in_stock`, `manage_stock`, `backorders`, `min_qty`, `notify_stock_qty`, `min_sale_qty`, `max_sale_qty`. Only the fields you pass per item are changed, and setting one clears its `use_config_*` flag so the value actually takes effect. Reports per-item success or failure, so one bad SKU doesn't fail the batch. |

`catalog.product.create` also accepts `qty` / `is_in_stock` so a new product
lands sellable in one call.

This writes **legacy** stock via `StockRegistryInterface`. On a multi-source
(MSI) store those writes resolve to the default source only, so each result row
carries a warning pointing at `inventory.source_item.set` — install
[`Magebit_McpInventoryTools`](https://github.com/magebitcom/magento2-mcp-inventory-tools)
to address a specific warehouse. Without that module the check assumes
single-source and stays quiet; the seam is
`Magebit\McpCatalogTools\Api\SingleSourceModeCheckerInterface`.

### Media (write)

| Tool | Confirm? | What it does |
|---|---|---|
| `catalog.product.media.add` | yes | Upload an image to a product gallery. `content_base64` takes the raw bytes base64-encoded, or an RFC 2397 `data:` URI. JPEG / PNG / GIF / WebP, 8 MB max. |
| `catalog.product.media.update` | yes | PATCH-style metadata update by `entry_id` — `label`, `position`, `disabled`, `types`. The file itself is not replaceable; remove and re-add. |
| `catalog.product.media.remove` | yes | Permanently remove a gallery entry. The file is deleted, not just unlinked. |

The image type is detected from the decoded bytes, never from the supplied
filename — a payload that doesn't decode as a real image is rejected, and the
stored extension is derived from the sniffed type. Filenames are stripped of
path components before use.

`types` assigns image roles (`image`, `small_image`, `thumbnail`,
`swatch_image`). Each role belongs to one image at a time, so assigning it here
removes it from whichever image held it before.

**Request size.** Base64 inflates a file by roughly a third, and the MCP
endpoint caps request bodies at 256 KB by default. For real product photos raise
**Stores → Configuration → Magebit → MCP Server → Max Request Body (KB)** (an
8 MB image needs about `11000`) *and* raise the matching web-server limit —
nginx `client_max_body_size` or Apache `LimitRequestBody` — otherwise the upload
is rejected before Magento sees it.

### Categories (write)

| Tool | Confirm? | What it does |
|---|---|---|
| `catalog.category.create` | yes | Create a category under an existing parent. Values are saved at global/default scope. |
| `catalog.category.update` | yes | PATCH-style update by id. Changing `parent_id` triggers a tree move via `CategoryManagementInterface::move()` (path / level rebuild); use `after_id` to control sibling ordering at the destination. Saves at global/default scope; pass `store_id` for a deliberate store-view override. |
| `catalog.category.delete` | yes | Permanently delete a category and its descendants. Cascades. |

Every write tool also implements `Magebit\Mcp\Api\UnderlyingAclAwareInterface`
with `Magento_Catalog::products` / `Magento_Catalog::categories` as the
underlying Magento admin resource, so they block calls from admins who
wouldn't be allowed to perform the same action in the admin UI.

## Upgrade notes

- **Global-scope writes by default.** `catalog.product.create` /
  `catalog.product.update` and `catalog.category.create` /
  `catalog.category.update` now save attribute values at global/default
  scope, matching REST `/all/V1/products`. Previously, calling these tools
  from the frontend area silently wrote store-view override rows instead of
  updating the default value. `catalog.product.update` gained an optional
  `store_code` argument (a store view code, or `"all"`/omitted for global)
  for intentional store-view overrides; `catalog.category.update` keeps its
  existing integer `store_id` argument for the same purpose (categories are
  always created at global scope — there is no store-scoped
  `catalog.category.create`). Override rows created by earlier versions of
  these tools are not repaired automatically — reapply the desired value at
  global scope (or the correct store view) if you need to clean one up.
  `catalog.product.get` reads the resolved store view, so a pre-existing
  store-view override row will still mask a newly written global value when
  reading back — such overrides are not repaired automatically.
- **`catalog.category.get` reads true admin scope by default.** Without a
  `store_id`, the tool now reads at admin/default scope as documented. One
  consequence: the `products.product_ids` slice always lists every product
  assigned to the category, not a store-view-filtered subset — pass
  `exclude: ["products"]` if you don't need it.
- **`catalog.product.list` has a new `has_special_price` filter** — boolean,
  presence-only (it does not evaluate `special_from_date` /
  `special_to_date`).
- **`catalog.product.get` has a new `variants` slice** for configurable
  products: id, sku, name, price, special_price, and status for each child.

## Extending

See `docs/EXTENDING.md` for:
- adding a new field to any tool response via `ProductFieldResolverInterface`
  / `CategoryFieldResolverInterface`;
- adding a new filter to `catalog.product.list` / `catalog.category.list`
  via `ProductFilterTranslatorInterface` /
  `CategoryFilterTranslatorInterface`;
- the ACL layering rules for custom write tools.

## License

Released under the [MIT License](LICENSE).

---

![Magebit](https://github.com/user-attachments/assets/cdc904ce-e839-40a0-a86f-792f7ab7961f)

Magebit - Full-service e-commerce agency

[magebit.com](https://magebit.com)
