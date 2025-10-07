<?php

defined('ABSPATH') || exit;

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

        // Convertir le string 'datetime-local' en timestamp, en utilisant le fuseau horaire du serveur
        $timestamp = strtotime($date_heure_iso);
        // Formater en ISO 8601 AVEC l'offset du fuseau horaire (ex: -04:00)
        $date_with_offset = date('Y-m-d\TH:i:sP', $timestamp);
        // Mettre à jour le RDV dans Baserow
        $payload = ["field_{$date_field_id}" => $date_with_offset];
        
        $update_result = eecie_crm_baserow_patch("rows/table/{$rdv_table_id}/{$rdv_id}/", $payload);

        if (is_wp_error($update_result)) {
            return $update_result;
        }

        return rest_ensure_response(['success' => true]);
    },
    'permission_callback' => '__return_true',
]);