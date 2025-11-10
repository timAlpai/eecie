<?php

defined('ABSPATH') || exit;

require_once __DIR__ . '/baserow-proxy.php';
require_once GCE_PLUGIN_DIR . 'includes/shared/security.php';


/**
 * Fonction d'aide pour récupérer une ligne Baserow par son slug et son ID.
 * C'est la logique de la route GET /row/{slug}/{id} factorisée pour un usage interne en PHP.
 *
 * @param string $slug Le slug de la table (ex: 'opportunites', 'contacts').
 * @param int $row_id L'ID de la ligne.
 * @return array|WP_Error Les données de la ligne ou une erreur.
 */
function eecie_crm_get_row_by_slug_and_id($slug, $row_id)
{
    // Table de correspondance pour les cas où le slug ne matche pas le nom de la table
    $slug_to_baserow_name_map = [
        'opportunites'   => 'Task_input',
        'utilisateurs'   => 'T1_user',
        'articles_devis' => 'Articles_devis',
        // Ajoutez ici d'autres exceptions si nécessaire
    ];

    // 1. On cherche si un ID de table est défini manuellement dans les options. C'est prioritaire.
    $option_key = 'gce_baserow_table_' . $slug;
    $table_id = get_option($option_key);

    // 2. Si pas d'ID manuel, on essaie de deviner.
    if (!$table_id) {
        $baserow_name_to_guess = $slug_to_baserow_name_map[$slug] ?? ucfirst($slug);
        $table_id = eecie_crm_guess_table_id($baserow_name_to_guess);
    }

    if (!$table_id) {
        return new WP_Error('invalid_table', "Impossible de trouver la table Baserow pour le slug `$slug`", ['status' => 404]);
    }

    // On utilise le proxy existant pour récupérer les données
    return eecie_crm_baserow_get("rows/table/$table_id/$row_id/", ['user_field_names' => 'true']);
}

function eecie_crm_check_capabilities()
{
    $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
    return is_user_logged_in() && $nonce_valid;
}

function eecie_crm_get_contacts(WP_REST_Request $request)
{
    // audessus test
    $table_id = get_option('gce_baserow_table_contacts') ?: eecie_crm_guess_table_id('Contacts');

    if (!$table_id) {
        return new WP_Error('missing_table_id', 'ID de table non configuré', ['status' => 500]);
    }

    $data = eecie_crm_baserow_get("rows/table/$table_id/", ['user_field_names' => 'true']);
    return is_wp_error($data) ? $data : rest_ensure_response($data);
}

