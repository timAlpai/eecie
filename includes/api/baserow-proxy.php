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
    // On ajoute le corps de la réponse à l'objet d'erreur pour un meilleur débogage
    return new WP_Error(
        'baserow_api_error', 
        "Erreur Baserow ($status)", 
        ['status' => $status, 'body' => json_decode($body, true)]
    );
}
    return json_decode($body, true);
}

function eecie_crm_baserow_get_all_tables()
{
    $database_id = get_option('gce_baserow_database_id');
    if (empty($database_id)) {
        return new WP_Error('missing_database_id', 'L\'ID de la base de données Baserow n\'est pas configuré.');
    }
    
    // On construit le chemin pour l'API de gestion (pas l'API de base de données)
    $path = "database/tables/database/" . intval($database_id) . "/";

    // On utilise notre nouvelle fonction d'appel admin
    return eecie_crm_baserow_admin_get($path);
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

/**
 * Obtient un JWT valide pour les opérations d'administration.
 * Utilise un transient (cache WordPress) pour éviter de s'authentifier à chaque fois.
 * @return string|WP_Error Le token JWT ou une erreur.
 */
function eecie_crm_get_jwt_token() {
    // 1. Essayer de récupérer le token depuis le cache
    $cached_token = get_transient('gce_baserow_jwt');
    if ($cached_token) {
        return $cached_token;
    }

    // 2. Si pas de cache, s'authentifier pour en obtenir un nouveau
    $baseUrl = rtrim(get_option('gce_baserow_url'), '/');
    $email = get_option('gce_baserow_service_email');
    $password = gce_decrypt_password(); // Utilise notre fonction de déchiffrement

    if (empty($baseUrl) || empty($email) || empty($password)) {
        return new WP_Error('missing_jwt_credentials', 'Les identifiants du compte de service Baserow ne sont pas configurés.', ['status' => 500]);
    }

    $url = "$baseUrl/api/user/token-auth/";
    $response = wp_remote_post($url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => json_encode(['email' => $email, 'password' => $password]),
        'timeout' => 15
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $status = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($status !== 200) {
        $error_detail = $body['error'] ?? 'Erreur d\'authentification inconnue.';
        return new WP_Error('jwt_auth_failed', "Échec de l'authentification JWT ($status): " . $error_detail, ['status' => $status]);
    }

    $jwt_token = $body['access_token'] ?? null;
    if (!$jwt_token) {
        return new WP_Error('jwt_not_found', 'Le token JWT n\'a pas été trouvé dans la réponse.', ['status' => 500]);
    }

    // 3. Mettre le nouveau token en cache pour 10 minutes
    set_transient('gce_baserow_jwt', $jwt_token, 10 * MINUTE_IN_SECONDS);

    return $jwt_token;
}

/**
 * Fonction pour effectuer une requête GET admin sécurisée à Baserow en utilisant un JWT.
 */
function eecie_crm_baserow_admin_get($path, $queryParams = []) {
    $jwt = eecie_crm_get_jwt_token();
    if (is_wp_error($jwt)) {
        return $jwt; // Propage l'erreur
    }

    $baseUrl = rtrim(get_option('gce_baserow_url'), '/');
    $url = "$baseUrl/api/$path"; // Note: l'URL pour les appels admin est différente

    if (!empty($queryParams)) {
        $url .= '?' . http_build_query($queryParams);
    }
    
    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'JWT ' . $jwt, // Utilise l'en-tête JWT
            'Content-Type'  => 'application/json',
        ],
        'timeout' => 10,
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('baserow_admin_error', $response->get_error_message(), ['status' => 502]);
    }
    
    $status = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($status !== 200) {
        return new WP_Error('baserow_admin_api_error', "Erreur Admin Baserow ($status)", ['status' => $status, 'body' => json_decode($body, true)]);
    }

    return json_decode($body, true);
}
/**
 * Récupère l'ID d'un utilisateur Baserow à partir de son adresse email.
 * @param string $email L'adresse email à rechercher.
 * @return int|null L'ID de l'utilisateur Baserow ou null si non trouvé.
 */
