<?php
// Dans votre fichier de routes REST (ex: includes/api/rest-routes.php)

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

register_rest_route('eecie-crm/v1', '/ws-auth', [
    'methods'  => WP_REST_Server::CREATABLE, // ou 'POST'
    'callback' => 'gce_handle_ws_auth_jwt', // Renommons la fonction pour plus de clarté
    'permission_callback' => '__return_true', // TRÈS IMPORTANT : On dit à WP de ne pas gérer les permissions ici.
]);

register_rest_route('eecie-crm/v1', '/connected-users', [
    'methods'  => WP_REST_Server::READABLE,
    'callback' => 'gce_get_connected_users',
    'permission_callback' => function () {
        return current_user_can('manage_options');
    },
]);

register_rest_route('eecie-crm/v1', '/admin-ws-token', [
    'methods'  => WP_REST_Server::READABLE,
    'callback' => 'gce_get_admin_ws_token',
    'permission_callback' => function () {
        return current_user_can('manage_options');
    },
]);
// Route pour envoyer un message
register_rest_route('eecie-crm/v1', '/messages', [
    'methods'  => WP_REST_Server::CREATABLE,
    'callback' => 'gce_handle_send_message',
    'permission_callback' => function ($request) {
        // La permission est accordée si le JWT est valide.
        return gce_get_user_id_from_jwt($request) !== false;
    },
]);


// Route pour marquer des messages comme lus
register_rest_route('eecie-crm/v1', '/messages/mark-as-read', [
    'methods'  => WP_REST_Server::CREATABLE,
    'callback' => 'gce_handle_mark_as_read',
    'permission_callback' => 'is_user_logged_in',
]);
register_rest_route('eecie-crm/v1', '/messages/my-threads', ['methods' => 'GET', 'callback' => 'gce_get_my_threads', 'permission_callback' => 'is_user_logged_in']);
register_rest_route('eecie-crm/v1', '/threads/(?P<id>\d+)/messages', ['methods' => 'GET', 'callback' => 'gce_get_thread_messages', 'permission_callback' => 'is_user_logged_in']);
 register_rest_route('eecie-crm/v1', '/me/t1-user', [
        'methods'  => WP_REST_Server::READABLE,
        'callback' => 'gce_get_my_t1_user_profile',
        'permission_callback' => 'is_user_logged_in',
    ]);



function gce_get_my_t1_user_profile(WP_REST_Request $request) {
    $user_t1 = gce_get_baserow_t1_user_by_email(wp_get_current_user()->user_email);
    if (!$user_t1) {
        return new WP_Error('no_t1_user', 'Utilisateur métier non trouvé.', ['status' => 404]);
    }
    return new WP_REST_Response($user_t1, 200);
}

function gce_get_my_threads(WP_REST_Request $request)
{
    $user_t1 = gce_get_baserow_t1_user_by_email(wp_get_current_user()->user_email);
    if (!$user_t1) {
        return new WP_Error('no_t1_user', 'Utilisateur métier non trouvé.', ['status' => 404]);
    }
    $threads_table_id = eecie_crm_guess_table_id('Threads');
    $params = ['user_field_names' => 'true', 'filter__Participant__link_row_has' => $user_t1['id']];
    $threads = eecie_crm_baserow_get("rows/table/{$threads_table_id}/", $params);
    if (is_wp_error($threads)) {
        return $threads;
    }
    return new WP_REST_Response($threads['results'] ?? [], 200);
}

function gce_get_thread_messages(WP_REST_Request $request)
{
    $thread_id = (int)$request->get_param('id');
    $messages_table_id = eecie_crm_guess_table_id('Messages');
    $params = ['user_field_names' => 'true', 'filter__Threads__link_row_has' => $thread_id, 'order_by' => 'created_on'];
    $messages = eecie_crm_baserow_get("rows/table/{$messages_table_id}/", $params);
    if (is_wp_error($messages)) {
        return $messages;
    }
    return new WP_REST_Response($messages['results'] ?? [], 200);
}



/**
 * Fonction helper pour valider un JWT et retourner l'ID de l'utilisateur.
 * @param WP_REST_Request $request
 * @return int|false L'ID de l'utilisateur ou false si invalide.
 */
function gce_get_user_id_from_jwt(WP_REST_Request $request)
{
    $auth_header = $request->get_header('Authorization');
    if (empty($auth_header) || !preg_match('/^Bearer\s+(.*)$/i', $auth_header, $matches)) {
        return false;
    }

    $token = $matches[1];
    $secret_key = defined('GCE_SHARED_SECRET_KEY_JWT') ? GCE_SHARED_SECRET_KEY_JWT : '';
    if (empty($secret_key)) return false;

    try {
        $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
        return isset($decoded->user_id) ? (int)$decoded->user_id : false;
    } catch (Exception $e) {
        return false;
    }
}



