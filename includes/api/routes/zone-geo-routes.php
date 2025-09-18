<?php

defined('ABSPATH') || exit;

// ==========================================
// ==         ROUTES POUR ZONE_GEO         ==
// ==========================================

// GET /zone_geo -> Récupère toutes les zones
register_rest_route('eecie-crm/v1', '/zone_geo', [
    'methods'  => 'GET',
    'callback' => function () {
        $table_id = get_option('gce_baserow_table_zone_geo') ?: eecie_crm_guess_table_id('Zone_geo');
        if (!$table_id) return new WP_Error('no_table', 'Table Zone_geo introuvable', ['status' => 500]);
        return eecie_crm_baserow_get("rows/table/$table_id/", ['user_field_names' => 'true']);
    },
    'permission_callback' => 'eecie_crm_check_capabilities',
]);

// GET /zone_geo/schema -> Récupère la structure
register_rest_route('eecie-crm/v1', '/zone_geo/schema', [
    'methods'  => 'GET',
    'callback' => function () {
        $table_id = get_option('gce_baserow_table_zone_geo') ?: eecie_crm_guess_table_id('Zone_geo');
        if (!$table_id) return new WP_Error('no_table', 'Table Zone_geo introuvable', ['status' => 500]);
        return eecie_crm_baserow_get_fields($table_id);
    },
    'permission_callback' => 'eecie_crm_check_capabilities',
]);

// POST /zone_geo -> Crée une nouvelle zone
register_rest_route('eecie-crm/v1', '/zone_geo', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $request) {
        $table_id = get_option('gce_baserow_table_zone_geo') ?: eecie_crm_guess_table_id('Zone_geo');
        if (!$table_id) return new WP_Error('no_table', 'Table Zone_geo introuvable');
        $body = $request->get_json_params();
        return eecie_crm_baserow_post("rows/table/$table_id/", $body);
    },
    'permission_callback' => 'eecie_crm_check_capabilities',
]);

// PATCH /zone_geo/{id} -> Met à jour une zone
register_rest_route('eecie-crm/v1', '/zone_geo/(?P<id>\d+)', [
    'methods'  => 'PATCH',
    'callback' => function (WP_REST_Request $request) {
        $id = (int)$request['id'];
        $table_id = get_option('gce_baserow_table_zone_geo') ?: eecie_crm_guess_table_id('Zone_geo');
        if (!$table_id) return new WP_Error('no_table', 'Table Zone_geo introuvable');

        $baseUrl = rtrim(get_option('gce_baserow_url'), '/');
        $token = get_option('gce_baserow_api_key');
        $url = "$baseUrl/api/database/rows/table/$table_id/$id/";
        $body = $request->get_json_params();

        $response = wp_remote_request($url, [
            'method' => 'PATCH',
            'headers' => ['Authorization' => 'Token ' . $token, 'Content-Type'  => 'application/json'],
            'body' => json_encode($body)
        ]);

        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) return new WP_Error('baserow_error', "Erreur PATCH ($code)", ['status' => $code, 'body' => $body]);
        return rest_ensure_response($body);
    },
    'permission_callback' => 'eecie_crm_check_capabilities',
]);

// DELETE /zone_geo/{id} -> Supprime une zone
register_rest_route('eecie-crm/v1', '/zone_geo/(?P<id>\d+)', [
    'methods'  => 'DELETE',
    'callback' => function (WP_REST_Request $request) {
        $id = (int)$request['id'];
        $table_id = get_option('gce_baserow_table_zone_geo') ?: eecie_crm_guess_table_id('Zone_geo');
        if (!$table_id) return new WP_Error('no_table', 'Table Zone_geo introuvable');
        return eecie_crm_baserow_delete("rows/table/$table_id/$id/");
    },
    'permission_callback' => 'eecie_crm_check_capabilities',
]);