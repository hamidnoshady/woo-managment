<?php
// wp-plugin/woo-mgmt-agent/includes/class-wma-rest.php

defined('ABSPATH') || exit;

class Wma_Rest
{
    public static function register(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route('wma/v1', '/jobs', [
                'methods' => 'POST',
                'callback' => [self::class, 'create_job'],
                'permission_callback' => [Wma_Auth::class, 'check'],
            ]);
            register_rest_route('wma/v1', '/jobs/(?P<id>\d+)', [
                'methods' => 'GET',
                'callback' => [self::class, 'get_job_status'],
                'permission_callback' => [Wma_Auth::class, 'check'],
            ]);

            register_rest_route('wma/v1', '/products', [
                ['methods' => 'GET', 'callback' => [self::class, 'list_products'], 'permission_callback' => [Wma_Auth::class, 'check']],
                ['methods' => 'POST', 'callback' => [self::class, 'create_product'], 'permission_callback' => [Wma_Auth::class, 'check']],
            ]);
            register_rest_route('wma/v1', '/products/ids', [
                'methods' => 'GET',
                'callback' => [self::class, 'list_product_ids'],
                'permission_callback' => [Wma_Auth::class, 'check'],
            ]);
            register_rest_route('wma/v1', '/products/batch', [
                'methods' => 'POST',
                'callback' => [self::class, 'batch_products'],
                'permission_callback' => [Wma_Auth::class, 'check'],
            ]);
            register_rest_route('wma/v1', '/products/(?P<id>\d+)', [
                ['methods' => 'GET', 'callback' => [self::class, 'get_product'], 'permission_callback' => [Wma_Auth::class, 'check']],
                ['methods' => 'PUT', 'callback' => [self::class, 'update_product'], 'permission_callback' => [Wma_Auth::class, 'check']],
                ['methods' => 'DELETE', 'callback' => [self::class, 'delete_product'], 'permission_callback' => [Wma_Auth::class, 'check']],
            ]);
            register_rest_route('wma/v1', '/products/(?P<id>\d+)/variations', [
                ['methods' => 'GET', 'callback' => [self::class, 'list_variations'], 'permission_callback' => [Wma_Auth::class, 'check']],
                ['methods' => 'POST', 'callback' => [self::class, 'create_variation'], 'permission_callback' => [Wma_Auth::class, 'check']],
            ]);
            register_rest_route('wma/v1', '/products/attribute-suggestions', [
                'methods' => 'GET',
                'callback' => [self::class, 'list_attribute_suggestions'],
                'permission_callback' => [Wma_Auth::class, 'check'],
            ]);

            register_rest_route('wma/v1', '/categories', [
                'methods' => 'GET',
                'callback' => [self::class, 'list_categories'],
                'permission_callback' => [Wma_Auth::class, 'check'],
            ]);
            register_rest_route('wma/v1', '/taxonomies', [
                'methods' => 'GET',
                'callback' => [self::class, 'list_taxonomies'],
                'permission_callback' => [Wma_Auth::class, 'check'],
            ]);
            register_rest_route('wma/v1', '/media', [
                'methods' => 'POST',
                'callback' => [self::class, 'upload_media'],
                'permission_callback' => [Wma_Auth::class, 'check'],
            ]);
        });
    }

    private static function require_woocommerce(): ?WP_REST_Response
    {
        if (!class_exists('WooCommerce')) {
            return new WP_REST_Response(['error' => 'WooCommerce is not active on this site.'], 503);
        }
        return null;
    }

    public static function create_job(WP_REST_Request $request): WP_REST_Response
    {
        $jobId = (int) $request->get_param('job_id');
        $kind = (string) $request->get_param('kind');
        $scope = (string) $request->get_param('scope');
        $parts = (array) ($request->get_param('parts') ?? []);

        if ($jobId <= 0 || !in_array($kind, ['backup', 'restore'], true) || !in_array($scope, ['database', 'full'], true)) {
            return new WP_REST_Response(['error' => 'Invalid job parameters'], 400);
        }

        Wma_Db::create_job($jobId, $kind, $scope, $parts);
        return new WP_REST_Response(['accepted' => true], 202);
    }

    public static function get_job_status(WP_REST_Request $request): WP_REST_Response
    {
        $jobId = (int) $request->get_param('id');
        $job = Wma_Db::get_job($jobId);
        if ($job === null) {
            return new WP_REST_Response(['error' => 'Job not found'], 404);
        }

        if ($request->get_param('tick') && $job['status'] === 'running') {
            $job = $job['kind'] === 'backup' ? Wma_Backup_Job::tick($job) : Wma_Restore_Job::tick($job);
            Wma_Db::save_job($job);
        }

        return new WP_REST_Response([
            'status' => $job['status'],
            'step' => $job['step'],
            'parts' => json_decode((string) $job['parts_json'], true) ?: [],
            'error' => $job['error'],
        ], 200);
    }

    public static function list_products(WP_REST_Request $request): WP_REST_Response
    {
        if ($err = self::require_woocommerce()) {
            return $err;
        }
        try {
            return new WP_REST_Response(Wma_Products::list_products($request->get_params()), 200);
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }

    public static function list_product_ids(WP_REST_Request $request): WP_REST_Response
    {
        if ($err = self::require_woocommerce()) {
            return $err;
        }
        try {
            return new WP_REST_Response(['ids' => Wma_Products::list_product_ids($request->get_params())], 200);
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }

    public static function get_product(WP_REST_Request $request): WP_REST_Response
    {
        if ($err = self::require_woocommerce()) {
            return $err;
        }
        try {
            $product = Wma_Products::get_product((int) $request->get_param('id'));
            return $product === null
                ? new WP_REST_Response(['error' => 'Product not found'], 404)
                : new WP_REST_Response(['item' => $product], 200);
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }

    public static function create_product(WP_REST_Request $request): WP_REST_Response
    {
        if ($err = self::require_woocommerce()) {
            return $err;
        }
        try {
            return new WP_REST_Response(['item' => Wma_Products::create_product($request->get_json_params())], 201);
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }

    public static function update_product(WP_REST_Request $request): WP_REST_Response
    {
        if ($err = self::require_woocommerce()) {
            return $err;
        }
        try {
            $product = Wma_Products::update_product((int) $request->get_param('id'), $request->get_json_params());
            return $product === null
                ? new WP_REST_Response(['error' => 'Product not found'], 404)
                : new WP_REST_Response(['item' => $product], 200);
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }

    public static function delete_product(WP_REST_Request $request): WP_REST_Response
    {
        if ($err = self::require_woocommerce()) {
            return $err;
        }
        try {
            $force = (bool) $request->get_param('force');
            $ok = Wma_Products::delete_product((int) $request->get_param('id'), $force);
            return $ok ? new WP_REST_Response(['ok' => true], 200) : new WP_REST_Response(['error' => 'Product not found'], 404);
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }

    public static function list_variations(WP_REST_Request $request): WP_REST_Response
    {
        if ($err = self::require_woocommerce()) {
            return $err;
        }
        try {
            $result = Wma_Products::list_variations((int) $request->get_param('id'));
            return isset($result['error'])
                ? new WP_REST_Response(['error' => $result['error']], 422)
                : new WP_REST_Response($result, 200);
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }

    public static function create_variation(WP_REST_Request $request): WP_REST_Response
    {
        if ($err = self::require_woocommerce()) {
            return $err;
        }
        try {
            $result = Wma_Products::create_variation((int) $request->get_param('id'), $request->get_json_params());
            return isset($result['error'])
                ? new WP_REST_Response(['error' => $result['error']], 422)
                : new WP_REST_Response(['item' => $result], 201);
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }

    public static function list_attribute_suggestions(WP_REST_Request $request): WP_REST_Response
    {
        if ($err = self::require_woocommerce()) {
            return $err;
        }
        try {
            return new WP_REST_Response(['items' => Wma_Products::list_attribute_suggestions()], 200);
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }

    public static function batch_products(WP_REST_Request $request): WP_REST_Response
    {
        if ($err = self::require_woocommerce()) {
            return $err;
        }
        $body = $request->get_json_params();
        $items = (array) ($body['update'] ?? []);
        return new WP_REST_Response(['update' => Wma_Products::batch_update($items)], 200);
    }

    public static function list_categories(WP_REST_Request $request): WP_REST_Response
    {
        if ($err = self::require_woocommerce()) {
            return $err;
        }
        try {
            return new WP_REST_Response(['items' => Wma_Taxonomies::list_categories()], 200);
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }

    public static function list_taxonomies(WP_REST_Request $request): WP_REST_Response
    {
        if ($err = self::require_woocommerce()) {
            return $err;
        }
        try {
            return new WP_REST_Response(['items' => Wma_Taxonomies::list_custom_taxonomies()], 200);
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }

    public static function upload_media(WP_REST_Request $request): WP_REST_Response
    {
        $filename = $request->get_header('x-filename') ?: 'upload.jpg';
        $mimeType = $request->get_header('content-type') ?: 'application/octet-stream';
        try {
            return new WP_REST_Response(['item' => Wma_Media::upload($request->get_body(), $filename, $mimeType)], 201);
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }
}