/**
 * Gère la création d'un nouveau message dans un thread.
 */
function gce_handle_send_message(WP_REST_Request $request)
{
    // 1. Valider le token et récupérer l'utilisateur WordPress
    $sender_wp_id = gce_get_user_id_from_jwt($request);
    $user_wp = get_userdata($sender_wp_id);

    if (!$user_wp) {
        return new WP_Error('invalid_user', 'Utilisateur JWT invalide.', ['status' => 403]);
    }

    // 2. Traduire l'utilisateur WP en utilisateur Baserow T1_user
    $user_t1 = gce_get_baserow_t1_user_by_email($user_wp->user_email);

    if (!$user_t1 || !isset($user_t1['id'])) {
        return new WP_Error('t1_user_not_found', 'L\'utilisateur métier correspondant n\'a pas été trouvé dans Baserow.', ['status' => 404]);
    }
    $sender_baserow_id = $user_t1['id'];

    // 3. Récupérer les paramètres de la requête
    $params = $request->get_json_params();
    $thread_id = isset($params['thread_id']) ? intval($params['thread_id']) : 0;
    $content = isset($params['content']) ? sanitize_textarea_field($params['content']) : '';

    if (empty($thread_id) || empty($content)) {
        return new WP_Error('missing_params', 'ID du fil de discussion ou contenu manquant.', ['status' => 400]);
    }

    // 4. Sauvegarder le message dans Baserow
    $messages_table_id = eecie_crm_guess_table_id('Messages');
    $payload = [
        'contenu' => $content,
        'Expediteur' => [$sender_baserow_id],
        'Threads' => [$thread_id]
    ];
    $saved_message = eecie_crm_baserow_request('POST', "rows/table/{$messages_table_id}/?user_field_names=true", $payload);

    if (is_wp_error($saved_message)) {
        return $saved_message;
    }

    // 5. Notifier les autres participants du thread en temps réel
    $threads_table_id = eecie_crm_guess_table_id('Threads');
    $thread_data = eecie_crm_baserow_get("rows/table/{$threads_table_id}/{$thread_id}/?user_field_names=true");
    error_log("[MESSAGE INFO] Participants trouvés. Entrée dans la boucle de notification.");
    // On utilise le nom de champ correct 'Participant'
    if (!is_wp_error($thread_data) && isset($thread_data['Participant']) && !empty($thread_data['Participant'])) {
        foreach ($thread_data['Participant'] as $participant) {
            $participant_t1_id = $participant['id'];
            // --- DEBUG ---
            error_log("[MESSAGE INFO] Traitement du participant T1 ID: {$participant_t1_id}");
            // Ne pas se notifier soi-même
            if ($participant_t1_id !== $sender_baserow_id) {
                $recipient_wp_user = gce_get_wp_user_by_t1_user_id($participant_t1_id);

                // --- DEBUG ---
                error_log("[MESSAGE INFO] Participant WP trouvé (ID: {$recipient_wp_user->ID}). Envoi de l'événement à l'orchestrateur...");
                if ($recipient_wp_user) {
                    gce_send_event_to_orchestrator('new_message', [
                        'user_id' => $recipient_wp_user->ID,
                        'data'    => $saved_message
                    ]);
                    // --- DEBUG ---
                    if (is_wp_error($result)) {
                        error_log("[MESSAGE ERROR] Échec de l'envoi à l'orchestrateur: " . $result->get_error_message());
                    } else {
                        error_log("[MESSAGE SUCCESS] Événement envoyé avec succès à l'orchestrateur pour WP user ID " . $recipient_wp_user->ID);
                    }
                } else {
                    error_log("[MESSAGE WARNING] Participant T1 ID {$participant_t1_id} n'a pas pu être traduit en utilisateur WP.");
                }
            } else {
                error_log("[MESSAGE INFO] Participant T1 ID {$participant_t1_id} est l'expéditeur. Notification ignorée.");
            }
        }
    } else {
        // --- DEBUG ---
        error_log("[MESSAGE WARNING] Condition de notification non remplie. Pas de participants ou erreur lors de la récupération du thread.");
    }

    error_log("--- [MESSAGE] Fin de la fonction ---");
    return new WP_REST_Response(['success' => true, 'message' => $saved_message], 201);
}
/**
 * Marque un ou plusieurs messages comme lus pour l'utilisateur courant.
 */
