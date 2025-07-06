<?php
defined('ABSPATH') || exit;

add_action('admin_menu', 'gce_add_admin_menu');
function gce_enqueue_admin_assets($hook) {
    if (strpos($hook, 'gce-configuration') !== false) {
        wp_enqueue_script('gce-config-js', plugin_dir_url(__FILE__) . 'assets/js/configuration.js',  ['eecie-crm-rest'], null, true);
        wp_enqueue_style('gce-admin-css', plugin_dir_url(__FILE__) . 'assets/css/admin.css');
    }
}
add_action('admin_enqueue_scripts', 'gce_enqueue_admin_assets');

function gce_add_admin_menu() {
	add_menu_page(
        __('Gestion CRM EECIE', 'gestion-crm-eecie'),
        __('Gestion CRM EECIE', 'gestion-crm-eecie'),
        'manage_options',
        'gce-dashboard',
        'gce_render_dashboard_page',
        'dashicons-chart-bar',
        26
    );
    add_submenu_page(
    'gce-dashboard',
    __('Configuration', 'gestion-crm-eecie'),
    __('Configuration', 'gestion-crm-eecie'),
    'manage_options',
    'gce-configuration',
    'gce_render_config_page'
	);


    add_submenu_page(
        'gce-dashboard',
        __('Gestion Utilisateurs', 'gestion-crm-eecie'),
        __('Gestion Utilisateurs', 'gestion-crm-eecie'),
        'manage_options',
        'gce-gestion-utilisateurs',
        'gce_render_user_admin_page'
    );

}

function gce_render_dashboard_page() {
    include_once plugin_dir_path(__FILE__) . 'pages/dashboard.php';
}

function gce_render_user_admin_page() {
    include_once plugin_dir_path(__FILE__) . 'pages/utilisateurs.php';
}
function gce_render_config_page() {
    include_once plugin_dir_path(__FILE__) . 'pages/configuration.php';
}
