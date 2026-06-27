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

    public static function get_product(int $id): ?array
    {
        $product = wc_get_product($id);
        return $product ? self::product_to_array($product) : null;
    }

    public static function create_product(array $data): array
    {
        $product = new WC_Product_Simple();
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
            $updated = $id > 0 ? self::update_product($id, $item) : null;
            if ($updated !== null) {
                $results[] = $updated;
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
            $imageIds = array_map('intval', (array) $data['images']);
            $product->set_image_id($imageIds[0]);
            $product->set_gallery_image_ids(array_slice($imageIds, 1));
        }
        if (array_key_exists('taxonomies', $data) && is_array($data['taxonomies'])) {
            self::apply_taxonomies($product, $data['taxonomies']);
        }
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

    /** @return array<\WP_Taxonomy> */
    public static function custom_product_taxonomies(): array
    {
        $taxonomies = get_object_taxonomies('product', 'objects');
        return array_values(array_filter($taxonomies, fn($t) => !in_array($t->name, ['product_cat', 'product_tag'], true)));
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
        ];
    }
}
