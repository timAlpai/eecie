<?php

defined('ABSPATH') || exit;

// GET /devis
register_rest_route('eecie-crm/v1', '/devis', [
    'methods'  => 'GET',
    'callback' => function (WP_REST_Request $request) {
        // 1. Identifier l'utilisateur Baserow connecté
        $current_wp_user = wp_get_current_user();
        if (!$current_wp_user || !$current_wp_user->exists()) {
            return new WP_Error('not_logged_in', 'Utilisateur non connecté.', ['status' => 401]);
        }
        $t1_user_object = gce_get_baserow_t1_user_by_email($current_wp_user->user_email);
        if (!$t1_user_object) {
            return rest_ensure_response(['results' => []]); // Pas d'utilisateur Baserow, donc pas de devis
        }
        $current_user_baserow_id = $t1_user_object['id'];

        // 2. Récupérer toutes les opportunités pour trouver celles de l'utilisateur
        $opportunites_table_id = get_option('gce_baserow_table_opportunites') ?: eecie_crm_guess_table_id('Task_input');
        if (!$opportunites_table_id) return new WP_Error('no_opp_table', 'Table Opportunités introuvable.', ['status' => 500]);

        $all_opportunites = eecie_crm_baserow_get_all_paginated_results("rows/table/$opportunites_table_id/", ['user_field_names' => 'true']);
        if (is_wp_error($all_opportunites)) return $all_opportunites;

        // 3. Filtrer pour ne garder que les IDs des opportunités de l'utilisateur
        $my_opportunity_ids = [];
        foreach ($all_opportunites as $opp) {
            if (isset($opp['T1_user']) && is_array($opp['T1_user'])) {
                foreach ($opp['T1_user'] as $assigned_user) {
                    if ($assigned_user['id'] === $current_user_baserow_id) {
                        $my_opportunity_ids[] = $opp['id'];
                        break;
                    }
                }
            }
        }
        if (empty($my_opportunity_ids)) {
            return rest_ensure_response(['results' => []]); // Pas d'opportunités, donc pas de devis
        }

        // 4. Récupérer tous les devis
        $devis_table_id = get_option('gce_baserow_table_devis') ?: eecie_crm_guess_table_id('Devis');
        if (!$devis_table_id) return new WP_Error('no_devis_table', 'Table Devis introuvable.', ['status' => 500]);

        $all_devis = eecie_crm_baserow_get_all_paginated_results("rows/table/$devis_table_id/", ['user_field_names' => 'true']);
        if (is_wp_error($all_devis)) return $all_devis;

        // 5. Filtrer les devis pour ne garder que ceux liés aux opportunités de l'utilisateur
        $my_devis = [];
        foreach ($all_devis as $devis) {
            if (isset($devis['Task_input']) && is_array($devis['Task_input'])) {
                foreach ($devis['Task_input'] as $linked_opp) {
                    if (in_array($linked_opp['id'], $my_opportunity_ids)) {
                        $my_devis[] = $devis;
                        break; // Le devis est pertinent, on passe au suivant
                    }
                }
            }
        }

        // 6. Retourner la liste filtrée dans la structure attendue par le frontend
        return rest_ensure_response(['results' => $my_devis]);
    },
    'permission_callback' => function () {
        $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
        return is_user_logged_in() && $nonce_valid;
    },
]);

// GET /devis
register_rest_route('eecie-crm/v1', '/devis', [
    'methods'  => 'GET',
    'callback' => function () {
        $table_id = get_option('gce_baserow_table_devis') ?: eecie_crm_guess_table_id('Devis');
        if (!$table_id) return new WP_Error('no_table', 'Table Devis introuvable', ['status' => 500]);
        return eecie_crm_baserow_get("rows/table/$table_id/", ['user_field_names' => 'true']);
    },
    'permission_callback' => function () {
        $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
        return is_user_logged_in() && $nonce_valid;
    },
]);

// GET /devis/schema
register_rest_route('eecie-crm/v1', '/devis/schema', [
    'methods'  => 'GET',
    'callback' => function () {
        $table_id = get_option('gce_baserow_table_devis') ?: eecie_crm_guess_table_id('Devis');
        if (!$table_id) return new WP_Error('no_table', 'Table Devis introuvable', ['status' => 500]);
        return eecie_crm_baserow_get_fields($table_id);
    },
    'permission_callback' => function () {
        $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
        return is_user_logged_in() && $nonce_valid;
    },
]);

