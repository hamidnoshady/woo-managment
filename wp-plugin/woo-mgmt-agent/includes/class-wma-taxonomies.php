<?php
// wp-plugin/woo-mgmt-agent/includes/class-wma-taxonomies.php

defined('ABSPATH') || exit;

/**
 * Categories and custom product taxonomies via native get_terms() — every
 * taxonomy's terms in one in-process pass, not one HTTP round-trip per
 * taxonomy. This is the direct fix for the old N-sequential-request
 * slowness and the silent-failure flakiness: a real error here surfaces as
 * one clean exception/HTTP failure, not a swallowed empty array.
 */
class Wma_Taxonomies
{
    public static function list_categories(): array
    {
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        if (is_wp_error($terms)) {
            throw new RuntimeException($terms->get_error_message());
        }
        return array_map(fn($t) => [
            'id' => $t->term_id,
            'name' => $t->name,
            'count' => $t->count,
            'parent' => $t->parent,
        ], $terms);
    }

    public static function list_custom_taxonomies(): array
    {
        $result = [];
        foreach (Wma_Products::custom_product_taxonomies() as $taxonomy) {
            $terms = get_terms(['taxonomy' => $taxonomy->name, 'hide_empty' => false]);
            if (is_wp_error($terms)) {
                throw new RuntimeException("Failed to load terms for taxonomy {$taxonomy->name}: " . $terms->get_error_message());
            }
            $result[] = [
                'slug' => $taxonomy->name,
                'rest_base' => $taxonomy->rest_base,
                'name' => $taxonomy->label,
                'hierarchical' => $taxonomy->hierarchical,
                'terms' => array_map(fn($t) => ['id' => $t->term_id, 'name' => $t->name], $terms),
            ];
        }
        return $result;
    }
}
