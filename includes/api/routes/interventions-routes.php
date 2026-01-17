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

/**
 * Endpoint pour déclencher la synchronisation globale des interventions
 */
register_rest_route('eecie-crm/v1', '/interventions/sync-all', [
    'methods'  => 'POST',
    'callback' => 'gce_api_sync_interventions_from_rdv',
    'permission_callback' => function () {
        return current_user_can('manage_options');
    },
]);

function gce_api_sync_interventions_from_rdv() {
    $rdv_table_id = eecie_crm_guess_table_id('Rendez_vous_fournisseur');
    $interv_table_id = eecie_crm_guess_table_id('Interventions_Planifiees');
    $opp_table_id = eecie_crm_guess_table_id('Task_input');

    $logs = [];
    $logs[] = "Démarrage de la synchronisation...";

    if (!$rdv_table_id || !$interv_table_id) {
        return new WP_Error('config_error', 'Tables introuvables. Vérifiez la configuration des IDs.');
    }

    // 1. Récupérer tous les RDV
    $rdvs = eecie_crm_baserow_get_all_paginated_results("rows/table/{$rdv_table_id}/", ['user_field_names' => 'true']);
    
    if (is_wp_error($rdvs)) {
        return $rdvs;
    }

    $logs[] = "Nombre de RDV trouvés : " . count($rdvs);
    $created_count = 0;
    $ignored_count = 0;

    foreach ($rdvs as $rdv) {
        $rdv_id = $rdv['id'];
        
        // Validation de base
        if (empty($rdv['Date_Heure_RDV']) || empty($rdv['Opportunite_liee'][0]['id'])) {
            $logs[] = "RDV #$rdv_id ignoré : Date ou Opportunité manquante.";
            continue;
        }

        $opp_id = $rdv['Opportunite_liee'][0]['id'];
        $opp_name = $rdv['Opportunite_liee'][0]['value'];
        $fournisseur_id = $rdv['Fournisseur_concerne'][0]['id'] ?? null;
        
        // On normalise la date du RDV pour éviter les soucis de format (on enlève les millisecondes si présentes)
        $rdv_date_raw = $rdv['Date_Heure_RDV'];
        $rdv_date_obj = new DateTime($rdv_date_raw);
        $base_date_iso = $rdv_date_obj->format('Y-m-d\TH:i:s\Z');

        $logs[] = "--- Traitement RDV #$rdv_id (Opp: $opp_name) ---";

        // 2. Récupérer le type d'opportunité
        $opp_data = eecie_crm_baserow_get("rows/table/{$opp_table_id}/{$opp_id}/?user_field_names=true");
        if (is_wp_error($opp_data)) {
            $logs[] = "Erreur lecture Opportunité #$opp_id : " . $opp_data->get_error_message();
            continue;
        }

        $type_opp = $opp_data['Type_Opportunite']['value'] ?? 'Ponctuelle';
        $logs[] = "Type detecté : $type_opp";
        
        // 3. Calcul des dates
        $dates_to_create = [];
        if ($type_opp === 'Récurrente') {
            $interval = (int)($opp_data['Intervalle'] ?? 1);
            $frequence_raw = strtolower($opp_data['Frequence']['value'] ?? 'semaines');
            
            $map = ['jours' => 'D', 'semaines' => 'W', 'mois' => 'M', 'années' => 'Y'];
            $period = $map[$frequence_raw] ?? 'W';

            for ($i = 0; $i < 3; $i++) {
                $clone_date = clone $rdv_date_obj;
                if ($i > 0) {
                    try {
                        $clone_date->add(new DateInterval("P" . ($i * $interval) . $period));
                    } catch (Exception $e) {
                        $logs[] = "Erreur calcul intervalle : " . $e->getMessage();
                    }
                }
                $dates_to_create[] = $clone_date->format('Y-m-d\TH:i:s\Z');
            }
        } else {
            $dates_to_create[] = $base_date_iso;
        }

        // 4. Tentative de création
        foreach ($dates_to_create as $date_str) {
            // Vérification anti-doublon plus souple (on cherche par filtre)
            // Note : filter__Date_Prev_Intervention__contains est parfois plus fiable que equal
            $check_params = [
                'user_field_names' => 'true',
                'filter__Opportunite_Liee__link_row_has' => $opp_id,
                'filter__Date_Prev_Intervention__contains' => substr($date_str, 0, 16) // On check YYYY-MM-DDTHH:mm
            ];
            
            $existing = eecie_crm_baserow_get("rows/table/{$interv_table_id}/", $check_params);

            if (!is_wp_error($existing) && isset($existing['count']) && $existing['count'] > 0) {
                $logs[] = "L'intervention du $date_str existe déjà (Doublon ignoré).";
                $ignored_count++;
                continue;
            }

            $payload = [
                'Opportunite_Liee' => [$opp_id],
                'Date_Prev_Intervention' => $date_str,
                'Fournisseur' => $fournisseur_id ? [$fournisseur_id] : [],
                'Statut_Intervention' => 3440 // ID "Planifiée"
            ];

            $res = eecie_crm_baserow_post("rows/table/{$interv_table_id}/?user_field_names=true", $payload);
            
            if (is_wp_error($res)) {
                $logs[] = "❌ Erreur création pour le $date_str : " . $res->get_error_message();
            } else {
                $logs[] = "✅ Créée avec succès pour le $date_str";
                $created_count++;
            }
        }
    }

    $summary = "Fini. Crées: $created_count, Doublons ignorés: $ignored_count.";
    $logs[] = $summary;

    // On écrit aussi dans le debug.log pour historique
    error_log("[GCE SYNC INTERVENTIONS] " . implode(" | ", $logs));

    return new WP_REST_Response([
        'success' => true, 
        'message' => $summary,
        'debug_logs' => $logs // Renvoyé au JS pour affichage si besoin
    ], 200);
}