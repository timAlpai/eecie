<?php
defined('ABSPATH') || exit;

register_rest_route('eecie-crm/v1', '/interventions/schedule-first', [
    'methods'  => 'POST',
    'callback' => 'gce_api_schedule_first_intervention',
    'permission_callback' => 'is_user_logged_in',
]);

/**
 * Crée une entrée de RDV et la met à jour immédiatement pour déclencher le workflow n8n.
 * VERSION CORRIGÉE AVEC GESTION UTC NAIVE
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function gce_api_schedule_first_intervention(WP_REST_Request $request) {
    $params = $request->get_json_params();
    $opportunite_id = isset($params['opportunite_id']) ? intval($params['opportunite_id']) : 0;
    $fournisseur_id = isset($params['fournisseur_id']) ? intval($params['fournisseur_id']) : 0;
    $date_heure = isset($params['date_heure']) ? sanitize_text_field($params['date_heure']) : '';

    if (!$opportunite_id || !$fournisseur_id || !$date_heure) {
        return new WP_Error('missing_params', 'Paramètres manquants (opportunite_id, fournisseur_id, date_heure).', ['status' => 400]);
    }

    $rdv_table_id = eecie_crm_guess_table_id('Rendez_vous_fournisseur');
    if (!$rdv_table_id) {
        return new WP_Error('config_error', 'Table Rendez_vous_fournisseur non trouvée.', ['status' => 500]);
    }

    // Étape 1 : Créer l'enregistrement de RDV sans la date.
    $create_payload = [
        'Opportunite_liee' => [$opportunite_id],
        'Fournisseur_concerne' => [$fournisseur_id],
    ];
    $new_rdv = eecie_crm_baserow_request('POST', "rows/table/{$rdv_table_id}/?user_field_names=true", $create_payload);

    if (is_wp_error($new_rdv) || !isset($new_rdv['id'])) {
        return new WP_Error('creation_failed', 'La création de la ligne de rendez-vous a échoué.', ['status' => 500]);
    }
    $new_rdv_id = $new_rdv['id'];

    // Étape 2 : Mettre à jour immédiatement cette nouvelle ligne avec la date pour déclencher le webhook.
    
    // --- CORRECTION : Utiliser gmdate() pour un format UTC naïf (avec 'Z') ---
    
    // strtotime() interprète la date locale envoyée par le JS selon le fuseau du serveur, 
    // ce qui crée un timestamp Unix correct (un moment unique dans le temps).
    $timestamp = strtotime($date_heure);
    
    // gmdate() formate ce timestamp directement en UTC, et on ajoute le 'Z' littéralement.
    // C'est le format "UTC Naive" que Baserow et n8n attendent.
    $date_utc_naive = gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    
    $update_payload = [
        'Date_Heure_RDV' => $date_utc_naive,
    ];
    // --- FIN DE LA CORRECTION ---

    $update_result = eecie_crm_baserow_patch("rows/table/{$rdv_table_id}/{$new_rdv_id}/", $update_payload);
    
    if (is_wp_error($update_result)) {
        return new WP_Error('update_failed', 'La mise à jour de la date du rendez-vous a échoué.', ['status' => 500]);
    }

    return new WP_REST_Response(['success' => true, 'message' => 'Première intervention planifiée et workflow de notification déclenché.'], 200);
}