function gce_handle_mark_as_read(WP_REST_Request $request)
{
    $current_user_id = get_current_user_id();
    if ($current_user_id === 0) {
        return new WP_Error('not_logged_in', 'Accès non autorisé.', ['status' => 401]);
    }

    $params = $request->get_json_params();
    $message_ids = isset($params['message_ids']) && is_array($params['message_ids']) ? $params['message_ids'] : [];

    if (empty($message_ids)) {
        return new WP_REST_Response(['success' => true, 'message' => 'Aucun message à marquer.'], 200);
    }

    $lectures_table_id = eecie_crm_guess_table_id('Lectures');
    if (!$lectures_table_id) {
        return new WP_Error('config_error', 'La table des lectures n\'est pas configurée.', ['status' => 500]);
    }

    $date_now = new DateTime("now", new DateTimeZone("UTC"));

    // On prépare un batch de requêtes pour Baserow
    $batch_requests = [];
    foreach ($message_ids as $msg_id) {
        $batch_requests[] = [
            'Message_Lu' => [intval($msg_id)],
            'Utilisateur' => [$current_user_id],
            'Date_Lecture' => $date_now->format(DateTime::ATOM)
        ];
    }

    $result = eecie_crm_baserow_request('POST', "rows/table/{$lectures_table_id}/batch/?user_field_names=true", ['items' => $batch_requests]);

    if (is_wp_error($result)) {
        return $result;
    }

    // Notifier les autres participants du thread que des messages ont été lus
    // ... (Logique à ajouter, similaire à celle de gce_handle_send_message)
    // Pour l'instant, on se concentre sur la sauvegarde.

    return new WP_REST_Response(['success' => true, 'marked_count' => count($message_ids)], 200);
}

function gce_handle_ws_auth_jwt(WP_REST_Request $request)
{
    // 1. Extraire l'en-tête d'autorisation
    $auth_header = $request->get_header('Authorization');
    if (empty($auth_header) || !str_starts_with(strtolower($auth_header), 'basic ')) {
        return new WP_Error('no_auth_header', 'En-tête d\'authentification Basic manquant ou invalide.', ['status' => 401]);
    }

    // 2. Décoder les identifiants
    $credentials = base64_decode(substr($auth_header, 6));
    if (strpos($credentials, ':') === false) {
        return new WP_Error('invalid_credentials_format', 'Format des identifiants invalide.', ['status' => 400]);
    }
    list($username, $raw_password) = explode(':', $credentials, 2);

    // --- LA CORRECTION EST ICI ---
    // On supprime tous les espaces du mot de passe, comme le fait wp_signon.
    $password = str_replace(' ', '', $raw_password);

    if (empty($username) || empty($password)) {
        return new WP_Error('empty_credentials', 'Identifiants vides.', ['status' => 400]);
    }

    // 3. Récupérer l'utilisateur
    $user = get_user_by('email', $username);
    if (!$user) {
        return new WP_Error('invalid_user', 'Utilisateur inconnu.', ['status' => 403]);
    }

    // 4. Valider manuellement le mot de passe d'application (maintenant nettoyé)
    if (!class_exists('WP_Application_Passwords')) {
        require_once ABSPATH . 'wp-includes/class-wp-application-passwords.php';
    }

    $passwords = WP_Application_Passwords::get_user_application_passwords($user->ID);
    $is_valid_password = false;
    foreach ($passwords as $app_pass) {
        if (wp_check_password($password, $app_pass['password'], $user->ID)) {
            $is_valid_password = true;
            break;
        }
    }

    // On vérifie le mot de passe principal si aucun mot de passe d'application n'a fonctionné
    if (!$is_valid_password) {
        if (wp_check_password($password, $user->user_pass, $user->ID)) {
            $is_valid_password = true;
        }
    }

    if (!$is_valid_password) {
        return new WP_Error('invalid_password', 'Le mot de passe (principal ou d\'application) est incorrect.', ['status' => 403]);
    }

    // 5. Si la validation réussit, générer et retourner le JWT
    $issuedAt   = time();
    $expire     = $issuedAt + 3600;
    $secret_key = defined('GCE_SHARED_SECRET_KEY_JWT') ? GCE_SHARED_SECRET_KEY_JWT : '';

    if (empty($secret_key)) {
        return new WP_Error('jwt_secret_missing', 'La configuration du serveur est incomplète.', ['status' => 500]);
    }

    $payload = [
        'iat'  => $issuedAt,
        'exp'  => $expire,
        'user_id' => $user->ID,
        'email' => $user->user_email,
    ];
    // On vérifie si l'utilisateur est un administrateur
    // IMPORTANT : On se base sur le fait que l'admin se connecte avec son mot de passe principal
    // via le dashboard, et non un mot de passe d'application.
    if (user_can($user, 'manage_options')) {
        $payload['is_admin'] = true;
    }

    $jwt = JWT::encode($payload, $secret_key, 'HS256');

    return new WP_REST_Response([
        'token' => $jwt,
        'user'  => [
            'id' => $user->ID,
            'email' => $user->user_email,
            'display_name' => $user->display_name,
        ],
    ], 200);
}


