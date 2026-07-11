# Variable Product Creation & Attribute Editing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a variable product be created and edited entirely from the admin app — choosing Simple/Variable and defining attributes on both the add-product wizard and the edit page — so the existing (already-built) variation list/add UI has something to attach to.

**Architecture:** The WP plugin's `Wma_Products::create_product()`/`update_product()` gain product-type selection and attribute (`WC_Product_Attribute`) read/write; `public/api/product.php` passes `type`/`attributes` through instead of hardcoding `type: 'simple'`. The admin app's wizard and edit page each get a Simple/Variable toggle plus a repeatable attribute-row editor. The variation list/add UI already built into `products.js` is extracted into a shared `App.renderVariationsSection()` helper in `app.js` so `product-edit.php` can show the same "Variations" section without duplicating ~250 lines.

**Tech Stack:** PHP 8 (no framework), vanilla JS, WooCommerce `WC_Product_Variable`/`WC_Product_Attribute` native APIs, Tailwind (CDN) for styling.

## Global Constraints

- No Composer, no build step, no new dependencies (per `CLAUDE.md`).
- No test suite in this repo — verification is `php -l` / `node --check` per file, plus manual in-browser exercise of both `en` (LTR) and `fa` (RTL), as `CLAUDE.md` prescribes.
- Any new JS string read via `t(...)` must be added to **both** `includes/i18n.php` and `public/assets/js/i18n.js`, symmetric in `en`/`fa` (per `CLAUDE.md` gotchas).
- `install_json_fatal_handler()` must be called only from files under `public/api/`.
- All mutating API endpoints require `verify_csrf_api()` and follow the boilerplate order documented in `CLAUDE.md`.
- Attributes are simple per-product custom attributes (name + free-text options) — not WooCommerce global attribute taxonomies (`pa_*`).
- `product-wizard.php`'s publish handler already redirects to `/product-edit.php?id={id}` for every product (not just variable ones) — no redirect logic needs to change.
- `public/api/product.php` (PUT) and `public/api/stock.php` already work unmodified for a variation id (a variation is just a `WC_Product` by id) — this plan adds no new variation-specific write endpoints.

---

### Task 1: WP plugin — product type on create/update

**Files:**
- Modify: `wp-plugin/woo-mgmt-agent/includes/class-wma-products.php:236-254` (`create_product`, `update_product`)

**Interfaces:**
- Produces: `Wma_Products::create_product(array $data): array` now creates a `WC_Product_Variable` when `$data['type'] === 'variable'`, `WC_Product_Simple` otherwise (unchanged default).
- Produces: `Wma_Products::update_product(int $id, array $data): ?array` now switches an existing product's type when `$data['type']` differs from its current type.

- [ ] **Step 1: Add a type→class map and use it in `create_product()`**

In `wp-plugin/woo-mgmt-agent/includes/class-wma-products.php`, replace the existing `create_product()` (currently lines 236–243):

```php
    public static function create_product(array $data): array
    {
        $product = new WC_Product_Simple();
        $product->save(); // assign a real post ID before any taxonomy term assignment
        self::apply_fields($product, $data);
        $product->save();
        return self::product_to_array($product);
    }
```

with:

```php
    private const PRODUCT_TYPE_CLASSES = [
        'simple' => WC_Product_Simple::class,
        'variable' => WC_Product_Variable::class,
    ];

    public static function create_product(array $data): array
    {
        $type = (string) ($data['type'] ?? 'simple');
        $class = self::PRODUCT_TYPE_CLASSES[$type] ?? WC_Product_Simple::class;

        $product = new $class();
        $product->save(); // assign a real post ID before any taxonomy term assignment
        self::apply_fields($product, $data);
        $product->save();
        return self::product_to_array($product);
    }
```

- [ ] **Step 2: Support a type change in `update_product()`**

In the same file, replace `update_product()` (currently lines 245–254):

```php
    public static function update_product(int $id, array $data): ?array
    {
        $product = wc_get_product($id);
        if (!$product) {
            return null;
        }
        self::apply_fields($product, $data);
        $product->save();
        return self::product_to_array($product);
    }
```

with:

```php
    public static function update_product(int $id, array $data): ?array
    {
        $product = wc_get_product($id);
        if (!$product) {
            return null;
        }

        // Changing an existing product's type isn't a setter on the object
        // (WC_Product is one concrete class per type) - it's the
        // `product_type` taxonomy term, which wc_get_product() reads to
        // decide which class to instantiate. Swap the term, then reload.
        $type = (string) ($data['type'] ?? '');
        if ($type !== '' && $type !== $product->get_type() && array_key_exists($type, self::PRODUCT_TYPE_CLASSES)) {
            wp_set_object_terms($id, $type, 'product_type');
            $product = wc_get_product($id);
            if (!$product) {
                return null;
            }
        }

        self::apply_fields($product, $data);
        $product->save();
        return self::product_to_array($product);
    }
```

- [ ] **Step 3: Lint check**

Run: `php -l wp-plugin/woo-mgmt-agent/includes/class-wma-products.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add wp-plugin/woo-mgmt-agent/includes/class-wma-products.php
git commit -m "Support product type selection on create and update in WP plugin"
```

---

### Task 2: WP plugin — attribute read/write

**Files:**
- Modify: `wp-plugin/woo-mgmt-agent/includes/class-wma-products.php` (`apply_fields`, `product_to_array`)

**Interfaces:**
- Consumes: `Wma_Products::PRODUCT_TYPE_CLASSES` (Task 1, unrelated to this task but same file).
- Produces: `apply_fields()` now applies `$data['attributes']` (`[{name, options: string[]}]`) as `WC_Product_Attribute` objects when present.
- Produces: `product_to_array()` now includes `'attributes' => array<{name: string, options: string[]}>` (only custom, non-taxonomy attributes).

- [ ] **Step 1: Add attribute handling to `apply_fields()`**

In `wp-plugin/woo-mgmt-agent/includes/class-wma-products.php`, find the end of `apply_fields()` (currently lines 324–327):

```php
        if (array_key_exists('taxonomies', $data) && is_array($data['taxonomies'])) {
            self::apply_taxonomies($product, $data['taxonomies']);
        }
    }
```

Replace with:

```php
        if (array_key_exists('taxonomies', $data) && is_array($data['taxonomies'])) {
            self::apply_taxonomies($product, $data['taxonomies']);
        }
        if (array_key_exists('attributes', $data) && is_array($data['attributes'])) {
            $product->set_attributes(self::build_attributes($data['attributes']));
        }
    }

    /**
     * Builds custom (non-taxonomy) WC_Product_Attribute objects from the
     * admin app's [{name, options: string[]}] shape. `set_variation(true)`
     * is required for WooCommerce's get_variation_attributes() - and
     * therefore the existing variation list/create flow - to see these at
     * all.
     *
     * @param array<int, array{name?: mixed, options?: mixed}> $attributeDefs
     * @return WC_Product_Attribute[]
     */
    private static function build_attributes(array $attributeDefs): array
    {
        $attributes = [];
        $position = 0;
        foreach ($attributeDefs as $def) {
            $name = trim((string) ($def['name'] ?? ''));
            $options = array_values(array_filter(array_map(
                fn($o) => trim((string) $o),
                (array) ($def['options'] ?? [])
            )));
            if ($name === '' || empty($options)) {
                continue;
            }

            $attribute = new WC_Product_Attribute();
            $attribute->set_id(0);
            $attribute->set_name($name);
            $attribute->set_options($options);
            $attribute->set_position($position++);
            $attribute->set_visible(true);
            $attribute->set_variation(true);
            $attributes[] = $attribute;
        }
        return $attributes;
    }
```

- [ ] **Step 2: Expose attributes from `product_to_array()`**

In the same file, find `product_to_array()` (currently ending around line 436–441):

```php
            'status' => $product->get_status(),
            'permalink' => $product->get_permalink(),
            'taxonomies' => $taxonomies,
        ];
    }
}
```

Replace with:

