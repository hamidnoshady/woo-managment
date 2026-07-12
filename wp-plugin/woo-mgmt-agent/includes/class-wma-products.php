<?php
// wp-plugin/woo-mgmt-agent/includes/class-wma-products.php

defined('ABSPATH') || exit;

/**
 * All product reads/writes via native WooCommerce PHP APIs
 * (wc_get_products()/WC_Product) — no outbound HTTP, no WooCommerce REST
 * API involved, even though this plugin runs on the same site.
 */
class Wma_Products
{
    private const DEFAULT_PER_PAGE = 20;

    public static function list_products(array $params): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($params['per_page'] ?? self::DEFAULT_PER_PAGE)));

        $args = [
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'paginate' => true,
        ];
        if (!empty($params['search'])) {
            $args['s'] = (string) $params['search'];
        }
        if (!empty($params['category'])) {
            $args['category'] = [(string) $params['category']];
        }
        if (!empty($params['stock_status'])) {
            $args['stock_status'] = (string) $params['stock_status'];
        }
        if (!empty($params['taxonomy_terms']) && is_array($params['taxonomy_terms'])) {
            $args['tax_query'] = self::build_tax_query($params['taxonomy_terms']);
        }

        $result = wc_get_products($args);
        $products = $result->products;

        if (!empty($params['on_sale'])) {
            $products = array_values(array_filter($products, fn($p) => $p->is_on_sale()));
        }

        return [
            'items' => array_map([self::class, 'product_to_array'], $products),
            'page' => $page,
            'per_page' => $perPage,
            'total' => (int) $result->total,
            'total_pages' => (int) $result->max_num_pages,
        ];
    }

    /**
     * Returns every matching product's id, with no pagination limit and no
     * per-product hydration - used for "select all matching filters" in
     * batch editing, where a full list_products() page-by-page fetch would
     * be far too slow for a store with thousands of products.
     */
    public static function list_product_ids(array $params): array
    {
        $args = ['return' => 'ids', 'limit' => -1];

        if (!empty($params['search'])) {
            $args['s'] = (string) $params['search'];
        }
        if (!empty($params['category'])) {
            $args['category'] = [(string) $params['category']];
        }
        if (!empty($params['stock_status'])) {
            $args['stock_status'] = (string) $params['stock_status'];
        }
        if (!empty($params['taxonomy_terms']) && is_array($params['taxonomy_terms'])) {
            $args['tax_query'] = self::build_tax_query($params['taxonomy_terms']);
        }

        $ids = wc_get_products($args);

        if (!empty($params['on_sale'])) {
            $ids = array_values(array_filter($ids, function ($id) {
                $product = wc_get_product($id);
                return $product && $product->is_on_sale();
            }));
        }

        return array_map('intval', $ids);
    }

    public static function get_product(int $id): ?array
    {
        $product = wc_get_product($id);
        return $product ? self::product_to_array($product) : null;
    }

    public static function list_variations(int $parentId): array
    {
        $parent = wc_get_product($parentId);
        if (!$parent || $parent->get_type() !== 'variable') {
            return ['error' => 'Not a variable product'];
        }

        $items = array_map(
            fn($childId) => self::variation_to_array(wc_get_product($childId), $parent),
            $parent->get_children()
        );

        $options = array_map(
            fn($attrName, $values) => ['name' => wc_attribute_label($attrName), 'options' => array_values($values)],
            array_keys($parent->get_variation_attributes()),
            $parent->get_variation_attributes()
        );

        return ['items' => array_values(array_filter($items)), 'options' => array_values($options)];
    }

    private static function variation_to_array(?WC_Product_Variation $variation, WC_Product $parent): ?array
    {
        if (!$variation) {
            return null;
        }

        $imageId = $variation->get_image_id() ?: $parent->get_image_id();

        return [
            'id' => $variation->get_id(),
            'sku' => $variation->get_sku(),
            'regular_price' => $variation->get_regular_price(),
            'sale_price' => $variation->get_sale_price(),
            'price' => $variation->get_price(),
            'stock_quantity' => $variation->get_stock_quantity(),
            'manage_stock' => $variation->get_manage_stock(),
            'stock_status' => $variation->get_stock_status(),
            'image' => $imageId ? (wp_get_attachment_url($imageId) ?: null) : null,
            'attributes' => self::readable_variation_attributes($variation),
            'attribute_summary' => wc_get_formatted_variation($variation, true, false),
        ];
    }

    /** @return array<string,string> attribute label => selected value */
    private static function readable_variation_attributes(WC_Product_Variation $variation): array
    {
        $result = [];
        foreach ($variation->get_variation_attributes() as $attrKey => $value) {
            // $attrKey looks like "attribute_pa_color" or "attribute_size".
            $taxonomy = str_replace('attribute_', '', $attrKey);
            $label = wc_attribute_label($taxonomy);
            if (str_starts_with($taxonomy, 'pa_') && $value !== '') {
                $term = get_term_by('slug', $value, $taxonomy);
                $result[$label] = $term ? $term->name : $value;
            } else {
                $result[$label] = $value;
            }
        }
        return $result;
    }

    public static function create_variation(int $parentId, array $data): array
    {
        $parent = wc_get_product($parentId);
        if (!$parent || $parent->get_type() !== 'variable') {
            return ['error' => 'Not a variable product'];
        }

        $requested = (array) ($data['attributes'] ?? []);
        $resolved = self::resolve_variation_attributes($parent, $requested);
        if (isset($resolved['error'])) {
            return $resolved;
        }
        $attributes = $resolved['attributes'];

        // get_matching_variation() expects attribute_<name> => value keys.
        $matchArgs = [];
        foreach ($attributes as $key => $value) {
            $matchArgs["attribute_{$key}"] = $value;
        }
        $existingId = $parent->get_matching_variation($matchArgs);
        if ($existingId) {
            return ['error' => 'A variation with this attribute combination already exists'];
        }

        $variation = new WC_Product_Variation();
        $variation->set_parent_id($parentId);
        $variation->set_attributes($attributes);
        self::apply_fields($variation, $data);
        $variation->save();

        return self::variation_to_array($variation, $parent);
    }

    /**
     * Maps human-readable attribute labels/values (as sent by the admin
     * app, taken from get_variation_attributes()'s own output) back to the
     * taxonomy-or-custom-attribute keys/values WC_Product_Variation::
     * set_attributes() expects, rejecting anything not already configured
     * on the parent - this endpoint picks existing options, it does not
     * define new ones.
     *
     * @return array{attributes: array<string,string>}|array{error: string}
     */
    private static function resolve_variation_attributes(WC_Product $parent, array $requested): array
    {
        $available = $parent->get_variation_attributes(); // attrName (taxonomy or slugified custom) => values[]
        $labelToKey = [];
        foreach (array_keys($available) as $attrName) {
            $labelToKey[wc_attribute_label($attrName)] = $attrName;
        }

        $attributes = [];
        foreach ($requested as $label => $value) {
            $key = $labelToKey[$label] ?? null;
            if ($key === null) {
                return ['error' => "Unknown attribute: {$label}"];
            }

            if (str_starts_with($key, 'pa_')) {
                $term = get_term_by('name', $value, $key) ?: get_term_by('slug', $value, $key);
                if (!$term || !in_array($term->slug, $available[$key], true)) {
                    return ['error' => "Invalid value for {$label}: {$value}"];
                }
                $attributes[$key] = $term->slug;
            } else {
                if (!in_array($value, $available[$key], true)) {
                    return ['error' => "Invalid value for {$label}: {$value}"];
                }
                $attributes[$key] = $value;
            }
        }

        if (count($attributes) !== count($available)) {
            return ['error' => 'All variation attributes must be specified'];
        }

        return ['attributes' => $attributes];
    }

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

    public static function delete_product(int $id, bool $force): bool
    {
        $product = wc_get_product($id);
        if (!$product) {
            return false;
        }
        $product->delete($force);
        return true;
    }

    /** @param array<int, array> $items each with an 'id' plus fields to change */
    public static function batch_update(array $items): array
    {
        $results = [];
        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            try {
                $updated = self::update_product($id, $item);
                if ($updated !== null) {
                    $results[] = $updated;
                }
            } catch (Throwable $e) {
                continue;
            }
        }
        return $results;
    }

    private static function apply_fields(WC_Product $product, array $data): void
    {
        $map = [
            'name' => 'set_name',
            'sku' => 'set_sku',
            'regular_price' => 'set_regular_price',
            'sale_price' => 'set_sale_price',
            'stock_quantity' => 'set_stock_quantity',
            'stock_status' => 'set_stock_status',
            'short_description' => 'set_short_description',
            'description' => 'set_description',
            'status' => 'set_status',
        ];
        foreach ($map as $key => $setter) {
            if (array_key_exists($key, $data)) {
                $product->{$setter}($data[$key]);
            }
        }
        if (array_key_exists('manage_stock', $data)) {
            $product->set_manage_stock((bool) $data['manage_stock']);
        }
        if (array_key_exists('categories', $data)) {
            $product->set_category_ids(array_map('intval', (array) $data['categories']));
        }
        if (array_key_exists('images', $data) && !empty($data['images'])) {
            $imageIds = [];
            foreach ((array) $data['images'] as $img) {
                $id = self::resolve_image_id(is_array($img) ? $img : ['src' => $img]);
                if ($id > 0) {
                    $imageIds[] = $id;
                }
            }
            if (!empty($imageIds)) {
                $product->set_image_id($imageIds[0]);
                $product->set_gallery_image_ids(array_slice($imageIds, 1));
            }
        }
        if (array_key_exists('taxonomies', $data) && is_array($data['taxonomies'])) {
            self::apply_taxonomies($product, $data['taxonomies']);
        }
        if (array_key_exists('attributes', $data) && is_array($data['attributes'])) {
            $existingTaxonomyAttributes = array_values(array_filter(
                $product->get_attributes(),
                fn($attr) => $attr instanceof WC_Product_Attribute && $attr->is_taxonomy()
            ));
            $product->set_attributes(array_merge($existingTaxonomyAttributes, self::build_attributes($data['attributes'])));
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

    /**
     * Resolves an image entry (as sent by the admin app) to a WP attachment
     * ID. Prefers an explicit 'id' (set when the image came through our own
     * /media upload endpoint). Falls back to matching a same-site attachment
     * URL, then to sideloading the URL as a new attachment - covers images
     * pasted in by URL rather than uploaded.
     */
    private static function resolve_image_id(array $img): int
    {
        $id = (int) ($img['id'] ?? 0);
        if ($id > 0) {
            return $id;
        }

        $src = trim((string) ($img['src'] ?? ''));
        if ($src === '') {
            return 0;
        }

        $existing = attachment_url_to_postid($src);
        if ($existing > 0) {
            return $existing;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $sideloaded = media_sideload_image($src, 0, null, 'id');
        return is_wp_error($sideloaded) ? 0 : (int) $sideloaded;
    }

    /** @param array<string, array<int>> $taxonomyFields rest_base => term ids */
    private static function apply_taxonomies(WC_Product $product, array $taxonomyFields): void
    {
        foreach (self::custom_product_taxonomies() as $taxonomy) {
            if (array_key_exists($taxonomy->rest_base, $taxonomyFields)) {
                wp_set_object_terms(
                    $product->get_id(),
                    array_map('intval', (array) $taxonomyFields[$taxonomy->rest_base]),
                    $taxonomy->name
                );
            }
        }
    }

    private static function build_tax_query(array $taxonomyTerms): array
    {
        $clauses = [];
        foreach (self::custom_product_taxonomies() as $taxonomy) {
            if (!empty($taxonomyTerms[$taxonomy->rest_base])) {
                $clauses[] = [
                    'taxonomy' => $taxonomy->name,
                    'field' => 'term_id',
                    'terms' => array_map('intval', (array) $taxonomyTerms[$taxonomy->rest_base]),
                ];
            }
        }
        return $clauses;
    }

    // Built-in WooCommerce/plugin system taxonomies that aren't real
    // merchandising attributes - never worth showing as a filter checkbox
    // list in the admin app.
    private const SYSTEM_TAXONOMIES = ['product_cat', 'product_tag', 'product_type'];

    /** @return array<\WP_Taxonomy> */
    public static function custom_product_taxonomies(): array
    {
        $taxonomies = get_object_taxonomies('product', 'objects');
        return array_values(array_filter($taxonomies, function ($t) {
            if (in_array($t->name, self::SYSTEM_TAXONOMIES, true)) {
                return false;
            }
            // Every WooCommerce global attribute taxonomy (Product
            // attributes -> "Color", "Brand", etc.) is registered as
            // pa_<slug> - none of these are "custom taxonomies" this app
            // can manage (no term-picker UI for them), so they must never
            // leak into the categories-step custom-taxonomy checkboxes.
            // Was previously hardcoded to just 'pa_color' above, missing
            // every other attribute taxonomy a site defines.
            if (str_starts_with($t->name, 'pa_')) {
                return false;
            }
            // Covers WooCommerce's own product_visibility and any
            // POS-plugin visibility/shipping-class taxonomy variants.
            return !str_contains($t->name, 'visibility') && !str_contains($t->name, 'shipping_class');
        }));
    }

    private static function product_to_array(WC_Product $product): array
    {
        $taxonomies = [];
        foreach (self::custom_product_taxonomies() as $taxonomy) {
            $terms = wp_get_post_terms($product->get_id(), $taxonomy->name, ['fields' => 'ids']);
            $taxonomies[$taxonomy->rest_base] = array_map('intval', is_array($terms) ? $terms : []);
        }

        return [
            'id' => $product->get_id(),
            'type' => $product->get_type(),
            'name' => $product->get_name(),
            'sku' => $product->get_sku(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'price' => $product->get_price(),
            'stock_quantity' => $product->get_stock_quantity(),
            'manage_stock' => $product->get_manage_stock(),
            'stock_status' => $product->get_stock_status(),
            'short_description' => $product->get_short_description(),
            'description' => $product->get_description(),
            'categories' => array_map(
                fn($id) => ['id' => $id, 'name' => get_term($id)?->name ?? ''],
                $product->get_category_ids()
            ),
            'images' => array_map(
                fn($id) => ['id' => $id, 'src' => wp_get_attachment_url($id) ?: ''],
                array_filter([$product->get_image_id(), ...$product->get_gallery_image_ids()])
            ),
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

    /**
     * Distinct {name, options} attribute suggestions across the site's
     * variable products, used to power autocomplete when defining a new
     * product's attributes (e.g. suggest "Color" -> "Red, Blue, Green"
     * instead of retyping the same options on every product).
     *
     * ponytail: scans up to 200 variable products per request, no cache -
     * fine for a typical catalog; add a transient cache if this shows up
     * as a slow request on a very large one.
     *
     * @return array<int, array{name: string, options: string[]}>
     */
    public static function list_attribute_suggestions(): array
    {
        $ids = wc_get_products(['type' => 'variable', 'limit' => 200, 'return' => 'ids']);

        $byName = [];
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if (!$product) {
                continue;
            }
            foreach (self::attributes_to_array($product) as $attr) {
                $key = mb_strtolower($attr['name']);
                if (!isset($byName[$key])) {
                    $byName[$key] = ['name' => $attr['name'], 'options' => []];
                }
                $byName[$key]['options'] = array_values(array_unique(
                    array_merge($byName[$key]['options'], $attr['options'])
                ));
            }
        }

        return array_values($byName);
    }
}
