<?php

defined('ABSPATH') || exit;

// GET /articles_devis
register_rest_route('eecie-crm/v1', '/articles_devis', [
    'methods'  => 'GET',
    'callback' => function () {
        $table_id = get_option('gce_baserow_table_articles_devis') ?: eecie_crm_guess_table_id('Articles_devis');
        if (!$table_id) return new WP_Error('no_table', 'Table Articles_devis introuvable');
        return eecie_crm_baserow_get("rows/table/$table_id/", ['user_field_names' => 'true']);
    },
    'permission_callback' => function () {
        return is_user_logged_in() &&
            isset($_SERVER['HTTP_X_WP_NONCE']) &&
            wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
    },
]);

// GET /articles_devis/schema
register_rest_route('eecie-crm/v1', '/articles_devis/schema', [
    'methods'  => 'GET',
    'callback' => function () {
        $table_id = get_option('gce_baserow_table_articles_devis') ?: eecie_crm_guess_table_id('Articles_devis');
        if (!$table_id) return new WP_Error('no_table', 'Table Articles_devis introuvable');
        return eecie_crm_baserow_get_fields($table_id);
    },
    'permission_callback' => function () {
        return is_user_logged_in() &&
            isset($_SERVER['HTTP_X_WP_NONCE']) &&
            wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
    },
]);

// PATCH /articles_devis/{id}
register_rest_route('eecie-crm/v1', '/articles_devis/(?P<id>\d+)', [
    'methods'  => 'PATCH',
    'callback' => function (WP_REST_Request $request) {
        $id = (int) $request['id'];
        $body = $request->get_json_params();
        $table_id = get_option('gce_baserow_table_articles_devis') ?: eecie_crm_guess_table_id('Articles_devis');
        if (!$table_id) return new WP_Error('no_table', 'Table Articles_devis introuvable');

        $baseUrl = rtrim(get_option('gce_baserow_url'), '/');
        $token = get_option('gce_baserow_api_key');
        $url = "$baseUrl/api/database/rows/table/$table_id/$id/";

        $response = wp_remote_request($url, [
            'method' => 'PATCH',
            'headers' => [
                'Authorization' => 'Token ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status !== 200) {
            return new WP_Error('baserow_api_error', "Erreur Baserow ($status)", ['status' => $status, 'details' => $body]);
        }

        return rest_ensure_response($body);
    },
    'permission_callback' => function () {
        return is_user_logged_in() &&
            isset($_SERVER['HTTP_X_WP_NONCE']) &&
            wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
    },
]);

// DELETE /articles_devis/{id}
register_rest_route('eecie-crm/v1', '/articles_devis/(?P<id>\d+)', [
    'methods'  => 'DELETE',
    'callback' => function (WP_REST_Request $request) {
        $row_id = (int) $request['id'];
        $table_id = get_option('gce_baserow_table_articles_devis') ?: eecie_crm_guess_table_id('Articles_devis');
        if (!$table_id) return new WP_Error('no_table', 'Table Articles_devis introuvable');

        return eecie_crm_baserow_delete("rows/table/$table_id/$row_id/");
    },
    'permission_callback' => function () {
        return is_user_logged_in() &&
            isset($_SERVER['HTTP_X_WP_NONCE']) &&
            wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
    },
]);

// POST /articles_devis
register_rest_route('eecie-crm/v1', '/articles_devis', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $request) {
        $table_id = get_option('gce_baserow_table_articles_devis') ?: eecie_crm_guess_table_id('Articles_devis');
        if (!$table_id) return new WP_Error('no_table', 'Table Articles_devis introuvable');

        $payload = $request->get_json_params();
        return eecie_crm_baserow_post("rows/table/$table_id/", $payload);
    },
    'permission_callback' => function () {
        return is_user_logged_in() &&
            isset($_SERVER['HTTP_X_WP_NONCE']) &&
            wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
    },
]);