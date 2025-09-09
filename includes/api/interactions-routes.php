<?php

defined('ABSPATH') || exit;

register_rest_route('eecie-crm/v1', '/interactions', [
    'methods'  => 'GET',
    'callback' => function () {
        $table_id = get_option('gce_baserow_table_interactions') ?: eecie_crm_guess_table_id('Interactions');
        if (!$table_id) return new WP_Error('no_table', 'Table Interactions introuvable', ['status' => 500]);

        return eecie_crm_baserow_get("rows/table/$table_id/", ['user_field_names' => 'true']);
    },
    'permission_callback' => function () {
        $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
        return is_user_logged_in() && $nonce_valid;
    },
]);

register_rest_route('eecie-crm/v1', '/interactions/schema', [
    'methods'  => 'GET',
    'callback' => function () {
        $table_id = get_option('gce_baserow_table_interactions') ?: eecie_crm_guess_table_id('Interactions');
        if (!$table_id) return new WP_Error('no_table', 'Table Interactions introuvable', ['status' => 500]);

        return eecie_crm_baserow_get_fields($table_id);
    },
    'permission_callback' => function () {
        return is_user_logged_in() &&
            isset($_SERVER['HTTP_X_WP_NONCE']) &&
            wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
    },
]);

register_rest_route('eecie-crm/v1', '/interactions', [
    'methods'  => 'GET',
    'callback' => function () {
        $table_id = get_option('gce_baserow_table_interactions') ?: eecie_crm_guess_table_id('Interactions');
        if (!$table_id) return new WP_Error('no_table', 'Table Interactions introuvable', ['status' => 500]);

        return eecie_crm_baserow_get("rows/table/$table_id/", ['user_field_names' => 'true']);
    },
    'permission_callback' => function () {
        return is_user_logged_in() &&
            isset($_SERVER['HTTP_X_WP_NONCE']) &&
            wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
    },
]);

register_rest_route('eecie-crm/v1', '/interactions/(?P<id>\d+)', [
    'methods'  => 'PATCH',
    'callback' => function (WP_REST_Request $request) {
        $id = (int) $request['id'];
        $body = $request->get_json_params();

        $table_id = get_option('gce_baserow_table_interactions') ?: eecie_crm_guess_table_id('Interactions');
        if (!$table_id) {
            return new WP_Error('no_table', 'Table Interactions inconnue');
        }

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
            return new WP_Error('baserow_error', $response->get_error_message(), ['status' => 502]);
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status !== 200) {
            return new WP_Error('baserow_api_error', "Erreur Baserow ($status)", ['status' => $status, 'details' => $body]);
        }

        return rest_ensure_response($body);
    },
    'permission_callback' => function () {
        $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
        return is_user_logged_in() && $nonce_valid;
    },
]);

register_rest_route('eecie-crm/v1', '/interactions/(?P<id>\d+)', [
    'methods'  => 'DELETE',
    'callback' => function (WP_REST_Request $request) {
        $row_id = (int) $request['id'];
        $table_id = get_option('gce_baserow_table_interactions') ?: eecie_crm_guess_table_id('Interactions');
        if (!$table_id) {
            return new WP_Error('no_table', 'Table Interactions introuvable');
        }

        return eecie_crm_baserow_delete("rows/table/$table_id/$row_id/");
    },
    'permission_callback' => function () {
        return is_user_logged_in() &&
            isset($_SERVER['HTTP_X_WP_NONCE']) &&
            wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
    },
]);

// POST /interactions (pour la création)
register_rest_route('eecie-crm/v1', '/interactions', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $request) {
        // 1. Trouver l'ID de la table des interactions
        $table_id = get_option('gce_baserow_table_interactions') ?: eecie_crm_guess_table_id('Interactions');
        if (!$table_id) {
            return new WP_Error('no_table', 'La table des Interactions est introuvable ou non configurée.', ['status' => 500]);
        }

        // 2. Récupérer les données envoyées par le popup
        $payload = $request->get_json_params();
        if (empty($payload)) {
            return new WP_Error('invalid_payload', 'Aucune donnée reçue pour la création.', ['status' => 400]);
        }

        // 3. Utiliser le proxy pour envoyer la requête POST à Baserow
        // La fonction eecie_crm_baserow_post existe déjà et fait tout le travail.
        return eecie_crm_baserow_post("rows/table/$table_id/", $payload);
    },
    'permission_callback' => function () {
        // Sécurité standard: utilisateur connecté + nonce valide
        return is_user_logged_in() &&
            isset($_SERVER['HTTP_X_WP_NONCE']) &&
            wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
    },
]);