```php
            'status' => $product->get_status(),
            'permalink' => $product->get_permalink(),
            'taxonomies' => $taxonomies,
            'attributes' => self::attributes_to_array($product),
        ];
    }

    /**
     * Only custom (non-taxonomy) attributes - this app has no UI to
     * round-trip global attribute term ids, so pa_* taxonomy attributes are
     * omitted rather than returned in a shape the app can't re-submit.
     *
     * @return array<int, array{name: string, options: string[]}>
     */
    private static function attributes_to_array(WC_Product $product): array
    {
        $result = [];
        foreach ($product->get_attributes() as $attribute) {
            if (!$attribute instanceof WC_Product_Attribute || $attribute->is_taxonomy()) {
                continue;
            }
            $result[] = ['name' => $attribute->get_name(), 'options' => $attribute->get_options()];
        }
        return $result;
    }
}
```

- [ ] **Step 3: Lint check**

Run: `php -l wp-plugin/woo-mgmt-agent/includes/class-wma-products.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add wp-plugin/woo-mgmt-agent/includes/class-wma-products.php
git commit -m "Read and write custom product attributes in WP plugin"
```

---

### Task 3: Main app — pass `type`/`attributes` through `product.php`

**Files:**
- Modify: `public/api/product.php` (`build_product_payload`, `map_product_detail`, `describe_product_changes`)

**Interfaces:**
- Consumes: `SiteAgentClient::createProduct(array $data)`/`updateProduct(int $id, array $data)` (unchanged — already forward `$data` as-is).
- Produces: `build_product_payload(array $body): array` now includes a sanitized `type` (only `'simple'`/`'variable'`) and `attributes` (`[{name, options: string[]}]`) when present in the request body.
- Produces: `map_product_detail(array $product): array` now includes `'attributes' => array`.

- [ ] **Step 1: Stop forcing `type` and add attribute sanitization**

In `public/api/product.php`, find the end of `build_product_payload()` (currently lines 289–301):

```php
    if (isset($body['status'])) {
        $allowed = ['publish', 'draft', 'private'];
        if (in_array($body['status'], $allowed, true)) {
            $data['status'] = $body['status'];
        }
    }

    if (!isset($body['id']) || (int) $body['id'] <= 0) {
        // New products default to a simple product type.
        $data['type'] = 'simple';
    }

    return $data;
}
```

Replace with:

```php
    if (isset($body['status'])) {
        $allowed = ['publish', 'draft', 'private'];
        if (in_array($body['status'], $allowed, true)) {
            $data['status'] = $body['status'];
        }
    }

    $allowedTypes = ['simple', 'variable'];
    if (isset($body['type']) && in_array($body['type'], $allowedTypes, true)) {
        $data['type'] = $body['type'];
    } elseif (!isset($body['id']) || (int) $body['id'] <= 0) {
        // New products default to a simple product type when the client
        // doesn't specify one.
        $data['type'] = 'simple';
    }

    if (isset($body['attributes']) && is_array($body['attributes'])) {
        $attributes = [];
        foreach ($body['attributes'] as $attr) {
            if (!is_array($attr)) {
                continue;
            }
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

    return $data;
}
```

- [ ] **Step 2: Pass attributes through `map_product_detail()`**

In the same file, find `map_product_detail()` (currently lines 200–220), specifically the `'status'`/`'permalink'` tail:

```php
        'status' => $product['status'] ?? 'publish',
        'permalink' => $product['permalink'] ?? null,
    ];
}
```

Replace with:

```php
        'status' => $product['status'] ?? 'publish',
        'permalink' => $product['permalink'] ?? null,
        'attributes' => $product['attributes'] ?? [],
    ];
}
```

- [ ] **Step 3: Cover attribute changes in the activity-log summary**

In the same file, find `describe_product_changes()`'s `$bulkFields` (currently lines 379–382):

```php
    $bulkFields = ['categories', 'images', 'short_description', 'description'];
    if (array_intersect($bulkFields, array_keys($newData))) {
        $parts[] = t('other_details_updated');
    }
```

Replace with:

```php
    $bulkFields = ['categories', 'images', 'short_description', 'description', 'attributes'];
    if (array_intersect($bulkFields, array_keys($newData))) {
        $parts[] = t('other_details_updated');
    }
```

- [ ] **Step 4: Lint check**

Run: `php -l public/api/product.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add public/api/product.php
git commit -m "Pass product type and attributes through the product API endpoint"
```

---

### Task 4: i18n keys (PHP + JS, en + fa)

**Files:**
- Modify: `includes/i18n.php`
- Modify: `public/assets/js/i18n.js`

**Interfaces:**
- Produces: translation keys `product_type`, `type_simple`, `type_variable`, `attributes`, `attribute_name`, `attribute_options`, `attribute_options_hint`, `add_attribute`, `remove_attribute`, `variable_product_needs_attribute`, `variations` available via both server-side `t()` and client-side `t()`.

- [ ] **Step 1: Add keys to `includes/i18n.php`**

In `includes/i18n.php`, inside the `'en' =>` array, right after `'log_variation_created'` (currently line 159):

```php
        'log_variation_created' => 'Created variation "%s"',
        'product_type' => 'Product type',
        'type_simple' => 'Simple',
        'type_variable' => 'Variable',
        'attributes' => 'Attributes',
        'attribute_name' => 'Attribute name',
        'attribute_options' => 'Options (comma-separated)',
        'attribute_options_hint' => 'e.g. Red, Blue, Green',
        'add_attribute' => 'Add attribute',
        'remove_attribute' => 'Remove',
        'variable_product_needs_attribute' => 'Add at least one attribute with at least one option for a variable product.',
        'variations' => 'Variations',
```

In the same file, inside the `'fa' =>` array, right after `'log_variation_created'` (currently line 582):

```php
        'log_variation_created' => 'تنوع «%s» ایجاد شد',
        'product_type' => 'نوع محصول',
        'type_simple' => 'ساده',
        'type_variable' => 'متغیر',
        'attributes' => 'ویژگی‌ها',
        'attribute_name' => 'نام ویژگی',
        'attribute_options' => 'گزینه‌ها (با کاما جدا کنید)',
        'attribute_options_hint' => 'مثلاً: قرمز، آبی، سبز',
        'add_attribute' => 'افزودن ویژگی',
        'remove_attribute' => 'حذف',
        'variable_product_needs_attribute' => 'برای محصول متغیر، حداقل یک ویژگی با یک گزینه اضافه کنید.',
        'variations' => 'تنوع‌ها',
```

- [ ] **Step 2: Add the same keys to `public/assets/js/i18n.js`**

In `public/assets/js/i18n.js`, inside the English object, right after `"saving_variation"` (currently line 118):

```js
        "saving_variation": "Saving variation...",
        "product_type": "Product type",
        "type_simple": "Simple",
        "type_variable": "Variable",
        "attributes": "Attributes",
        "attribute_name": "Attribute name",
        "attribute_options": "Options (comma-separated)",
        "attribute_options_hint": "e.g. Red, Blue, Green",
        "add_attribute": "Add attribute",
        "remove_attribute": "Remove",
        "variable_product_needs_attribute": "Add at least one attribute with at least one option for a variable product.",
        "variations": "Variations",
```

In the same file, inside the Persian object, right after `"saving_variation"` (currently line 444):

```js
        "saving_variation": "در حال ذخیره تنوع...",
        "product_type": "نوع محصول",
        "type_simple": "ساده",
        "type_variable": "متغیر",
        "attributes": "ویژگی‌ها",
        "attribute_name": "نام ویژگی",
        "attribute_options": "گزینه‌ها (با کاما جدا کنید)",
        "attribute_options_hint": "مثلاً: قرمز، آبی، سبز",
        "add_attribute": "افزودن ویژگی",
        "remove_attribute": "حذف",
        "variable_product_needs_attribute": "برای محصول متغیر، حداقل یک ویژگی با یک گزینه اضافه کنید.",
        "variations": "تنوع‌ها",
```

- [ ] **Step 3: Lint check**

Run: `php -l includes/i18n.php && node --check public/assets/js/i18n.js`
Expected: `No syntax errors detected` and no output (node --check is silent on success)

- [ ] **Step 4: Commit**

```bash
git add includes/i18n.php public/assets/js/i18n.js
git commit -m "Add product type/attribute translation keys (en/fa)"
```

---

### Task 5: Frontend — extract shared variation-section helper into `app.js`

**Files:**
- Modify: `public/assets/js/app.js` (new `App` methods + private IIFE)
- Modify: `public/assets/js/products.js` (remove now-duplicated functions, call the shared ones)

