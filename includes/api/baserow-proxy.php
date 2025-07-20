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

/**
 * Récupère toutes les tables de la base de données configurée.
 * @return array|WP_Error
 */
function eecie_crm_baserow_get_all_tables()
{
    $database_id = get_option('gce_baserow_database_id');

    if (empty($database_id)) {
        return new WP_Error(
            'missing_database_id',
            'L\'ID de la base de données Baserow n\'est pas configuré. Veuillez le définir dans la page de configuration du plugin.',
            ['status' => 500]
        );
    }

    // Utilisation de l'endpoint standard de Baserow pour lister les tables d'une base de données spécifique.
    $path = "tables/database/" . intval($database_id) . "/";

    return eecie_crm_baserow_get($path);
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
/**
 * Fonction pour téléverser un fichier à Baserow en utilisant cURL pour plus de fiabilité.
 *
 * @param string $file_path Chemin temporaire du fichier sur le serveur
 * @param string $file_name Nom original du fichier
 * @return array|WP_Error Les informations du fichier téléversé ou une erreur
 */
function eecie_crm_baserow_upload_file($file_path, $file_name)
{
    $baseUrl = rtrim(get_option('gce_baserow_url'), '/');
    $token = get_option('gce_baserow_api_key');

    if (empty($baseUrl) || empty($token)) {
        return new WP_Error('missing_credentials', 'Baserow credentials not configured.', ['status' => 500]);
    }

    $url = "$baseUrl/api/user-files/upload-file/";

    if (!function_exists('curl_init')) {
        return new WP_Error('curl_missing', 'L\'extension cURL de PHP est requise.', ['status' => 500]);
    }
    
    if (!class_exists('CURLFile')) {
        return new WP_Error('curlfile_missing', 'La classe CURLFile est requise.', ['status' => 500]);
    }

    $cfile = new CURLFile($file_path, mime_content_type($file_path), $file_name);
    $post_data = ['file' => $cfile];

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Token ' . sanitize_text_field($token),
        // IMPORTANT: Ne pas définir 'Content-Type' manuellement, cURL le fait pour nous
        // avec la bonne délimitation (boundary) pour les requêtes multipart.
    ]);

    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);

    curl_close($ch);

    if ($curl_error) {
        return new WP_Error('curl_error', $curl_error, ['status' => 502]);
    }

    if ($http_code !== 200) {
        // Essayons de décoder le message d'erreur de Baserow pour plus de clarté
        $error_details = json_decode($response_body, true);
        $error_message = $error_details['detail'] ?? "Erreur d'upload Baserow ($http_code)";
        return new WP_Error('baserow_api_upload_error', $error_message, ['status' => $http_code]);
    }

    return json_decode($response_body, true);
}