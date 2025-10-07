<?php

defined('ABSPATH') || exit;

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

// --- NOUVEAU : Route pour le mot de passe Nextcloud ---
register_rest_route('eecie-crm/v1', '/utilisateurs/(?P<id>\d+)/update-nc-password', [
    'methods'  => 'PATCH',
    'callback' => function (WP_REST_Request $request) {
        $user_id = (int)$request['id'];
        $body = $request->get_json_params();
        $new_password = $body['nc_password'] ?? null;

        if (empty($new_password)) {
            return new WP_Error('no_nc_password', 'Le mot de passe d\'application Nextcloud est manquant.', ['status' => 400]);
        }

        $encrypted_password = gce_encrypt_shared_data($new_password);
        if ($encrypted_password === false) {
            return new WP_Error('encryption_failed', 'La clé de chiffrement partagée n\'est pas configurée.', ['status' => 500]);
        }

        $table_id = get_option('gce_baserow_table_utilisateurs') ?: eecie_crm_guess_table_id('T1_user');
        if (!$table_id) return new WP_Error('no_table', 'Table utilisateurs introuvable.');

        // On cherche l'ID du champ "ncsec" (ID: 7903 d'après votre schéma)
        $ncsec_field_id = 7903; 

        $payload = ['field_' . $ncsec_field_id => $encrypted_password];

        $result = eecie_crm_baserow_patch("rows/table/{$table_id}/{$user_id}/", $payload);

        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response(['success' => true, 'message' => 'Mot de passe Nextcloud enregistré.']);
    },
    'permission_callback' => function () {
        $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
        return is_user_logged_in() && current_user_can('manage_options') && $nonce_valid;
    },
]);

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