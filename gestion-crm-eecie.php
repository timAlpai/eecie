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

defined('ABSPATH') || exit;

// Constants
define('GCE_VERSION', '0.1.0');
define('GCE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GCE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Admin zone
require_once GCE_PLUGIN_DIR . 'includes/admin/menu.php';

// Front/public zone
require_once GCE_PLUGIN_DIR . 'includes/public/hooks.php';


// API REST - routes sécurisées pour accès Baserow
require_once GCE_PLUGIN_DIR . 'includes/api/rest-routes.php';

function eecie_crm_register_global_rest_nonce() {
    $script_url = plugins_url('/includes/api/js/eecie-rest.js', __FILE__);

    wp_enqueue_script('eecie-crm-rest', $script_url, [], GCE_VERSION, true);
    error_log('TIM NONCE généré pour user ID : ' . get_current_user_id());
    wp_localize_script('eecie-crm-rest', 'EECIE_CRM', [
        'rest_url' => rest_url(),
        'nonce'    => wp_create_nonce('wp_rest'),
    ]);
}
add_action('wp_enqueue_scripts', 'eecie_crm_register_global_rest_nonce');
add_action('admin_enqueue_scripts', 'eecie_crm_register_global_rest_nonce');