**Interfaces:**
- Consumes: `App.api`, `App.formatToman`, `t()` (all existing).
- Produces (on `App`): `App.escapeHtml(str): string`, `App.stockStatusBadge(status): string`, `App.renderPriceRow(card, product, isSelectionMode = () => false): void`, `App.showPriceEditor(card, row, product, isSelectionMode = () => false): void`, `App.wireProductElement(el, product, { isSelectionMode = () => false, onSelectChange } = {}): void`, `App.renderVariationsSection(container, product, { isSelectionMode = () => false, onSelectChange } = {}): void`.
- Behavior is unchanged from what `products.js` already does today — this task moves code, it doesn't change what renders or what any endpoint receives.

- [ ] **Step 1: Add the shared helpers to `App` in `app.js`**

In `public/assets/js/app.js`, the `App` object literal currently ends at line 505:

```js
  expandCheckedCategoryAncestors(container) {
    container.querySelectorAll('.category-checkbox:checked').forEach((checkbox) => {
      let node = checkbox.closest('.cat-children');
      while (node) {
        node.classList.remove('hidden');
        const toggle = node.previousElementSibling && node.previousElementSibling.querySelector
          ? node.previousElementSibling.querySelector('.cat-toggle')
          : null;
        if (toggle) toggle.textContent = '▾';
        node = node.parentElement ? node.parentElement.closest('.cat-children') : null;
      }
    });
  },
};
```

Replace the closing `},\n};` with `},` followed by these new methods, then the closing `};`:

```js
  expandCheckedCategoryAncestors(container) {
    container.querySelectorAll('.category-checkbox:checked').forEach((checkbox) => {
      let node = checkbox.closest('.cat-children');
      while (node) {
        node.classList.remove('hidden');
        const toggle = node.previousElementSibling && node.previousElementSibling.querySelector
          ? node.previousElementSibling.querySelector('.cat-toggle')
          : null;
        if (toggle) toggle.textContent = '▾';
        node = node.parentElement ? node.parentElement.closest('.cat-children') : null;
      }
    });
  },

  escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
  },

  stockStatusBadge(status) {
    const map = {
      instock: [t('in_stock'), 'bg-green-100 text-green-700'],
      outofstock: [t('out_of_stock'), 'bg-red-100 text-red-700'],
      onbackorder: [t('backorder'), 'bg-yellow-100 text-yellow-700'],
    };
    const [label, cls] = map[status] || [t('unknown'), 'bg-gray-100 text-gray-600'];
    return `<span class="stock-badge-wrap"><span class="text-[10px] font-medium px-1.5 py-0.5 rounded ${cls}">${label}</span></span>`;
  },

  /**
   * Renders the price for a product (or variation) card/row. Clicking the
   * price turns it into an editable field; on save, it's sent to the API
   * and the card is updated in place with a success notification.
   * `isSelectionMode` lets a caller (e.g. the product list's bulk-select
   * mode) suppress the click-to-edit while selection is active; callers
   * without a selection concept (e.g. the edit page) can omit it.
   */
  renderPriceRow(card, product, isSelectionMode = () => false) {
    const row = card.querySelector('.price-row');
    row.className = 'mt-1 flex items-center gap-2 price-row';
    const stockBadge = App.stockStatusBadge(product.stock_status);

    const priceHtml = product.on_sale && product.sale_price
      ? `<span class="text-sm font-semibold text-gray-900">${App.formatToman(product.sale_price)}</span>
         <span class="text-xs text-gray-400 line-through ml-1">${App.formatToman(product.regular_price)}</span>`
      : `<span class="text-sm font-semibold text-gray-900">${App.formatToman(product.price)}</span>`;

    row.innerHTML = `
      <button type="button" class="price-display text-left">${priceHtml}</button>
      ${stockBadge}
    `;

    row.querySelector('.price-display').addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      if (isSelectionMode()) return;
      App.showPriceEditor(card, row, product, isSelectionMode);
    });
  },

  showPriceEditor(card, row, product, isSelectionMode = () => false) {
    row.className = 'price-row price-row-editing flex flex-col gap-1.5 w-full';
    row.innerHTML = `
      <div>
        <label class="block text-[10px] text-gray-400 mb-0.5">${App.escapeHtml(t('regular_price'))}</label>
        <input type="number" inputmode="decimal" min="0" step="any"
               class="price-input regular-price-input w-full rounded-lg border border-gray-300 px-2 py-1 text-xs focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none"
               value="${App.escapeHtml(product.regular_price ?? '')}">
        <div class="regular-price-preview text-[10px] text-gray-400 mt-0.5"></div>
      </div>
      <div>
        <label class="block text-[10px] text-gray-400 mb-0.5">${App.escapeHtml(t('sale_price_optional'))}</label>
        <input type="number" inputmode="decimal" min="0" step="any"
               class="price-input sale-price-input w-full rounded-lg border border-gray-300 px-2 py-1 text-xs focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none"
               value="${App.escapeHtml(product.on_sale ? (product.sale_price ?? '') : '')}">
        <div class="sale-price-preview text-[10px] text-gray-400 mt-0.5"></div>
      </div>
      <div class="price-edit-error hidden text-[10px] text-red-600"></div>
      <div class="flex gap-1.5">
        <button type="button" class="price-save flex-1 rounded-lg bg-gray-900 text-white text-xs font-medium py-1">${App.escapeHtml(t('save'))}</button>
        <button type="button" class="price-cancel flex-1 rounded-lg border border-gray-300 text-gray-700 text-xs font-medium py-1">${App.escapeHtml(t('cancel'))}</button>
      </div>
    `;

    const regularInput = row.querySelector('.regular-price-input');
    const saleInput = row.querySelector('.sale-price-input');
    const regularPreview = row.querySelector('.regular-price-preview');
    const salePreview = row.querySelector('.sale-price-preview');
    const errorEl = row.querySelector('.price-edit-error');

    const updatePreview = (input, previewEl) => {
      const value = parseFloat(input.value);
      previewEl.textContent = !isNaN(value) && input.value.trim() !== '' ? App.formatToman(value) : '';
    };
    updatePreview(regularInput, regularPreview);
    updatePreview(saleInput, salePreview);
    regularInput.addEventListener('input', () => updatePreview(regularInput, regularPreview));
    saleInput.addEventListener('input', () => updatePreview(saleInput, salePreview));

    const showError = (message) => {
      errorEl.textContent = message;
      errorEl.classList.remove('hidden');
    };
    const clearError = () => errorEl.classList.add('hidden');

    let resolved = false;
    const cancel = () => {
      if (resolved) return;
      resolved = true;
      App.renderPriceRow(card, product, isSelectionMode);
    };

    const save = async () => {
      if (resolved) return;
      clearError();

      const regularValue = regularInput.value.trim();
      const saleValue = saleInput.value.trim();
      const regularNum = parseFloat(regularValue);
      const saleNum = saleValue === '' ? null : parseFloat(saleValue);

      if (regularValue === '' || isNaN(regularNum) || regularNum <= 0) {
        showError(t('price_required'));
        regularInput.focus();
        return;
      }
      if (saleNum !== null && (isNaN(saleNum) || saleNum < 0 || saleNum >= regularNum)) {
        showError(t('sale_price_must_be_lower'));
        saleInput.focus();
        return;
      }

      const regularChanged = regularValue !== String(product.regular_price ?? '');
      const saleChanged = saleValue !== String(product.on_sale ? (product.sale_price ?? '') : '');

      if (!regularChanged && !saleChanged) {
        cancel();
        return;
      }

      const payload = {
        id: product.id,
        regular_price: regularValue,
        sale_price: saleValue === '' ? '' : saleValue,
        before: { regular_price: product.regular_price ?? '', sale_price: product.on_sale ? (product.sale_price ?? '') : '' },
      };

      resolved = true;
      regularInput.disabled = true;
      saleInput.disabled = true;
      const saveBtn = row.querySelector('.price-save');
      const saveBtnOriginalText = saveBtn.textContent;
      saveBtn.disabled = true;
      saveBtn.textContent = t('saving');
      row.querySelector('.price-cancel').disabled = true;

      try {
        const data = await App.api('/api/product.php', {
          method: 'PUT',
          body: JSON.stringify(payload),
        });

        product.regular_price = data.item.regular_price;
        product.sale_price = data.item.sale_price;
        product.price = data.item.price;
        product.on_sale = !!(data.item.sale_price && parseFloat(data.item.sale_price) < parseFloat(data.item.regular_price));

        App.renderPriceRow(card, product, isSelectionMode);

        if (data.item.log_id) {
          App.notify(data.item.message, { logId: data.item.log_id, productId: product.id });
        }
      } catch (err) {
        resolved = false;
        App.toast(err.message, 'error');
        regularInput.disabled = false;
        saleInput.disabled = false;
        saveBtn.disabled = false;
        saveBtn.textContent = saveBtnOriginalText;
        row.querySelector('.price-cancel').disabled = false;
        showError(err.message);
      }
    };

    row.querySelector('.price-save').addEventListener('click', (e) => { e.stopPropagation(); save(); });
    row.querySelector('.price-cancel').addEventListener('click', (e) => { e.stopPropagation(); cancel(); });

    const onKeydown = (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        save();
      } else if (e.key === 'Escape') {
        e.preventDefault();
        cancel();
      }
    };

    const onFocusOut = (e) => {
      if (row.contains(e.relatedTarget)) return;
      cancel();
    };

    row.addEventListener('click', (e) => e.stopPropagation());
    row.addEventListener('keydown', onKeydown);
    row.addEventListener('focusout', onFocusOut);

    regularInput.focus();
    regularInput.select();
  },

  /**
   * Wires up the interactive bits shared by a product/variation card or
   * row: quick stock +/- and (if the markup includes a
   * `.select-checkbox`/`.checkbox-wrap`) the bulk-selection checkbox.
   * `isSelectionMode`/`onSelectChange` let a caller with a bulk-select
   * concept (the product list) participate; callers without one (the edit
   * page's variations section) can omit both and the checkbox, if present
   * in the markup, simply stays inert.
   */
  wireProductElement(el, product, { isSelectionMode = () => false, onSelectChange } = {}) {
    el.querySelectorAll('.stock-btn').forEach((btn) => {
      btn.addEventListener('click', async (e) => {
        e.preventDefault();
        e.stopPropagation();
        const delta = parseInt(btn.dataset.delta, 10);
        const otherBtn = [...el.querySelectorAll('.stock-btn')].find((b) => b !== btn);
        const qtyEl = el.querySelector('.stock-qty');
        const qtyOriginal = qtyEl.textContent;
        btn.disabled = true;
        if (otherBtn) otherBtn.disabled = true;
        qtyEl.classList.add('opacity-50');
        try {
          const result = await App.api('/api/stock.php', {
            method: 'POST',
            body: JSON.stringify({
              id: product.id,
              delta,
              current_quantity: product.stock_quantity,
              current_status: product.stock_status,
              name: product.name,
            }),
          });
          qtyEl.textContent = result.stock_quantity;
          product.stock_quantity = result.stock_quantity;
          product.stock_status = result.stock_status;
          const badgeWrap = el.querySelector('.stock-badge-wrap');
          if (badgeWrap) {
            badgeWrap.outerHTML = App.stockStatusBadge(result.stock_status);
          }
          if (result.log_id) {
            App.notify(result.message, { logId: result.log_id, productId: product.id });
          }
        } catch (err) {
          qtyEl.textContent = qtyOriginal;
          App.toast(err.message, 'error');
        } finally {
          btn.disabled = false;
          if (otherBtn) otherBtn.disabled = false;
          qtyEl.classList.remove('opacity-50');
        }
      });
    });

    const checkbox = el.querySelector('.select-checkbox');
    if (checkbox) {
      checkbox.addEventListener('change', () => {
        if (onSelectChange) onSelectChange(product.id, checkbox.checked);
      });
    }

    el.querySelectorAll('.card-link').forEach((link) => {
      link.addEventListener('click', (e) => {
        if (isSelectionMode()) {
          e.preventDefault();
          if (checkbox) {
            checkbox.checked = !checkbox.checked;
            checkbox.dispatchEvent(new Event('change'));
          }
        }
      });
    });

    if (isSelectionMode() && checkbox) {
      const wrap = el.querySelector('.checkbox-wrap');
      if (wrap) wrap.classList.remove('hidden');
    }
  },
};
```

