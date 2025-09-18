<?php

defined('ABSPATH') || exit;

register_rest_route('eecie-crm/v1', '/taches', [
    'methods'  => 'GET',

    'callback' => function (WP_REST_Request $request) {
        // ... (Toute la logique d'identification de l'utilisateur et de récupération des IDs de champs reste la même)
        $current_wp_user = wp_get_current_user();
        if (!$current_wp_user || !$current_wp_user->exists()) {
            return new WP_Error('not_logged_in', '...', ['status' => 401]);
        }
        $t1_user_object = gce_get_baserow_t1_user_by_email($current_wp_user->user_email);
        if (!$t1_user_object) {
            return rest_ensure_response([]);
        } // Retourne un tableau vide
        $taches_table_id = get_option('gce_baserow_table_taches') ?: eecie_crm_guess_table_id('Taches');
        if (!$taches_table_id) {
            return new WP_Error('no_table', '...', ['status' => 500]);
        }
        $fields = eecie_crm_baserow_get_fields($taches_table_id);
        if (is_wp_error($fields)) {
            return $fields;
        }
        $assigne_field = array_values(array_filter($fields, fn($f) => $f['name'] === 'assigne'))[0] ?? null;
        $statut_field = array_values(array_filter($fields, fn($f) => $f['name'] === 'statut'))[0] ?? null;
        if (!$assigne_field || !$statut_field) {
            return rest_ensure_response([]);
        }
        $terminer_option_id = null;
        $terminer_option = array_values(array_filter($statut_field['select_options'], fn($o) => $o['value'] === 'Terminer'))[0] ?? null;
        if ($terminer_option) {
            $terminer_option_id = $terminer_option['id'];
        }
        if (!$terminer_option_id) {
            return rest_ensure_response([]);
        }

        // On définit les paramètres de la requête UNE SEULE FOIS
        $params = [
            'user_field_names' => 'true',
            'size'             => 200, // On utilise la taille de lot maximale de Baserow
            'filter_type'      => 'AND',
        ];
        $params['filter__field_' . $assigne_field['id'] . '__link_row_has'] =  $t1_user_object['id'];  // '__link_row_contains'] = $t1_user_object['id'];
        $params['filter__field_' . $statut_field['id'] . '__single_select_not_equal'] = $terminer_option_id;

        $path = "rows/table/$taches_table_id/";

        // On appelle notre nouvelle fonction qui gère la pagination de Baserow
        $all_tasks = eecie_crm_baserow_get_all_paginated_results($path, $params);

        // On renvoie le tableau complet de toutes les tâches.
        return rest_ensure_response($all_tasks);
    },


    'permission_callback' => function () {
        $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
        return is_user_logged_in() && $nonce_valid;
    },
]);

register_rest_route('eecie-crm/v1', '/taches/schema', [
    'methods' => 'GET',
    'callback' => function () {
        $table_id = get_option('gce_baserow_table_taches') ?: eecie_crm_guess_table_id('Taches');
        if (!$table_id) return new WP_Error('no_table', 'Table Tâches introuvable', ['status' => 500]);

        $fields = eecie_crm_baserow_get_fields($table_id);
        return is_wp_error($fields) ? $fields : rest_ensure_response($fields);
    },
    'permission_callback' => function () {
        $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
        return is_user_logged_in() && $nonce_valid;
    },
]);

register_rest_route('eecie-crm/v1', '/taches/(?P<id>\d+)', [
    'methods'  => 'PATCH',
    'callback' => function (WP_REST_Request $request) {
        $id = (int) $request['id'];
        $body = $request->get_json_params();

        $table_id = get_option('gce_baserow_table_taches') ?: eecie_crm_guess_table_id('Taches');
        if (!$table_id) {
            return new WP_Error('no_table', 'Table inconnue');
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
        $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) &&
            wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
        return is_user_logged_in() && $nonce_valid;
    },
]);

// NOUVELLE ROUTE : POST /taches -> Pour la création d'une nouvelle tâche
register_rest_route('eecie-crm/v1', '/taches', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $request) {
        $table_id = get_option('gce_baserow_table_taches') ?: eecie_crm_guess_table_id('Taches');
        if (!$table_id) {
            return new WP_Error('no_table', 'Table des Tâches introuvable.', ['status' => 500]);
        }

        $payload = $request->get_json_params();
        // Utilise la fonction proxy POST générique qui existe déjà
        return eecie_crm_baserow_post("rows/table/$table_id/", $payload);
    },
    'permission_callback' => 'eecie_crm_check_capabilities',
]);

// Cette route vérifie maintenant le nonce, ce qui est beaucoup plus fiable
register_rest_route('eecie-crm/v1', '/taches/prioritaires', [
    'methods'  => 'GET',
    'callback' => 'gce_get_user_tasks_sorted',
    'permission_callback' => function ($request) {
        return wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest');
    },
]);