<?php
defined('ABSPATH') || exit;
error_log('routes chargées');
require_once __DIR__ . '/baserow-proxy.php';

add_action('rest_api_init', function () {
    register_rest_route('eecie-crm/v1', '/contacts', [
        'methods'  => 'GET',
        'callback' => 'eecie_crm_get_contacts',
        'permission_callback' => function () {
        // Récupération du nonce par entête JS
        $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');

        error_log('[DEBUG REST] user_id=' . get_current_user_id() . ' | nonce_valid=' . ($nonce_valid ? 'yes' : 'no'));

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