- [ ] **Step 2: Add the private variation-rendering cluster and `App.renderVariationsSection`**

In the same file, right after the `App` object literal's closing `};` (from Step 1) and before the existing `document.addEventListener('DOMContentLoaded', ...)` block, add:

```js
/**
 * Variation list/add UI for a variable product's card, row, or edit-page
 * section. `container` must contain a `.variations-toggle` button and a
 * `.variations-list` element (both already part of the markup wherever
 * this is called). Kept in a closure so its helper functions don't leak
 * into global scope - only App.renderVariationsSection is public.
 */
(function () {
  const variationsCache = new Map();

  function renderVariationsList(listEl, product, data, isSelectionMode, onSelectChange) {
    listEl.innerHTML = '';
    (data.items || []).forEach((variation) => {
      listEl.appendChild(renderVariationRow(variation, isSelectionMode, onSelectChange));
    });
    listEl.appendChild(renderAddVariationRow(listEl, product, data.options || [], isSelectionMode, onSelectChange));
  }

  /**
   * Renders one variation as a compact row reusing the same price-click-edit
   * and stock +/- markup/handlers as a top-level product card - both
   * App.wireProductElement() and App.renderPriceRow() only ever look at
   * `.id`, `.regular_price`, `.stock_quantity`, etc. on the object they're
   * given, so a variation object (same field names) works unmodified.
   */
  function renderVariationRow(variation, isSelectionMode, onSelectChange) {
    const row = document.createElement('div');
    row.className = 'variation-row bg-gray-50 rounded-xl border border-gray-100 p-2 flex gap-2 relative';
    row.dataset.id = variation.id;

    const image = variation.image
      ? `<img src="${App.escapeHtml(variation.image)}" alt="" class="h-10 w-10 rounded-lg object-cover flex-shrink-0 bg-gray-100">`
      : `<div class="h-10 w-10 rounded-lg bg-gray-100 flex-shrink-0"></div>`;

    row.innerHTML = `
      <div class="checkbox-wrap hidden flex items-center pr-1">
        <input type="checkbox" class="select-checkbox h-4 w-4 rounded border-gray-300">
      </div>
      ${image}
      <div class="flex-1 min-w-0">
        <div class="text-xs font-medium text-gray-800">${App.escapeHtml(variation.attribute_summary || '')}</div>
        <div class="text-[10px] text-gray-400">${App.escapeHtml(variation.sku || '')}</div>
        <div class="mt-1 flex items-center gap-2 price-row"></div>
      </div>
      <div class="stock-control flex flex-col items-center justify-center gap-1 flex-shrink-0">
        <button class="stock-btn rounded-lg border border-gray-300 w-6 h-6 text-xs leading-none" data-delta="1">+</button>
        <span class="stock-qty text-[10px] font-medium text-gray-700">${variation.stock_quantity ?? '-'}</span>
        <button class="stock-btn rounded-lg border border-gray-300 w-6 h-6 text-xs leading-none" data-delta="-1">-</button>
      </div>
    `;

    App.renderPriceRow(row, variation, isSelectionMode);
    App.wireProductElement(row, variation, { isSelectionMode, onSelectChange });
    return row;
  }

  function renderAddVariationRow(listEl, product, options, isSelectionMode, onSelectChange) {
    const wrap = document.createElement('div');
    wrap.className = 'add-variation-wrap';

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'add-variation-btn text-xs text-gray-500 underline';
    btn.textContent = t('add_variation');
    wrap.appendChild(btn);

    btn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      wrap.innerHTML = '';
      wrap.appendChild(buildAddVariationForm(listEl, product, options, wrap, isSelectionMode, onSelectChange));
    });

    return wrap;
  }

  function buildAddVariationForm(listEl, product, options, wrap, isSelectionMode, onSelectChange) {
    const form = document.createElement('div');
    form.className = 'bg-white rounded-xl border border-gray-200 p-2 space-y-1.5';

    const selects = options.map((opt) => {
      const selectId = `variation-attr-${product.id}-${opt.name}`.replace(/\s+/g, '-');
      const optionsHtml = opt.options.map((v) => `<option value="${App.escapeHtml(v)}">${App.escapeHtml(v)}</option>`).join('');
      return `
        <div>
          <label class="block text-[10px] text-gray-400 mb-0.5">${App.escapeHtml(opt.name)}</label>
          <select id="${selectId}" data-attr-name="${App.escapeHtml(opt.name)}" class="variation-attr-select w-full rounded-lg border border-gray-300 px-2 py-1 text-xs">
            ${optionsHtml}
          </select>
        </div>`;
    }).join('');

    form.innerHTML = `
      ${selects}
      <div>
        <label class="block text-[10px] text-gray-400 mb-0.5">${App.escapeHtml(t('regular_price'))}</label>
        <input type="number" inputmode="decimal" min="0" step="any" class="new-variation-price w-full rounded-lg border border-gray-300 px-2 py-1 text-xs">
      </div>
      <div>
        <label class="block text-[10px] text-gray-400 mb-0.5">${App.escapeHtml(t('sale_price_optional'))}</label>
        <input type="number" inputmode="decimal" min="0" step="any" class="new-variation-sale-price w-full rounded-lg border border-gray-300 px-2 py-1 text-xs">
      </div>
      <div>
        <label class="block text-[10px] text-gray-400 mb-0.5">${App.escapeHtml(t('sku'))}</label>
        <input type="text" class="new-variation-sku w-full rounded-lg border border-gray-300 px-2 py-1 text-xs">
      </div>
      <div class="new-variation-error hidden text-[10px] text-red-600"></div>
      <div class="flex gap-1.5">
        <button type="button" class="new-variation-save flex-1 rounded-lg bg-gray-900 text-white text-xs font-medium py-1">${App.escapeHtml(t('save'))}</button>
        <button type="button" class="new-variation-cancel flex-1 rounded-lg border border-gray-300 text-gray-700 text-xs font-medium py-1">${App.escapeHtml(t('cancel'))}</button>
      </div>
    `;

    const errorEl = form.querySelector('.new-variation-error');
    const showError = (msg) => { errorEl.textContent = msg; errorEl.classList.remove('hidden'); };

    form.querySelector('.new-variation-cancel').addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      wrap.innerHTML = '';
      wrap.appendChild(renderAddVariationRow(listEl, product, options, isSelectionMode, onSelectChange));
    });

    form.querySelector('.new-variation-save').addEventListener('click', async (e) => {
      e.preventDefault();
      e.stopPropagation();
      errorEl.classList.add('hidden');

      const attributes = {};
      form.querySelectorAll('.variation-attr-select').forEach((sel) => {
        attributes[sel.dataset.attrName] = sel.value;
      });
      if (options.length === 0 || Object.values(attributes).some((v) => !v)) {
        showError(t('variation_attribute_required'));
        return;
      }

      const regularPrice = form.querySelector('.new-variation-price').value.trim();
      const salePrice = form.querySelector('.new-variation-sale-price').value.trim();
      const sku = form.querySelector('.new-variation-sku').value.trim();

      const saveBtn = form.querySelector('.new-variation-save');
      saveBtn.disabled = true;
      saveBtn.textContent = t('saving_variation');

      try {
        const payload = { product_id: product.id, attributes };
        if (regularPrice !== '') payload.regular_price = regularPrice;
        if (salePrice !== '') payload.sale_price = salePrice;
        if (sku !== '') payload.sku = sku;

        const data = await App.api('/api/product-variations.php', {
          method: 'POST',
          body: JSON.stringify(payload),
        });

        const cached = variationsCache.get(product.id) || { items: [], options };
        cached.items = [...cached.items, data.item];
        variationsCache.set(product.id, cached);

        wrap.insertAdjacentElement('beforebegin', renderVariationRow(data.item, isSelectionMode, onSelectChange));
        wrap.innerHTML = '';
        wrap.appendChild(renderAddVariationRow(listEl, product, options, isSelectionMode, onSelectChange));

        if (data.item.log_id) {
          App.notify(data.item.message, { logId: data.item.log_id });
        }
      } catch (err) {
        showError(err.message);
        saveBtn.disabled = false;
        saveBtn.textContent = t('save');
      }
    });

    return form;
  }

  App.renderVariationsSection = function (container, product, { isSelectionMode = () => false, onSelectChange } = {}) {
    const toggleBtn = container.querySelector('.variations-toggle');
    const listEl = container.querySelector('.variations-list');

    toggleBtn.addEventListener('click', async (e) => {
      e.preventDefault();
      e.stopPropagation();

      const expanded = !listEl.classList.contains('hidden');
      if (expanded) {
        listEl.classList.add('hidden');
        toggleBtn.textContent = t('show_variations');
        return;
      }

      listEl.classList.remove('hidden');
      toggleBtn.textContent = t('hide_variations');

      if (variationsCache.has(product.id)) {
        renderVariationsList(listEl, product, variationsCache.get(product.id), isSelectionMode, onSelectChange);
        return;
      }

      listEl.innerHTML = `<div class="text-xs text-gray-400">${App.escapeHtml(t('loading'))}</div>`;
      try {
        const data = await App.api(`/api/product-variations.php?product_id=${product.id}`);
        variationsCache.set(product.id, data);
        renderVariationsList(listEl, product, data, isSelectionMode, onSelectChange);
      } catch (err) {
        listEl.innerHTML = `
          <div class="text-xs text-red-600">${App.escapeHtml(t('variations_load_error'))}
            <button type="button" class="variations-retry underline ml-1">${App.escapeHtml(t('retry'))}</button>
          </div>`;
        listEl.querySelector('.variations-retry').addEventListener('click', (ev) => {
          ev.preventDefault();
          ev.stopPropagation();
          toggleBtn.textContent = t('show_variations');
          listEl.classList.add('hidden');
          toggleBtn.click();
        });
      }
    });
  };
})();
```

