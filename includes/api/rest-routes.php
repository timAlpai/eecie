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
            return new WP_Error('no_table', 'Table Opportunités introuvable', ['status'=>500]);
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

            $option_key = 'gce_baserow_table_' . $slug;
            $table_id = get_option($option_key) ?: eecie_crm_guess_table_id(ucfirst($slug));

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
        if (!$table_id) return new WP_Error('no_table', 'Table Tâches introuvable', ['status'=>500]);

        $rows = eecie_crm_baserow_get("rows/table/$table_id/", ['user_field_names'=>'true']);
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
        if (!$table_id) return new WP_Error('no_table', 'Table Tâches introuvable', ['status'=>500]);

        $fields = eecie_crm_baserow_get_fields($table_id);
        return is_wp_error($fields) ? $fields : rest_ensure_response($fields);
    },
    'permission_callback' => function () {
        $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
        return is_user_logged_in() && $nonce_valid;
    },
]);

});



function eecie_crm_get_opportunites(WP_REST_Request $request) {
    $table_id = get_option('gce_baserow_table_opportunites')
              ?: eecie_crm_guess_table_id('Task_input');
    if (!$table_id) {
        return new WP_Error('no_table', 'Table Opportunités introuvable', ['status'=>500]);
    }
    // On récupère tout pour filtrer côté client
    $rows = eecie_crm_baserow_get("rows/table/$table_id/", ['user_field_names'=>'true']);
    return is_wp_error($rows) ? $rows : rest_ensure_response($rows);
}


function eecie_crm_create_utilisateur(WP_REST_Request $request) {
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


function eecie_crm_delete_utilisateur(WP_REST_Request $request) {
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


function eecie_crm_baserow_delete($endpoint) {
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


function eecie_crm_check_capabilities() {
    return  check_ajax_referer('wp_rest', '_wpnonce', false);
}


function eecie_crm_get_contacts(WP_REST_Request $request)
{
    // audessus test
    $table_id = get_option('eecie_baserow_table_contacts');
    if (!$table_id) {
        return new WP_Error('missing_table_id', 'ID de table non configuré', ['status' => 500]);
    }

    $data = eecie_crm_baserow_get("rows/table/$table_id/", ['user_field_names' => 'true']);
    return is_wp_error($data) ? $data : rest_ensure_response($data);
}


function eecie_crm_baserow_post($endpoint, $payload = []) {
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

function eecie_crm_update_utilisateur(WP_REST_Request $request) {
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
