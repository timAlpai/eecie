<?php

defined('ABSPATH') || exit;

// ===== Opportunités =====
register_rest_route('eecie-crm/v1', '/opportunites', [
    'methods'  => 'GET',
    'callback' => 'gce_get_user_opportunites',
    'permission_callback' => function () {
        $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE'])
            && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
        return is_user_logged_in() && $nonce_valid;
    },
]);

register_rest_route('eecie-crm/v1', '/opportunites/schema', [
    'methods'  => 'GET',
    'callback' => function () {
        // slug « opportunites » => nom Baserow « Task_input »
        $table_id = get_option('gce_baserow_table_opportunites')
            ?: eecie_crm_guess_table_id('Task_input');
        if (!$table_id) {
            return new WP_Error('no_table', 'Table Opportunités introuvable', ['status' => 500]);
        }
        $fields = eecie_crm_baserow_get_fields($table_id);
        return is_wp_error($fields) ? $fields : rest_ensure_response($fields);
    },
    'permission_callback' => function () {
        $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE'])
            && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
        return is_user_logged_in() && $nonce_valid;
    },
]);

register_rest_route('eecie-crm/v1', '/row/(?P<table_slug>[a-z_]+)/(?P<id>\d+)', [
    'methods'  => 'GET',
    'callback' => function (WP_REST_Request $request) {
        $slug = sanitize_text_field($request['table_slug']);
        $row_id = (int) $request['id'];

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
            // On regarde dans notre map si le slug a un nom spécial.
            // Sinon, on prend le slug et on met la première lettre en majuscule (pour "contacts", "devis", etc.)
            $baserow_name_to_guess = $slug_to_baserow_name_map[$slug] ?? ucfirst($slug);

            $table_id = eecie_crm_guess_table_id($baserow_name_to_guess);
        }

        if (!$table_id) {
            return new WP_Error('invalid_table', "Impossible de trouver la table Baserow pour le slug `$slug`", ['status' => 404]);
        }

        $data = eecie_crm_baserow_get("rows/table/$table_id/$row_id/?user_field_names=true");

        if (is_wp_error($data)) {
            return $data;
        }

        // Pour aider le JS, on peut lui redonner le slug, même si ce n'est plus strictement nécessaire
        // avec la dernière version du JS. C'est une bonne pratique.
        $data['rest_slug_for_popup'] = $slug;

        return rest_ensure_response($data);
    },
    'permission_callback' => function () {
        $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
        return is_user_logged_in() && $nonce_valid;
    },
]);

