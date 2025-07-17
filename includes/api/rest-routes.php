 <?php
defined('ABSPATH') || exit;

require_once __DIR__ . '/baserow-proxy.php';

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
        if (!$fournisseur_table_id) { return new WP_Error('no_supplier_table', 'Table des Fournisseurs introuvable.', ['status' => 500]); }

        $fournisseur_fields = eecie_crm_baserow_get_fields($fournisseur_table_id);
        if (is_wp_error($fournisseur_fields)) { return new WP_Error('schema_fetch_failed_fournisseur', 'Impossible de lire la structure des Fournisseurs.', ['status' => 500]); }
        
        $contacts_link_field_in_fournisseur = array_values(array_filter($fournisseur_fields, function($field) { return $field['name'] === 'Contacts'; }))[0] ?? null;
        if (!$contacts_link_field_in_fournisseur) { return new WP_Error('no_link_field', 'Champ de liaison "Contacts" introuvable dans Fournisseur.', ['status' => 500]); }

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
        if (is_wp_error($contact_fields)) { return new WP_Error('schema_fetch_failed_contact', 'Impossible de lire la structure des Contacts.', ['status' => 500]); }

        $fournisseur_link_field_in_contact = array_values(array_filter($contact_fields, function($field) { return $field['name'] === 'Fournisseur'; }))[0] ?? null;
        if (!$fournisseur_link_field_in_contact) { return new WP_Error('no_link_field_in_contact', 'Champ de liaison "Fournisseur" introuvable dans Contacts.', ['status' => 500]); }

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
            $params = [
    'user_field_names' => 'true',
    'size' => 200 // <-- Votre nouveau paramètre ici
];
$rows = eecie_crm_baserow_get("rows/table/$table_id/", $params);
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


});
   

   

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
