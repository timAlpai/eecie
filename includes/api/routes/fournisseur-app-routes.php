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
function gce_api_get_fournisseur_jobs($request)
{
    // === ÉTAPE 1: IDENTIFIER LE FOURNISSEUR (Logique 100% validée) ===
    $user_email = wp_get_current_user()->user_email;

    $contacts_table_id = get_option('gce_baserow_table_contacts');
    if (!$contacts_table_id) return new WP_Error('config_error', "ID table Contacts non configuré.", ['status' => 500]);
    $id_option_fournisseur = 3065;
    $params_contact = ['user_field_names' => 'true', 'filter__Email__equal' => $user_email, 'filter__Type__single_select_equal' => $id_option_fournisseur, 'size' => 1];
    $contact_response = eecie_crm_baserow_get("rows/table/{$contacts_table_id}/", $params_contact);

    if (is_wp_error($contact_response) || empty($contact_response['results'])) {
        error_log("[PWA DEBUG 1.2] ERREUR : Aucun contact de type Fournisseur trouvé pour " . $user_email . ". Fin.");
        return new WP_Error('no_provider_contact', "Aucun contact de type Fournisseur trouvé pour l'email: " . $user_email, ['status' => 404]);
    }
    $contact_fournisseur = $contact_response['results'][0];
    if (empty($contact_fournisseur['Fournisseur'][0]['id'])) {
        error_log("[PWA DEBUG 1.3] ERREUR : Le contact trouvé n'est pas lié à une fiche Fournisseur. Fin.");
        return new WP_Error('no_provider_linked', "Le contact trouvé n'est lié à aucune fiche Fournisseur.", ['status' => 404]);
    }
    $fournisseur_id = $contact_fournisseur['Fournisseur'][0]['id'];

    // === ÉTAPE 2: TROUVER LES DEVIS LIÉS (Syntaxe validée par cURL) ===
    $devis_table_id = get_option('gce_baserow_table_devis');
    if (!$devis_table_id) return new WP_Error('config_error', "ID table Devis non configuré.", ['status' => 500]);
    $id_field_fournisseur_in_devis = 6958;
    $params_devis = [
        'user_field_names' => 'true',
        'filter__field_' . $id_field_fournisseur_in_devis . '__link_row_has' => $fournisseur_id
    ];
    $devis_lies = eecie_crm_baserow_get("rows/table/{$devis_table_id}/", $params_devis);
    if (is_wp_error($devis_lies) || empty($devis_lies['results'])) return new WP_REST_Response([], 200);

    // === ÉTAPE 3: EXTRAIRE LES IDs DES OPPORTUNITÉS (Logique validée) ===
    $opportunite_ids_du_fournisseur = array_unique(array_filter(array_map(fn($devis) => $devis['Task_input'][0]['id'] ?? null, $devis_lies['results'])));
    if (empty($opportunite_ids_du_fournisseur)) return new WP_REST_Response([], 200);

    // === ÉTAPE 4: RÉCUPÉRER TOUTES LES OPPORTUNITÉS EN "LIVRAISON" ===
    $opp_table_id = get_option('gce_baserow_table_opportunites');
    if (!$opp_table_id) return new WP_Error('config_error', "ID table Opportunites non configuré.", ['status' => 500]);
    $id_option_livraison = 3155;
    $params_opp = ['user_field_names' => 'true', 'filter__Status__single_select_equal' => $id_option_livraison, 'size' => 200];
    $toutes_les_opps_en_livraison = eecie_crm_baserow_get("rows/table/{$opp_table_id}/", $params_opp);
    if (is_wp_error($toutes_les_opps_en_livraison) || empty($toutes_les_opps_en_livraison['results'])) return new WP_REST_Response([], 200);

    // === ÉTAPE 5: FILTRER EN PHP (La méthode 100% fiable) ===
    $jobs_filtres = [];
    foreach ($toutes_les_opps_en_livraison['results'] as $job) {
        if (in_array($job['id'], $opportunite_ids_du_fournisseur)) {
            $jobs_filtres[] = $job;
        }
    }

    // On récupère les IDs des tables une seule fois
    $articles_devis_table_id = get_option('gce_baserow_table_articles_devis');
    $rapports_table_id = eecie_crm_guess_table_id('Rapports_Livraison');
    $signatures_table_id = eecie_crm_guess_table_id('Signature_Livraison');
    $articles_livraison_table_id = eecie_crm_guess_table_id('Articles_Livraison');

    // On prépare le tableau final qui sera renvoyé
    $cleaned_jobs = [];

    foreach ($jobs_filtres as $job) {
        $final_job_data = [
            'id'               => $job['id'],
            'NomClient'        => $job['NomClient'],
            'Ville'            => $job['Ville'],
            'Travaux'          => $job['Travaux'] ?? 'Aucune description',
            'ArticlesDevis'    => [], // Sera rempli ci-dessous
            'RapportLivraison' => null // Sera rempli ci-dessous
        ];

        // 6.1 - Enrichir avec les Articles du Devis (les articles prévus)
        if (!empty($job['Devis'][0]['id'])) {
            $devis_id = $job['Devis'][0]['id'];
            $devis_complet = eecie_crm_get_row_by_slug_and_id('devis', $devis_id);
            if (!is_wp_error($devis_complet) && !empty($devis_complet['Articles_devis'])) {
                foreach ($devis_complet['Articles_devis'] as $article_ref) {
                    $article_data = eecie_crm_get_row_by_slug_and_id('articles_devis', $article_ref['id']);
                    if (!is_wp_error($article_data)) {
                        $final_job_data['ArticlesDevis'][] = $article_data;
                    }
                }
            }
        }

        // 6.2 - Enrichir avec le Rapport de Livraison (s'il existe)
        if ($rapports_table_id) {
            $params_rapport = ['user_field_names' => 'true', 'filter__Opportunite_liee__link_row_has' => $job['id'], 'size' => 1];
            $rapport_response = eecie_crm_baserow_get("rows/table/{$rapports_table_id}/", $params_rapport);

            if (!is_wp_error($rapport_response) && !empty($rapport_response['results'])) {
                $rapport_enrichi = $rapport_response['results'][0];

                // 6.2.1 - Hydrater la Signature liée au rapport
                if (!empty($rapport_enrichi['Signature_client'][0]['id'])) {
                    $signature_id = $rapport_enrichi['Signature_client'][0]['id'];
                    $signature_data = eecie_crm_get_row_by_slug_and_id('Signatures_Livraison', $signature_id); // Assumant que le slug est 'devis_signatures'
                    if (!is_wp_error($signature_data) && !empty($signature_data['Fichier_signature'][0]['url'])) {
                        $rapport_enrichi['Signature_Image_URL'] = $signature_data['Fichier_signature'][0]['url'];
                    }
                }

                // 6.2.2 - **LA CORRECTION CRUCIALE** - Hydrater les Articles de Livraison liés au rapport
                if (!empty($rapport_enrichi['Articles_Livraison'])) {
                    error_log("6.2.1 verif 1 ok");
                    $articles_complets = [];
                    foreach ($rapport_enrichi['Articles_Livraison'] as $article_ref) {
                        // On utilise la fonction eecie_crm_get_row_by_slug_and_id qui est faite pour ça
                        $article_data = eecie_crm_get_row_by_slug_and_id('articles_livraison', $article_ref['id']);
                        if (!is_wp_error($article_data)) {
                            $articles_complets[] = $article_data;
                        }
                    }
                    // On remplace le tableau de références par le tableau d'objets complets
                    $rapport_enrichi['Articles_Livraison'] = $articles_complets;
                }

                $final_job_data['RapportLivraison'] = $rapport_enrichi;
            }
        }

        $cleaned_jobs[] = $final_job_data;
    }
    $test = print_r($cleaned_jobs, 1);
    error_log($test);

    return new WP_REST_Response($cleaned_jobs, 200);
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

    if (empty($params['opportunite_id']) || empty($params['signature_base64'])) {
        return new WP_REST_Response(['message' => 'Données manquantes (opportunité ou signature).'], 400);
    }

    // --- ÉTAPE 2: IDENTIFICATION DE L'UTILISATEUR ET DES TABLES ---
    $user_id = get_current_user_id();
    $user = get_userdata($user_id);
    $fournisseur_baserow = gce_get_baserow_t1_user_by_email($user->user_email);
    if (!$fournisseur_baserow) {
        return new WP_REST_Response(['message' => 'Utilisateur fournisseur introuvable dans Baserow.'], 403);
    }

    $rapport_table_id = get_option('gce_baserow_table_rapports_livraison') ?: eecie_crm_guess_table_id('Rapports_Livraison');
    $articles_table_id = get_option('gce_baserow_table_articles_livraison') ?: eecie_crm_guess_table_id('Articles_Livraison');
    $signature_table_id = get_option('gce_baserow_table_signatures_livraison') ?: eecie_crm_guess_table_id('Signatures_Livraison');

    if (!$rapport_table_id || !$articles_table_id || !$signature_table_id) {
        return new WP_REST_Response(['message' => 'Erreur critique de configuration des tables.'], 500);
    }

    // --- ÉTAPE 3: GESTION DU RAPPORT (CRÉATION OU MISE À JOUR) ---
    $report_id = null;

    if (isset($params['report_id']) && !empty($params['report_id'])) {
        $report_id = (int)$params['report_id'];
        $update_payload = ['Notes_intervention' => sanitize_textarea_field($params['notes']), 'Date_intervention' => gmdate('Y-m-d\TH:i:s\Z')];
        eecie_crm_baserow_patch("rows/table/{$rapport_table_id}/{$report_id}/", $update_payload);
    } else {
        $create_payload = ['Opportunite_liee' => [(int)$params['opportunite_id']], 'Fournisseur_intervenant' => [(int)$fournisseur_baserow['id']], 'Notes_intervention' => sanitize_textarea_field($params['notes']), 'Date_intervention' => gmdate('Y-m-d\TH:i:s\Z')];
        $new_report_data = eecie_crm_baserow_request('POST', "rows/table/{$rapport_table_id}/", $create_payload);

        if (is_wp_error($new_report_data) || !isset($new_report_data['id'])) {
            return new WP_REST_Response(['message' => 'La création du rapport a échoué.'], 500);
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
    error_log($test);
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
    $opportunite_id = (int) $request['id'];

    // Récupérer l'ID de la table des opportunités
    $opp_table_id = get_option('gce_baserow_table_opportunites') ?: eecie_crm_guess_table_id('Task_input');
    if (!$opp_table_id) {
        return new WP_Error('config_error', "La table des opportunités n'est pas configurée.", ['status' => 500]);
    }

    // Récupérer le schéma de la table pour trouver l'ID du champ et de l'option
    $opp_schema = eecie_crm_baserow_get_fields($opp_table_id);
    if (is_wp_error($opp_schema)) {
        return new WP_Error('schema_error', "Impossible de lire la structure de la table des opportunités.", ['status' => 500]);
    }

    $status_field = array_values(array_filter($opp_schema, fn($f) => $f['name'] === 'Status'))[0] ?? null;
    if (!$status_field) {
        return new WP_Error('schema_error', "Le champ 'Status' est introuvable.", ['status' => 500]);
    }

    $finaliser_option = array_values(array_filter($status_field['select_options'], fn($o) => $o['value'] === 'Finaliser'))[0] ?? null;
    if (!$finaliser_option) {
        return new WP_Error('schema_error', "L'option de statut 'Finaliser' est introuvable.", ['status' => 500]);
    }

    // Préparer le payload pour la mise à jour
    $payload = [
        'field_' . $status_field['id'] => $finaliser_option['id']
    ];

    // Envoyer la requête PATCH à Baserow
    $update_result = eecie_crm_baserow_patch("rows/table/{$opp_table_id}/{$opportunite_id}/", $payload);

    if (is_wp_error($update_result)) {
        return new WP_Error('baserow_error', "La mise à jour du statut dans Baserow a échoué.", ['status' => 502]);
    }

    return new WP_REST_Response(['success' => true, 'message' => 'Dossier clôturé avec succès.'], 200);
}