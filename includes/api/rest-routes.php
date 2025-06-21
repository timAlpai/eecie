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

});



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
