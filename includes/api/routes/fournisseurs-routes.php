<?php

defined('ABSPATH') || exit;

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

// POST /fournisseurs/create-with-contact
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