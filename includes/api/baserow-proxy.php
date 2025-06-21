<?php
defined('ABSPATH') || exit;

/**
 * Fonction pour effectuer une requête GET sécurisée à Baserow
 *
 * @param string $path L’endpoint Baserow après /api/database/
 * @param array $queryParams Optionnel, tableau des paramètres de requête
 * @return array|string Tableau de données ou message d’erreur
 */
function eecie_crm_baserow_get($path, $queryParams = [])
{
    $baseUrl = rtrim(get_option('gce_baserow_url'), '/');
    $token = get_option('gce_baserow_api_key');

    if (empty($baseUrl) || empty($token)) {
        return new WP_Error('missing_credentials', 'Baserow credentials not configured.', ['status' => 500]);
    }

    $url = "$baseUrl/api/database/$path";

    if (!empty($queryParams)) {
        $url .= '?' . http_build_query($queryParams);
    }

    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Token ' . sanitize_text_field($token),
            'Content-Type'  => 'application/json',
        ],
        'timeout' => 10,
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('baserow_error', $response->get_error_message(), ['status' => 502]);
    }

    $status = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($status !== 200) {
        return new WP_Error('baserow_api_error', "Erreur Baserow ($status)", ['status' => $status]);
    }

    return json_decode($body, true);
}
function eecie_crm_baserow_get_all_tables()
{
    return eecie_crm_baserow_get('tables/all-tables/');
}


function eecie_crm_guess_table_id($target_name)
{
    $tables = eecie_crm_baserow_get_all_tables();

    if (is_wp_error($tables) || !is_array($tables)) {
        return null;
    }

    foreach ($tables as $table) {
        if (strtolower($table['name']) === strtolower($target_name)) {
            return $table['id'];
        }
    }

    return null;
}

function eecie_crm_baserow_get_fields($table_id)
{
    return eecie_crm_baserow_get("fields/table/$table_id/");
}
