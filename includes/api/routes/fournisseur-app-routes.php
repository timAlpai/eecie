<?php

defined('ABSPATH') || exit;

/**
 * ===================================================================
 * ==                 ROUTES POUR L'APP FOURNISSEUR                 ==
 * ===================================================================
 */

// 1. ROUTE POUR RÉCUPÉRER LES MISSIONS ASSIGNÉES
// GET https://portal.eecie.ca/wp-json/eecie-crm/v1/fournisseur/mes-jobs
register_rest_route('eecie-crm/v1', '/fournisseur/mes-jobs', [
    'methods'  => 'GET',
    'callback' => 'gce_api_get_fournisseur_jobs',
    'permission_callback' => 'is_user_logged_in', // <- Utilise notre fonction de sécurité
]);

// 2. ROUTE POUR SOUMETTRE LE RAPPORT DE LIVRAISON
// POST https://portal.eecie.ca/wp-json/eecie-crm/v1/livraison/submit-report
register_rest_route('eecie-crm/v1', '/livraison/submit-report', [
    'methods'  => 'POST',
    'callback' => 'gce_api_submit_livraison_report',
    'permission_callback' => 'is_user_logged_in',
]);

// NOUVEL ENDPOINT POUR INVALIDER UN RAPPORT DE LIVRAISON SIGNÉ
register_rest_route('eecie-crm/v1', '/livraison/invalidate-report/(?P<id>\\d+)', [
    'methods' => WP_REST_Server::CREATABLE, // Correspond à POST
    'callback' => 'gce_invalidate_livraison_report',
    'permission_callback' => 'is_user_logged_in',
    'args' => [
        'id' => [
            'validate_callback' => function ($param, $request, $key) {
                return is_numeric($param);
            }
        ],
    ],
]);

// NOUVEL ENDPOINT POUR SUPPRIMER UN ARTICLE DE LIVRAISON
register_rest_route('eecie-crm/v1', '/articles-livraison/(?P<id>\\d+)', [
    'methods' => WP_REST_Server::DELETABLE, // Correspond à DELETE
    'callback' => 'gce_delete_article_livraison',
    'permission_callback' => 'is_user_logged_in',
    'args' => [
        'id' => [
            'validate_callback' => function ($param, $request, $key) {
                return is_numeric($param);
            }
        ],
    ],
]);

// NOUVEL ENDPOINT POUR CLÔTURER UN DOSSIER
register_rest_route('eecie-crm/v1', '/opportunite/(?P<id>\\d+)/cloturer', [
    'methods' => WP_REST_Server::CREATABLE, // Correspond à POST
    'callback' => 'gce_api_cloturer_opportunite',
    'permission_callback' => 'is_user_logged_in',
    'args' => [
        'id' => [
            'validate_callback' => function ($param, $request, $key) {
                return is_numeric($param);
            }
        ],
    ],
]);

