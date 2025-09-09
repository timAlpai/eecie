<?php

defined('ABSPATH') || exit;

// GET /contacts
register_rest_route('eecie-crm/v1', '/contacts', [
    'methods'  => 'GET',
    'callback' => 'eecie_crm_get_contacts',
    'permission_callback' => function () {
        // Récupération du nonce par entête JS
        $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');

        return is_user_logged_in() && $nonce_valid;
    },
]);

// GET /contacts
register_rest_route('eecie-crm/v1', '/contacts', [
    'methods'  => 'GET',
    'callback' => function () {
        $table_id = get_option('gce_baserow_table_contacts') ?: eecie_crm_guess_table_id('Contacts');
        if (!$table_id) return new WP_Error('no_table', 'Table Contacts introuvable');
        return eecie_crm_baserow_get("rows/table/$table_id/", ['user_field_names' => 'true']);
    },
    'permission_callback' => function () {
        return is_user_logged_in() &&
            isset($_SERVER['HTTP_X_WP_NONCE']) &&
            wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
    },
]);

// GET /contacts/schema
register_rest_route('eecie-crm/v1', '/contacts/schema', [
    'methods'  => 'GET',
    'callback' => function () {
        $table_id = get_option('gce_baserow_table_contacts') ?: eecie_crm_guess_table_id('Contacts');
        if (!$table_id) return new WP_Error('no_table', 'Table Contacts introuvable');
        return eecie_crm_baserow_get_fields($table_id);
    },
    'permission_callback' => function () {
        return is_user_logged_in() &&
            isset($_SERVER['HTTP_X_WP_NONCE']) &&
            wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
    },
]);

// PATCH /contacts/{id}
register_rest_route('eecie-crm/v1', '/contacts/(?P<id>\d+)', [
    'methods'  => 'PATCH',
    'callback' => function (WP_REST_Request $request) {
        $id = (int)$request['id'];
        $table_id = get_option('gce_baserow_table_contacts') ?: eecie_crm_guess_table_id('Contacts');
        if (!$table_id) return new WP_Error('no_table', 'Table Contacts introuvable');
        $body = $request->get_json_params();

        $url = rtrim(get_option('gce_baserow_url'), '/') . "/api/database/rows/table/$table_id/$id/";
        $response = wp_remote_request($url, [
            'method' => 'PATCH',
            'headers' => [
                'Authorization' => 'Token ' . get_option('gce_baserow_api_key'),
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode($body),
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            return new WP_Error('baserow_error', "Erreur PATCH ($code)", ['status' => $code, 'body' => $body]);
        }

        return rest_ensure_response($body);
    },
    'permission_callback' => function () {
        return is_user_logged_in() &&
            isset($_SERVER['HTTP_X_WP_NONCE']) &&
            wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
    },
]);

// DELETE /contacts/{id}
register_rest_route('eecie-crm/v1', '/contacts/(?P<id>\d+)', [
    'methods'  => 'DELETE',
    'callback' => function (WP_REST_Request $request) {
        $id = (int)$request['id'];
        $table_id = get_option('gce_baserow_table_contacts') ?: eecie_crm_guess_table_id('Contacts');
        if (!$table_id) return new WP_Error('no_table', 'Table Contacts introuvable');
        return eecie_crm_baserow_delete("rows/table/$table_id/$id/");
    },
    'permission_callback' => function () {
        return is_user_logged_in() &&
            isset($_SERVER['HTTP_X_WP_NONCE']) &&
            wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
    },
]);

// POST /contacts
register_rest_route('eecie-crm/v1', '/contacts', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $request) {
        $table_id = get_option('gce_baserow_table_contacts') ?: eecie_crm_guess_table_id('Contacts');
        if (!$table_id) return new WP_Error('no_table', 'Table Contacts introuvable');
        $body = $request->get_json_params();
        return eecie_crm_baserow_post("rows/table/$table_id/", $body);
    },
    'permission_callback' => function () {
        $nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? '(absent)';
        $valid = wp_verify_nonce($nonce, 'wp_rest');
        $user = is_user_logged_in();


        return $user && $valid;
    },

]);