// *** CRÉER UN NOUVEL ENDPOINT POUR LE DASHBOARD ADMIN ***
function gce_get_admin_ws_token(WP_REST_Request $request)
{
    // S'assurer que seul un admin connecté peut obtenir ce token
    if (!current_user_can('manage_options')) {
        return new WP_Error('rest_forbidden', 'Accès refusé.', ['status' => 403]);
    }

    $user = wp_get_current_user();

    // On réutilise la même logique de génération de token
    $issuedAt   = time();
    $expire     = $issuedAt + 3600;
    $secret_key = defined('GCE_SHARED_SECRET_KEY_JWT') ? GCE_SHARED_SECRET_KEY_JWT : '';
    if (empty($secret_key)) {
        return new WP_Error('jwt_secret_missing', '...', ['status' => 500]);
    }

    $payload = [
        'iat'  => $issuedAt,
        'exp'  => $expire,
        'user_id' => $user->ID,
        'email' => $user->user_email,
        'is_admin' => true // On force le flag admin
    ];
    $jwt = JWT::encode($payload, $secret_key, 'HS256');

    return new WP_REST_Response(['token' => $jwt], 200);
}




/**
 * Helper pour envoyer des événements à l'Orchestrateur Python.
 *
 * @param string $event_type Type de l'événement (ex: 'task_updated').
 * @param array $data Données à envoyer. Doit contenir une clé 'user_id'.
 * @return bool|WP_Error True en cas de succès, WP_Error en cas d'échec.
 */
function gce_send_event_to_orchestrator($event_type, $data)
{
    if (!defined('GCE_ORCHESTRATOR_URL') || !defined('GCE_ORCHESTRATOR_INTERNAL_API_KEY')) {
        return new WP_Error('orchestrator_not_configured', 'L\'orchestrateur n\'est pas configuré.');
    }

    $url = GCE_ORCHESTRATOR_URL . '/api/event/' . sanitize_key($event_type);

    $response = wp_remote_post($url, [
        'method'  => 'POST',
        'headers' => [
            'Content-Type'      => 'application/json',
            'X-Internal-API-Key' => GCE_ORCHESTRATOR_INTERNAL_API_KEY,
        ],
        'body'    => json_encode($data),
        'timeout' => 5, // Timeout court pour ne pas bloquer le PHP
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code($response);

    if ($status_code !== 200) {
        return new WP_Error('orchestrator_error', 'L\'orchestrateur a retourné une erreur.', ['status' => $status_code]);
    }

    return true;
}

function gce_get_connected_users(WP_REST_Request $request)
{
    // Seuls les administrateurs peuvent accéder à cette information
    if (!current_user_can('manage_options')) {
        return new WP_Error('rest_forbidden', 'Accès refusé.', ['status' => 403]);
    }

    try {
        // Connexion à Redis (les paramètres peuvent venir de votre gce-secrets.php)
        $redis = new Predis\Client([
            'scheme' => 'tcp',
            'host'   => '127.0.0.1',
            'port'   => 6379,
        ]);

        // Récupérer tous les champs de la table de hachage
        $connected_user_ids = $redis->hkeys('user_connections');

        if (empty($connected_user_ids)) {
            return new WP_REST_Response(['count' => 0, 'users' => []], 200);
        }

        // Récupérer les détails des utilisateurs WordPress à partir de leurs IDs
        $users_data = [];
        foreach ($connected_user_ids as $user_id) {
            $user = get_userdata(intval($user_id));
            if ($user) {
                $users_data[] = [
                    'id' => $user->ID,
                    'display_name' => $user->display_name,
                    'email' => $user->user_email,
                ];
            }
        }

        return new WP_REST_Response([
            'count' => count($users_data),
            'users' => $users_data,
        ], 200);
    } catch (Exception $e) {
        return new WP_Error('redis_connection_failed', 'Impossible de se connecter au service temps réel.', ['status' => 503]);
    }
}

// Enregistrer la nouvelle route
