<?php

defined('ABSPATH') || exit;

/***********************************************************************************************
 * PWA Employés route                                                                          *
 * *********************************************************************************************
 */

register_rest_route('eecie-crm/v1', '/auth/login', [
    'methods'  => 'POST',
    'callback' => 'gce_pwa_login',
    'permission_callback' => '__return_true',
]);

// Cette route vérifie maintenant le nonce, ce qui est beaucoup plus fiable
register_rest_route('eecie-crm/v1', '/taches/prioritaires', [
    'methods'  => 'GET',
    'callback' => 'gce_get_user_tasks_sorted',
    'permission_callback' => function ($request) {
        return wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest');
    },
]);

/**
 * ===================================================================
 * ==                 ROUTES POUR L'APP EMPLOYÉ                   ==
 * ===================================================================
 */

// NOUVELLE ROUTE POUR VALIDER LA CONNEXION DE LA PWA EMPLOYÉ
register_rest_route('eecie-crm/v1', '/auth/validate', [
    'methods'  => 'GET',
    'callback' => function () {
        // Si le code arrive jusqu'ici, c'est que la permission_callback a réussi.
        // L'utilisateur est donc bien authentifié.
        $user = wp_get_current_user();
        return new WP_REST_Response([
            'success' => true,
            'user' => [
                'id' => $user->ID,
                'email' => $user->user_email,
                'displayName' => $user->display_name,
            ]
        ], 200);
    },
    // On utilise la même permission simple et fiable que pour les prestataires.
    'permission_callback' => 'is_user_logged_in',
]);

/**********************************************************
 * FONCTION PWA EMPLOYES
 * ********************************************************
 */
function gce_get_user_tasks_sorted(WP_REST_Request $request)
{
    $current_wp_user = wp_get_current_user();
    if (!$current_wp_user || $current_wp_user->ID === 0) {
        return new WP_Error('not_logged_in', 'Utilisateur non authentifié.', ['status' => 401]);
    }
    // Le reste de la fonction est correct...
    $t1_user_object = gce_get_baserow_t1_user_by_email($current_wp_user->user_email);
    if (!$t1_user_object) return new WP_REST_Response([], 200);
    $taches_table_id = get_option('gce_baserow_table_taches') ?: eecie_crm_guess_table_id('Taches');
    if (!$taches_table_id) return new WP_Error('config_error', 'Table des tâches non configurée.', ['status' => 500]);
    $taches_schema = eecie_crm_baserow_get_fields($taches_table_id);
    $assigne_field_id = null;
    $statut_field_id = null;
    $terminer_option_id = null;
    foreach ($taches_schema as $field) {
        if ($field['name'] === 'assigne') $assigne_field_id = $field['id'];
        if ($field['name'] === 'statut') {
            $statut_field_id = $field['id'];
            foreach ($field['select_options'] as $option) {
                if ($option['value'] === 'Terminer') $terminer_option_id = $option['id'];
            }
        }
    }
    if (!$assigne_field_id || !$statut_field_id || !$terminer_option_id) return new WP_Error('schema_error', 'Champs requis introuvables.', ['status' => 500]);
    $params = ['user_field_names' => 'true', 'filter_type' => 'AND', 'filter__field_' . $assigne_field_id . '__link_row_has' => $t1_user_object['id'], 'filter__field_' . $statut_field_id . '__single_select_not_equal' => $terminer_option_id,];
    $all_tasks = eecie_crm_baserow_get_all_paginated_results("rows/table/{$taches_table_id}/", $params);
    if (empty($all_tasks)) return new WP_REST_Response([], 200);
    foreach ($all_tasks as &$task) {
        $score = 0;
        if (isset($task['priorite']['value'])) {
            switch ($task['priorite']['value']) {
                case 'Urgence':
                    $score += 1000;
                    break;
                case 'haute':
                    $score += 500;
                    break;
                case 'normal':
                    $score += 100;
                    break;
            }
        }
        if (isset($task['statut']['value']) && $task['statut']['value'] === 'Creation') {
            $score += 200;
        }
        if (!empty($task['date_echeance'])) {
            try {
                if (new DateTime($task['date_echeance']) < new DateTime()) {
                    $score += 300;
                }
            } catch (Exception $e) {
            }
        }
        $task['_score'] = $score;
    }
    usort($all_tasks, function ($a, $b) {
        return $b['_score'] <=> $a['_score'];
    });
    return new WP_REST_Response($all_tasks, 200);
}

function gce_pwa_login(WP_REST_Request $request)
{
    $params = $request->get_json_params();
    $creds = ['user_login' => sanitize_user($params['username'] ?? ''), 'user_password' => $params['password'] ?? '', 'remember' => true];
    $user = wp_signon($creds, is_ssl());
    if (is_wp_error($user)) {
        return new WP_Error('login_failed', 'Identifiants incorrects.', ['status' => 401]);
    }
    wp_set_current_user($user->ID);
    $tasks_response = gce_get_user_tasks_sorted(new WP_REST_Request());
    return new WP_REST_Response([
        'success' => true,
        'nonce'   => wp_create_nonce('wp_rest'), // La partie la plus importante
        'tasks'   => $tasks_response->get_data(),
        'user'    => ['display_name' => $user->display_name]
    ], 200);
}