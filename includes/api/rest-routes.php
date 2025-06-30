<?php
defined('ABSPATH') || exit;

require_once __DIR__ . '/baserow-proxy.php';

add_action('rest_api_init', function () {
    register_rest_route('eecie-crm/v1', '/contacts', [
        'methods'  => 'GET',
        'callback' => 'eecie_crm_get_contacts',
        'permission_callback' => function () {
            // Récupération du nonce par entête JS
            $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');

            return is_user_logged_in() && $nonce_valid;
        },
    ]);

    // route utilisateur (employés)
    register_rest_route('eecie-crm/v1', '/utilisateurs', [
        'methods'  => 'GET',
        'callback' => 'eecie_crm_get_utilisateurs',
        'permission_callback' => function () {
            $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
            return is_user_logged_in() && $nonce_valid;
        },
    ]);

    // get table fields and structure

    register_rest_route('eecie-crm/v1', '/utilisateurs/schema', [
        'methods' => 'GET',
        'callback' => function () {
            $table_id = get_option('gce_baserow_table_utilisateurs') ?: eecie_crm_guess_table_id('T1_user');
            if (!$table_id) return new WP_Error('no_table', 'Table inconnue');

            $fields = eecie_crm_baserow_get_fields($table_id);
            return is_wp_error($fields) ? $fields : rest_ensure_response($fields);
        },
        'permission_callback' => function () {
            $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
            return is_user_logged_in() && $nonce_valid;
        },
    ]);

    register_rest_route('eecie-crm/v1', '/utilisateurs/(?P<id>\d+)', [
        'methods'  => 'PUT',
        'callback' => 'eecie_crm_update_utilisateur',
        'permission_callback' => function () {
            $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) &&
                wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
            return is_user_logged_in() && $nonce_valid;
        },
    ]);

    register_rest_route('eecie-crm/v1', '/utilisateurs', [
        'methods'  => 'POST',
        'callback' => 'eecie_crm_create_utilisateur',
        'permission_callback' => function () {
            $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) &&
                wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
            return is_user_logged_in() && $nonce_valid;
        },
    ]);

    register_rest_route('eecie-crm/v1', '/utilisateurs/(?P<id>\d+)', [
        'methods'  => 'DELETE',
        'callback' => 'eecie_crm_delete_utilisateur',
        'permission_callback' => function () {
            $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) &&
                wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
            return is_user_logged_in() && $nonce_valid;
        },
    ]);
    // ===== Opportunités =====
    register_rest_route('eecie-crm/v1', '/opportunites', [
        'methods'  => 'GET',
        'callback' => 'eecie_crm_get_opportunites',
        'permission_callback' => function () {
            $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE'])
                && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
            return is_user_logged_in() && $nonce_valid;
        },
    ]);
    register_rest_route('eecie-crm/v1', '/opportunites/schema', [
        'methods'  => 'GET',
        'callback' => function () {
            // slug « opportunites » => nom Baserow « Task_input »
            $table_id = get_option('gce_baserow_table_opportunites')
                ?: eecie_crm_guess_table_id('Task_input');
            if (!$table_id) {
                return new WP_Error('no_table', 'Table Opportunités introuvable', ['status' => 500]);
            }
            $fields = eecie_crm_baserow_get_fields($table_id);
            return is_wp_error($fields) ? $fields : rest_ensure_response($fields);
        },
        'permission_callback' => function () {
            $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE'])
                && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
            return is_user_logged_in() && $nonce_valid;
        },
    ]);

   register_rest_route('eecie-crm/v1', '/row/(?P<table_slug>[a-z_]+)/(?P<id>\d+)', [
        'methods'  => 'GET',
        'callback' => function (WP_REST_Request $request) {
            $slug = sanitize_text_field($request['table_slug']);
            $row_id = (int) $request['id'];

            // Table de correspondance pour les cas où le slug ne matche pas le nom de la table
            $slug_to_baserow_name_map = [
                'opportunites'   => 'Task_input',
                'utilisateurs'   => 'T1_user',
                'articles_devis' => 'Articles_devis',
                // Ajoutez ici d'autres exceptions si nécessaire
            ];

            // 1. On cherche si un ID de table est défini manuellement dans les options. C'est prioritaire.
            $option_key = 'gce_baserow_table_' . $slug;
            $table_id = get_option($option_key);

            // 2. Si pas d'ID manuel, on essaie de deviner.
            if (!$table_id) {
                // On regarde dans notre map si le slug a un nom spécial.
                // Sinon, on prend le slug et on met la première lettre en majuscule (pour "contacts", "devis", etc.)
                $baserow_name_to_guess = $slug_to_baserow_name_map[$slug] ?? ucfirst($slug);
                
                $table_id = eecie_crm_guess_table_id($baserow_name_to_guess);
            }

            if (!$table_id) {
                return new WP_Error('invalid_table', "Impossible de trouver la table Baserow pour le slug `$slug`", ['status' => 404]);
            }

            $data = eecie_crm_baserow_get("rows/table/$table_id/$row_id/?user_field_names=true");
            
            if (is_wp_error($data)) {
                return $data;
            }

            // Pour aider le JS, on peut lui redonner le slug, même si ce n'est plus strictement nécessaire
            // avec la dernière version du JS. C'est une bonne pratique.
            $data['rest_slug_for_popup'] = $slug;

            return rest_ensure_response($data);
        },
        'permission_callback' => function () {
            $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
            return is_user_logged_in() && $nonce_valid;
        },
    ]);
    register_rest_route('eecie-crm/v1', '/opportunites/(?P<id>\d+)', [
        'methods'  => 'PATCH',
        'callback' => function (WP_REST_Request $request) {
            $id = (int) $request['id'];
            $body = $request->get_json_params();
            $table_id = get_option('gce_baserow_table_opportunites') ?: eecie_crm_guess_table_id('Task_input');
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
    register_rest_route('eecie-crm/v1', '/taches', [
        'methods'  => 'GET',
        'callback' => function () {
            $table_id = get_option('gce_baserow_table_taches') ?: eecie_crm_guess_table_id('Taches');
            if (!$table_id) return new WP_Error('no_table', 'Table Tâches introuvable', ['status' => 500]);

            $rows = eecie_crm_baserow_get("rows/table/$table_id/", ['user_field_names' => 'true']);
            return is_wp_error($rows) ? $rows : rest_ensure_response($rows);
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

    register_rest_route('eecie-crm/v1', '/proxy/start-task', [
        'methods'  => 'POST',
        'callback' => function (WP_REST_Request $req) {
            $body = $req->get_body();

            $response = wp_remote_post("https://n8n.eecie.ca/webhook/StartTask", [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body'    => $body,
                'timeout' => 15
            ]);

            if (is_wp_error($response)) {
                return new WP_Error('proxy_failed', $response->get_error_message(), ['status' => 500]);
            }

            return rest_ensure_response([
                'code' => wp_remote_retrieve_response_code($response),
                'body' => wp_remote_retrieve_body($response)
            ]);
        },
        'permission_callback' => function () {
            return is_user_logged_in(); // ou __return_true si tu veux public
        }
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
    register_rest_route('eecie-crm/v1', '/appels', [
        'methods'  => 'GET',
        'callback' => function () {
            $table_id = get_option('gce_baserow_table_appels') ?: eecie_crm_guess_table_id('Appels');
            if (!$table_id) return new WP_Error('no_table', 'Table Appels introuvable', ['status' => 500]);

            return eecie_crm_baserow_get("rows/table/$table_id/", ['user_field_names' => 'true']);
        },
        'permission_callback' => function () {
            $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
            return is_user_logged_in() && $nonce_valid;
        },
    ]);

    register_rest_route('eecie-crm/v1', '/appels/schema', [
        'methods'  => 'GET',
        'callback' => function () {
            $table_id = get_option('gce_baserow_table_appels') ?: eecie_crm_guess_table_id('Appels');
            if (!$table_id) return new WP_Error('no_table', 'Table Appels introuvable', ['status' => 500]);

            return eecie_crm_baserow_get_fields($table_id);
        },
        'permission_callback' => function () {
            $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
            return is_user_logged_in() && $nonce_valid;
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
            $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
            return is_user_logged_in() && $nonce_valid;
        },
    ]);
    register_rest_route('eecie-crm/v1', '/appels/(?P<id>\d+)', [
        'methods'  => 'PATCH',
        'callback' => function (WP_REST_Request $request) {
            $id = (int) $request['id'];
            $body = $request->get_json_params();

            $table_id = get_option('gce_baserow_table_appels') ?: eecie_crm_guess_table_id('Appels');
            if (!$table_id) {
                return new WP_Error('no_table', 'Table Appels inconnue');
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
 register_rest_route('eecie-crm/v1', '/proxy/calculate-devis', [
        'methods'  => 'POST',
        'callback' => function (WP_REST_Request $req) {
            // IMPORTANT : Remplacez cette URL par l'URL réelle de votre webhook n8n
            $n8n_webhook_url = "https://n8n.eecie.ca/webhook/fc9a0dba-704b-4391-9190-4db7a33a85b0"; 
            
            $body = $req->get_body(); // On transmet les données du devis reçues du JS

            $response = wp_remote_post($n8n_webhook_url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body'    => $body,
                'timeout' => 180 // Augmentez le timeout si le workflow est long
            ]);

            if (is_wp_error($response)) {
                return new WP_Error('proxy_failed', $response->get_error_message(), ['status' => 502]);
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            // On vérifie si n8n a répondu avec un code de succès (2xx)
            if ($response_code < 200 || $response_code >= 300) {
                 return new WP_Error(
                    'n8n_error', 
                    'Le service de calcul a retourné une erreur.', 
                    ['status' => $response_code, 'details' => $response_body]
                );
            }

            return rest_ensure_response([
                'status' => 'success',
                'n8n_response_code' => $response_code,
                'n8n_response_body' => json_decode($response_body) // On décode la réponse de n8n
            ]);
        },
        'permission_callback' => function () {
            // Sécurisé pour les utilisateurs connectés avec un nonce valide
            return is_user_logged_in() && 
                   isset($_SERVER['HTTP_X_WP_NONCE']) &&
                   wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
        }
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

    error_log("[CRM] nonce = $nonce / valide = " . ($valid ? '✅' : '❌') . " / connecté = " . ($user ? '✅' : '❌'));

    return $user && $valid;
},

]);

 register_rest_route('eecie-crm/v1', '/row/(?P<table_slug>[a-z_]+)/(?P<id>\d+)', [
        'methods'  => 'GET',
        'callback' => function (WP_REST_Request $request) {
            $slug = sanitize_text_field($request['table_slug']);
            $row_id = (int) $request['id'];

            // V2 - CORRECTION : Table de correspondance slug -> nom réel dans Baserow
            $slug_to_baserow_name_map = [
                'opportunites' => 'Task_input',
                'utilisateurs' => 'T1_user',
                // Ajoutez ici d'autres exceptions si nécessaire
            ];

            $option_key = 'gce_baserow_table_' . $slug;
            $table_id = get_option($option_key); // 1. On cherche une valeur manuelle d'abord

            if (!$table_id) {
                // 2. Si pas de valeur manuelle, on consulte notre table de correspondance
                $baserow_name_to_guess = $slug_to_baserow_name_map[$slug] ?? ucfirst($slug);
                $table_id = eecie_crm_guess_table_id($baserow_name_to_guess);
            }

            if (!$table_id) {
                return new WP_Error('invalid_table', "Table inconnue pour slug `$slug`", ['status' => 400]);
            }

            $data = eecie_crm_baserow_get("rows/table/$table_id/$row_id/?user_field_names=true");

            return is_wp_error($data) ? $data : rest_ensure_response($data);
        },
        'permission_callback' => function () {
            $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
            return is_user_logged_in() && $nonce_valid;
        },
    ]);
});



function eecie_crm_get_opportunites(WP_REST_Request $request)
{
    $table_id = get_option('gce_baserow_table_opportunites')
        ?: eecie_crm_guess_table_id('Task_input');
    if (!$table_id) {
        return new WP_Error('no_table', 'Table Opportunités introuvable', ['status' => 500]);
    }
    // On récupère tout pour filtrer côté client
    $rows = eecie_crm_baserow_get("rows/table/$table_id/", ['user_field_names' => 'true']);
    return is_wp_error($rows) ? $rows : rest_ensure_response($rows);
}


function eecie_crm_create_utilisateur(WP_REST_Request $request)
{
    $table_id = get_option('gce_baserow_table_utilisateurs') ?: eecie_crm_guess_table_id('T1_user');
    if (!$table_id) {
        return new WP_Error('missing_table_id', 'ID de table introuvable.', ['status' => 500]);
    }

    $payload = $request->get_json_params();
    if (!is_array($payload)) {
        return new WP_Error('invalid_data', 'Format JSON invalide.', ['status' => 400]);
    }

    $response = eecie_crm_baserow_post("rows/table/$table_id/", $payload);

    return is_wp_error($response)
        ? $response
        : rest_ensure_response($response);
}


function eecie_crm_delete_utilisateur(WP_REST_Request $request)
{
    $row_id = (int) $request->get_param('id');
    $table_id = get_option('gce_baserow_table_utilisateurs') ?: eecie_crm_guess_table_id('T1_user');

    if (!$table_id || !$row_id) {
        return new WP_Error('invalid_request', 'Table ID ou Row ID manquant.', ['status' => 400]);
    }

    $response = eecie_crm_baserow_delete("rows/table/$table_id/$row_id/");

    return is_wp_error($response)
        ? $response
        : rest_ensure_response(['success' => true]);
}


function eecie_crm_baserow_delete($endpoint)
{
    $base_url = rtrim(get_option('gce_baserow_url'), '/');
    $token = get_option('gce_baserow_api_key');

    if (!$base_url || !$token) {
        return new WP_Error('baserow_credentials', 'Baserow credentials not configured.');
    }

    $url = $base_url . '/api/database/' . ltrim($endpoint, '/');

    $response = wp_remote_request($url, [
        'method'  => 'DELETE',
        'headers' => [
            'Authorization' => 'Token ' . $token,
        ],
        'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);

    if ($code >= 400) {
        return new WP_Error('baserow_http_error', 'Erreur Baserow: ' . $code);
    }

    return true;
}


function eecie_crm_check_capabilities()
{
    $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
    return is_user_logged_in() && $nonce_valid;
}


function eecie_crm_get_contacts(WP_REST_Request $request)
{
    // audessus test
    $table_id = get_option('gce_baserow_table_contacts') ?: eecie_crm_guess_table_id('Contacts');

    if (!$table_id) {
        return new WP_Error('missing_table_id', 'ID de table non configuré', ['status' => 500]);
    }

    $data = eecie_crm_baserow_get("rows/table/$table_id/", ['user_field_names' => 'true']);
    return is_wp_error($data) ? $data : rest_ensure_response($data);
}


function eecie_crm_baserow_post($endpoint, $payload = [])
{
    $base_url = rtrim(get_option('gce_baserow_url'), '/');
    $token = get_option('gce_baserow_api_key');

    if (!$base_url || !$token) {
        return new WP_Error('baserow_credentials', 'Baserow credentials not configured.');
    }

    $url = $base_url . '/api/database/' . ltrim($endpoint, '/');

    $response = wp_remote_post($url, [
        'headers' => [
            'Authorization' => 'Token ' . $token,
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode($payload),
        'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code >= 400 || !$body) {
        return new WP_Error('baserow_http_error', 'Baserow error: ' . $code, ['body' => $body]);
    }

    return $body;
}



function eecie_crm_get_utilisateurs(WP_REST_Request $request)
{
    $manual = get_option('gce_baserow_table_utilisateurs');
    $table_id = $manual ?: eecie_crm_guess_table_id('T1_user');

    if (!$table_id) {
        return new WP_Error('no_table', 'Impossible de détecter la table des utilisateurs.', ['status' => 500]);
    }

    $rows = eecie_crm_baserow_get("rows/table/$table_id/", ['user_field_names' => 'true']);
    return is_wp_error($rows) ? $rows : rest_ensure_response($rows);
}

function eecie_crm_update_utilisateur(WP_REST_Request $request)
{
    $id = (int) $request['id'];
    $body = $request->get_json_params();

    $table_id = get_option('gce_baserow_table_utilisateurs') ?: eecie_crm_guess_table_id('T1_user');
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
}