function eecie_crm_baserow_post($endpoint, $payload = [])
{
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

function eecie_crm_baserow_delete($endpoint)
{
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

/**
 * Helper pour trouver un contact dans Baserow par email et par type.
 * VERSION DÉFINITIVE ET VALIDÉE PAR LE TEST cURL
 */
function gce_get_baserow_user_by_email($email, $type_string_ignored = 'Fournisseur')
{
    $contacts_table_id = get_option('gce_baserow_table_contacts');
    if (!$contacts_table_id) {
        return null;
    }

    // ID numérique de l'option "Fournisseur" que nous avons validé.
    $id_option_fournisseur = 3065;

    // On construit le filtre sous forme de tableau PHP.
    // Cette structure correspond EXACTEMENT à la requête cURL qui a réussi.
    $filters = [
        'filter_type' => 'AND',
        'filters' => [
            [
                'field' => 'Email',
                'type'  => 'equal', // Correct pour un champ texte
                'value' => $email
            ],
            [
                'field' => 'Type',
                'type'  => 'single_select_equal', // L'opérateur correct pour ce champ
                'value' => (string) $id_option_fournisseur  // La valeur correcte (ID numérique)
            ]
        ]
    ];

    // Les autres paramètres de la requête
    $otherParams = [
        'user_field_names' => 'true',
        'size' => 1
    ];

    // On appelle notre fonction spécialisée pour les filtres JSON, qui est correcte.
    $response = eecie_crm_baserow_get_with_json_filter(
        "rows/table/{$contacts_table_id}/",
        $filters,
        $otherParams
    );

    if (is_wp_error($response) || empty($response['results'])) {
        return null;
    }

    return $response['results'][0];
}

function gce_get_user_opportunites()
{
    $current_user = wp_get_current_user();
    if (!$current_user || !$current_user->exists()) {
        return new WP_Error('not_logged_in', 'Utilisateur non connecté.', ['status' => 401]);
    }

    // 1. Trouver l'utilisateur Baserow correspondant via son e-mail
    $baserow_user = gce_get_baserow_t1_user_by_email($current_user->user_email);

    if (!$baserow_user || !isset($baserow_user['id'])) {
        return rest_ensure_response(['count' => 0, 'next' => null, 'previous' => null, 'results' => []]);
    }

    $baserow_user_id = $baserow_user['id'];

    // 2. Récupérer l'ID de la table des opportunités
    $table_id = get_option('gce_baserow_table_opportunites') ?: eecie_crm_guess_table_id('Task_input');
    if (!$table_id) {
        return new WP_Error('config_error', 'Table des opportunités non configurée.', ['status' => 500]);
    }

    // 3. Construire le filtre pour Baserow
    // D'après le workflow `assignTask.json`, l'ID du champ de liaison 'T1_user' est 6840.
    // === VOTRE CORRECTION EST APPLIQUÉE ICI ===
    $params = [
        'user_field_names'             => 'true',
        'filter__field_6840__link_row_has' => $baserow_user_id, // On utilise le filtre link_row_has
        'size'                         => 200,
    ];
    // ===========================================

    // 4. Appeler la fonction qui récupère toutes les pages de résultats
    $path = "rows/table/{$table_id}/";
    $results = eecie_crm_baserow_get_all_paginated_results($path, $params);

    if (is_wp_error($results)) {
        return $results;
    }

    // 5. Retourner une réponse formatée que le JavaScript comprendra
    return rest_ensure_response([
        'count'    => count($results),
        'next'     => null,
        'previous' => null,
        'results'  => $results
    ]);
}

function eecie_crm_get_appels_view_data(WP_REST_Request $request)
{
    // 1. Identifier l'utilisateur connecté
    $current_wp_user = wp_get_current_user();
    if (!$current_wp_user || !$current_wp_user->exists()) {
        return new WP_Error('not_logged_in', 'Utilisateur non connecté.', ['status' => 401]);
    }
    $t1_user_object = gce_get_baserow_t1_user_by_email($current_wp_user->user_email);
    if (!$t1_user_object) {
        return rest_ensure_response([]); // Pas d'utilisateur Baserow, donc pas de données
    }

    // 2. Récupérer toutes les données nécessaires en une fois
    $opportunites = eecie_crm_baserow_get_all_paginated_results('rows/table/' . (get_option('gce_baserow_table_opportunites') ?: eecie_crm_guess_table_id('Task_input')) . '/', ['user_field_names' => 'true']);
    $appels = eecie_crm_baserow_get_all_paginated_results('rows/table/' . (get_option('gce_baserow_table_appels') ?: eecie_crm_guess_table_id('Appels')) . '/', ['user_field_names' => 'true']);
    $interactions = eecie_crm_baserow_get_all_paginated_results('rows/table/' . (get_option('gce_baserow_table_interactions') ?: eecie_crm_guess_table_id('Interactions')) . '/', ['user_field_names' => 'true']);

    // 3. Filtrer les opportunités assignées à l'utilisateur
    $my_opportunity_ids = [];
    foreach ($opportunites as $opp) {
        if (isset($opp['T1_user']) && is_array($opp['T1_user'])) {
            foreach ($opp['T1_user'] as $assigned_user) {
                if ($assigned_user['id'] === $t1_user_object['id']) {
                    $my_opportunity_ids[] = $opp['id'];
                    break;
                }
            }
        }
    }

    if (empty($my_opportunity_ids)) {
        return rest_ensure_response([]); // Aucune opportunité assignée
    }

    // 4. Grouper les interactions par ID d'appel parent
    $interactions_by_appel_id = [];
    foreach ($interactions as $interaction) {
        if (isset($interaction['Appels']) && is_array($interaction['Appels'])) {
            foreach ($interaction['Appels'] as $appel_link) {
                if (!isset($interactions_by_appel_id[$appel_link['id']])) {
                    $interactions_by_appel_id[$appel_link['id']] = [];
                }
                $interactions_by_appel_id[$appel_link['id']][] = $interaction;
            }
        }
    }

    // 5. Construire la liste finale de "dossiers d'appel"
    $result_appels = [];
    $processed_opportunites = []; // Pour garantir la règle "1 opportunité = 1 appel"

    foreach ($appels as $appel) {
        if (isset($appel['Opportunité']) && is_array($appel['Opportunité']) && !empty($appel['Opportunité'])) {
            $linked_opp_id = $appel['Opportunité'][0]['id'];

            // Si l'opportunité est une des nôtres ET qu'on ne l'a pas déjà traitée
            if (in_array($linked_opp_id, $my_opportunity_ids) && !in_array($linked_opp_id, $processed_opportunites)) {
                // On ajoute les interactions imbriquées
                $appel['_children'] = $interactions_by_appel_id[$appel['id']] ?? [];

                $result_appels[] = $appel;
                $processed_opportunites[] = $linked_opp_id; // On "verrouille" cette opportunité
            }
        }
    }

    return rest_ensure_response($result_appels);
}

function gce_handle_devis_acceptance(WP_REST_Request $request)
{
    // --- DÉBUT DES LOGS ---

    // 1. Logger les paramètres bruts reçus de l'URL
    $devis_id_raw = $request->get_param('devis_id');
    $token_raw = $request->get_param('token');

    // 2. Nettoyer et valider les paramètres
    $devis_id = (int) $devis_id_raw;
    $token = sanitize_text_field($token_raw);

    $confirmation_page_url = home_url('/devis-confirmation');

    // 3. Première validation (la plus probable source de l'erreur)
    if (empty($devis_id) || empty($token) || strlen($token) !== 64) {
        error_log("[ERREUR A] Validation initiale échouée. devis_id: {$devis_id}, token_length: " . strlen($token));
        wp_redirect(add_query_arg(['status' => 'error', 'message' => 'invalid_link'], $confirmation_page_url));
        exit;
    }

    // 4. Récupérer les détails du devis depuis Baserow
    $devis_table_id = get_option('gce_baserow_table_devis');
    if (empty($devis_table_id)) {
        error_log("[ERREUR B] L'ID de la table Devis n'est pas configuré dans WordPress.");
        wp_redirect(add_query_arg(['status' => 'error', 'message' => 'config_error'], $confirmation_page_url));
        exit;
    }

    $devis_data = eecie_crm_baserow_get("rows/table/{$devis_table_id}/{$devis_id}/?user_field_names=true");

    if (is_wp_error($devis_data)) {
        error_log("[ERREUR C] WP_Error lors de la récupération du devis : " . $devis_data->get_error_message());
        wp_redirect(add_query_arg(['status' => 'error', 'message' => 'devis_not_found'], $confirmation_page_url));
        exit;
    }
    if (!isset($devis_data['Acceptation_Token'])) {
        error_log("[ERREUR D] Devis trouvé, mais le champ 'Acceptation_Token' est manquant dans les données Baserow.");
        wp_redirect(add_query_arg(['status' => 'error', 'message' => 'devis_not_found'], $confirmation_page_url));
        exit;
    }

    // 5. Validation cruciale du token
    if ($devis_data['Acceptation_Token'] !== $token) {
        error_log("[ERREUR E] Incompatibilité des tokens. Reçu: '{$token}', Attendu: '" . $devis_data['Acceptation_Token'] . "'");
        wp_redirect(add_query_arg(['status' => 'error', 'message' => 'token_mismatch'], $confirmation_page_url));
        exit;
    }

    // 6. Vérifier si le devis n'est pas déjà accepté
    if ($devis_data['Status']['value'] === 'accepter') {
        error_log("[LOG 6] Le devis est déjà marqué comme accepté. Redirection.");
        wp_redirect(add_query_arg('status', 'already_accepted', $confirmation_page_url));
        exit;
    }

    // Si nous arrivons ici, toutes les validations sont OK.
    error_log("[LOG 7] Toutes les validations sont passées. Procédure d'acceptation...");

    // =========================================================================
    // ==                 DÉBUT DU CODE D'ÉCRITURE COMPLET                  ==
    // =========================================================================

    // A) Récupérer les IDs de table depuis les options
    $signatures_table_id = get_option('gce_baserow_table_devis_signatures');
    $task_input_table_id = get_option('gce_baserow_table_opportunites');

    error_log("[LOG 7.1] IDs de table récupérés : Signatures={$signatures_table_id}, Opportunités={$task_input_table_id}");

    if (empty($signatures_table_id) || empty($task_input_table_id)) {
        error_log("[ERREUR F] IDs de table manquants dans la config WP. Signatures='{$signatures_table_id}', Opportunités='{$task_input_table_id}'.");
        wp_redirect(add_query_arg(['status' => 'error', 'message' => 'config_error'], $confirmation_page_url));
        exit;
    }

    // B) Préparer le payload pour la création de la signature
    $signature_payload = [
        'Devis' => [$devis_id],
        'Date_Signature' => current_time('Y-m-d\TH:i:s.u\Z', true),
        'Adresse_IP' => $_SERVER['REMOTE_ADDR'],
        'User_Agent' => $_SERVER['HTTP_USER_AGENT'],
        'Acceptation_Token' => $token
    ];
    error_log("[LOG 7.2] Payload de la signature préparé : " . json_encode($signature_payload));

    // C) Appeler l'API pour créer la signature
    $new_signature = eecie_crm_baserow_post("rows/table/{$signatures_table_id}/?user_field_names=true", $signature_payload);

    if (is_wp_error($new_signature)) {
        error_log("[ERREUR G] Échec de la création de la signature. Erreur : " . $new_signature->get_error_message() . " | Données : " . json_encode($new_signature->get_error_data()));
        wp_redirect(add_query_arg(['status' => 'error', 'message' => 'signature_failed'], $confirmation_page_url));
        exit;
    }
    error_log("[LOG 7.3] Signature créée avec succès. ID : " . $new_signature['id']);

    // D) Mettre à jour le statut du devis et le lier à la signature
    $status_accepter_id = 3013;
    $devis_update_payload = [
        'Status' => $status_accepter_id,
        'Signature_Numerique' => [$new_signature['id']]
    ];
    error_log("[LOG 7.4] Payload de mise à jour du devis préparé : " . json_encode($devis_update_payload));

    $devis_update_result = eecie_crm_baserow_patch("rows/table/{$devis_table_id}/{$devis_id}/?user_field_names=true", $devis_update_payload);

    if (is_wp_error($devis_update_result)) {
        error_log("[ERREUR H] Échec de la mise à jour du devis. Erreur : " . $devis_update_result->get_error_message());
    } else {
        error_log("[LOG 7.5] Devis mis à jour avec succès.");
    }

    // E) Mettre à jour le statut de l'opportunité
    if (isset($devis_data['Task_input'][0]['id'])) {
        $opportunite_id = $devis_data['Task_input'][0]['id'];
        $status_confirmation_id = 3076;
        $opportunite_update_payload = ['Status' => $status_confirmation_id];

        $opp_update_result = eecie_crm_baserow_patch("rows/table/{$task_input_table_id}/{$opportunite_id}/?user_field_names=true", $opportunite_update_payload);

        if (is_wp_error($opp_update_result)) {
            error_log("[ERREUR I] Échec de la mise à jour de l'opportunité. Erreur : " . $opp_update_result->get_error_message());
        } else {
            error_log("[LOG 7.7] Opportunité mise à jour avec succès.");
        }
    } else {
        error_log("[AVERTISSEMENT J] Aucune opportunité liée au devis trouvée. Impossible de mettre à jour le statut de l'opportunité.");
    }

    // F) Redirection finale
    wp_redirect(add_query_arg('status', 'success', $confirmation_page_url));
    exit;

    // =========================================================================
    // ==                  FIN DU CODE D'ÉCRITURE COMPLET                   ==
    // =========================================================================
}

add_action('rest_api_init', function () {

    // Include all the route files
require_once __DIR__ . '/routes/fournisseurs-routes.php';
require_once __DIR__ . '/routes/zone-geo-routes.php';
require_once __DIR__ . '/routes/contacts-routes.php';
require_once __DIR__ . '/routes/opportunites-routes.php';
require_once __DIR__ . '/routes/taches-routes.php';
require_once __DIR__ . '/routes/appels-routes.php';
require_once __DIR__ . '/routes/interactions-routes.php';
require_once __DIR__ . '/routes/devis-routes.php';
require_once __DIR__ . '/routes/articles-devis-routes.php';
require_once __DIR__ . '/routes/utilisateurs-routes.php';
require_once __DIR__ . '/routes/fournisseur-app-routes.php';
require_once __DIR__ . '/routes/employee-app-routes.php';
require_once __DIR__ . '/routes/proxy-routes.php';
require_once __DIR__ . '/routes/structure-routes.php';
require_once __DIR__ . '/routes/rdv-routes.php';
require_once __DIR__ . '/routes/debug-routes.php';
require_once __DIR__ . '/routes/orchestrator-routes.php';
require_once __DIR__ . '/routes/interventions-routes.php';

    // All routes are now included in separate files
});