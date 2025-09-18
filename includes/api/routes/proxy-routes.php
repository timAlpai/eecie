<?php

defined('ABSPATH') || exit;

// Route spéciale pour générer un éditeur de texte riche
register_rest_route('eecie-crm/v1', '/get-wp-editor', [
    'methods'  => 'POST', // On utilise POST pour pouvoir envoyer des paramètres comme le contenu initial
    'callback' => function (WP_REST_Request $request) {
        $params = $request->get_json_params();
        $content = $params['content'] ?? ''; // Contenu initial de l'éditeur
        $editor_id = $params['editor_id'] ?? 'gce_rich_text_editor'; // ID unique pour l'éditeur
        $settings = [
            'textarea_name' => $editor_id, // <-- CORRECTION CRUCIALE
            'media_buttons' => false,
            'textarea_rows' => 10,
            'tinymce'       => [
                'toolbar1' => 'bold,italic,underline,bullist,numlist,link,unlink,undo,redo',
                'toolbar2' => '',
            ],
        ];

        ob_start();
        wp_editor($content, $editor_id, $settings);
        $editor_html = ob_get_clean();

        // On retourne le HTML brut
        return rest_ensure_response([
            'html'     => $editor_html,
            'settings' => $settings // On renvoie aussi les paramètres
        ]);
    },
    'permission_callback' => function () {
        return is_user_logged_in() &&
            isset($_SERVER['HTTP_X_WP_NONCE']) &&
            wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
    },
]);

register_rest_route('eecie-crm/v1', '/proxy/start-task', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $req) {
        $body = $req->get_body();

        $response = wp_remote_post("https://n8n.eecie.ca/webhook/StartTask", [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body'    => $body,
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('proxy_failed', $response->get_error_message(), ['status' => 500]);
        }

        return rest_ensure_response([
            'code' => wp_remote_retrieve_response_code($response),
            'body' => wp_remote_retrieve_body($response)
        ]);
    },
    'permission_callback' => function () {
        return is_user_logged_in(); // ou __return_true si tu veux public
    }
]);

register_rest_route('eecie-crm/v1', '/proxy/calculate-devis', [
    'methods'  => 'POST',
    'callback' => 'eecie_crm_calculate_and_forward_devis_to_n8n',
    'permission_callback' => function () {
        // Sécurisé pour les utilisateurs connectés avec un nonce valide
        return is_user_logged_in() &&
            isset($_SERVER['HTTP_X_WP_NONCE']) &&
            wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
    }
]);

register_rest_route('eecie-crm/v1', '/upload-file', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $request) {
        $files = $request->get_file_params();

        if (empty($files['file'])) {
            return new WP_Error('no_file', 'Aucun fichier n\'a été envoyé.', ['status' => 400]);
        }

        $file = $files['file']; // ['name' => ..., 'type' => ..., 'tmp_name' => ..., 'error' => ..., 'size' => ...]

        // Utilise notre nouvelle fonction proxy
        $result = eecie_crm_baserow_upload_file($file['tmp_name'], $file['name']);

        if (is_wp_error($result)) {
            return $result;
        }

        // Baserow renvoie un objet avec les détails du fichier. On le renvoie au client.
        return rest_ensure_response($result);
    },
    'permission_callback' => function () {
        // Sécurité : utilisateur connecté + nonce valide
        return is_user_logged_in() &&
            isset($_SERVER['HTTP_X_WP_NONCE']) &&
            wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
    },
]);