- [ ] **Step 3: Lint check for `app.js`**

Run: `node --check public/assets/js/app.js`
Expected: no output (success)

- [ ] **Step 4: Commit `app.js`**

```bash
git add public/assets/js/app.js
git commit -m "Extract shared price/stock/variation rendering into App"
```

- [ ] **Step 5: Update `products.js` to use the shared helpers and delete its now-duplicated code**

In `public/assets/js/products.js`:

1. Remove the module-level `const variationsCache = new Map();` (currently line 26) — it now lives inside `app.js`'s closure.

2. In `renderProductCard()` (currently lines 342–379), replace:

```js
  renderPriceRow(card, product);
  wireProductElement(card, product);
  if (product.type === 'variable') {
    wireVariationsToggle(card, product);
  }
  return card;
```

with:

```js
  App.renderPriceRow(card, product, () => state.selectionMode);
  App.wireProductElement(card, product, {
    isSelectionMode: () => state.selectionMode,
    onSelectChange: (id, checked) => {
      if (checked) state.selected.add(id); else state.selected.delete(id);
      updateSelectionBar();
    },
  });
  if (product.type === 'variable') {
    App.renderVariationsSection(card, product, {
      isSelectionMode: () => state.selectionMode,
      onSelectChange: (id, checked) => {
        if (checked) state.selected.add(id); else state.selected.delete(id);
        updateSelectionBar();
      },
    });
  }
  return card;
```

Also in the same function, the dead `const stockBadge = stockStatusBadge(product.stock_status);` line (currently line 347) — change `stockStatusBadge` to `App.stockStatusBadge`:

```js
  const stockBadge = App.stockStatusBadge(product.stock_status);
```

3. In `renderProductRow()` (currently lines 386–435), replace:

```js
  renderPriceRow(row, product);
  wireProductElement(row, product);

  if (product.type !== 'variable') {
    return row;
  }

  const toggleRow = document.createElement('tr');
  toggleRow.className = 'variations-toggle-row';
  toggleRow.innerHTML = `
    <td></td>
    <td colspan="4" class="px-3 pb-2">
      <button type="button" class="variations-toggle text-xs text-gray-500 underline">${escapeHtml(t('show_variations'))}</button>
      <div class="variations-list mt-2 space-y-2 hidden"></div>
    </td>
  `;
  wireVariationsToggle(toggleRow, product);

  const fragment = document.createDocumentFragment();
  fragment.appendChild(row);
  fragment.appendChild(toggleRow);
  return fragment;
```

with:

