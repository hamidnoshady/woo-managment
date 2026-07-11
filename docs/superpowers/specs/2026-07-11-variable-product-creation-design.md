# Variable Product Creation & Attribute Editing — Design

Status: approved

## Context

[2026-07-06-product-variations-design.md](2026-07-06-product-variations-design.md)
("Product Variations Support") shipped viewing, editing, and adding
variations for products that are **already** WooCommerce variable products
with attributes configured. It explicitly deferred attribute definition:
"variation attributes are configured on the parent product in WooCommerce
already — this feature does not build an attribute-definition UI."

That leaves a gap: there is currently no way, from this app, to turn a
product into a variable product or define its attributes in the first
place — `Wma_Products::create_product()` hardcodes `WC_Product_Simple`, and
neither `create_product()` nor `update_product()` look at a `type` or
`attributes` field. This spec closes that gap so a variable product can be
created and edited entirely from the add/edit product screens, with
variation management (already built) picking up from there.

Confirmed scope decisions:
- Both the WP plugin (`wp-plugin/woo-mgmt-agent`) and the admin app change.
- Type + attribute editing appears on both `product-wizard.php` (create)
  and `product-edit.php` (edit). Variation list/add stays edit-page-only
  (a variation needs a saved parent product id, which a brand-new product
  in the wizard doesn't have yet). Publishing a new variable product from
  the wizard redirects to its edit page instead of the wizard's normal
  post-publish destination, so the user lands where variations can be
  added.
- Attributes are simple per-product custom attributes (name + free-text,
  comma-separated options) — not WooCommerce global attribute taxonomies
  (`pa_*`). This matches what the existing variation-resolving code
  (`resolve_variation_attributes()`) already handles generically.

## Backend: WP plugin (`wp-plugin/woo-mgmt-agent`)

### `includes/class-wma-products.php`

- `create_product(array $data)`: choose the product class from
  `$data['type'] ?? 'simple'` (`'variable' => WC_Product_Variable::class`,
  anything else → `WC_Product_Simple::class`). Instantiate, `save()` to
  get a real post id (unchanged reason: taxonomy assignment needs an id
  first), then `apply_fields()`, then `save()` again — same shape as
  today, just with a class lookup instead of a hardcoded `new
  WC_Product_Simple()`.
- `update_product(int $id, array $data)`: if `$data['type']` is present
  and differs from `$product->get_type()`, swap the type before applying
  other fields: `wp_set_object_terms($id, $data['type'], 'product_type')`
  then reload `$product = wc_get_product($id)` (WooCommerce itself changes
  a product's type this way — the `product_type` taxonomy term is what
  `wc_get_product()` uses to decide which class to instantiate). If the
  reload fails (`null`), leave the original 404 handling in
  `update_product()` as-is.
- `apply_fields()`: new branch — if `array_key_exists('attributes',
  $data)`, build a `WC_Product_Attribute[]` from `$data['attributes']`
  (`[{name, options: [...]}]`) via a new private
  `build_attributes(array $attributeDefs): array`:
  - Skip entries with an empty `name` or an empty `options` list after
    trimming (defensive — the app validates this client-side, but the
    plugin doesn't assume the app is the only caller).
  - Each `WC_Product_Attribute`: `set_id(0)` (custom, not a taxonomy
    attribute), `set_name(trim($name))`, `set_options(array_values(array_filter(array_map('trim', $options))))`,
    `set_position($index)`, `set_visible(true)`, `set_variation(true)`
    (required for `get_variation_attributes()` — and therefore the
    existing variation add/list flow — to see these attributes at all).
  - `$product->set_attributes($attributes)`.
- `product_to_array()`: add `'attributes' => self::attributes_to_array($product)`
  (new private helper) — for each of `$product->get_attributes()`, return
  `{name: $attr->get_name(), options: $attr->get_options()}` (skip
  taxonomy-backed attributes, i.e. `$attr->is_taxonomy()`, since this app
  doesn't create those and has no UI to round-trip term ids — empty array
  for a simple product or a variable product with only taxonomy
  attributes). Used by `product-edit.php` to prefill the attribute editor.

No REST route changes — `create_product`/`update_product` routes already
accept an arbitrary JSON body; they just weren't reading these two fields.

## Backend: main app

### `public/api/product.php`

- `build_product_payload()`:
  - Drop the unconditional `$data['type'] = 'simple'` for new products.
    Instead: `if (isset($body['type']) && in_array($body['type'], ['simple', 'variable'], true)) { $data['type'] = $body['type']; } elseif (!isset($body['id']) || (int) $body['id'] <= 0) { $data['type'] = 'simple'; }` —
    new products still default to simple when the client omits type
    (defensive default), but a client-supplied type (from the wizard or
    edit form) wins, and editing an existing product without touching the
    type field leaves it alone.
  - New `attributes` handling, same shape/sanitization the client already
    performs (belt-and-suspenders since this is the trust boundary):
    ```php
    if (isset($body['attributes']) && is_array($body['attributes'])) {
        $attributes = [];
        foreach ($body['attributes'] as $attr) {
            $name = trim((string) ($attr['name'] ?? ''));
            $options = array_values(array_filter(array_map(
                fn($o) => trim((string) $o),
                (array) ($attr['options'] ?? [])
            )));
            if ($name === '' || empty($options)) {
                continue;
            }
            $attributes[] = ['name' => $name, 'options' => $options];
        }
        $data['attributes'] = $attributes;
    }
    ```
- `map_product_detail()`: add `'attributes' => $product['attributes'] ?? []`
  passthrough, alongside the existing `'type' => $product['type'] ?? 'simple'`
  (already present — no change needed there).
- `describe_product_changes()`: no change — `type`/`attributes` aren't
  added to `$fieldLabels`, so a type/attribute change on an existing
  product falls through to the existing `other_details_updated` bulk-field
  summary the same way images/categories do today (add `'attributes'` to
  the existing `$bulkFields` array so it's covered rather than silently
  omitted from the change summary).

### `includes/SiteAgentClient.php`

No changes — `createProduct()`/`updateProduct()` already forward the
`$data` array as-is; `type`/`attributes` just need to be present in it,
which `build_product_payload()` now provides.

## Frontend

### `public/assets/js/app.js` (new shared helper)

Extract the variation list/render/add-form logic currently inlined in
`products.js` (`variationsCache`, `renderVariationsList`,
`renderVariationRow`, `renderAddVariationRow`,
`buildAddVariationForm`, the `.variations-toggle` wiring) into a shared
`App.renderVariationsSection(container, product, { onNotify })`-style
function so `product-edit.js` can reuse it without duplicating ~150 lines.
`products.js` calls the same shared function instead of its own copies.
This is a refactor of existing working code, not new behavior — the
rendered markup and API calls stay the same.

### `public/product-wizard.php` / `product-wizard.js`

- New field in step 1 (basic info): a Simple/Variable radio or segmented
  toggle, `id="product-type"`.
- New collapsible block, shown only when Variable is selected: repeatable
  attribute rows (`id="attributes-list"`), each with a name input and a
  comma-separated options input, plus "Add attribute"/remove-row buttons
  — plain DOM manipulation matching the existing categories/images list
  patterns already in this file.
- Client-side validation before publish: if type is Variable, require at
  least one attribute row with a non-empty name and at least one non-empty
  option (comma-split, trimmed) — block submit with an inline error
  otherwise, same pattern as the existing required-field checks.
- Publish payload gains `type` and, when variable, `attributes: [{name,
  options: [...]}]` (options split on `,`, trimmed, empty entries
  dropped).
- Post-publish: if `type === 'variable'`, redirect to
  `/product-edit.php?id={new id}` instead of the current destination;
  simple products keep today's behavior unchanged.

### `public/product-edit.php` / `product-edit.js`

- Same type toggle + attribute row editor as the wizard, prefilled on
  load from the fetched product's `type`/`attributes` (empty/Simple for a
  new unsaved product — the editor is usable but Variable is only
  meaningful once saved, same submit validation as the wizard).
- New "Variations" section, rendered via the shared `App.renderVariationsSection`
  helper, visible only when `productId > 0 && type === 'variable'` (a
  freshly-created draft with no id yet has nothing to attach variations
  to — consistent with the wizard-only-redirects-after-save decision).
  If the user switches an existing simple product to Variable and saves,
  the section appears after the save completes and the page reloads the
  product (existing edit-page reload-after-save behavior).
- Save payload includes `type` and `attributes` alongside the existing
  fields.

### i18n (`includes/i18n.php` and `public/assets/js/i18n.js`, `en` + `fa`)

New keys, added to both files' `en` and `fa` sections per the existing
mirrored-key convention: `product_type`, `type_simple`, `type_variable`,
`attributes`, `attribute_name`, `attribute_options`,
`attribute_options_hint` (comma-separated hint text),
`add_attribute`, `remove_attribute`,
`variable_product_needs_attribute` (validation message).

## Data flow summary

```
Wizard/edit form submit (type=variable)
  -> POST/PUT /api/product.php {..., type: 'variable', attributes: [{name, options}]}
  -> build_product_payload() sanitizes type + attributes
  -> SiteAgentClient::createProduct()/updateProduct() (unchanged, forwards data)
  -> WP: POST/PUT /wma/v1/products[/{id}]
  -> Wma_Products::create_product()/update_product()
       -> instantiate/reload as WC_Product_Variable
       -> apply_fields() -> build_attributes() -> set_attributes()
  -> map_product_detail() returns type + attributes
  -> wizard: redirect to product-edit.php?id=X
  -> edit page: reload shows Variations section (existing add-variation
     flow from the 2026-07-06 spec takes over from here, unchanged)
```

## Error handling

- Attribute rows with an empty name or no options are silently dropped
  client-side and server-side (not a hard error — matches how empty image
  URLs are already handled in `build_product_payload()`).
- Variable type with zero valid attribute rows is blocked client-side
  before submit (clear inline message) rather than allowed through to
  create an unusable variable product with no attributes.
- Converting an existing variable product back to Simple is allowed
  (`update_product()`'s type-swap handles either direction) but existing
  variations are left as orphaned WooCommerce data if any exist — this
  mirrors WooCommerce's own admin behavior when you change a product's
  type via the dropdown, so no special handling is added.
- `product_to_array()`'s `attributes_to_array()` failing to find any
  custom (non-taxonomy) attributes returns `[]`, not an error — the edit
  page just shows an empty attribute editor, same as a fresh product.

## Testing / verification

No test suite in this repo. `php -l` on every touched PHP file, `node
--check` on every touched JS file, then manual browser verification:

- Create a new Simple product via the wizard — confirm unchanged behavior
  (no attribute editor shown, normal redirect after publish).
- Create a new Variable product via the wizard with 2 attributes (e.g.
  Color: Red,Blue and Size: S,M) — confirm it redirects to the edit page,
  and the Variations section appears and lets you add a variation (reusing
  the existing tested add-variation flow).
- Try to publish a Variable product with no attribute rows filled in —
  confirm the inline validation blocks submit.
- Edit an existing Simple product, switch it to Variable, add attributes,
  save — confirm the Variations section appears after reload and a
  variation can be added.
- Edit an existing Variable product — confirm its attributes prefill
  correctly in the editor.
- Check both `en` and `fa` (RTL) for the new type toggle and attribute
  editor on both pages.
- Confirm `products.js`'s existing variation UI on the product list page
  still works identically after the `App.renderVariationsSection`
  extraction (no regression from the refactor).