register_rest_route('eecie-crm/v1', '/opportunites/(?P<id>\d+)', [
    'methods'  => 'PATCH',
    'callback' => function (WP_REST_Request $request) {
        $id = (int) $request['id'];
        $body = $request->get_json_params();
        $table_id = get_option('gce_baserow_table_opportunites') ?: eecie_crm_guess_table_id('Task_input');
        if (!$table_id) {
            return new WP_Error('no_table', 'Table inconnue');
        }

        $baseUrl = rtrim(get_option('gce_baserow_url'), '/');
        $token = get_option('gce_baserow_api_key');
        $url = "$baseUrl/api/database/rows/table/$table_id/$id/";

        $response = wp_remote_request($url, [
            'method' => 'PATCH',
            'headers' => [
                'Authorization' => 'Token ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('baserow_error', $response->get_error_message(), ['status' => 502]);
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status !== 200) {
            return new WP_Error('baserow_api_error', "Erreur Baserow ($status)", ['status' => $status, 'details' => $body]);
        }

        return rest_ensure_response($body);
    },
    'permission_callback' => function () {
        $nonce_valid = isset($_SERVER['HTTP_X_WP_NONCE']) &&
            wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');
        return is_user_logged_in() && $nonce_valid;
    },
]);

// NOUVEL ENDPOINT EFFICACE POUR LE LAZY LOADING
register_rest_route('eecie-crm/v1', '/opportunites/(?P<id>\d+)/related-data', [
    'methods'  => 'GET',
    'callback' => function (WP_REST_Request $request) {
        $opp_id = (int)$request['id'];

        $results = [
            'taches'       => [],
            'appels'       => [],
            'interactions' => [],
            'devis'        => [],
            'articles'     => [],
        ];

        // 1. Récupérer les Tâches liées (AVEC FILTRE)
        $taches_table_id = get_option('gce_baserow_table_taches') ?: eecie_crm_guess_table_id('Taches');
        if ($taches_table_id) {
            $taches_schema = eecie_crm_baserow_get_fields($taches_table_id);
            if (!is_wp_error($taches_schema)) {
                $opp_link_in_taches = array_values(array_filter($taches_schema, fn($f) => $f['name'] === 'opportunite'))[0] ?? null;

                // --- DÉBUT DE LA CORRECTION ---
                // On trouve le champ 'statut' et l'ID de l'option 'Terminer'
                $statut_field = array_values(array_filter($taches_schema, fn($f) => $f['name'] === 'statut'))[0] ?? null;
                $terminer_option_id = null;
                if ($statut_field && isset($statut_field['select_options'])) {
                    $terminer_option = array_values(array_filter($statut_field['select_options'], fn($o) => $o['value'] === 'Terminer'))[0] ?? null;
                    if ($terminer_option) {
                        $terminer_option_id = $terminer_option['id'];
                    }
                }
                // --- FIN DE LA CORRECTION ---

                if ($opp_link_in_taches) {
                    $params = [
                        'user_field_names' => 'true',
                        'filter_type'      => 'AND', // On s'assure que les deux filtres s'appliquent
                        'filter__field_' . $opp_link_in_taches['id'] . '__link_row_has' => $opp_id
                    ];

                    // --- AJOUT DU FILTRE DE STATUT ---
                    if ($terminer_option_id) {
                        $params['filter__field_' . $statut_field['id'] . '__single_select_not_equal'] = $terminer_option_id;
                    }
                    // --- FIN DE L'AJOUT ---

                    $results['taches'] = eecie_crm_baserow_get_all_paginated_results("rows/table/$taches_table_id/", $params);
                }
            }
        }

        // 2. Récupérer les Appels liés (inchangé)
        $appels_table_id = get_option('gce_baserow_table_appels') ?: eecie_crm_guess_table_id('Appels');
        if ($appels_table_id) {
            $appels_schema = eecie_crm_baserow_get_fields($appels_table_id);
            if (!is_wp_error($appels_schema)) {
                $opp_link_in_appels = array_values(array_filter($appels_schema, fn($f) => $f['name'] === 'Opportunité'))[0] ?? null;
                if ($opp_link_in_appels) {
                    $params = ['user_field_names' => 'true', 'filter__field_' . $opp_link_in_appels['id'] . '__link_row_has' => $opp_id];
                    $results['appels'] = eecie_crm_baserow_get_all_paginated_results("rows/table/$appels_table_id/", $params);
                }
            }
        }

        // 3. Récupérer les Interactions liées (inchangé)
        $interactions_table_id = get_option('gce_baserow_table_interactions') ?: eecie_crm_guess_table_id('Interactions');
        if ($interactions_table_id) {
            $interactions_schema = eecie_crm_baserow_get_fields($interactions_table_id);
            if (!is_wp_error($interactions_schema)) {
                $opp_link_in_interactions = array_values(array_filter($interactions_schema, fn($f) => $f['name'] === 'opportunité'))[0] ?? null;
                if ($opp_link_in_interactions) {
                    $params = ['user_field_names' => 'true', 'filter__field_' . $opp_link_in_interactions['id'] . '__link_row_has' => $opp_id];
                    $results['interactions'] = eecie_crm_baserow_get_all_paginated_results("rows/table/$interactions_table_id/", $params);
                }
            }
        }

        // 4. --- AJOUTER TOUTE CETTE NOUVELLE SECTION ---
        // Récupérer les Devis et leurs Articles liés
        $devis_table_id = get_option('gce_baserow_table_devis') ?: eecie_crm_guess_table_id('Devis');
        if ($devis_table_id) {
            $devis_schema = eecie_crm_baserow_get_fields($devis_table_id);
            if (!is_wp_error($devis_schema)) {
                $opp_link_in_devis = array_values(array_filter($devis_schema, fn($f) => $f['name'] === 'Task_input'))[0] ?? null;
                if ($opp_link_in_devis) {
                    $params = ['user_field_names' => 'true', 'filter__field_' . $opp_link_in_devis['id'] . '__link_row_has' => $opp_id];
                    $devis_lies = eecie_crm_baserow_get_all_paginated_results("rows/table/$devis_table_id/", $params);
                    $results['devis'] = $devis_lies;

                    // Si on a trouvé des devis, on va chercher leurs articles
                    if (!empty($devis_lies)) {
                        $articles_table_id = get_option('gce_baserow_table_articles_devis') ?: eecie_crm_guess_table_id('Articles_devis');
                        if ($articles_table_id) {
                            $articles_schema = eecie_crm_baserow_get_fields($articles_table_id);
                            if (!is_wp_error($articles_schema)) {
                                $devis_link_in_articles = array_values(array_filter($articles_schema, fn($f) => $f['name'] === 'Devis'))[0] ?? null;
                                if ($devis_link_in_articles) {
                                    $all_articles = [];
                                    foreach ($devis_lies as $devis) {
                                        $params_articles = ['user_field_names' => 'true', 'filter__field_' . $devis_link_in_articles['id'] . '__link_row_has' => $devis['id']];
                                        $articles_pour_ce_devis = eecie_crm_baserow_get_all_paginated_results("rows/table/$articles_table_id/", $params_articles);
                                        $all_articles = array_merge($all_articles, $articles_pour_ce_devis);
                                    }
                                    $results['articles'] = $all_articles;
                                }
                            }
                        }
                    }
                }
            }
        }

        return rest_ensure_response($results);
    },
    'permission_callback' => 'eecie_crm_check_capabilities',
]);

register_rest_route('eecie-crm/v1', '/opportunites/(?P<id>\d+)/reset', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $request) {
        $opp_id = (int)$request['id'];

        // --- PARTIE 1 : PRÉPARATION (inchangée) ---
        $current_wp_user = wp_get_current_user();
        $baserow_user = gce_get_baserow_t1_user_by_email($current_wp_user->user_email);
        $current_baserow_user_id = $baserow_user ? $baserow_user['id'] : null;

        $log_table_id = get_option('gce_baserow_table_log_reset_opportunite') ?: eecie_crm_guess_table_id('Log_reset_opportunite');
        if (!$log_table_id) return new WP_Error('no_log_table', 'La table de logs n\'est pas configurée.', ['status' => 500]);

        $log_schema = eecie_crm_baserow_get_fields($log_table_id);
        if (is_wp_error($log_schema)) return new WP_Error('log_schema_error', 'Impossible de lire la structure de la table de logs.', ['status' => 500]);

        $log_field_map = [];
        foreach ($log_schema as $field) {
            $log_field_map[$field['name']] = 'field_' . $field['id'];
        }

        $required_log_fields = ['Date', 'Opportunite_liee', 'Element_efface', 'Details', 'Nom_element_efface', 'ID_element_efface', 'Utilisateur_action'];
        foreach ($required_log_fields as $field_name) {
            if (!isset($log_field_map[$field_name])) return new WP_Error('log_schema_incomplete', "Le champ '{$field_name}' est manquant dans la table de log.", ['status' => 500]);
        }

        $log_action = function ($item_type, $item_data) use ($log_table_id, $opp_id, $log_field_map, $current_baserow_user_id) {
            $item_name = $item_data['Nom'] ?? $item_data['Name'] ?? $item_data['titre'] ?? ($item_type . " #" . ($item_data['id'] ?? 'inconnu'));
            $log_payload = [
                $log_field_map['Date'] => gmdate('Y-m-d\TH:i:s\Z'),
                $log_field_map['Opportunite_liee'] => [$opp_id],
                $log_field_map['Element_efface'] => $item_type,
                $log_field_map['Details'] => json_encode($item_data, JSON_PRETTY_PRINT),
                $log_field_map['Nom_element_efface'] => (string)$item_name,
                $log_field_map['ID_element_efface'] => (int)$item_data['id'],
                $log_field_map['Utilisateur_action'] => $current_baserow_user_id ? [$current_baserow_user_id] : [],
            ];
            eecie_crm_baserow_post("rows/table/{$log_table_id}/", $log_payload);
        };

        // --- PARTIE 2 : LOGIQUE DE SUPPRESSION (MODIFIÉE) ---
        $opportunite = eecie_crm_get_row_by_slug_and_id('opportunites', $opp_id);
        if (is_wp_error($opportunite)) return $opportunite;

        // Traitement spécial pour les Devis et Articles (inchangé)
        if (!empty($opportunite['Devis']) && is_array($opportunite['Devis'])) {
            foreach ($opportunite['Devis'] as $devis_link) {
                $devis_details = eecie_crm_get_row_by_slug_and_id('devis', $devis_link['id']);
                if (!is_wp_error($devis_details)) {
                    if (!empty($devis_details['Articles_devis']) && is_array($devis_details['Articles_devis'])) {
                        foreach ($devis_details['Articles_devis'] as $article_link) {
                            $article_details = eecie_crm_get_row_by_slug_and_id('articles_devis', $article_link['id']);
                            eecie_crm_baserow_delete("rows/table/" . (get_option('gce_baserow_table_articles_devis') ?: eecie_crm_guess_table_id('Articles_devis')) . "/" . $article_link['id'] . "/");
                            $log_action('Article Devis', $article_details);
                        }
                    }
                    eecie_crm_baserow_delete("rows/table/" . (get_option('gce_baserow_table_devis') ?: eecie_crm_guess_table_id('Devis')) . "/" . $devis_link['id'] . "/");
                    $log_action('Devis', $devis_details);
                }
            }
        }

        // --- NOUVEAU BLOC : Traitement spécial pour les Appels et Interactions imbriqués ---
        if (!empty($opportunite['Appels']) && is_array($opportunite['Appels'])) {
            foreach ($opportunite['Appels'] as $appel_link) {
                // On récupère l'appel complet pour trouver ses interactions
                $appel_details = eecie_crm_get_row_by_slug_and_id('appels', $appel_link['id']);
                if (!is_wp_error($appel_details)) {
                    // 1. Supprimer les interactions liées à CET appel
                    if (!empty($appel_details['Interactions']) && is_array($appel_details['Interactions'])) {
                        foreach ($appel_details['Interactions'] as $interaction_link) {
                            $interaction_details = eecie_crm_get_row_by_slug_and_id('interactions', $interaction_link['id']);
                            eecie_crm_baserow_delete("rows/table/" . (get_option('gce_baserow_table_interactions') ?: eecie_crm_guess_table_id('Interactions')) . "/" . $interaction_link['id'] . "/");
                            $log_action('Interaction', $interaction_details);
                        }
                    }
                    // 2. Supprimer l'appel lui-même
                    eecie_crm_baserow_delete("rows/table/" . (get_option('gce_baserow_table_appels') ?: eecie_crm_guess_table_id('Appels')) . "/" . $appel_link['id'] . "/");
                    $log_action('Appel', $appel_details);
                }
            }
        }

        // --- Boucle générique simplifiée (ne contient plus que les Tâches) ---
        // Note : On pourrait même supprimer cette boucle et traiter les tâches directement, mais la garder est plus flexible pour le futur.
        $relations_to_delete = ['Taches' => 'taches'];
        foreach ($relations_to_delete as $baserow_field_name => $slug) {
            if (!empty($opportunite[$baserow_field_name]) && is_array($opportunite[$baserow_field_name])) {
                $table_id_to_delete_from = get_option('gce_baserow_table_' . $slug) ?: eecie_crm_guess_table_id(ucfirst($slug));
                if ($table_id_to_delete_from) {
                    foreach ($opportunite[$baserow_field_name] as $item_link) {
                        $item_details = eecie_crm_get_row_by_slug_and_id($slug, $item_link['id']);
                        eecie_crm_baserow_delete("rows/table/{$table_id_to_delete_from}/{$item_link['id']}/");
                        $log_action($baserow_field_name, $item_details);
                    }
                }
            }
        }

        // --- PARTIE 3 : MISE À JOUR DE L'OPPORTUNITÉ (inchangée) ---
        $opp_table_id = get_option('gce_baserow_table_opportunites') ?: eecie_crm_guess_table_id('Task_input');
        $opp_schema = eecie_crm_baserow_get_fields($opp_table_id);
        $status_field = array_values(array_filter($opp_schema, fn($f) => $f['name'] === 'Status'))[0] ?? null;
        $assigner_option = $status_field ? array_values(array_filter($status_field['select_options'], fn($o) => $o['value'] === 'Assigner'))[0] ?? null : null;
        $reset_count_field = array_values(array_filter($opp_schema, fn($f) => $f['name'] === 'Reset_count'))[0] ?? null;

        if (!$status_field || !$assigner_option || !$reset_count_field) return new WP_Error('schema_error', 'Impossible de trouver les champs Status ou Reset_count.', ['status' => 500]);


        // --- PARTIE 3 : MISE À JOUR DE L'OPPORTUNITÉ (CORRIGÉE) ---
        $opp_table_id = get_option('gce_baserow_table_opportunites') ?: eecie_crm_guess_table_id('Task_input');
        $opp_schema = eecie_crm_baserow_get_fields($opp_table_id);

        // Helper pour trouver un champ par son nom
        $find_field_id = function ($name) use ($opp_schema) {
            foreach ($opp_schema as $field) {
                if ($field['name'] === $name) {
                    return $field['id'];
                }
            }
            return null;
        };

        $status_field_id = $find_field_id('Status');
        $reset_count_field_id = $find_field_id('Reset_count');
        $progression_field_id = $find_field_id('Progression');
        $devis_sent_field_id = $find_field_id('Devis_sent_client');
        $devis_sent_id_field_id = $find_field_id('DevisSentInteractionId');
        $dernier_status_field_id = $find_field_id('Dernier_status_ok');

        $assigner_option_id = null;
        $status_field_data = array_values(array_filter($opp_schema, fn($f) => $f['name'] === 'Status'))[0] ?? null;
        if ($status_field_data) {
            $assigner_option = array_values(array_filter($status_field_data['select_options'], fn($o) => $o['value'] === 'Assigner'))[0] ?? null;
            if ($assigner_option) {
                $assigner_option_id = $assigner_option['id'];
            }
        }

        if (!$status_field_id || !$assigner_option_id || !$reset_count_field_id) {
            return new WP_Error('schema_error', 'Impossible de trouver les champs requis (Status, Reset_count) pour la mise à jour finale.', ['status' => 500]);
        }

        $current_reset_count = (int)($opportunite['Reset_count'] ?? 0);
        $update_payload = [
            'field_' . $status_field_id => $assigner_option_id,
            'field_' . $reset_count_field_id => $current_reset_count + 1,

            // --- CHAMPS AJOUTÉS POUR LA RÉINITIALISATION COMPLÈTE ---
            'field_' . $progression_field_id => 0,
            'field_' . $devis_sent_field_id => false,
            'field_' . $devis_sent_id_field_id => null,
            'field_' . $dernier_status_field_id => null,
        ];

        // On s'assure de ne pas envoyer de clés pour des champs qui n'ont pas été trouvés
        $update_payload = array_filter($update_payload, function ($key) {
            // La clé est 'field_XXXX'. On extrait XXXX.
            $field_id = substr($key, 6);
            return !empty($field_id);
        }, ARRAY_FILTER_USE_KEY);
        $baseUrl = rtrim(get_option('gce_baserow_url'), '/');
        $token = get_option('gce_baserow_api_key');
        $url = "$baseUrl/api/database/rows/table/$opp_table_id/$opp_id/";

        $response = wp_remote_request($url, [
            'method' => 'PATCH',
            'headers' => ['Authorization' => 'Token ' . $token, 'Content-Type'  => 'application/json'],
            'body' => json_encode($update_payload)
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return new WP_Error('update_failed', 'La mise à jour finale de l\'opportunité a échoué.', ['status' => 500]);

        return rest_ensure_response(['success' => true, 'message' => 'Opportunité réinitialisée avec succès.']);
    },
    'permission_callback' => 'eecie_crm_check_capabilities',
]);