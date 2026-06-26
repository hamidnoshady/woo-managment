<?php
// wp-plugin/woo-mgmt-agent/includes/class-wma-auth.php

defined('ABSPATH') || exit;

class Wma_Auth
{
    public static function check(WP_REST_Request $request)
    {
        $header = $request->get_header('authorization') ?? '';
        $expected = Wma_Settings::get_token();

        if ($expected === '' || !preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return new WP_Error('wma_unauthorized', 'Missing or invalid Authorization header', ['status' => 401]);
        }
        if (!hash_equals($expected, $m[1])) {
            return new WP_Error('wma_unauthorized', 'Invalid token', ['status' => 401]);
        }
        return true;
    }
}