```js
  const isSelectionMode = () => state.selectionMode;
  const onSelectChange = (id, checked) => {
    if (checked) state.selected.add(id); else state.selected.delete(id);
    updateSelectionBar();
  };

  App.renderPriceRow(row, product, isSelectionMode);
  App.wireProductElement(row, product, { isSelectionMode, onSelectChange });

  if (product.type !== 'variable') {
    return row;
  }

  const toggleRow = document.createElement('tr');
  toggleRow.className = 'variations-toggle-row';
  toggleRow.innerHTML = `
    <td></td>
    <td colspan="4" class="px-3 pb-2">
      <button type="button" class="variations-toggle text-xs text-gray-500 underline">${escapeHtml(t('show_variations'))}</button>
      <div class="variations-list mt-2 space-y-2 hidden"></div>
    </td>
  `;
  App.renderVariationsSection(toggleRow, product, { isSelectionMode, onSelectChange });

  const fragment = document.createDocumentFragment();
  fragment.appendChild(row);
  fragment.appendChild(toggleRow);
  return fragment;
```

4. Delete these now-moved function definitions entirely from `products.js`: `wireProductElement()` (currently lines 442–510), `wireVariationsToggle()` (currently lines 512–554), `renderVariationsList()` (556–562), `renderVariationRow()` (564–600), `renderAddVariationRow()` (602–620), `buildAddVariationForm()` (622–721), `renderPriceRow()` (728–749), `showPriceEditor()` (751–901), `stockStatusBadge()` (903–911).

   Keep `escapeHtml()` (currently lines 913–917) — it's still used by `renderProductCard()`/`renderProductRow()` for the product name/SKU/image alt text, which weren't moved.

- [ ] **Step 6: Lint check for `products.js`**

Run: `node --check public/assets/js/products.js`
Expected: no output (success)

- [ ] **Step 7: Manually verify no regression on the product list page**

In a browser, open `/products.php`:
- A simple product's price still click-to-edits and saves correctly.
- A simple product's stock +/- still works.
- A variable product (if one exists in the test site) still expands/collapses its variations, and "Add variation" still works.
- Bulk-select mode (checkbox in the top bar) still lets you select both top-level products and variation rows.
- Table view (`view-table-btn`) shows the same behavior as grid view.

- [ ] **Step 8: Commit**

```bash
git add public/assets/js/products.js
git commit -m "Use the shared App variation/price/stock helpers in products.js"
```

---

### Task 6: Frontend — product-wizard type toggle and attribute editor

**Files:**
- Modify: `public/product-wizard.php`
- Modify: `public/assets/js/product-wizard.js`

**Interfaces:**
- Produces (in `product-wizard.js`): `setProductType(type: 'simple'|'variable'): void`, `addAttributeRow(name?: string, options?: string): void`, `collectAttributes(): Array<{name: string, options: string[]}>`.
- Consumes: `t()`, `App.toast` (existing).

- [ ] **Step 1: Add the type toggle and attribute editor markup**

In `public/product-wizard.php`, find the end of "Step 1: Basic info" (currently lines 49–59):

```php
      <!-- Step 1: Basic info -->
      <section class="wizard-step bg-white rounded-2xl border border-gray-100 p-4 space-y-4" data-step="0">
        <h2 class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars(t('wizard_step_basic')); ?></h2>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('name')); ?></label>
          <input id="name" type="text" required class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('sku')); ?></label>
          <input id="sku" type="text" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>
      </section>
```

Replace with:

```php
      <!-- Step 1: Basic info -->
      <section class="wizard-step bg-white rounded-2xl border border-gray-100 p-4 space-y-4" data-step="0">
        <h2 class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars(t('wizard_step_basic')); ?></h2>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('name')); ?></label>
          <input id="name" type="text" required class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('sku')); ?></label>
          <input id="sku" type="text" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo htmlspecialchars(t('product_type')); ?></label>
          <div class="flex gap-2">
            <button type="button" data-type="simple" class="product-type-btn flex-1 rounded-xl border border-gray-300 px-3 py-2.5 text-sm font-medium"><?php echo htmlspecialchars(t('type_simple')); ?></button>
            <button type="button" data-type="variable" class="product-type-btn flex-1 rounded-xl border border-gray-300 px-3 py-2.5 text-sm font-medium"><?php echo htmlspecialchars(t('type_variable')); ?></button>
          </div>
        </div>
        <div id="attributes-section" class="hidden space-y-2">
          <label class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(t('attributes')); ?></label>
          <div id="attributes-list" class="space-y-2"></div>
          <button type="button" id="add-attribute" class="text-xs font-medium text-blue-600"><?php echo htmlspecialchars(t('add_attribute')); ?></button>
        </div>
      </section>
```

- [ ] **Step 2: Lint check for the PHP file**

Run: `php -l public/product-wizard.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Add the type/attribute state and DOM refs to `product-wizard.js`**

In `public/assets/js/product-wizard.js`, replace the `els` object (currently lines 15–41):

```js
const els = {
  loading: document.getElementById('loading'),
  form: document.getElementById('wizard-form'),
  name: document.getElementById('name'),
  sku: document.getElementById('sku'),
  regularPrice: document.getElementById('regular_price'),
  salePrice: document.getElementById('sale_price'),
  stockQuantity: document.getElementById('stock_quantity'),
  stockStatus: document.getElementById('stock_status'),
  categoriesList: document.getElementById('categories-list'),
  customTaxonomies: document.getElementById('custom-taxonomies'),
  imagesGrid: document.getElementById('images-grid'),
  addImage: document.getElementById('add-image'),
  imageUpload: document.getElementById('image-upload'),
  uploadStatus: document.getElementById('upload-status'),
  shortDescription: document.getElementById('short_description'),
  description: document.getElementById('description'),
  aiGenerateShort: document.getElementById('ai-generate-short'),
  aiGenerateLong: document.getElementById('ai-generate-long'),
  status: document.getElementById('status'),
  stepIndicator: document.getElementById('step-indicator'),
  progressBar: document.getElementById('progress-bar'),
  backBtn: document.getElementById('wizard-back'),
  nextBtn: document.getElementById('wizard-next'),
  publishBtn: document.getElementById('wizard-publish'),
  reviewSummary: document.getElementById('review-summary'),
};
```

with:

```js
const els = {
  loading: document.getElementById('loading'),
  form: document.getElementById('wizard-form'),
  name: document.getElementById('name'),
  sku: document.getElementById('sku'),
  regularPrice: document.getElementById('regular_price'),
  salePrice: document.getElementById('sale_price'),
  stockQuantity: document.getElementById('stock_quantity'),
  stockStatus: document.getElementById('stock_status'),
  categoriesList: document.getElementById('categories-list'),
  customTaxonomies: document.getElementById('custom-taxonomies'),
  imagesGrid: document.getElementById('images-grid'),
  addImage: document.getElementById('add-image'),
  imageUpload: document.getElementById('image-upload'),
  uploadStatus: document.getElementById('upload-status'),
  shortDescription: document.getElementById('short_description'),
  description: document.getElementById('description'),
  aiGenerateShort: document.getElementById('ai-generate-short'),
  aiGenerateLong: document.getElementById('ai-generate-long'),
  status: document.getElementById('status'),
  stepIndicator: document.getElementById('step-indicator'),
  progressBar: document.getElementById('progress-bar'),
  backBtn: document.getElementById('wizard-back'),
  nextBtn: document.getElementById('wizard-next'),
  publishBtn: document.getElementById('wizard-publish'),
  reviewSummary: document.getElementById('review-summary'),
  productTypeBtns: Array.from(document.querySelectorAll('.product-type-btn')),
  attributesSection: document.getElementById('attributes-section'),
  attributesList: document.getElementById('attributes-list'),
  addAttributeBtn: document.getElementById('add-attribute'),
};

let productType = 'simple';
```

- [ ] **Step 4: Add `setProductType()`, `addAttributeRow()`, `collectAttributes()`**

In the same file, add these functions right after `collectCustomTaxonomies()` (currently ends at line 147):

```js
function setProductType(type) {
  productType = type;
  els.productTypeBtns.forEach((btn) => {
    const active = btn.dataset.type === type;
    btn.classList.toggle('bg-gray-900', active);
    btn.classList.toggle('text-white', active);
    btn.classList.toggle('border-gray-900', active);
  });
  els.attributesSection.classList.toggle('hidden', type !== 'variable');
  if (type === 'variable' && els.attributesList.children.length === 0) {
    addAttributeRow();
  }
}