function eecie_crm_calculate_and_forward_devis_to_n8n(WP_REST_Request $request)
{
    // URL du webhook N8N
    $n8n_webhook_url = "https://n8n.eecie.ca/webhook/fc9a0dba-704b-4391-9190-4db7a33a85b0";

    // 1. Recevoir juste l'ID du devis depuis le JavaScript
    $params = $request->get_json_params();
    $devis_id = $params['devis_id'] ?? null;

    if (!$devis_id) {
        return new WP_Error('invalid_payload', 'ID du devis manquant.', ['status' => 400]);
    }

    // 2. Récupérer les données complètes et fraîches du devis
    $devis_data = eecie_crm_get_row_by_slug_and_id('devis', $devis_id);
    if (is_wp_error($devis_data)) {
        return $devis_data;
    }

    // 3. Récupérer manuellement les articles liés (les _children)
    $articles_list_for_calc = [];
    if (isset($devis_data['Articles_devis']) && is_array($devis_data['Articles_devis'])) {
        foreach ($devis_data['Articles_devis'] as $article_link) {
            $article_data = eecie_crm_get_row_by_slug_and_id('articles_devis', $article_link['id']);
            if (!is_wp_error($article_data)) {
                $articles_list_for_calc[] = $article_data;
            }
        }
    }
    // On attache les articles récupérés à notre objet principal pour la suite du traitement
    $devis_data['_children'] = $articles_list_for_calc;

    // 4. Enrichir avec les informations du contact (logique existante)
    $contact_details = null;
    $opportunity_id = $devis_data['Task_input'][0]['id'] ?? null;
    if ($opportunity_id) {
        $opportunity_data = eecie_crm_get_row_by_slug_and_id('opportunites', $opportunity_id);
        if (!is_wp_error($opportunity_data)) {
            $contact_id = $opportunity_data['Contacts'][0]['id'] ?? null;
            if ($contact_id) {
                $contact_data = eecie_crm_get_row_by_slug_and_id('contacts', $contact_id);
                if (!is_wp_error($contact_data)) {
                    $contact_details = [
                        'id' => $contact_data['id'],
                        'nom' => $contact_data['Nom'] ?? null,
                        'email' => $contact_data['Email'] ?? null,
                        'tel' => $contact_data['Tel'] ?? null, // Note: le nom du champ dans Contacts est 'Telephone'
                        'adresse' => $contact_data['Adresse'] ?? null,
                        'code postal' => $contact_data["Code_postal"] ?? null,
                        'ville' => $contact_data['Ville'] ?? null,
                    ];
                }
            }
        }
    }

    // 5. Calculer les totaux (logique existante)
    $articles_list_for_n8n = [];
    $total_ht = 0.0;
    foreach ($devis_data['_children'] as $article) {
        if (!isset($article['Quantités']) || !isset($article['Prix_unitaire'])) continue;
        $quantite = floatval($article['Quantités']);
        $prix_unitaire = floatval($article['Prix_unitaire']);
        $sous_total_ht = $quantite * $prix_unitaire;
        $total_ht += $sous_total_ht;
        $articles_list_for_n8n[] = [
            'nom' => $article['Nom'] ?? 'Article sans nom',
            'quantite' => $quantite,
            'prix_unitaire' => $prix_unitaire,
            'sous_total_ht' => round($sous_total_ht, 2),
        ];
    }

    $tps_rate = floatval(get_option('gce_tps_rate', '0.05'));
    $tvq_rate = floatval(get_option('gce_tvq_rate', '0.09975'));
    $tps = $total_ht * $tps_rate;
    $tvq = $total_ht * $tvq_rate;
    $total_ttc = $total_ht + $tps + $tvq;

    // 6. Assembler le payload final pour N8N
    $n8n_payload = [
        'devis_id'       => $devis_data['id'] ?? null,
        'opportunity_id' => $devis_data['Task_input'][0]['id'] ?? null,
        'opportunity_name' => $devis_data['Task_input'][0]['value'] ?? null,
        'contact'        => $contact_details,
        'notes'          => $devis_data['Notes'] ?? '',
        'summary'        => [
            'total_hors_taxe' => round($total_ht, 2),
            'tps_amount'      => round($tps, 2),
            'tvq_amount'      => round($tvq, 2),
            'total_ttc'       => round($total_ttc, 2),
        ],
        'articles'       => $articles_list_for_n8n,
        'raw_data'       => $devis_data, // On envoie les données fraîches complètes
    ];

    // 7. Envoyer à N8N (logique existante)
    $response_to_n8n = wp_remote_post($n8n_webhook_url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => json_encode($n8n_payload),
        'timeout' => 30
    ]);

    if (is_wp_error($response_to_n8n)) {
        return new WP_Error('n8n_proxy_failed', 'Erreur de communication avec N8N: ' . $response_to_n8n->get_error_message(), ['status' => 502]);
    }
    $n8n_status_code = wp_remote_retrieve_response_code($response_to_n8n);
    if ($n8n_status_code >= 400) {
        return new WP_Error('n8n_error', 'N8N a retourné une erreur.', ['status' => $n8n_status_code, 'n8n_response_body' => wp_remote_retrieve_body($response_to_n8n)]);
    }

    return rest_ensure_response([
        'status'  => 'success',
        'message' => 'Devis traité et transmis à N8N.',
        'n8n_http_status' => $n8n_status_code,
    ]);
}