<?php

defined('ABSPATH') || exit;

register_rest_route('eecie-crm/v1', '/structure', [
    'methods'  => 'GET',
    'callback' => function () {
        $tables = eecie_crm_baserow_get_all_tables();
        if (is_wp_error($tables)) return $tables;

        $structure = [];
        foreach ($tables as $table) {
            $fields = eecie_crm_baserow_get_fields($table['id']);
            $structure[] = [
                'table' => $table,
                'fields' => is_wp_error($fields) ? [] : $fields,
            ];
        }

        return rest_ensure_response($structure);
    },
    'permission_callback' => function () {
        $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
        return is_user_logged_in() && $nonce_valid;
    },
]);