function addAttributeRow(name = '', options = '') {
  const row = document.createElement('div');
  row.className = 'attribute-row flex gap-2 items-start';
  row.innerHTML = `
    <div class="flex-1 space-y-1">
      <input type="text" class="attribute-name w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm" placeholder="${escapeHtml(t('attribute_name'))}" value="${escapeHtml(name)}">
      <input type="text" class="attribute-options w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm" placeholder="${escapeHtml(t('attribute_options_hint'))}" value="${escapeHtml(options)}">
    </div>
    <button type="button" class="remove-attribute-row text-red-500 text-lg leading-none mt-1.5">&times;</button>
  `;
  row.querySelector('.remove-attribute-row').addEventListener('click', () => row.remove());
  els.attributesList.appendChild(row);
}

function collectAttributes() {
  return Array.from(els.attributesList.querySelectorAll('.attribute-row')).map((row) => ({
    name: row.querySelector('.attribute-name').value.trim(),
    options: row.querySelector('.attribute-options').value.split(',').map((o) => o.trim()).filter((o) => o !== ''),
  })).filter((attr) => attr.name !== '' && attr.options.length > 0);
}
```

- [ ] **Step 5: Wire the buttons and include type/attributes in the publish payload**

In the same file, in `bindEvents()` (currently starts at line 222), add right after the existing `els.status.addEventListener(...)`/`updatePublishLabel();` lines (currently lines 223–224):

```js
  els.status.addEventListener('change', updatePublishLabel);
  updatePublishLabel();

  els.productTypeBtns.forEach((btn) => {
    btn.addEventListener('click', () => setProductType(btn.dataset.type));
  });
  els.addAttributeBtn.addEventListener('click', () => addAttributeRow());
  setProductType('simple');
```

Still in `bindEvents()`, find the submit handler's payload build (currently lines 251–264):

```js
    const payload = {
      name: els.name.value.trim(),
      sku: els.sku.value.trim(),
      regular_price: els.regularPrice.value,
      sale_price: els.salePrice.value,
      stock_quantity: els.stockQuantity.value === '' ? 0 : parseInt(els.stockQuantity.value, 10),
      stock_status: els.stockStatus.value,
      short_description: els.shortDescription.value,
      description: els.description.value,
      status: els.status.value,
      categories: Array.from(els.categoriesList.querySelectorAll('.category-checkbox:checked')).map((cb) => cb.value),
      images: imageGallery.getImages(),
      taxonomies: collectCustomTaxonomies(),
    };
```

Replace with:

```js
    if (productType === 'variable' && collectAttributes().length === 0) {
      App.toast(t('variable_product_needs_attribute'), 'error');
      submitting = false;
      els.publishBtn.disabled = false;
      els.publishBtn.textContent = els.publishBtn.dataset.label;
      showStep(0);
      return;
    }

    const payload = {
      name: els.name.value.trim(),
      sku: els.sku.value.trim(),
      type: productType,
      regular_price: els.regularPrice.value,
      sale_price: els.salePrice.value,
      stock_quantity: els.stockQuantity.value === '' ? 0 : parseInt(els.stockQuantity.value, 10),
      stock_status: els.stockStatus.value,
      short_description: els.shortDescription.value,
      description: els.description.value,
      status: els.status.value,
      categories: Array.from(els.categoriesList.querySelectorAll('.category-checkbox:checked')).map((cb) => cb.value),
      images: imageGallery.getImages(),
      taxonomies: collectCustomTaxonomies(),
    };
    if (productType === 'variable') {
      payload.attributes = collectAttributes();
    }
```

- [ ] **Step 6: Lint check for the JS file**

Run: `node --check public/assets/js/product-wizard.js`
Expected: no output (success)

- [ ] **Step 7: Manually verify in a browser**

Open `/product-wizard.php`:
- Default selection is Simple; the attribute editor stays hidden.
- Clicking Variable reveals the attribute editor with one empty row; "Add attribute" adds more rows; the × button removes a row.
- Publishing a Variable product with an empty attribute name/options is blocked with the `variable_product_needs_attribute` toast and returns to step 1.
- Publishing a Variable product with one attribute (e.g. `Color` / `Red, Blue`) succeeds and redirects to `/product-edit.php?id={id}`.
- Check both `en` and `fa` (RTL) for the new toggle/editor layout.

- [ ] **Step 8: Commit**

```bash
git add public/product-wizard.php public/assets/js/product-wizard.js
git commit -m "Add product type toggle and attribute editor to the add-product wizard"
```

---

### Task 7: Frontend — product-edit type toggle, attribute editor, and Variations section

**Files:**
- Modify: `public/product-edit.php`
- Modify: `public/assets/js/product-edit.js`

**Interfaces:**
- Consumes: `App.renderVariationsSection(container, product, opts)` (Task 5).
- Produces (in `product-edit.js`): `setProductType()`, `addAttributeRow()`, `collectAttributes()` (same shape as Task 6, page-local — matches this codebase's existing convention of small per-page duplication between the wizard and edit page, e.g. `generateDescription()`/`collectCustomTaxonomies()` are already duplicated the same way).

- [ ] **Step 1: Add the type toggle, attribute editor, and Variations section markup**

In `public/product-edit.php`, find the basic-info block (currently lines 38–47):

```php
      <div class="bg-white rounded-2xl border border-gray-100 p-4 space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('name')); ?></label>
          <input id="name" type="text" required class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('sku')); ?></label>
          <input id="sku" type="text" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>
      </div>
```

Replace with:

```php
      <div class="bg-white rounded-2xl border border-gray-100 p-4 space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('name')); ?></label>
          <input id="name" type="text" required class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('sku')); ?></label>
          <input id="sku" type="text" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo htmlspecialchars(t('product_type')); ?></label>
          <div class="flex gap-2">
            <button type="button" data-type="simple" class="product-type-btn flex-1 rounded-xl border border-gray-300 px-3 py-2.5 text-sm font-medium"><?php echo htmlspecialchars(t('type_simple')); ?></button>
            <button type="button" data-type="variable" class="product-type-btn flex-1 rounded-xl border border-gray-300 px-3 py-2.5 text-sm font-medium"><?php echo htmlspecialchars(t('type_variable')); ?></button>
          </div>
        </div>
        <div id="attributes-section" class="hidden space-y-2">
          <label class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(t('attributes')); ?></label>
          <div id="attributes-list" class="space-y-2"></div>
          <button type="button" id="add-attribute" class="text-xs font-medium text-blue-600"><?php echo htmlspecialchars(t('add_attribute')); ?></button>
        </div>
      </div>

      <div id="variations-section" class="hidden bg-white rounded-2xl border border-gray-100 p-4 space-y-2">
        <h2 class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars(t('variations')); ?></h2>
        <button type="button" class="variations-toggle text-xs text-gray-500 underline"><?php echo htmlspecialchars(t('show_variations')); ?></button>
        <div class="variations-list mt-2 space-y-2 hidden"></div>
      </div>
```

- [ ] **Step 2: Lint check for the PHP file**

Run: `php -l public/product-edit.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Add the type/attribute state and DOM refs to `product-edit.js`**

In `public/assets/js/product-edit.js`, replace the `els` object (currently lines 5–28):

```js
const els = {
  loading: document.getElementById('loading'),
  form: document.getElementById('product-form'),
  name: document.getElementById('name'),
  sku: document.getElementById('sku'),
  regularPrice: document.getElementById('regular_price'),
  salePrice: document.getElementById('sale_price'),
  stockQuantity: document.getElementById('stock_quantity'),
  stockStatus: document.getElementById('stock_status'),
  categoriesList: document.getElementById('categories-list'),
  customTaxonomies: document.getElementById('custom-taxonomies'),
  imagesGrid: document.getElementById('images-grid'),
  addImage: document.getElementById('add-image'),
  imageUpload: document.getElementById('image-upload'),
  uploadStatus: document.getElementById('upload-status'),
  shortDescription: document.getElementById('short_description'),
  description: document.getElementById('description'),
  aiGenerateShort: document.getElementById('ai-generate-short'),
  aiGenerateLong: document.getElementById('ai-generate-long'),
  status: document.getElementById('status'),
  saveBtn: document.getElementById('save-btn'),
  deleteBtn: document.getElementById('delete-btn'),
  viewLink: document.getElementById('view-product-link'),
};
```