/**
 * Callback pour la route GET /fournisseur/mes-jobs
 * Version corrigée qui suit la structure : Contact -> Fournisseur -> Devis -> Opportunité
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function gce_api_get_fournisseur_jobs(WP_REST_Request $request)
{
    // === ÉTAPE 1 & 2 : IDENTIFICATION ET RÉCUPÉRATION DES INTERVENTIONS (inchangées et validées) ===
    $user_email = wp_get_current_user()->user_email;
    $contact_fournisseur = gce_get_baserow_user_by_email($user_email, 'Fournisseur');
    if (!$contact_fournisseur || empty($contact_fournisseur['Fournisseur'][0]['id'])) {
        return new WP_Error('provider_not_found', "Aucun fournisseur associé.", ['status' => 404]);
    }
    $fournisseur_id = $contact_fournisseur['Fournisseur'][0]['id'];

    $interventions_table_id = eecie_crm_guess_table_id('Interventions_Planifiees');
    if (!$interventions_table_id) { return new WP_Error('config_error', "Table 'Interventions_Planifiees' non trouvée.", ['status' => 500]); }

    $params = [
        'user_field_names' => 'true',
        'filter__Fournisseur__link_row_has' => $fournisseur_id,
        'filter__Statut_Intervention__single_select_equal' => 3440,
        'size' => 200
    ];
    $interventions = eecie_crm_baserow_get("rows/table/{$interventions_table_id}/", $params);
    
    if (is_wp_error($interventions) || empty($interventions['results'])) {
        return new WP_REST_Response([], 200);
    }

    // === ÉTAPE 3: HYDRATATION COMPLÈTE DES DONNÉES POUR LA PWA ===
    $jobs_pour_pwa = [];
    foreach ($interventions['results'] as $intervention) {
        if (empty($intervention['Opportunite_Liee'][0]['id'])) continue;

        $opportunite_id = $intervention['Opportunite_Liee'][0]['id'];
        $job_data = eecie_crm_get_row_by_slug_and_id('opportunites', $opportunite_id);

        if (!is_wp_error($job_data)) {
            $job_data['intervention_id'] = $intervention['id'];
             $job_data['Date_Prev_Intervention'] = $intervention['Date_Prev_Intervention'];
             // ======================================================================
            // ==                 AJOUT : HYDRATER LE CONTACT COMPLET              ==
            // ======================================================================
            $job_data['ContactDetails'] = null; // Initialiser la propriété
            if (!empty($job_data['Contacts'][0]['id'])) {
                $contact_id = $job_data['Contacts'][0]['id'];
                $contact_data = eecie_crm_get_row_by_slug_and_id('contacts', $contact_id);
                if (!is_wp_error($contact_data)) {
                    // On attache l'objet contact complet à notre "job"
                    $job_data['ContactDetails'] = $contact_data;
                }
            }
            // ======================================================================
            // ==                           FIN DE L'AJOUT                         ==
            // ======================================================================
            $job_data['ArticlesDevis'] = [];
            $job_data['RapportLivraison'] = null; // Important: Initialiser à null

            // Hydratation des Articles du Devis (logique existante)
            if (!empty($job_data['Devis'][0]['id'])) {
                $devis_complet = eecie_crm_get_row_by_slug_and_id('devis', $job_data['Devis'][0]['id']);
                if (!is_wp_error($devis_complet) && !empty($devis_complet['Articles_devis'])) {
                    $articles_hydrates = [];
                    foreach ($devis_complet['Articles_devis'] as $article_ref) {
                        $article_data = eecie_crm_get_row_by_slug_and_id('articles_devis', $article_ref['id']);
                        if (!is_wp_error($article_data)) $articles_hydrates[] = $article_data;
                    }
                    $job_data['ArticlesDevis'] = $articles_hydrates;
                }
            }

            // ======================================================================
            // ==                 DÉBUT DE LA CORRECTION D'HYDRATATION               ==
            // ======================================================================
            
            // On vérifie si un rapport est lié à L'INTERVENTION
            if (!empty($intervention['Rapport_Livraison_Lie'][0]['id'])) {
                $rapport_id = $intervention['Rapport_Livraison_Lie'][0]['id'];

                // On récupère l'objet Rapport complet
                $rapport_data = eecie_crm_get_row_by_slug_and_id('rapports_livraison', $rapport_id);
                
                if (!is_wp_error($rapport_data)) {
                    // 1. HYDRATER LA SIGNATURE
                    if (!empty($rapport_data['Signature_client'][0]['id'])) {
                        $signature_id = $rapport_data['Signature_client'][0]['id'];
                        $signature_data = eecie_crm_get_row_by_slug_and_id('signatures_livraison', $signature_id);
                        if (!is_wp_error($signature_data) && !empty($signature_data['Fichier_signature'][0]['url'])) {
                            // On crée la propriété que la PWA attend
                            $rapport_data['Signature_Image_URL'] = $signature_data['Fichier_signature'][0]['url'];
                        }
                    }

                    // 2. HYDRATER LES ARTICLES DE LIVRAISON
                    if (!empty($rapport_data['Articles_Livraison'])) {
                        $articles_livraison_complets = [];
                        foreach ($rapport_data['Articles_Livraison'] as $article_ref) {
                            $article_data = eecie_crm_get_row_by_slug_and_id('articles_livraison', $article_ref['id']);
                            if (!is_wp_error($article_data)) {
                                $articles_livraison_complets[] = $article_data;
                            }
                        }
                        // On remplace le tableau de références par le tableau d'objets complets
                        $rapport_data['Articles_Livraison'] = $articles_livraison_complets;
                    }
                    
                    // On assigne l'objet rapport entièrement hydraté
                    $job_data['RapportLivraison'] = $rapport_data;
                }
            }
            
            // ======================================================================
            // ==                   FIN DE LA CORRECTION D'HYDRATATION                 ==
            // ======================================================================

            $jobs_pour_pwa[] = $job_data;
        }
    }


    return new WP_REST_Response($jobs_pour_pwa, 200);
}


/**
 * Crée ou met à jour un rapport de livraison et sa signature.
 * Version finale avec journalisation et correction de l'URL de l'API.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function gce_api_submit_livraison_report(WP_REST_Request $request)
{
    // --- ÉTAPE 1: DÉMARRAGE ET VALIDATION DES PARAMÈTRES ---
    $params = $request->get_json_params();
        // **NOUVEAU** : On attend maintenant l'ID de l'intervention
    if (empty($params['intervention_id']) || empty($params['signature_base64'])) {
        return new WP_Error('missing_data', 'Données manquantes (ID intervention ou signature).', ['status' => 400]);
    }
    
    $intervention_id = (int)$params['intervention_id'];
    $opportunite_id = (int)$params['opportunite_id']; // On le garde pour la liaison au rapport
    $report_id_from_pwa = isset($params['report_id']) ? (int)$params['report_id'] : null;

    // --- ÉTAPE 2: IDENTIFICATION DE L'UTILISATEUR ET DES TABLES ---
    $user_id = get_current_user_id();
    $user = get_userdata($user_id);
    // === ÉTAPE 2: IDENTIFIER LE FOURNISSEUR VIA L'OPPORTUNITÉ ET LE DEVIS ===
    $opportunite_data = eecie_crm_get_row_by_slug_and_id('opportunites', $opportunite_id);
    if (is_wp_error($opportunite_data)) {
        error_log("[PWA SUBMIT 2.2] ERREUR : Impossible de récupérer les données de l'opportunité.");
        return $opportunite_data;
    }

    if (empty($opportunite_data['Devis'][0]['id'])) {
        error_log("[PWA SUBMIT 2.3] ERREUR : L'opportunité #{$opportunite_id} n'est liée à aucun devis.");
        return new WP_Error('no_devis_linked', 'L\'opportunité n\'est pas liée à un devis.', ['status' => 400]);
    }
    $devis_id = $opportunite_data['Devis'][0]['id'];

    $devis_data = eecie_crm_get_row_by_slug_and_id('devis', $devis_id);
    if (is_wp_error($devis_data)) {
        error_log("[PWA SUBMIT 2.5] ERREUR : Impossible de récupérer les données du devis #{$devis_id}.");
        return $devis_data;
    }
    
    if (empty($devis_data['Fournisseur'][0]['id'])) {
        error_log("[PWA SUBMIT 2.6] ERREUR : Le devis #{$devis_id} n'a aucun fournisseur assigné.");
        return new WP_Error('no_provider_on_devis', 'Aucun fournisseur n\'est assigné à ce devis.', ['status' => 400]);
    }
    
    $fournisseur_id = $devis_data['Fournisseur'][0]['id'];

    $rapport_table_id = get_option('gce_baserow_table_rapports_livraison') ?: eecie_crm_guess_table_id('Rapports_Livraison');
    $interventions_table_id = eecie_crm_guess_table_id('Interventions_Planifiees');
    $articles_table_id = get_option('gce_baserow_table_articles_livraison') ?: eecie_crm_guess_table_id('Articles_Livraison');
    $signature_table_id = get_option('gce_baserow_table_signatures_livraison') ?: eecie_crm_guess_table_id('Signatures_Livraison');

    if (!$rapport_table_id || !$articles_table_id || !$signature_table_id) {
        return new WP_REST_Response(['message' => 'Erreur critique de configuration des tables.'], 500);
    }

    // --- ÉTAPE 3: GESTION DU RAPPORT (CRÉATION OU MISE À JOUR) ---
    $report_id = $report_id_from_pwa;
    
    // **CORRECTION MAJEURE : On assemble le payload AVANT de faire la requête**
    $shared_payload = [
        'Opportunite_liee'        => [$opportunite_id],
        'Fournisseur_intervenant'   => [$fournisseur_id],
        'Notes_intervention'        => sanitize_textarea_field($params['notes']),
        'Date_intervention'         => gmdate('Y-m-d\TH:i:s\Z'),
        'Interventions_Planifiees'  => [$intervention_id]
    ];

    if ($report_id) {
        // MISE À JOUR D'UN RAPPORT EXISTANT
        eecie_crm_baserow_patch("rows/table/{$rapport_table_id}/{$report_id}/?user_field_names=true", $shared_payload);
    } else {
        // CRÉATION D'UN NOUVEAU RAPPORT
        $new_report_data = eecie_crm_baserow_request('POST', "rows/table/{$rapport_table_id}/?user_field_names=true", $shared_payload);

        if (is_wp_error($new_report_data) || !isset($new_report_data['id'])) {
            error_log("[PWA SUBMIT 4.2] ERREUR lors de la création du rapport : " . (is_wp_error($new_report_data) ? $new_report_data->get_error_message() : 'ID manquant dans la réponse.'));
            return new WP_Error('report_creation_failed', 'La création du rapport a échoué.', ['status' => 500]);
        }
        $report_id = $new_report_data['id'];
    }


    // --- ÉTAPE 4: CRÉATION DES ARTICLES SUPPLÉMENTAIRES (VERSION CORRIGÉE) ---
    if (!empty($params['articles_supplementaires']) && is_array($params['articles_supplementaires'])) {
        // Étape 4.1 : Récupérer le schéma de la table des articles pour mapper les noms aux IDs de champs
        $articles_schema = eecie_crm_baserow_get_fields($articles_table_id);
        $articles_field_map = [];
        if (!is_wp_error($articles_schema)) {
            foreach ($articles_schema as $field) {
                $articles_field_map[$field['name']] = 'field_' . $field['id'];
            }
        } else {
            error_log("[EECIE PWA LOG] ERREUR ÉTAPE 4.1: Impossible de récupérer le schéma de la table Articles_Livraison.");
            // On ne bloque pas tout, mais on log l'erreur
        }

        foreach ($params['articles_supplementaires'] as $article) {
            // Étape 4.2 : Construire le payload avec les field_IDs, comme pour la signature
            $article_payload = [
                $articles_field_map['Nom'] => sanitize_text_field($article['nom']),
                $articles_field_map['Quantités'] => (float)$article['quantite'],
                $articles_field_map['Prix_unitaire'] => (float)$article['prix_unitaire'],
                $articles_field_map['Rapport_lie'] => [$report_id],
            ];

            // Étape 4.4 : Appeler l'API SANS le paramètre ?user_field_names=true
            eecie_crm_baserow_request('POST', "rows/table/{$articles_table_id}/", $article_payload);
        }
    } else {
    }

    // --- ÉTAPE 5: GESTION DE L'IMAGE DE SIGNATURE ---
    preg_match('/^data:image\/(png|jpeg);base64,(.*)$/', $params['signature_base64'], $matches);
    $image_data = base64_decode($matches[2]);
    $filename = 'signature_report_' . $report_id . '_' . time() . '.png';
    $temp_dir = get_temp_dir();
    $file_path = trailingslashit($temp_dir) . $filename;

    if (file_put_contents($file_path, $image_data) === false) {
        error_log("[EECIE PWA LOG] ERREUR ÉTAPE 5.1: Impossible d'écrire le fichier de signature temporaire sur le disque.");
        return new WP_REST_Response(['message' => 'Impossible de sauvegarder le fichier de signature.'], 500);
    }

    $uploaded_file = eecie_crm_baserow_upload_file($file_path, $filename);
    unlink($file_path);

    if (is_wp_error($uploaded_file) || !isset($uploaded_file['name'])) {
        error_log("[EECIE PWA LOG] ERREUR ÉTAPE 5.2: L'upload du fichier signature vers Baserow a échoué. Réponse: " . json_encode($uploaded_file));
        return new WP_REST_Response(['message' => 'L\'upload de la signature a échoué.'], 500);
    }

    // --- ÉTAPE 6: CRÉATION DE L'ENREGISTREMENT DE LA SIGNATURE ---
    $signature_schema = eecie_crm_baserow_get_fields($signature_table_id);
    $field_map = [];
    foreach ($signature_schema as $field) {
        $field_map[$field['name']] = 'field_' . $field['id'];
    }

    if (!isset($field_map['Fichier_signature']) || !isset($field_map['Rapports_Livraison']) || !isset($field_map['Date_signature']) || !isset($field_map['Addresse_IP']) || !isset($field_map['User_Agent'])) {
        return new WP_REST_Response(['message' => 'Erreur de configuration de la table des signatures.'], 500);
    }

    $signature_payload = [
        $field_map['Fichier_signature']  => [['name' => $uploaded_file['name']]],
        $field_map['Rapports_Livraison'] => [$report_id],
        $field_map['Date_signature']     => gmdate('Y-m-d\TH:i:s\Z'),
        $field_map['Addresse_IP']        => $_SERVER['REMOTE_ADDR'] ?? 'N/A',
        $field_map['User_Agent']         => $_SERVER['HTTP_USER_AGENT'] ?? 'N/A',
    ];

    // CORRECTION : On retire ?user_field_names=true de l'URL pour la création.
    $new_signature = eecie_crm_baserow_request('POST', "rows/table/{$signature_table_id}/", $signature_payload);

    if (is_wp_error($new_signature) || !isset($new_signature['id'])) {
        return new WP_REST_Response(['message' => 'La création de l\'enregistrement de la signature a échoué.'], 500);
    }

    // --- ÉTAPE 7: FINALISATION - LIER LA SIGNATURE AU RAPPORT ---
    $rapport_schema = eecie_crm_baserow_get_fields($rapport_table_id);
    $signature_client_field_id = null;
    foreach ($rapport_schema as $field) {
        if ($field['name'] === 'Signature_client') {
            $signature_client_field_id = $field['id'];
            break;
        }
    }

    if ($signature_client_field_id) {
        eecie_crm_baserow_patch("rows/table/{$rapport_table_id}/{$report_id}/", ['field_' . $signature_client_field_id => [$new_signature['id']]]);
    }

 return new WP_REST_Response(['message' => 'Rapport soumis avec succès !', 'report_id' => $report_id], 200);
}

/**
 * Invalide un rapport de livraison en supprimant sa signature.
 * Version Simplifiée et Corrigée : suit la logique directe "Lire puis Supprimer".
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function gce_invalidate_livraison_report(WP_REST_Request $request)
{
    $report_id = (int) $request['id'];
    $rapport_table_id = get_option('gce_baserow_table_rapports_livraison') ?: eecie_crm_guess_table_id('Rapports_Livraison');
    $signature_table_id = get_option('gce_baserow_table_signatures_livraison') ?: eecie_crm_guess_table_id('Signatures_Livraison');

    if (!$rapport_table_id || !$signature_table_id) {
        return new WP_REST_Response(['message' => 'Erreur: Configuration des tables de livraison manquante.'], 500);
    }

    // --- ÉTAPE 1 : Récupérer le rapport de livraison pour trouver l'ID de la signature ---
    $report_data = eecie_crm_baserow_get("rows/table/{$rapport_table_id}/{$report_id}/?user_field_names=true");

    if (is_wp_error($report_data)) {
        error_log('GCE API Invalidate - Erreur lors de la récupération du rapport #' . $report_id . ': ' . $report_data->get_error_message());
        return new WP_REST_Response(['message' => 'Erreur: Impossible de trouver le rapport de livraison.'], 404);
    }
    $test = print_r($report_data, 1);
    // --- ÉTAPE 2 : Extraire l'ID de la signature du champ de liaison ---
    // On vérifie que le champ 'Signatures_Livraison' existe, n'est pas vide et est un tableau.
    if (empty($report_data['Signature_client']) || !is_array($report_data['Signature_client']) || !isset($report_data['Signature_client'][0]['id'])) {
        // S'il n'y a pas de signature liée, le travail est déjà fait. On renvoie un succès.
        return new WP_REST_Response(['message' => 'Aucune signature n\'était liée à ce rapport. Modification autorisée.'], 200);
    }

    // On récupère l'ID de la première signature liée.
    $signature_id_to_delete = (int) $report_data['Signature_client'][0]['id'];

    // --- ÉTAPE 3 : Envoyer la requête de suppression pour la signature ---
    $delete_result = eecie_crm_baserow_request('DELETE', "rows/table/{$signature_table_id}/{$signature_id_to_delete}/");

    // L'API Baserow renvoie une réponse vide (null) avec un statut 204 en cas de succès.
    // Si une erreur se produit, $delete_result sera un objet WP_Error.
    if (is_wp_error($delete_result)) {
        error_log('GCE API Invalidate - Erreur lors de la suppression de la signature #' . $signature_id_to_delete . ': ' . $delete_result->get_error_message());
        return new WP_REST_Response(['message' => 'Erreur: La suppression de la signature dans la base de données a échoué.'], 502);
    }

    // --- ÉTAPE 4 (Nettoyage) : Vider le champ de liaison sur le rapport ---
    // C'est une bonne pratique pour éviter les liens "fantômes".
    $update_payload = ['Signatures_Livraison' => []];
    eecie_crm_baserow_patch("rows/table/{$rapport_table_id}/{$report_id}/?user_field_names=true", $update_payload);

    return new WP_REST_Response(['message' => 'Signature invalidée avec succès. Le rapport peut être modifié.'], 200);
}

/**
 * Supprime une ligne spécifique de la table Articles_Livraison.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function gce_delete_article_livraison(WP_REST_Request $request)
{
    $article_id = (int) $request['id'];
    $table_id = get_option('gce_baserow_table_articles_livraison') ?: eecie_crm_guess_table_id('Articles_Livraison');

    if (!$table_id) {
        return new WP_REST_Response(['message' => 'Erreur: Configuration de la table Articles_Livraison manquante.'], 500);
    }

    // Envoyer la requête de suppression à Baserow
    $delete_result = eecie_crm_baserow_request('DELETE', "rows/table/{$table_id}/{$article_id}/");

    // En cas de succès, Baserow renvoie un statut 204 avec un corps vide.
    if (is_wp_error($delete_result)) {
        error_log('GCE API Delete Article - Erreur lors de la suppression de l\'article #' . $article_id . ': ' . $delete_result->get_error_message());
        return new WP_REST_Response(['message' => 'Erreur: La suppression de l\'article dans la base de données a échoué.'], 502);
    }

    return new WP_REST_Response(['message' => 'Article supprimé avec succès.'], 200);
}

/**
 * Change le statut d'une opportunité à "Finaliser".
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function gce_api_cloturer_opportunite(WP_REST_Request $request)
{
 
    // === ÉTAPE 1: RÉCUPÉRER LES IDs NÉCESSAIRES ===
    $opportunite_id = (int) $request['id'];
    $params = $request->get_json_params();
    $intervention_id = isset($params['intervention_id']) ? (int)$params['intervention_id'] : null;

    if (!$intervention_id) {
        error_log("[PWA CLOTURE 1.1] ERREUR: L'ID de l'intervention est manquant dans la requête.");
        return new WP_Error('missing_intervention_id', "L'ID de l'intervention est requis.", ['status' => 400]);
    }
 
    // === ÉTAPE 2: CHANGER LE STATUT DE L'INTERVENTION À "Realisée" ===
    $interventions_table_id = eecie_crm_guess_table_id('Interventions_Planifiees');
    $id_option_realisee = 3441; // ID de l'option de statut "Realisée"

    $intervention_update_payload = ['Statut_Intervention' => $id_option_realisee];
    
    $intervention_update_result = eecie_crm_baserow_patch("rows/table/{$interventions_table_id}/{$intervention_id}/?user_field_names=true", $intervention_update_payload);

    if (is_wp_error($intervention_update_result)) {
        error_log("[PWA CLOTURE 2.1] ERREUR: La mise à jour du statut de l'intervention #{$intervention_id} a échoué. " . $intervention_update_result->get_error_message());
        return $intervention_update_result;
    }
    // === ÉTAPE 3: VÉRIFIER LE TYPE DE L'OPPORTUNITÉ ===
    $opportunite_data = eecie_crm_get_row_by_slug_and_id('opportunites', $opportunite_id);
    if (is_wp_error($opportunite_data)) {
        error_log("[PWA CLOTURE 3.1] ERREUR: Impossible de récupérer les données de l'opportunité #{$opportunite_id}.");
        return $opportunite_data;
    }

    $id_option_ponctuelle = 3433; // ID de l'option de type "Ponctuelle"
    $type_opportunite_id = $opportunite_data['Type_Opportunite']['id'] ?? null;

    // === ÉTAPE 4: SI PONCTUELLE, CHANGER LE STATUT DE L'OPPORTUNITÉ À "Finaliser" ===
    if ($type_opportunite_id === $id_option_ponctuelle) {
 
        $opp_table_id = eecie_crm_guess_table_id('Task_input');
        $id_option_finaliser = 3157; // ID de l'option de statut "Finaliser"

        $opportunite_update_payload = ['Status' => $id_option_finaliser];
        
        $opp_update_result = eecie_crm_baserow_patch("rows/table/{$opp_table_id}/{$opportunite_id}/?user_field_names=true", $opportunite_update_payload);
        
        if (is_wp_error($opp_update_result)) {
            error_log("[PWA CLOTURE 4.2] ERREUR: La mise à jour du statut de l'opportunité #{$opportunite_id} a échoué.");
            // On ne retourne pas une erreur fatale, car l'intervention a bien été mise à jour. On logue simplement.
        } else {
            error_log("[PWA CLOTURE 4.3] Succès: Statut de l'opportunité #{$opportunite_id} passé à 'Finaliser'.");
        }
    } else {
        error_log("[PWA CLOTURE 4.1] L'opportunité est de type 'Récurrente' ou non défini. Le statut de l'opportunité n'est pas modifié.");
    }
    
    return new WP_REST_Response(['success' => true, 'message' => 'L\'intervention a été marquée comme réalisée.'], 200);
}