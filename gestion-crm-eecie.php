<?php
/**
 * Plugin Name: Gestion CRM EECIE
 * Plugin URI: https://alpai.eu/plugins/gestion-crm-eecie
 * Description: Plugin CRM personnalisé pour eecie.ca avec intégration directe à Baserow.
 * Version: 0.1.0
 * Author: Timothée de Almeida
 * Author URI: https://alpai.eu
 * License: GPL2+
 * Text Domain: gestion-crm-eecie
 */

// Fichier : gestion-crm-eecie.php

// ... (au début du fichier)

defined('ABSPATH') || exit;

// Constants
define('GCE_VERSION', '0.1.0');
define('GCE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GCE_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Crée une map [table_id => slug] pour l'utiliser en JS.
 * @return array
 */
function gce_get_table_id_to_slug_map() {
    // La même liste que dans la page de configuration
    $known_usages = [
        'Contacts'      => 'contacts',
        'Taches'        => 'taches',
        'Task_input'    => 'opportunites',
        'Devis'         => 'devis',
        'Interactions'  => 'interactions',
        'Appels'        => 'appels',
        'Fournisseur'   => 'fournisseurs',
        'T1_user'       => 'utilisateurs',
        'Articles_devis' => 'articles_devis', 
        'Zone_geo'      => 'zone_geo',
        'Rappels'       => 'rappels',
        
    ];

    $map = [];
    foreach ($known_usages as $baserow_name => $slug) {
        // Vérifie d'abord si une valeur manuelle est enregistrée
        $table_id = get_option('gce_baserow_table_' . $slug);
        
        // Sinon, essaie de deviner l'ID à partir du nom
        if (!$table_id) {
            // Assurez-vous que baserow-proxy est chargé pour cette fonction
            if (!function_exists('eecie_crm_guess_table_id')) {
                require_once GCE_PLUGIN_DIR . 'includes/api/baserow-proxy.php';
            }
            $table_id = eecie_crm_guess_table_id($baserow_name);
        }

        if ($table_id) {
            $map[$table_id] = $slug;
        }
    }
    return $map;
}


// Admin zone
require_once GCE_PLUGIN_DIR . 'includes/admin/menu.php';

// Front/public zone
require_once GCE_PLUGIN_DIR . 'includes/public/hooks.php';

// API REST - routes sécurisées pour accès Baserow
require_once GCE_PLUGIN_DIR . 'includes/api/rest-routes.php';

function eecie_crm_register_global_rest_nonce() {
    $script_url = plugins_url('/includes/api/js/eecie-rest.js', __FILE__);
    wp_enqueue_script('eecie-crm-rest', $script_url, [], GCE_VERSION, true);

    if (function_exists('rest_url') && rest_url()) {
        wp_localize_script('eecie-crm-rest', 'EECIE_CRM', [
            'rest_url' => esc_url_raw(rest_url()),
            'nonce'    => wp_create_nonce('wp_rest'),
            'tableIdMap' => gce_get_table_id_to_slug_map(), // <-- AJOUT IMPORTANT
        ]);
    }
}

add_action('wp_enqueue_scripts', 'eecie_crm_register_global_rest_nonce');
add_action('admin_enqueue_scripts', 'eecie_crm_register_global_rest_nonce');