with:

```js
const els = {
  loading: document.getElementById('loading'),
  form: document.getElementById('product-form'),
  name: document.getElementById('name'),
  sku: document.getElementById('sku'),
  regularPrice: document.getElementById('regular_price'),
  salePrice: document.getElementById('sale_price'),
  stockQuantity: document.getElementById('stock_quantity'),
  stockStatus: document.getElementById('stock_status'),
  categoriesList: document.getElementById('categories-list'),
  customTaxonomies: document.getElementById('custom-taxonomies'),
  imagesGrid: document.getElementById('images-grid'),
  addImage: document.getElementById('add-image'),
  imageUpload: document.getElementById('image-upload'),
  uploadStatus: document.getElementById('upload-status'),
  shortDescription: document.getElementById('short_description'),
  description: document.getElementById('description'),
  aiGenerateShort: document.getElementById('ai-generate-short'),
  aiGenerateLong: document.getElementById('ai-generate-long'),
  status: document.getElementById('status'),
  saveBtn: document.getElementById('save-btn'),
  deleteBtn: document.getElementById('delete-btn'),
  viewLink: document.getElementById('view-product-link'),
  productTypeBtns: Array.from(document.querySelectorAll('.product-type-btn')),
  attributesSection: document.getElementById('attributes-section'),
  attributesList: document.getElementById('attributes-list'),
  addAttributeBtn: document.getElementById('add-attribute'),
  variationsSection: document.getElementById('variations-section'),
};

let productType = 'simple';
```

- [ ] **Step 4: Add `setProductType()`, `addAttributeRow()`, `collectAttributes()`, and an `escapeHtml()` helper**

In the same file, add these functions right after `collectCustomTaxonomies()` (currently ends at line 252, right before `stripHtml()`):

```js
function setProductType(type) {
  productType = type;
  els.productTypeBtns.forEach((btn) => {
    const active = btn.dataset.type === type;
    btn.classList.toggle('bg-gray-900', active);
    btn.classList.toggle('text-white', active);
    btn.classList.toggle('border-gray-900', active);
  });
  els.attributesSection.classList.toggle('hidden', type !== 'variable');
  if (type === 'variable' && els.attributesList.children.length === 0) {
    addAttributeRow();
  }
}

function addAttributeRow(name = '', options = '') {
  const row = document.createElement('div');
  row.className = 'attribute-row flex gap-2 items-start';
  row.innerHTML = `
    <div class="flex-1 space-y-1">
      <input type="text" class="attribute-name w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm" placeholder="${escapeHtml(t('attribute_name'))}" value="${escapeHtml(name)}">
      <input type="text" class="attribute-options w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm" placeholder="${escapeHtml(t('attribute_options_hint'))}" value="${escapeHtml(options)}">
    </div>
    <button type="button" class="remove-attribute-row text-red-500 text-lg leading-none mt-1.5">&times;</button>
  `;
  row.querySelector('.remove-attribute-row').addEventListener('click', () => row.remove());
  els.attributesList.appendChild(row);
}

function collectAttributes() {
  return Array.from(els.attributesList.querySelectorAll('.attribute-row')).map((row) => ({
    name: row.querySelector('.attribute-name').value.trim(),
    options: row.querySelector('.attribute-options').value.split(',').map((o) => o.trim()).filter((o) => o !== ''),
  })).filter((attr) => attr.name !== '' && attr.options.length > 0);
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}
```

- [ ] **Step 5: Prefill type/attributes and render the Variations section on load**

In the same file, in `loadProduct()` (currently lines 98–137), find:

```js
    els.status.value = item.status || 'publish';
    updateSaveLabel();

    if (item.permalink && els.viewLink) {
```

Replace with:

```js
    els.status.value = item.status || 'publish';
    updateSaveLabel();

    setProductType(item.type === 'variable' ? 'variable' : 'simple');
    (item.attributes || []).forEach((attr) => addAttributeRow(attr.name, (attr.options || []).join(', ')));
    if (item.type === 'variable' && window.PRODUCT_ID > 0) {
      els.variationsSection.classList.remove('hidden');
      App.renderVariationsSection(els.variationsSection, item);
    }

    if (item.permalink && els.viewLink) {
```

- [ ] **Step 6: Wire the buttons and include type/attributes in the save payload**

In the same file, in `bindEvents()` (currently starts at line 145), add right after `els.status.addEventListener('change', updateSaveLabel);` (currently line 146):

```js
  els.status.addEventListener('change', updateSaveLabel);

  els.productTypeBtns.forEach((btn) => {
    btn.addEventListener('click', () => setProductType(btn.dataset.type));
  });
  els.addAttributeBtn.addEventListener('click', () => addAttributeRow());
  if (window.PRODUCT_ID <= 0) {
    setProductType('simple');
  }
```

Still in `bindEvents()`, find the submit handler's payload build (currently lines 159–172):

```js
    const payload = {
      name: els.name.value.trim(),
      sku: els.sku.value.trim(),
      regular_price: els.regularPrice.value,
      sale_price: els.salePrice.value,
      stock_quantity: els.stockQuantity.value === '' ? 0 : parseInt(els.stockQuantity.value, 10),
      stock_status: els.stockStatus.value,
      short_description: els.shortDescription.value,
      description: els.description.value,
      status: els.status.value,
      categories: Array.from(els.categoriesList.querySelectorAll('.category-checkbox:checked')).map((cb) => cb.value),
      images: imageGallery.getImages(),
      taxonomies: collectCustomTaxonomies(),
    };
```

Replace with:

```js
    if (productType === 'variable' && collectAttributes().length === 0) {
      App.toast(t('variable_product_needs_attribute'), 'error');
      submitting = false;
      els.saveBtn.disabled = false;
      els.saveBtn.textContent = els.saveBtn.dataset.label || t('save');
      return;
    }

    const payload = {
      name: els.name.value.trim(),
      sku: els.sku.value.trim(),
      type: productType,
      regular_price: els.regularPrice.value,
      sale_price: els.salePrice.value,
      stock_quantity: els.stockQuantity.value === '' ? 0 : parseInt(els.stockQuantity.value, 10),
      stock_status: els.stockStatus.value,
      short_description: els.shortDescription.value,
      description: els.description.value,
      status: els.status.value,
      categories: Array.from(els.categoriesList.querySelectorAll('.category-checkbox:checked')).map((cb) => cb.value),
      images: imageGallery.getImages(),
      taxonomies: collectCustomTaxonomies(),
    };
    if (productType === 'variable') {
      payload.attributes = collectAttributes();
    }
```

- [ ] **Step 7: Lint check for the JS file**

Run: `node --check public/assets/js/product-edit.js`
Expected: no output (success)

- [ ] **Step 8: Manually verify in a browser**

- New product (`/product-edit.php` with no id): type defaults to Simple, no Variations section shown (no id yet).
- Edit an existing Simple product: switch to Variable, add one attribute (e.g. `Size` / `S, M, L`), save — confirm it redirects/reloads and the Variations section now appears with "Add variation" working.
- Edit an existing Variable product (one created via Task 6 or the step above): confirm its attributes prefill correctly in the editor, and the Variations section shows existing variations.
- Try to save a Variable product with no valid attribute rows — confirm the inline toast blocks the save.
- Check both `en` and `fa` (RTL) for the new toggle/editor/Variations section layout.

- [ ] **Step 9: Commit**

```bash
git add public/product-edit.php public/assets/js/product-edit.js
git commit -m "Add product type toggle, attribute editor, and Variations section to product edit page"
```

---

## Post-implementation note

The WP plugin changes (Tasks 1–2) live in `wp-plugin/woo-mgmt-agent/` (tracked source). `wp-plugin/woo-mgmt-agent.zip` is a separately-built deploy artifact (already stale relative to tracked source before this plan) — it is **not** rebuilt by this plan. Before this feature works on a real managed site, the updated plugin source needs to be re-zipped and re-uploaded/updated on that site; flag this to the user rather than doing it silently, since it's a deployment action outside this repo's normal `php -l`/browser verification loop.
