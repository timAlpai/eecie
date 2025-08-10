 <?php

    defined('ABSPATH') || exit;

    require_once __DIR__ . '/baserow-proxy.php';
    require_once GCE_PLUGIN_DIR . 'includes/shared/security.php';

    /**
     * Fonction d'aide pour récupérer une ligne Baserow par son slug et son ID.
     * C'est la logique de la route GET /row/{slug}/{id} factorisée pour un usage interne en PHP.
     *
     * @param string $slug Le slug de la table (ex: 'opportunites', 'contacts').
     * @param int $row_id L'ID de la ligne.
     * @return array|WP_Error Les données de la ligne ou une erreur.
     */
    function eecie_crm_get_row_by_slug_and_id($slug, $row_id)
    {
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
            $baserow_name_to_guess = $slug_to_baserow_name_map[$slug] ?? ucfirst($slug);
            $table_id = eecie_crm_guess_table_id($baserow_name_to_guess);
        }

        if (!$table_id) {
            return new WP_Error('invalid_table', "Impossible de trouver la table Baserow pour le slug `$slug`", ['status' => 404]);
        }

        // On utilise le proxy existant pour récupérer les données
        return eecie_crm_baserow_get("rows/table/$table_id/$row_id/", ['user_field_names' => 'true']);
    }
    add_filter('rest_pre_dispatch', function ($result, $server, $request) {
        // ... votre liste blanche de routes publiques ...
        $public_routes = [
            '#^/eecie-crm/v1/devis/accept$#',
            '#^/eecie-crm/v1/rdv/verify$#',
            '#^/eecie-crm/v1/rdv/submit-schedule$#',
        ];

        $route = $request->get_route();
        $is_public = false;

        foreach ($public_routes as $pattern) {
            if (preg_match($pattern, $route)) {
                $is_public = true;
                break;
            }
        }

        // Voici la ligne problématique :
        if (!$is_public && !is_user_logged_in()) {
            return new WP_Error(
                'rest_not_logged_in',
                'Vous devez être connecté pour effectuer cette action.',
                ['status' => 401]
            );
        }

        return $result;
    }, 10, 3);

    add_action('rest_api_init', function () {

        // Route spéciale pour générer un éditeur de texte riche
        register_rest_route('eecie-crm/v1', '/get-wp-editor', [
            'methods'  => 'POST', // On utilise POST pour pouvoir envoyer des paramètres comme le contenu initial
            'callback' => function (WP_REST_Request $request) {
                $params = $request->get_json_params();
                $content = $params['content'] ?? ''; // Contenu initial de l'éditeur
                $editor_id = $params['editor_id'] ?? 'gce_rich_text_editor'; // ID unique pour l'éditeur
                $settings = [
                    'textarea_name' => $editor_id, // <-- CORRECTION CRUCIALE
                    'media_buttons' => false,
                    'textarea_rows' => 10,
                    'tinymce'       => [
                        'toolbar1' => 'bold,italic,underline,bullist,numlist,link,unlink,undo,redo',
                        'toolbar2' => '',
                    ],
                ];

                ob_start();
                wp_editor($content, $editor_id, $settings);
                $editor_html = ob_get_clean();

                // On retourne le HTML brut
                return rest_ensure_response([
                    'html'     => $editor_html,
                    'settings' => $settings // On renvoie aussi les paramètres
                ]);
            },
            'permission_callback' => function () {
                return is_user_logged_in() &&
                    isset($_SERVER['HTTP_X_WP_NONCE']) &&
                    wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
            },
        ]);

        // ==========================================
        // ==         ROUTES POUR FOURNISSEURS       ==
        // ==========================================

        // GET /fournisseurs -> Récupère tous les fournisseurs
        register_rest_route('eecie-crm/v1', '/fournisseurs', [
            'methods'  => 'GET',
            'callback' => function () {
                $table_id = get_option('gce_baserow_table_fournisseurs') ?: eecie_crm_guess_table_id('Fournisseur');
                if (!$table_id) return new WP_Error('no_table', 'Table Fournisseur introuvable', ['status' => 500]);
                return eecie_crm_baserow_get("rows/table/$table_id/", ['user_field_names' => 'true']);
            },
            'permission_callback' => 'eecie_crm_check_capabilities',
        ]);

        // GET /fournisseurs/schema -> Récupère la structure de la table
        register_rest_route('eecie-crm/v1', '/fournisseurs/schema', [
            'methods'  => 'GET',
            'callback' => function () {
                $table_id = get_option('gce_baserow_table_fournisseurs') ?: eecie_crm_guess_table_id('Fournisseur');
                if (!$table_id) return new WP_Error('no_table', 'Table Fournisseur introuvable', ['status' => 500]);
                return eecie_crm_baserow_get_fields($table_id);
            },
            'permission_callback' => 'eecie_crm_check_capabilities',
        ]);

        // POST /fournisseurs -> Crée un nouveau fournisseur
        register_rest_route('eecie-crm/v1', '/fournisseurs', [
            'methods'  => 'POST',
            'callback' => function (WP_REST_Request $request) {
                $table_id = get_option('gce_baserow_table_fournisseurs') ?: eecie_crm_guess_table_id('Fournisseur');
                if (!$table_id) return new WP_Error('no_table', 'Table Fournisseur introuvable');
                $body = $request->get_json_params();
                return eecie_crm_baserow_post("rows/table/$table_id/", $body);
            },
            'permission_callback' => 'eecie_crm_check_capabilities',
        ]);

        // PATCH /fournisseurs/{id} -> Met à jour un fournisseur
        register_rest_route('eecie-crm/v1', '/fournisseurs/(?P<id>\d+)', [
            'methods'  => 'PATCH',
            'callback' => function (WP_REST_Request $request) {
                $id = (int)$request['id'];
                $table_id = get_option('gce_baserow_table_fournisseurs') ?: eecie_crm_guess_table_id('Fournisseur');
                if (!$table_id) return new WP_Error('no_table', 'Table Fournisseur introuvable');

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

        // DELETE /fournisseurs/{id} -> Supprime un fournisseur
        register_rest_route('eecie-crm/v1', '/fournisseurs/(?P<id>\d+)', [
            'methods'  => 'DELETE',
            'callback' => function (WP_REST_Request $request) {
                $id = (int)$request['id'];
                $table_id = get_option('gce_baserow_table_fournisseurs') ?: eecie_crm_guess_table_id('Fournisseur');
                if (!$table_id) return new WP_Error('no_table', 'Table Fournisseur introuvable');
                return eecie_crm_baserow_delete("rows/table/$table_id/$id/");
            },
            'permission_callback' => 'eecie_crm_check_capabilities',
        ]);


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
        register_rest_route('eecie-crm/v1', '/fournisseurs/create-with-contact', [
            'methods'  => 'POST',
            'callback' => function (WP_REST_Request $request) {
                $payload = $request->get_json_params();

                // 1. Valider les données reçues
                if (empty($payload['fournisseur_data']) || empty($payload['contact_data'])) {
                    return new WP_Error('invalid_payload', 'Données du fournisseur ou du contact manquantes.', ['status' => 400]);
                }

                $contact_data = $payload['contact_data'];
                $fournisseur_data = $payload['fournisseur_data'];

                // 2. Trouver l'ID de la table des Contacts
                $contact_table_id = get_option('gce_baserow_table_contacts') ?: eecie_crm_guess_table_id('Contacts');
                if (!$contact_table_id) {
                    return new WP_Error('no_contact_table', 'Table des Contacts introuvable.', ['status' => 500]);
                }

                // 3. Créer le contact D'ABORD
                $new_contact = eecie_crm_baserow_post("rows/table/$contact_table_id/", $contact_data);
                if (is_wp_error($new_contact)) {
                    return new WP_Error('contact_creation_failed', 'La création du contact a échoué.', ['status' => 500, 'details' => $new_contact->get_error_message()]);
                }
                $new_contact_id = $new_contact['id'];

                // 4. Préparer les données du fournisseur en injectant l'ID du nouveau contact
                $fournisseur_table_id = get_option('gce_baserow_table_fournisseurs') ?: eecie_crm_guess_table_id('Fournisseur');
                if (!$fournisseur_table_id) {
                    return new WP_Error('no_supplier_table', 'Table des Fournisseurs introuvable.', ['status' => 500]);
                }

                $fournisseur_fields = eecie_crm_baserow_get_fields($fournisseur_table_id);
                if (is_wp_error($fournisseur_fields)) {
                    return new WP_Error('schema_fetch_failed_fournisseur', 'Impossible de lire la structure des Fournisseurs.', ['status' => 500]);
                }

                $contacts_link_field_in_fournisseur = array_values(array_filter($fournisseur_fields, function ($field) {
                    return $field['name'] === 'Contacts';
                }))[0] ?? null;
                if (!$contacts_link_field_in_fournisseur) {
                    return new WP_Error('no_link_field', 'Champ de liaison "Contacts" introuvable dans Fournisseur.', ['status' => 500]);
                }

                $fournisseur_data['field_' . $contacts_link_field_in_fournisseur['id']] = [$new_contact_id];

                // 5. Créer le fournisseur
                $new_fournisseur = eecie_crm_baserow_post("rows/table/$fournisseur_table_id/", $fournisseur_data);
                if (is_wp_error($new_fournisseur)) {
                    // Idéalement, on supprimerait le contact orphelin ici.
                    return new WP_Error('supplier_creation_failed', 'Le contact a été créé, mais la création du fournisseur a échoué.', ['status' => 500, 'details' => $new_fournisseur->get_error_message()]);
                }
                $new_fournisseur_id = $new_fournisseur['id'];

                // --- DÉBUT DE LA NOUVELLE ÉTAPE ---
                // 6. Mettre à jour le contact pour le lier en retour au nouveau fournisseur
                $contact_fields = eecie_crm_baserow_get_fields($contact_table_id);
                if (is_wp_error($contact_fields)) {
                    return new WP_Error('schema_fetch_failed_contact', 'Impossible de lire la structure des Contacts.', ['status' => 500]);
                }

                $fournisseur_link_field_in_contact = array_values(array_filter($contact_fields, function ($field) {
                    return $field['name'] === 'Fournisseur';
                }))[0] ?? null;
                if (!$fournisseur_link_field_in_contact) {
                    return new WP_Error('no_link_field_in_contact', 'Champ de liaison "Fournisseur" introuvable dans Contacts.', ['status' => 500]);
                }

                $update_payload = [
                    'field_' . $fournisseur_link_field_in_contact['id'] => [$new_fournisseur_id]
                ];

                $baseUrl = rtrim(get_option('gce_baserow_url'), '/');
                $token = get_option('gce_baserow_api_key');
                $update_url = "$baseUrl/api/database/rows/table/$contact_table_id/$new_contact_id/";

                wp_remote_request($update_url, [
                    'method' => 'PATCH',
                    'headers' => ['Authorization' => 'Token ' . $token, 'Content-Type'  => 'application/json'],
                    'body' => json_encode($update_payload)
                ]);
                // On ne vérifie pas la réponse pour ne pas bloquer le flux, c'est une mise à jour "au mieux".
                // --- FIN DE LA NOUVELLE ÉTAPE ---

                // 7. Tout s'est bien passé, on renvoie le nouveau fournisseur
                return rest_ensure_response($new_fournisseur);
            },

            'permission_callback' => function () {
                // Récupération du nonce par entête JS
                $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');

                return is_user_logged_in() && $nonce_valid;
            },


        ]);
        register_rest_route('eecie-crm/v1', '/contacts', [
            'methods'  => 'GET',
            'callback' => 'eecie_crm_get_contacts',
            'permission_callback' => function () {
                // Récupération du nonce par entête JS
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
            'callback' => 'eecie_crm_get_appels_view_data',
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

        register_rest_route('eecie-crm/v1', '/proxy/calculate-devis', [
            'methods'  => 'POST',
            'callback' => 'eecie_crm_calculate_and_forward_devis_to_n8n',
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
            'callback' => 'eecie_crm_get_utilisateurs_secure',
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

        // NOUVELLE ROUTE SÉCURISÉE POUR METTRE À JOUR LE MOT DE PASSE
        register_rest_route('eecie-crm/v1', '/utilisateurs/(?P<id>\d+)/update-password', [
            'methods'  => 'PATCH',
            'callback' => function (WP_REST_Request $request) {
                $user_id = (int)$request['id'];
                $body = $request->get_json_params();
                $new_password = $body['password'] ?? null;

                if (empty($new_password)) {
                    return new WP_Error('no_password', 'Le nouveau mot de passe est manquant.', ['status' => 400]);
                }

                // Chiffrer le nouveau mot de passe
                $encrypted_password = gce_encrypt_shared_data($new_password);
                if ($encrypted_password === false) {
                    return new WP_Error('encryption_failed', 'La clé de chiffrement partagée n\'est pas configurée.', ['status' => 500]);
                }

                // Préparer le payload pour Baserow
                $table_id = get_option('gce_baserow_table_utilisateurs') ?: eecie_crm_guess_table_id('T1_user');
                if (!$table_id) return new WP_Error('no_table', 'Table utilisateurs introuvable.');

                $schema = eecie_crm_baserow_get_fields($table_id);
                $sec1_field = array_values(array_filter($schema, fn($f) => $f['name'] === 'sec1'))[0] ?? null;

                if (!$sec1_field) {
                    return new WP_Error('no_sec1_field', 'Le champ sec1 est introuvable dans le schéma Baserow.', ['status' => 500]);
                }

                $payload = [
                    'field_' . $sec1_field['id'] => $encrypted_password,
                ];

                // Envoyer la mise à jour à Baserow (copié depuis eecie_crm_update_utilisateur)
                $baseUrl = rtrim(get_option('gce_baserow_url'), '/');
                $token = get_option('gce_baserow_api_key');
                $url = "$baseUrl/api/database/rows/table/$table_id/$user_id/";

                $response = wp_remote_request($url, [
                    'method' => 'PATCH',
                    'headers' => ['Authorization' => 'Token ' . $token, 'Content-Type'  => 'application/json'],
                    'body' => json_encode($payload)
                ]);

                if (is_wp_error($response)) return $response;
                $code = wp_remote_retrieve_response_code($response);
                if ($code !== 200) return new WP_Error('baserow_error', "Erreur PATCH ($code)");

                return rest_ensure_response(['success' => true, 'message' => 'Mot de passe mis à jour.']);
            },
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

        register_rest_route('eecie-crm/v1', '/proxy/calculate-devis', [
            'methods'  => 'POST',
            'callback' => 'eecie_crm_calculate_and_forward_devis_to_n8n',
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

        register_rest_route('eecie-crm/v1', '/upload-file', [
            'methods'  => 'POST',
            'callback' => function (WP_REST_Request $request) {
                $files = $request->get_file_params();

                if (empty($files['file'])) {
                    return new WP_Error('no_file', 'Aucun fichier n\'a été envoyé.', ['status' => 400]);
                }

                $file = $files['file']; // ['name' => ..., 'type' => ..., 'tmp_name' => ..., 'error' => ..., 'size' => ...]

                // Utilise notre nouvelle fonction proxy
                $result = eecie_crm_baserow_upload_file($file['tmp_name'], $file['name']);

                if (is_wp_error($result)) {
                    return $result;
                }

                // Baserow renvoie un objet avec les détails du fichier. On le renvoie au client.
                return rest_ensure_response($result);
            },
            'permission_callback' => function () {
                // Sécurité : utilisateur connecté + nonce valide
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

        // NOUVEL ENDPOINT EFFICACE POUR LE LAZY LOADING
        register_rest_route('eecie-crm/v1', '/opportunites/(?P<id>\d+)/related-data', [
            'methods'  => 'GET',
            'callback' => function (WP_REST_Request $request) {
                $opp_id = (int)$request['id'];

                $results = [
                    'taches'       => [],
                    'appels'       => [],
                    'interactions' => [],
                    'devis'        => [],
                    'articles'     => [],
                ];

                // 1. Récupérer les Tâches liées (AVEC FILTRE)
                $taches_table_id = get_option('gce_baserow_table_taches') ?: eecie_crm_guess_table_id('Taches');
                if ($taches_table_id) {
                    $taches_schema = eecie_crm_baserow_get_fields($taches_table_id);
                    if (!is_wp_error($taches_schema)) {
                        $opp_link_in_taches = array_values(array_filter($taches_schema, fn($f) => $f['name'] === 'opportunite'))[0] ?? null;

                        // --- DÉBUT DE LA CORRECTION ---
                        // On trouve le champ 'statut' et l'ID de l'option 'Terminer'
                        $statut_field = array_values(array_filter($taches_schema, fn($f) => $f['name'] === 'statut'))[0] ?? null;
                        $terminer_option_id = null;
                        if ($statut_field && isset($statut_field['select_options'])) {
                            $terminer_option = array_values(array_filter($statut_field['select_options'], fn($o) => $o['value'] === 'Terminer'))[0] ?? null;
                            if ($terminer_option) {
                                $terminer_option_id = $terminer_option['id'];
                            }
                        }
                        // --- FIN DE LA CORRECTION ---

                        if ($opp_link_in_taches) {
                            $params = [
                                'user_field_names' => 'true',
                                'filter_type'      => 'AND', // On s'assure que les deux filtres s'appliquent
                                'filter__field_' . $opp_link_in_taches['id'] . '__link_row_has' => $opp_id
                            ];

                            // --- AJOUT DU FILTRE DE STATUT ---
                            if ($terminer_option_id) {
                                $params['filter__field_' . $statut_field['id'] . '__single_select_not_equal'] = $terminer_option_id;
                            }
                            // --- FIN DE L'AJOUT ---

                            $results['taches'] = eecie_crm_baserow_get_all_paginated_results("rows/table/$taches_table_id/", $params);
                        }
                    }
                }

                // 2. Récupérer les Appels liés (inchangé)
                $appels_table_id = get_option('gce_baserow_table_appels') ?: eecie_crm_guess_table_id('Appels');
                if ($appels_table_id) {
                    $appels_schema = eecie_crm_baserow_get_fields($appels_table_id);
                    if (!is_wp_error($appels_schema)) {
                        $opp_link_in_appels = array_values(array_filter($appels_schema, fn($f) => $f['name'] === 'Opportunité'))[0] ?? null;
                        if ($opp_link_in_appels) {
                            $params = ['user_field_names' => 'true', 'filter__field_' . $opp_link_in_appels['id'] . '__link_row_has' => $opp_id];
                            $results['appels'] = eecie_crm_baserow_get_all_paginated_results("rows/table/$appels_table_id/", $params);
                        }
                    }
                }

                // 3. Récupérer les Interactions liées (inchangé)
                $interactions_table_id = get_option('gce_baserow_table_interactions') ?: eecie_crm_guess_table_id('Interactions');
                if ($interactions_table_id) {
                    $interactions_schema = eecie_crm_baserow_get_fields($interactions_table_id);
                    if (!is_wp_error($interactions_schema)) {
                        $opp_link_in_interactions = array_values(array_filter($interactions_schema, fn($f) => $f['name'] === 'opportunité'))[0] ?? null;
                        if ($opp_link_in_interactions) {
                            $params = ['user_field_names' => 'true', 'filter__field_' . $opp_link_in_interactions['id'] . '__link_row_has' => $opp_id];
                            $results['interactions'] = eecie_crm_baserow_get_all_paginated_results("rows/table/$interactions_table_id/", $params);
                        }
                    }
                }

                // 4. --- AJOUTER TOUTE CETTE NOUVELLE SECTION ---
                // Récupérer les Devis et leurs Articles liés
                $devis_table_id = get_option('gce_baserow_table_devis') ?: eecie_crm_guess_table_id('Devis');
                if ($devis_table_id) {
                    $devis_schema = eecie_crm_baserow_get_fields($devis_table_id);
                    if (!is_wp_error($devis_schema)) {
                        $opp_link_in_devis = array_values(array_filter($devis_schema, fn($f) => $f['name'] === 'Task_input'))[0] ?? null;
                        if ($opp_link_in_devis) {
                            $params = ['user_field_names' => 'true', 'filter__field_' . $opp_link_in_devis['id'] . '__link_row_has' => $opp_id];
                            $devis_lies = eecie_crm_baserow_get_all_paginated_results("rows/table/$devis_table_id/", $params);
                            $results['devis'] = $devis_lies;

                            // Si on a trouvé des devis, on va chercher leurs articles
                            if (!empty($devis_lies)) {
                                $articles_table_id = get_option('gce_baserow_table_articles_devis') ?: eecie_crm_guess_table_id('Articles_devis');
                                if ($articles_table_id) {
                                    $articles_schema = eecie_crm_baserow_get_fields($articles_table_id);
                                    if (!is_wp_error($articles_schema)) {
                                        $devis_link_in_articles = array_values(array_filter($articles_schema, fn($f) => $f['name'] === 'Devis'))[0] ?? null;
                                        if ($devis_link_in_articles) {
                                            $all_articles = [];
                                            foreach ($devis_lies as $devis) {
                                                $params_articles = ['user_field_names' => 'true', 'filter__field_' . $devis_link_in_articles['id'] . '__link_row_has' => $devis['id']];
                                                $articles_pour_ce_devis = eecie_crm_baserow_get_all_paginated_results("rows/table/$articles_table_id/", $params_articles);
                                                $all_articles = array_merge($all_articles, $articles_pour_ce_devis);
                                            }
                                            $results['articles'] = $all_articles;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                return rest_ensure_response($results);
            },
            'permission_callback' => 'eecie_crm_check_capabilities',
        ]);
        register_rest_route('eecie-crm/v1', '/devis/accept', [
            'methods'  => 'GET',
            'callback' => 'gce_handle_devis_acceptance',
            'permission_callback' => '__return_true', // Important: doit être accessible publiquement
        ]);
        // À ajouter dans la fonction rest_api_init de includes/api/rest-routes.php

        // Route pour vérifier un token de RDV
        register_rest_route('eecie-crm/v1', '/rdv/verify', [
            'methods'  => 'GET',
            'callback' => function (WP_REST_Request $request) {
                $rdv_id = (int)$request->get_param('id');
                $token = sanitize_text_field($request->get_param('token'));

                $rdv_table_id = eecie_crm_guess_table_id('Rendezvous_Fournisseur');
                $rdv_data = eecie_crm_baserow_get("rows/table/{$rdv_table_id}/{$rdv_id}/?user_field_names=true");

                if (is_wp_error($rdv_data) || $rdv_data['Validation_Token'] !== $token || !empty($rdv_data['Date_Heure_RDV'])) {
                    return new WP_Error('invalid_token', 'Token invalide ou déjà utilisé.', ['status' => 403]);
                }

                return rest_ensure_response(['success' => true, 'opportunite' => $rdv_data['Opportunite_liee'][0]['value']]);
            },
            'permission_callback' => '__return_true',
        ]);
        /**
         * ===================================================================
         * ==                 ROUTES POUR L'APP FOURNISSEUR                 ==
         * ===================================================================
         */

        // 1. ROUTE POUR RÉCUPÉRER LES MISSIONS ASSIGNÉES
        // GET https://portal.eecie.ca/wp-json/eecie-crm/v1/fournisseur/mes-jobs
        register_rest_route('eecie-crm/v1', '/fournisseur/mes-jobs', [
            'methods'  => 'GET',
            'callback' => 'gce_api_get_fournisseur_jobs',
            'permission_callback' => 'gce_api_check_jwt_permission', // <- Utilise notre fonction de sécurité
        ]);

        // 2. ROUTE POUR SOUMETTRE LE RAPPORT DE LIVRAISON
        // POST https://portal.eecie.ca/wp-json/eecie-crm/v1/livraison/submit-report
        register_rest_route('eecie-crm/v1', '/livraison/submit-report', [
            'methods'  => 'POST',
            'callback' => 'gce_api_submit_livraison_report',
            'permission_callback' => 'gce_api_check_jwt_permission', // <- Utilise la même fonction
        ]);

        // Route pour soumettre la date du RDV
        register_rest_route('eecie-crm/v1', '/rdv/submit-schedule', [
            'methods'  => 'POST',
            'callback' => function (WP_REST_Request $request) {
                $params = $request->get_json_params();
                $rdv_id = (int)$params['id'];
                $token = sanitize_text_field($params['token']);
                $date_heure_iso = sanitize_text_field($params['date_heure']);

                $rdv_table_id = eecie_crm_guess_table_id('Rendez_vous_fournisseur');
                // On récupère les données avec les noms de champs système (field_XXX) pour la validation
                $rdv_data = eecie_crm_baserow_get("rows/table/{$rdv_table_id}/{$rdv_id}/");
                if (is_wp_error($rdv_data)) return $rdv_data;

                // On doit trouver les IDs des champs à partir de leur nom
                $rdv_schema = eecie_crm_baserow_get_fields($rdv_table_id);
                $token_field_id = null;
                $date_field_id = null;
                foreach ($rdv_schema as $field) {
                    if ($field['name'] === 'Validation_Token') $token_field_id = $field['id'];
                    if ($field['name'] === 'Date_Heure_RDV') $date_field_id = $field['id'];
                }

                if (!$token_field_id || !$date_field_id) {
                    return new WP_Error('schema_error', 'Champs critiques introuvables.', ['status' => 500]);
                }

                // Vérification de sécurité
                if ($rdv_data["field_{$token_field_id}"] !== $token || !empty($rdv_data["field_{$date_field_id}"])) {
                    return new WP_Error('invalid_submission', 'Lien invalide ou déjà utilisé.', ['status' => 403]);
                }

                // Baserow attend un format ISO 8601 UTC
                $date_utc = gmdate('Y-m-d\TH:i:s.u\Z', strtotime($date_heure_iso));

                // Mettre à jour le RDV dans Baserow
                $payload = ["field_{$date_field_id}" => $date_utc];
                $update_result = eecie_crm_baserow_patch("rows/table/{$rdv_table_id}/{$rdv_id}/", $payload);

                if (is_wp_error($update_result)) {
                    return $update_result;
                }

                return rest_ensure_response(['success' => true]);
            },
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('eecie-crm/v1', '/opportunites/(?P<id>\d+)/reset', [
            'methods'  => 'POST',
            'callback' => function (WP_REST_Request $request) {
                $opp_id = (int)$request['id'];

                // --- PARTIE 1 : PRÉPARATION (inchangée) ---
                $current_wp_user = wp_get_current_user();
                $baserow_user = gce_get_baserow_t1_user_by_email($current_wp_user->user_email);
                $current_baserow_user_id = $baserow_user ? $baserow_user['id'] : null;

                $log_table_id = get_option('gce_baserow_table_log_reset_opportunite') ?: eecie_crm_guess_table_id('Log_reset_opportunite');
                if (!$log_table_id) return new WP_Error('no_log_table', 'La table de logs n\'est pas configurée.', ['status' => 500]);

                $log_schema = eecie_crm_baserow_get_fields($log_table_id);
                if (is_wp_error($log_schema)) return new WP_Error('log_schema_error', 'Impossible de lire la structure de la table de logs.', ['status' => 500]);

                $log_field_map = [];
                foreach ($log_schema as $field) {
                    $log_field_map[$field['name']] = 'field_' . $field['id'];
                }

                $required_log_fields = ['Date', 'Opportunite_liee', 'Element_efface', 'Details', 'Nom_element_efface', 'ID_element_efface', 'Utilisateur_action'];
                foreach ($required_log_fields as $field_name) {
                    if (!isset($log_field_map[$field_name])) return new WP_Error('log_schema_incomplete', "Le champ '{$field_name}' est manquant dans la table de log.", ['status' => 500]);
                }

                $log_action = function ($item_type, $item_data) use ($log_table_id, $opp_id, $log_field_map, $current_baserow_user_id) {
                    $item_name = $item_data['Nom'] ?? $item_data['Name'] ?? $item_data['titre'] ?? ($item_type . " #" . ($item_data['id'] ?? 'inconnu'));
                    $log_payload = [
                        $log_field_map['Date'] => gmdate('Y-m-d\TH:i:s\Z'),
                        $log_field_map['Opportunite_liee'] => [$opp_id],
                        $log_field_map['Element_efface'] => $item_type,
                        $log_field_map['Details'] => json_encode($item_data, JSON_PRETTY_PRINT),
                        $log_field_map['Nom_element_efface'] => (string)$item_name,
                        $log_field_map['ID_element_efface'] => (int)$item_data['id'],
                        $log_field_map['Utilisateur_action'] => $current_baserow_user_id ? [$current_baserow_user_id] : [],
                    ];
                    eecie_crm_baserow_post("rows/table/{$log_table_id}/", $log_payload);
                };

                // --- PARTIE 2 : LOGIQUE DE SUPPRESSION (MODIFIÉE) ---
                $opportunite = eecie_crm_get_row_by_slug_and_id('opportunites', $opp_id);
                if (is_wp_error($opportunite)) return $opportunite;

                // Traitement spécial pour les Devis et Articles (inchangé)
                if (!empty($opportunite['Devis']) && is_array($opportunite['Devis'])) {
                    foreach ($opportunite['Devis'] as $devis_link) {
                        $devis_details = eecie_crm_get_row_by_slug_and_id('devis', $devis_link['id']);
                        if (!is_wp_error($devis_details)) {
                            if (!empty($devis_details['Articles_devis']) && is_array($devis_details['Articles_devis'])) {
                                foreach ($devis_details['Articles_devis'] as $article_link) {
                                    $article_details = eecie_crm_get_row_by_slug_and_id('articles_devis', $article_link['id']);
                                    eecie_crm_baserow_delete("rows/table/" . (get_option('gce_baserow_table_articles_devis') ?: eecie_crm_guess_table_id('Articles_devis')) . "/" . $article_link['id'] . "/");
                                    $log_action('Article Devis', $article_details);
                                }
                            }
                            eecie_crm_baserow_delete("rows/table/" . (get_option('gce_baserow_table_devis') ?: eecie_crm_guess_table_id('Devis')) . "/" . $devis_link['id'] . "/");
                            $log_action('Devis', $devis_details);
                        }
                    }
                }

                // --- NOUVEAU BLOC : Traitement spécial pour les Appels et Interactions imbriqués ---
                if (!empty($opportunite['Appels']) && is_array($opportunite['Appels'])) {
                    foreach ($opportunite['Appels'] as $appel_link) {
                        // On récupère l'appel complet pour trouver ses interactions
                        $appel_details = eecie_crm_get_row_by_slug_and_id('appels', $appel_link['id']);
                        if (!is_wp_error($appel_details)) {
                            // 1. Supprimer les interactions liées à CET appel
                            if (!empty($appel_details['Interactions']) && is_array($appel_details['Interactions'])) {
                                foreach ($appel_details['Interactions'] as $interaction_link) {
                                    $interaction_details = eecie_crm_get_row_by_slug_and_id('interactions', $interaction_link['id']);
                                    eecie_crm_baserow_delete("rows/table/" . (get_option('gce_baserow_table_interactions') ?: eecie_crm_guess_table_id('Interactions')) . "/" . $interaction_link['id'] . "/");
                                    $log_action('Interaction', $interaction_details);
                                }
                            }
                            // 2. Supprimer l'appel lui-même
                            eecie_crm_baserow_delete("rows/table/" . (get_option('gce_baserow_table_appels') ?: eecie_crm_guess_table_id('Appels')) . "/" . $appel_link['id'] . "/");
                            $log_action('Appel', $appel_details);
                        }
                    }
                }

                // --- Boucle générique simplifiée (ne contient plus que les Tâches) ---
                // Note : On pourrait même supprimer cette boucle et traiter les tâches directement, mais la garder est plus flexible pour le futur.
                $relations_to_delete = ['Taches' => 'taches'];
                foreach ($relations_to_delete as $baserow_field_name => $slug) {
                    if (!empty($opportunite[$baserow_field_name]) && is_array($opportunite[$baserow_field_name])) {
                        $table_id_to_delete_from = get_option('gce_baserow_table_' . $slug) ?: eecie_crm_guess_table_id(ucfirst($slug));
                        if ($table_id_to_delete_from) {
                            foreach ($opportunite[$baserow_field_name] as $item_link) {
                                $item_details = eecie_crm_get_row_by_slug_and_id($slug, $item_link['id']);
                                eecie_crm_baserow_delete("rows/table/{$table_id_to_delete_from}/{$item_link['id']}/");
                                $log_action($baserow_field_name, $item_details);
                            }
                        }
                    }
                }

                // --- PARTIE 3 : MISE À JOUR DE L'OPPORTUNITÉ (inchangée) ---
                $opp_table_id = get_option('gce_baserow_table_opportunites') ?: eecie_crm_guess_table_id('Task_input');
                $opp_schema = eecie_crm_baserow_get_fields($opp_table_id);
                $status_field = array_values(array_filter($opp_schema, fn($f) => $f['name'] === 'Status'))[0] ?? null;
                $assigner_option = $status_field ? array_values(array_filter($status_field['select_options'], fn($o) => $o['value'] === 'Assigner'))[0] ?? null : null;
                $reset_count_field = array_values(array_filter($opp_schema, fn($f) => $f['name'] === 'Reset_count'))[0] ?? null;

                if (!$status_field || !$assigner_option || !$reset_count_field) return new WP_Error('schema_error', 'Impossible de trouver les champs Status ou Reset_count.', ['status' => 500]);


                // --- PARTIE 3 : MISE À JOUR DE L'OPPORTUNITÉ (CORRIGÉE) ---
                $opp_table_id = get_option('gce_baserow_table_opportunites') ?: eecie_crm_guess_table_id('Task_input');
                $opp_schema = eecie_crm_baserow_get_fields($opp_table_id);

                // Helper pour trouver un champ par son nom
                $find_field_id = function ($name) use ($opp_schema) {
                    foreach ($opp_schema as $field) {
                        if ($field['name'] === $name) {
                            return $field['id'];
                        }
                    }
                    return null;
                };

                $status_field_id = $find_field_id('Status');
                $reset_count_field_id = $find_field_id('Reset_count');
                $progression_field_id = $find_field_id('Progression');
                $devis_sent_field_id = $find_field_id('Devis_sent_client');
                $devis_sent_id_field_id = $find_field_id('DevisSentInteractionId');
                $dernier_status_field_id = $find_field_id('Dernier_status_ok');

                $assigner_option_id = null;
                $status_field_data = array_values(array_filter($opp_schema, fn($f) => $f['name'] === 'Status'))[0] ?? null;
                if ($status_field_data) {
                    $assigner_option = array_values(array_filter($status_field_data['select_options'], fn($o) => $o['value'] === 'Assigner'))[0] ?? null;
                    if ($assigner_option) {
                        $assigner_option_id = $assigner_option['id'];
                    }
                }

                if (!$status_field_id || !$assigner_option_id || !$reset_count_field_id) {
                    return new WP_Error('schema_error', 'Impossible de trouver les champs requis (Status, Reset_count) pour la mise à jour finale.', ['status' => 500]);
                }

                $current_reset_count = (int)($opportunite['Reset_count'] ?? 0);
                $update_payload = [
                    'field_' . $status_field_id => $assigner_option_id,
                    'field_' . $reset_count_field_id => $current_reset_count + 1,

                    // --- CHAMPS AJOUTÉS POUR LA RÉINITIALISATION COMPLÈTE ---
                    'field_' . $progression_field_id => 0,
                    'field_' . $devis_sent_field_id => false,
                    'field_' . $devis_sent_id_field_id => null,
                    'field_' . $dernier_status_field_id => null,
                ];

                // On s'assure de ne pas envoyer de clés pour des champs qui n'ont pas été trouvés
                $update_payload = array_filter($update_payload, function ($key) {
                    // La clé est 'field_XXXX'. On extrait XXXX.
                    $field_id = substr($key, 6);
                    return !empty($field_id);
                }, ARRAY_FILTER_USE_KEY);
                $baseUrl = rtrim(get_option('gce_baserow_url'), '/');
                $token = get_option('gce_baserow_api_key');
                $url = "$baseUrl/api/database/rows/table/$opp_table_id/$opp_id/";

                $response = wp_remote_request($url, [
                    'method' => 'PATCH',
                    'headers' => ['Authorization' => 'Token ' . $token, 'Content-Type'  => 'application/json'],
                    'body' => json_encode($update_payload)
                ]);
                if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return new WP_Error('update_failed', 'La mise à jour finale de l\'opportunité a échoué.', ['status' => 500]);

                return rest_ensure_response(['success' => true, 'message' => 'Opportunité réinitialisée avec succès.']);
            },
            'permission_callback' => 'eecie_crm_check_capabilities',
        ]);


         // ROUTE DE DÉBOGAGE TEMPORAIRE
    register_rest_route('eecie-crm/v1', '/debug/server-vars', [
        'methods' => 'GET',
        'callback' => function() {
            return new WP_REST_Response($_SERVER, 200);
        },
        'permission_callback' => '__return_true' // Ouvert à tous pour le test
    ]);
    }); //fin api init



    function eecie_crm_get_utilisateurs_secure(WP_REST_Request $request)
    {
        $manual = get_option('gce_baserow_table_utilisateurs');
        $table_id = $manual ?: eecie_crm_guess_table_id('T1_user');

        if (!$table_id) {
            return new WP_Error('no_table', 'Impossible de détecter la table des utilisateurs.', ['status' => 500]);
        }

        $response = eecie_crm_baserow_get("rows/table/$table_id/", ['user_field_names' => 'true']);

        if (is_wp_error($response)) {
            return $response;
        }

        // --- C'EST LA PARTIE IMPORTANTE ---
        // On supprime le champ 'sec1' avant de l'envoyer au client.
        if (isset($response['results']) && is_array($response['results'])) {
            foreach ($response['results'] as &$user) { // Notez le '&' pour modifier le tableau directement
                unset($user['sec1']);
            }
        }

        return rest_ensure_response($response);
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






    function eecie_crm_get_opportunites(WP_REST_Request $request)
    {
        // 1. Identifier l'utilisateur Baserow connecté
        $current_wp_user = wp_get_current_user();
        if (!$current_wp_user || !$current_wp_user->exists()) {
            return new WP_Error('not_logged_in', 'Utilisateur non connecté.', ['status' => 401]);
        }
        $t1_user_object = gce_get_baserow_t1_user_by_email($current_wp_user->user_email);
        if (!$t1_user_object) {
            return rest_ensure_response(['results' => []]); // Pas d'utilisateur Baserow, donc pas d'opportunités
        }

        // 2. Récupérer les IDs de la table et du champ de liaison
        $table_id = get_option('gce_baserow_table_opportunites') ?: eecie_crm_guess_table_id('Task_input');
        if (!$table_id) {
            return new WP_Error('no_table', 'Table Opportunités introuvable', ['status' => 500]);
        }

        $fields = eecie_crm_baserow_get_fields($table_id);
        if (is_wp_error($fields)) return $fields;

        $assigne_field = array_values(array_filter($fields, fn($f) => $f['name'] === 'T1_user'))[0] ?? null;
        if (!$assigne_field) {
            return new WP_Error('no_link_field', 'Champ de liaison T1_user introuvable.', ['status' => 500]);
        }
        // On garde cette partie pour avoir l'ID du champ Status
        $status_field = array_values(array_filter($fields, fn($f) => $f['name'] === 'Status'))[0] ?? null;

        // 3. Construire la requête avec les filtres Baserow
        $params = [
            'user_field_names' => 'true',
            'size'             => 200,
            'filter_type'      => 'AND',
            // Votre filtre qui fonctionne déjà
            'filter__field_' . $assigne_field['id'] . '__link_row_has' => $t1_user_object['Name'],
        ];


        // 4. Appeler la fonction qui gère la pagination et retourner les résultats
        $path = "rows/table/$table_id/";
        $my_opportunites = eecie_crm_baserow_get_all_paginated_results($path, $params);

        // Le frontend s'attend à un objet avec une clé "results"
        return rest_ensure_response(['results' => $my_opportunites]);
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
        // Si 'sec1' est présent dans la requête, on le chiffre
        if (isset($payload['sec1']) && !empty($payload['sec1'])) {
            $encrypted = gce_encrypt_shared_data($payload['sec1']);
            if ($encrypted) {
                $payload['sec1'] = $encrypted;
            } else {
                unset($payload['sec1']);
            }
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
    //Modif devis tim









    //Modif devis tim

    function eecie_crm_calculate_and_forward_devis_to_n8n(WP_REST_Request $request)
    {
        // URL du webhook N8N
        $n8n_webhook_url = "https://n8n.eecie.ca/webhook/fc9a0dba-704b-4391-9190-4db7a33a85b0";

        // 1. Recevoir juste l'ID du devis depuis le JavaScript
        $params = $request->get_json_params();
        $devis_id = $params['devis_id'] ?? null;

        if (!$devis_id) {
            return new WP_Error('invalid_payload', 'ID du devis manquant.', ['status' => 400]);
        }

        // 2. Récupérer les données complètes et fraîches du devis
        $devis_data = eecie_crm_get_row_by_slug_and_id('devis', $devis_id);
        if (is_wp_error($devis_data)) {
            return $devis_data;
        }

        // 3. Récupérer manuellement les articles liés (les _children)
        $articles_list_for_calc = [];
        if (isset($devis_data['Articles_devis']) && is_array($devis_data['Articles_devis'])) {
            foreach ($devis_data['Articles_devis'] as $article_link) {
                $article_data = eecie_crm_get_row_by_slug_and_id('articles_devis', $article_link['id']);
                if (!is_wp_error($article_data)) {
                    $articles_list_for_calc[] = $article_data;
                }
            }
        }
        // On attache les articles récupérés à notre objet principal pour la suite du traitement
        $devis_data['_children'] = $articles_list_for_calc;


        // 4. Enrichir avec les informations du contact (logique existante)
        $contact_details = null;
        $opportunity_id = $devis_data['Task_input'][0]['id'] ?? null;
        if ($opportunity_id) {
            $opportunity_data = eecie_crm_get_row_by_slug_and_id('opportunites', $opportunity_id);
            if (!is_wp_error($opportunity_data)) {
                $contact_id = $opportunity_data['Contacts'][0]['id'] ?? null;
                if ($contact_id) {
                    $contact_data = eecie_crm_get_row_by_slug_and_id('contacts', $contact_id);
                    if (!is_wp_error($contact_data)) {
                        $contact_details = [
                            'id' => $contact_data['id'],
                            'nom' => $contact_data['Nom'] ?? null,
                            'email' => $contact_data['Email'] ?? null,
                            'tel' => $contact_data['Tel'] ?? null, // Note: le nom du champ dans Contacts est 'Telephone'
                            'adresse' => $contact_data['Adresse'] ?? null,
                            'code postal' => $contact_data["Code_postal"] ?? null,
                            'ville' => $contact_data['Ville'] ?? null,
                        ];
                    }
                }
            }
        }

        // 5. Calculer les totaux (logique existante)
        $articles_list_for_n8n = [];
        $total_ht = 0.0;
        foreach ($devis_data['_children'] as $article) {
            if (!isset($article['Quantités']) || !isset($article['Prix_unitaire'])) continue;
            $quantite = floatval($article['Quantités']);
            $prix_unitaire = floatval($article['Prix_unitaire']);
            $sous_total_ht = $quantite * $prix_unitaire;
            $total_ht += $sous_total_ht;
            $articles_list_for_n8n[] = [
                'nom' => $article['Nom'] ?? 'Article sans nom',
                'quantite' => $quantite,
                'prix_unitaire' => $prix_unitaire,
                'sous_total_ht' => round($sous_total_ht, 2),
            ];
        }

        $tps_rate = floatval(get_option('gce_tps_rate', '0.05'));
        $tvq_rate = floatval(get_option('gce_tvq_rate', '0.09975'));
        $tps = $total_ht * $tps_rate;
        $tvq = $total_ht * $tvq_rate;
        $total_ttc = $total_ht + $tps + $tvq;

        // 6. Assembler le payload final pour N8N
        $n8n_payload = [
            'devis_id'       => $devis_data['id'] ?? null,
            'opportunity_id' => $devis_data['Task_input'][0]['id'] ?? null,
            'opportunity_name' => $devis_data['Task_input'][0]['value'] ?? null,
            'contact'        => $contact_details,
            'notes'          => $devis_data['Notes'] ?? '',
            'summary'        => [
                'total_hors_taxe' => round($total_ht, 2),
                'tps_amount'      => round($tps, 2),
                'tvq_amount'      => round($tvq, 2),
                'total_ttc'       => round($total_ttc, 2),
            ],
            'articles'       => $articles_list_for_n8n,
            'raw_data'       => $devis_data, // On envoie les données fraîches complètes
        ];

        // 7. Envoyer à N8N (logique existante)
        $response_to_n8n = wp_remote_post($n8n_webhook_url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode($n8n_payload),
            'timeout' => 30
        ]);

        if (is_wp_error($response_to_n8n)) {
            return new WP_Error('n8n_proxy_failed', 'Erreur de communication avec N8N: ' . $response_to_n8n->get_error_message(), ['status' => 502]);
        }
        $n8n_status_code = wp_remote_retrieve_response_code($response_to_n8n);
        if ($n8n_status_code >= 400) {
            return new WP_Error('n8n_error', 'N8N a retourné une erreur.', ['status' => $n8n_status_code, 'n8n_response_body' => wp_remote_retrieve_body($response_to_n8n)]);
        }

        return rest_ensure_response([
            'status'  => 'success',
            'message' => 'Devis traité et transmis à N8N.',
            'n8n_http_status' => $n8n_status_code,
        ]);
    }


    function eecie_crm_update_utilisateur(WP_REST_Request $request)
    {
        $id = (int) $request['id'];
        $body = $request->get_json_params();

        // --- LOGIQUE DE CHIFFREMENT AJOUTÉE ---
        // Si 'sec1' est présent dans la requête (ce qui ne devrait plus arriver), on le chiffre.
        if (isset($body['sec1']) && !empty($body['sec1'])) {
            $encrypted = gce_encrypt_shared_data($body['sec1']);
            if ($encrypted) {
                $body['sec1'] = $encrypted;
            } else {
                unset($body['sec1']);
            }
        }
        // --- FIN DE LA LOGIQUE DE CHIFFREMENT ---

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
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status !== 200) {
            return new WP_Error('baserow_api_error', "Erreur Baserow ($status)", ['status' => $status, 'details' => $response_body]);
        }

        return rest_ensure_response($response_body);
    }
    /**
     * Prépare les données pour la vue "Appels" (Flux de travail).
     * Retourne une liste de "dossiers d'appel" uniques par opportunité assignée à l'utilisateur,
     * avec leurs interactions directement imbriquées.
     *
     * @return WP_REST_Response|WP_Error
     */
    function eecie_crm_get_appels_view_data(WP_REST_Request $request)
    {
        // 1. Identifier l'utilisateur connecté
        $current_wp_user = wp_get_current_user();
        if (!$current_wp_user || !$current_wp_user->exists()) {
            return new WP_Error('not_logged_in', 'Utilisateur non connecté.', ['status' => 401]);
        }
        $t1_user_object = gce_get_baserow_t1_user_by_email($current_wp_user->user_email);
        if (!$t1_user_object) {
            return rest_ensure_response([]); // Pas d'utilisateur Baserow, donc pas de données
        }

        // 2. Récupérer toutes les données nécessaires en une fois
        $opportunites = eecie_crm_baserow_get_all_paginated_results('rows/table/' . (get_option('gce_baserow_table_opportunites') ?: eecie_crm_guess_table_id('Task_input')) . '/', ['user_field_names' => 'true']);
        $appels = eecie_crm_baserow_get_all_paginated_results('rows/table/' . (get_option('gce_baserow_table_appels') ?: eecie_crm_guess_table_id('Appels')) . '/', ['user_field_names' => 'true']);
        $interactions = eecie_crm_baserow_get_all_paginated_results('rows/table/' . (get_option('gce_baserow_table_interactions') ?: eecie_crm_guess_table_id('Interactions')) . '/', ['user_field_names' => 'true']);

        // 3. Filtrer les opportunités assignées à l'utilisateur
        $my_opportunity_ids = [];
        foreach ($opportunites as $opp) {
            if (isset($opp['T1_user']) && is_array($opp['T1_user'])) {
                foreach ($opp['T1_user'] as $assigned_user) {
                    if ($assigned_user['id'] === $t1_user_object['id']) {
                        $my_opportunity_ids[] = $opp['id'];
                        break;
                    }
                }
            }
        }

        if (empty($my_opportunity_ids)) {
            return rest_ensure_response([]); // Aucune opportunité assignée
        }

        // 4. Grouper les interactions par ID d'appel parent
        $interactions_by_appel_id = [];
        foreach ($interactions as $interaction) {
            if (isset($interaction['Appels']) && is_array($interaction['Appels'])) {
                foreach ($interaction['Appels'] as $appel_link) {
                    if (!isset($interactions_by_appel_id[$appel_link['id']])) {
                        $interactions_by_appel_id[$appel_link['id']] = [];
                    }
                    $interactions_by_appel_id[$appel_link['id']][] = $interaction;
                }
            }
        }

        // 5. Construire la liste finale de "dossiers d'appel"
        $result_appels = [];
        $processed_opportunites = []; // Pour garantir la règle "1 opportunité = 1 appel"

        foreach ($appels as $appel) {
            if (isset($appel['Opportunité']) && is_array($appel['Opportunité']) && !empty($appel['Opportunité'])) {
                $linked_opp_id = $appel['Opportunité'][0]['id'];

                // Si l'opportunité est une des nôtres ET qu'on ne l'a pas déjà traitée
                if (in_array($linked_opp_id, $my_opportunity_ids) && !in_array($linked_opp_id, $processed_opportunites)) {
                    // On ajoute les interactions imbriquées
                    $appel['_children'] = $interactions_by_appel_id[$appel['id']] ?? [];

                    $result_appels[] = $appel;
                    $processed_opportunites[] = $linked_opp_id; // On "verrouille" cette opportunité
                }
            }
        }

        return rest_ensure_response($result_appels);
    }
    // Fichier : includes/api/rest-routes.php

    function gce_handle_devis_acceptance(WP_REST_Request $request)
    {
        // --- DÉBUT DES LOGS ---
        error_log("====== NOUVELLE TENTATIVE D'ACCEPTATION DE DEVIS ======");

        // 1. Logger les paramètres bruts reçus de l'URL
        $devis_id_raw = $request->get_param('devis_id');
        $token_raw = $request->get_param('token');
        error_log("[LOG 1] Paramètres reçus : devis_id = '{$devis_id_raw}', token = '{$token_raw}'");

        // 2. Nettoyer et valider les paramètres
        $devis_id = (int) $devis_id_raw;
        $token = sanitize_text_field($token_raw);

        $confirmation_page_url = home_url('/devis-confirmation');

        // 3. Première validation (la plus probable source de l'erreur)
        if (empty($devis_id) || empty($token) || strlen($token) !== 64) {
            error_log("[ERREUR A] Validation initiale échouée. devis_id: {$devis_id}, token_length: " . strlen($token));
            wp_redirect(add_query_arg(['status' => 'error', 'message' => 'invalid_link'], $confirmation_page_url));
            exit;
        }
        error_log("[LOG 2] Validation initiale réussie. devis_id = {$devis_id}");

        // 4. Récupérer les détails du devis depuis Baserow
        $devis_table_id = get_option('gce_baserow_table_devis');
        if (empty($devis_table_id)) {
            error_log("[ERREUR B] L'ID de la table Devis n'est pas configuré dans WordPress.");
            wp_redirect(add_query_arg(['status' => 'error', 'message' => 'config_error'], $confirmation_page_url));
            exit;
        }
        error_log("[LOG 3] Tentative de récupération du devis #{$devis_id} depuis la table #{$devis_table_id}");

        $devis_data = eecie_crm_baserow_get("rows/table/{$devis_table_id}/{$devis_id}/?user_field_names=true");

        if (is_wp_error($devis_data)) {
            error_log("[ERREUR C] WP_Error lors de la récupération du devis : " . $devis_data->get_error_message());
            wp_redirect(add_query_arg(['status' => 'error', 'message' => 'devis_not_found'], $confirmation_page_url));
            exit;
        }
        if (!isset($devis_data['Acceptation_Token'])) {
            error_log("[ERREUR D] Devis trouvé, mais le champ 'Acceptation_Token' est manquant dans les données Baserow.");
            wp_redirect(add_query_arg(['status' => 'error', 'message' => 'devis_not_found'], $confirmation_page_url));
            exit;
        }
        error_log("[LOG 4] Devis récupéré avec succès. Token attendu : '" . $devis_data['Acceptation_Token'] . "'");

        // 5. Validation cruciale du token
        if ($devis_data['Acceptation_Token'] !== $token) {
            error_log("[ERREUR E] Incompatibilité des tokens. Reçu: '{$token}', Attendu: '" . $devis_data['Acceptation_Token'] . "'");
            wp_redirect(add_query_arg(['status' => 'error', 'message' => 'token_mismatch'], $confirmation_page_url));
            exit;
        }
        error_log("[LOG 5] Validation du token réussie !");

        // 6. Vérifier si le devis n'est pas déjà accepté
        if ($devis_data['Status']['value'] === 'accepter') {
            error_log("[LOG 6] Le devis est déjà marqué comme accepté. Redirection.");
            wp_redirect(add_query_arg('status', 'already_accepted', $confirmation_page_url));
            exit;
        }

        // Si nous arrivons ici, toutes les validations sont OK.
        error_log("[LOG 7] Toutes les validations sont passées. Procédure d'acceptation...");

        // =========================================================================
        // ==                 DÉBUT DU CODE D'ÉCRITURE COMPLET                  ==
        // =========================================================================

        // A) Récupérer les IDs de table depuis les options
        $signatures_table_id = get_option('gce_baserow_table_devis_signatures');
        $task_input_table_id = get_option('gce_baserow_table_opportunites');

        error_log("[LOG 7.1] IDs de table récupérés : Signatures={$signatures_table_id}, Opportunités={$task_input_table_id}");

        if (empty($signatures_table_id) || empty($task_input_table_id)) {
            error_log("[ERREUR F] IDs de table manquants dans la config WP. Signatures='{$signatures_table_id}', Opportunités='{$task_input_table_id}'.");
            wp_redirect(add_query_arg(['status' => 'error', 'message' => 'config_error'], $confirmation_page_url));
            exit;
        }

        // B) Préparer le payload pour la création de la signature
        $signature_payload = [
            'Devis' => [$devis_id],
            'Date_Signature' => current_time('Y-m-d\TH:i:s.u\Z', true),
            'Adresse_IP' => $_SERVER['REMOTE_ADDR'],
            'User_Agent' => $_SERVER['HTTP_USER_AGENT'],
            'Acceptation_Token' => $token
        ];
        error_log("[LOG 7.2] Payload de la signature préparé : " . json_encode($signature_payload));

        // C) Appeler l'API pour créer la signature
        $new_signature = eecie_crm_baserow_post("rows/table/{$signatures_table_id}/?user_field_names=true", $signature_payload);

        if (is_wp_error($new_signature)) {
            error_log("[ERREUR G] Échec de la création de la signature. Erreur : " . $new_signature->get_error_message() . " | Données : " . json_encode($new_signature->get_error_data()));
            wp_redirect(add_query_arg(['status' => 'error', 'message' => 'signature_failed'], $confirmation_page_url));
            exit;
        }
        error_log("[LOG 7.3] Signature créée avec succès. ID : " . $new_signature['id']);

        // D) Mettre à jour le statut du devis et le lier à la signature
        $status_accepter_id = 3013;
        $devis_update_payload = [
            'Status' => $status_accepter_id,
            'Signature_Numerique' => [$new_signature['id']]
        ];
        error_log("[LOG 7.4] Payload de mise à jour du devis préparé : " . json_encode($devis_update_payload));

        $devis_update_result = eecie_crm_baserow_patch("rows/table/{$devis_table_id}/{$devis_id}/?user_field_names=true", $devis_update_payload);

        if (is_wp_error($devis_update_result)) {
            error_log("[ERREUR H] Échec de la mise à jour du devis. Erreur : " . $devis_update_result->get_error_message());
        } else {
            error_log("[LOG 7.5] Devis mis à jour avec succès.");
        }

        // E) Mettre à jour le statut de l'opportunité
        if (isset($devis_data['Task_input'][0]['id'])) {
            $opportunite_id = $devis_data['Task_input'][0]['id'];
            $status_confirmation_id = 3076;
            $opportunite_update_payload = ['Status' => $status_confirmation_id];
            error_log("[LOG 7.6] Payload de mise à jour de l'opportunité #{$opportunite_id} préparé : " . json_encode($opportunite_update_payload));

            $opp_update_result = eecie_crm_baserow_patch("rows/table/{$task_input_table_id}/{$opportunite_id}/?user_field_names=true", $opportunite_update_payload);

            if (is_wp_error($opp_update_result)) {
                error_log("[ERREUR I] Échec de la mise à jour de l'opportunité. Erreur : " . $opp_update_result->get_error_message());
            } else {
                error_log("[LOG 7.7] Opportunité mise à jour avec succès.");
            }
        } else {
            error_log("[AVERTISSEMENT J] Aucune opportunité liée au devis trouvée. Impossible de mettre à jour le statut de l'opportunité.");
        }

        // F) Redirection finale
        error_log("[LOG 8] Processus terminé. Redirection vers la page de succès.");
        wp_redirect(add_query_arg('status', 'success', $confirmation_page_url));
        exit;

        // =========================================================================
        // ==                  FIN DU CODE D'ÉCRITURE COMPLET                   ==
        // =========================================================================
    }
/**
 * Fonction de permission réutilisable qui vérifie la validité d'un token JWT.
 * Le plugin JWT ajoute les données de l'utilisateur à la requête si le token est valide.
 *
 * @param WP_REST_Request $request
 * @return bool|WP_Error
 */
function gce_api_check_jwt_permission($request) {
    // La fonction `apply_filters` est la manière dont le plugin JWT s'accroche à la validation.
    $error = apply_filters('jwt_auth_token_check', null, $request);

    // Si le plugin retourne une erreur, le token est invalide ou manquant.
    if (is_wp_error($error)) {
        return $error;
    }
    
    // Si tout va bien, le plugin ne retourne rien (null) et on autorise l'accès.
    return true;
}


/**
 * Callback pour la route GET /fournisseur/mes-jobs
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function gce_api_get_fournisseur_jobs($request) {
    // Le plugin JWT a validé le token et nous donne l'ID de l'utilisateur WordPress connecté.
    $user_id_wp = get_current_user_id();
    $user_email = wp_get_current_user()->user_email;

    // 1. Trouver le contact "Fournisseur" dans Baserow lié à cet email
    $contact_fournisseur = gce_get_baserow_user_by_email($user_email, 'Fournisseur');
    if (!$contact_fournisseur) {
        return new WP_Error('no_provider_contact', "Aucun contact fournisseur trouvé pour cet utilisateur.", ['status' => 404]);
    }

    // 2. Récupérer l'enregistrement "Fournisseur" principal lié à ce contact
    if (empty($contact_fournisseur['Fournisseur'][0]['id'])) {
         return new WP_Error('no_provider_linked', "Ce contact n'est lié à aucune fiche Fournisseur.", ['status' => 404]);
    }
    $fournisseur_id = $contact_fournisseur['Fournisseur'][0]['id'];
    
    // 3. Chercher les opportunités dans Baserow
    $opp_table_id = get_option('gce_baserow_table_opportunites');
    $devis_table_id = get_option('gce_baserow_table_devis');

    // On cherche les devis liés à ce fournisseur
    $params_devis = [
        'user_field_names' => 'true',
        'filter__Fournisseur__link_row_has' => $fournisseur_id,
        'include' => 'Task_input' // Pour récupérer l'ID de l'opportunité
    ];
    $devis_lies = eecie_crm_baserow_get("rows/table/{$devis_table_id}/", $params_devis);

    if (is_wp_error($devis_lies) || empty($devis_lies['results'])) {
        return new WP_REST_Response([], 200); // Pas de devis, donc pas de job
    }
    
    // Extraire les IDs des opportunités liées à ces devis
    $opportunite_ids = [];
    foreach ($devis_lies['results'] as $devis) {
        if (!empty($devis['Task_input'][0]['id'])) {
            $opportunite_ids[] = $devis['Task_input'][0]['id'];
        }
    }

    if (empty($opportunite_ids)) {
        return new WP_REST_Response([], 200);
    }
    
    // 4. Filtrer ces opportunités pour ne garder que celles avec le statut "Realisation"
    $params_opp = [
        'user_field_names' => 'true',
        'filter__Status__equal' => 'Realisation', // Le statut exact
        'filter__id__in' => implode(',', $opportunite_ids) // On ne cherche que parmi les IDs trouvés
    ];
    
    $jobs = eecie_crm_baserow_get("rows/table/{$opp_table_id}/", $params_opp);

    if (is_wp_error($jobs)) {
        return $jobs;
    }

    // On ne retourne que les champs utiles pour l'application
    $cleaned_jobs = array_map(function($job) {
        return [
            'id' => $job['id'],
            'NomClient' => $job['NomClient'],
            'Ville' => $job['Ville'],
            'Travaux' => $job['Travaux'] ?? 'Aucune description',
        ];
    }, $jobs['results']);

    return new WP_REST_Response($cleaned_jobs, 200);
}


/**
 * Callback pour la route POST /livraison/submit-report
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function gce_api_submit_livraison_report($request) {
    $params = $request->get_json_params();

    // 1. Validation basique des données
    if (empty($params['opportunite_id']) || empty($params['signature_base64'])) {
        return new WP_Error('bad_request', 'ID de l\'opportunité et signature manquants.', ['status' => 400]);
    }
    
    // On ajoute l'adresse IP du client pour la traçabilité
    $params['ip_address'] = $_SERVER['REMOTE_ADDR'];

    // 2. Déclencher le workflow n8n
    // IMPORTANT: Remplacez cette URL par celle de votre nouveau webhook n8n
    $webhook_url = 'https://n8n.eecie.ca/webhook/VOTRE_WEBHOOK_LIVRAISON_ICI';
    
    $response = wp_remote_post($webhook_url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => json_encode($params),
        'timeout' => 25,
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 400) {
        return new WP_Error('n8n_error', 'Le traitement du rapport a échoué.', ['status' => 502]);
    }

    return new WP_REST_Response(['success' => true, 'message' => 'Rapport soumis.'], 200);
}


/**
 * Helper pour trouver un contact dans Baserow par email et par type.
 */
function gce_get_baserow_user_by_email($email, $type = 'Fournisseur') {
    $contacts_table_id = get_option('gce_baserow_table_contacts');
    if (!$contacts_table_id) return null;

    $params = [
        'user_field_names' => 'true',
        'filter__Email__equal' => $email,
        'filter__Type__equal' => $type,
        'size' => 1
    ];

    $response = eecie_crm_baserow_get("rows/table/{$contacts_table_id}/", $params);

    if (is_wp_error($response) || empty($response['results'])) {
        return null;
    }

    return $response['results'][0];
}