// PATCH /devis/{id}
register_rest_route('eecie-crm/v1', '/devis/(?P<id>\d+)', [
    'methods'  => 'PATCH',
    'callback' => function (WP_REST_Request $request) {
        $id = (int) $request['id'];
        $body = $request->get_json_params();
        $table_id = get_option('gce_baserow_table_devis') ?: eecie_crm_guess_table_id('Devis');
        if (!$table_id) return new WP_Error('no_table', 'Table Devis introuvable');
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

register_rest_route('eecie-crm/v1', '/devis', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $request) {
        $table_id = get_option('gce_baserow_table_devis') ?: eecie_crm_guess_table_id('Devis');
        if (!$table_id) return new WP_Error('no_table', 'Table Devis introuvable');

        $payload = $request->get_json_params();
        return eecie_crm_baserow_post("rows/table/$table_id/", $payload);
    },
    'permission_callback' => function () {
        return is_user_logged_in() &&
            isset($_SERVER['HTTP_X_WP_NONCE']) &&
            wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
    },
]);

register_rest_route('eecie-crm/v1', '/devis/(?P<id>\d+)', [
    'methods'  => 'DELETE',
    'callback' => function (WP_REST_Request $request) {
        $row_id = (int)$request['id'];
        $table_id = get_option('gce_baserow_table_devis') ?: eecie_crm_guess_table_id('Devis');
        if (!$table_id) return new WP_Error('no_table', 'Table Devis introuvable');

        $endpoint = "rows/table/$table_id/$row_id/";
        return eecie_crm_baserow_delete($endpoint);
    },
    'permission_callback' => function () {
        return is_user_logged_in() &&
            isset($_SERVER['HTTP_X_WP_NONCE']) &&
            wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
    },
]);

// NOUVELLE ROUTE : GET /devis/{id}/articles -> Récupère les articles pour un devis spécifique
register_rest_route('eecie-crm/v1', '/devis/(?P<id>\d+)/articles', [
    'methods'  => 'GET',
    'callback' => function (WP_REST_Request $request) {
        $devis_id = (int) $request['id'];

        $articles_table_id = get_option('gce_baserow_table_articles_devis') ?: eecie_crm_guess_table_id('Articles_devis');
        if (!$articles_table_id) {
            return new WP_Error('no_articles_table', 'Table Articles_devis introuvable.', ['status' => 500]);
        }

        // Pour filtrer, nous devons trouver l'ID du champ "Devis" dans la table "Articles_devis"
        $articles_fields = eecie_crm_baserow_get_fields($articles_table_id);
        if (is_wp_error($articles_fields)) {
            return $articles_fields;
        }

        $devis_link_field = array_values(array_filter($articles_fields, function ($field) {
            return $field['name'] === 'Devis';
        }))[0] ?? null;

        if (!$devis_link_field) {
            return new WP_Error('no_link_field', 'Champ de liaison "Devis" introuvable dans la table des articles.', ['status' => 500]);
        }

        $devis_link_field_id = $devis_link_field['id'];

        // On construit la requête avec un filtre qui dit : "où le champ_Devis contient l'ID du devis"
        $params = [
            'user_field_names' => 'true',
            // C'est le filtre magique : filter__field_{ID_DU_CHAMP}__link_row_has={ID_DE_LA_LIGNE_RECHERCHÉE}
            'filter__field_' . $devis_link_field_id . '__link_row_has' => $devis_id,
        ];

        // On utilise la fonction de pagination au cas où un devis aurait plus de 100 articles
        $related_articles = eecie_crm_baserow_get_all_paginated_results("rows/table/$articles_table_id/", $params);

        return rest_ensure_response($related_articles);
    },
    'permission_callback' => 'eecie_crm_check_capabilities',
]);

register_rest_route('eecie-crm/v1', '/devis/accept', [
    'methods'  => 'GET',
    'callback' => 'gce_handle_devis_acceptance',
    'permission_callback' => '__return_true', // Important: doit être accessible publiquement
]);

// --- NOUVELLE ROUTE POUR ENVOYER UN DEVIS BROUILLON ---
register_rest_route('eecie-crm/v1', '/devis/(?P<id>\d+)/send-draft', [
    'methods'             => 'POST',
    'callback'            => 'gce_send_devis_draft_callback',
    'permission_callback' => function () {
        return is_user_logged_in();
    },
    'args' => [
        'id' => [
            'validate_callback' => function ($param, $request, $key) {
                return is_numeric($param);
            }
        ],
    ],
]);

function gce_send_devis_draft_callback($request)
{
    $devis_id = (int) $request['id'];

    // Remplacez par votre URL de webhook n8n de PRODUCTION
    $n8n_webhook_url = 'https://n8n.eecie.ca/webhook/send-devis-draft';

    $response = wp_remote_post($n8n_webhook_url, [
        'method'    => 'POST',
        'headers'   => ['Content-Type' => 'application/json; charset=utf-8'],
        'body'      => json_encode(['devis_id' => $devis_id]),
        'timeout'   => 20, // Augmenter le timeout car n8n peut prendre du temps
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('n8n_trigger_failed', 'La communication avec le service d\'automatisation a échoué.', ['status' => 502]);
    }

    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code >= 300) {
        return new WP_Error('n8n_workflow_error', 'Le service d\'automatisation a retourné une erreur.', ['status' => $response_code]);
    }

    return new WP_REST_Response(['success' => true, 'message' => 'La demande d\'envoi a été transmise.'], 200);
}