function gce_get_baserow_t1_user_by_email($email) {
    $user_table_id = get_option('gce_baserow_table_utilisateurs') ?: eecie_crm_guess_table_id('T1_user');
    if (!$user_table_id) {
        return null;
    }

    $params = [
        'user_field_names' => 'true',
        'filter__Email__equal' => $email,
        'size' => 1
    ];

    $response = eecie_crm_baserow_get("rows/table/$user_table_id/", $params);

    if (is_wp_error($response) || empty($response['results'])) {
        return null;
    }

    // On retourne l'objet utilisateur complet, pas seulement l'ID
    return $response['results'][0] ?? null;
}

/**
 * Récupère toutes les lignes d'une requête en suivant la pagination de Baserow.
 *
 * @param string $path Le chemin initial de l'API (ex: "rows/table/712/").
 * @param array $params Les paramètres initiaux de la requête (filtres, etc.).
 * @return array Le tableau complet de tous les résultats agrégés.
 */
function eecie_crm_baserow_get_all_paginated_results($path, $params = []) {
    $all_results = [];
    $page = 1;

    do {
        $params['page'] = $page;
        
        // On fait l'appel à Baserow
        $response = eecie_crm_baserow_get($path, $params);

        // Si erreur ou pas de résultats, on arrête
        if (is_wp_error($response) || !isset($response['results'])) {
            break;
        }

        // On ajoute les résultats de la page courante au tableau principal
        $all_results = array_merge($all_results, $response['results']);

        // On regarde s'il y a une page suivante
        $has_next_page = !empty($response['next']);
        $page++;

    } while ($has_next_page); // On continue tant qu'il y a une page suivante

    return $all_results;
}

// =========================================================================
// ==                 DÉBUT DU CODE MANQUANT À AJOUTER                    ==
// =========================================================================

/**
 * Fonction générique pour les requêtes qui modifient des données (POST, PATCH, DELETE)
 *
 * @param string $method La méthode HTTP (POST, PATCH, DELETE).
 * @param string $path Le chemin de l'API après /api/database/.
 * @param array $payload Les données à envoyer dans le corps de la requête.
 * @return array|WP_Error La réponse de l'API ou un objet d'erreur.
 */
function eecie_crm_baserow_request($method, $path, $payload = []) {
    $baseUrl = rtrim(get_option('gce_baserow_url'), '/');
    $token = get_option('gce_baserow_api_key');

    if (empty($baseUrl) || empty($token)) {
        return new WP_Error('missing_credentials', 'Les identifiants Baserow ne sont pas configurés.', ['status' => 500]);
    }

    $url = "$baseUrl/api/database/$path";
    
    $args = [
        'method'  => strtoupper($method),
        'headers' => [
            'Authorization' => 'Token ' . sanitize_text_field($token),
            'Content-Type'  => 'application/json',
        ],
        'timeout' => 15,
    ];

    // On n'ajoute le corps que si le payload n'est pas vide
    if (!empty($payload)) {
        $args['body'] = json_encode($payload);
    }

    $response = wp_remote_request($url, $args);

    if (is_wp_error($response)) {
        return new WP_Error('baserow_request_error', $response->get_error_message(), ['status' => 502]);
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $decoded_body = json_decode($body, true);

    // Baserow retourne 200 ou 201 pour POST/PATCH réussi, et 204 pour DELETE.
    if ($status_code < 200 || $status_code >= 300) {
        return new WP_Error(
            'baserow_api_write_error', 
            "Erreur d'écriture Baserow (Statut: {$status_code})", 
            ['status' => $status_code, 'body' => $decoded_body]
        );
    }

    return $decoded_body;
}


/**
 * Raccourci pour effectuer une requête PATCH à Baserow.
 */
function eecie_crm_baserow_patch($path, $payload) {
    return eecie_crm_baserow_request('PATCH', $path, $payload);
}



// =========================================================================
// ==                  FIN DU CODE MANQUANT À AJOUTER                     ==
// =========================================================================