<?php
/**
 * Plugin Name: Gestion CRM EECIE
 * Plugin URI: https://alpai.eu/plugins/gestion-crm-eecie
 * Description: Plugin CRM personnalisé pour eecie.ca avec intégration directe à Baserow.
 * Version: 0.3.0
 * Author: Timothée de Almeida
 * Author URI: https://alpai.eu
 * License: GPL2+
 * Text Domain: gestion-crm-eecie
 */

// Fichier : gestion-crm-eecie.php


defined('ABSPATH') || exit;

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Charger le fichier de secrets s'il existe.
$secrets_file = __DIR__ . '/gce-secrets.php';
if (file_exists($secrets_file)) {
    require_once $secrets_file;
}

// Constants
define('GCE_VERSION', '0.3.0');
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
        'Input_mail_history' => "input_mail_history",
        'Log_reset_opportunite' => 'log_reset_opportunite',
        'Devis_Signatures' => 'devis_signatures',
        'Rendez_vous_fournisseur' => 'rendezvous_fournisseur',
        'Rapports_Livraison' => 'rapports_livraison',
        'Articles_Livraison' => 'articles_livraison',
        'Signatures_Livraison' => 'signatures_livraison',
        
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

require_once GCE_PLUGIN_DIR . 'includes/shared/security.php'; 
// Admin zone
require_once GCE_PLUGIN_DIR . 'includes/admin/menu.php';

// Front/public zone
require_once GCE_PLUGIN_DIR . 'includes/public/hooks.php';
require_once GCE_PLUGIN_DIR . 'includes/api/baserow-proxy.php';

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

/**
 * Crée la table de base de données personnalisée pour les tâches (jobs).
 * Cette fonction est appelée à l'activation du plugin.
 */
function gce_create_jobs_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'gce_jobs';
    $charset_collate = $wpdb->get_charset_collate();

    // On a besoin de cette fonction pour dbDelta
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        job_type VARCHAR(10) NOT NULL, -- 'EXPORT' or 'IMPORT'
        baserow_job_id INT(11) DEFAULT NULL,
        workspace_id INT(11) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
        created_by_user_id BIGINT(20) UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        finished_at DATETIME DEFAULT NULL,
        progress_percentage TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
        error_message TEXT DEFAULT NULL,
        bundle_file_path VARCHAR(255) DEFAULT NULL,
        metadata_file_path VARCHAR(255) DEFAULT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    dbDelta($sql);
}

// Enregistre la fonction pour qu'elle s'exécute à l'activation du plugin
register_activation_hook(__FILE__, 'gce_create_jobs_table');

function gce_display_schedule_form() {
    // 1. Récupérer les paramètres de l'URL
    $rdv_id = isset($_GET['rdv_id']) ? intval($_GET['rdv_id']) : 0;
    $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

    // 2. Validation initiale côté serveur
    if (!$rdv_id || empty($token)) {
        return "<h2>Planifier l'intervention</h2><p style='color:red;'>Ce lien est invalide (paramètres manquants).</p>";
    }

    // 3. Appel à Baserow pour vérifier le token (logique serveur)
    $rdv_table_id = eecie_crm_guess_table_id('Rendez_vous_fournisseur');
    if (!$rdv_table_id) {
        return "<h2>Planifier l'intervention</h2><p style='color:red;'>Erreur de configuration (table RDV introuvable).</p>";
    }

    $rdv_data = eecie_crm_baserow_get("rows/table/{$rdv_table_id}/{$rdv_id}/?user_field_names=true");
    
    // 4. Conditions de validation
    $is_valid = true;
    if (is_wp_error($rdv_data)) {
        $is_valid = false;
    } elseif ($rdv_data['Validation_Token'] !== $token) {
        $is_valid = false;
    } elseif (!empty($rdv_data['Date_Heure_RDV'])) {
        // Le lien a déjà été utilisé
        return "<h2>Planifier l'intervention</h2><p style='color:orange;'>Ce rendez-vous a déjà été planifié pour le " . esc_html(date_i18n(get_option('date_format') . ' H:i', strtotime($rdv_data['Date_Heure_RDV']))) . ".</p>";
    }

    // 5. Affichage conditionnel
    ob_start();
    ?>
    <h2>Planifier l'intervention</h2>
    <?php if ($is_valid): ?>
        <div id="schedule-form-container" data-rdv-id="<?php echo $rdv_id; ?>" data-token="<?php echo $token; ?>">
            <p><strong>Dossier :</strong> <?php echo esc_html($rdv_data['Opportunite_liee'][0]['value']); ?></p>
            <p>Veuillez entrer la date et l'heure convenues avec le client :</p>
            <form id="rdv-form">
                <input type="datetime-local" id="date_heure" required style="padding: 8px; font-size: 1.1em;">
                <button type="submit" style="padding: 10px 15px; background-color: #28a745; color: white; border: none; cursor: pointer;">Soumettre</button>
            </form>
            <div id="form-status" style="margin-top: 15px;"></div>
        </div>
        
        <script>
        document.addEventListener('DOMContentLoaded', () => {
            const container = document.getElementById('schedule-form-container');
            const form = document.getElementById('rdv-form');
            const statusDiv = document.getElementById('form-status');
            const rdvId = container.dataset.rdvId;
            const token = container.dataset.token;

            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                form.querySelector('button').disabled = true;
                statusDiv.textContent = 'Envoi en cours...';

                const dateHeure = document.getElementById('date_heure').value;

                try {
                    const submitRes = await fetch(`<?php echo rest_url('eecie-crm/v1/rdv/submit-schedule'); ?>`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' }, // Pas besoin de Nonce ici car la route est publique
                        body: JSON.stringify({ id: rdvId, token: token, date_heure: dateHeure })
                    });
                    
                    if (!submitRes.ok) {
                        const errorData = await submitRes.json();
                        throw new Error(errorData.message || 'Erreur lors de la soumission.');
                    }

                    container.innerHTML = '<p style="color:green; font-weight:bold;">Merci ! La date a été enregistrée avec succès.</p>';
                } catch(error) {
                    statusDiv.innerHTML = `<p style="color:red;">${error.message}</p>`;
                    form.querySelector('button').disabled = false;
                }
            });
        });
        </script>
    <?php else: ?>
        <p style='color:red;'>Ce lien est invalide ou a déjà été utilisé.</p>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}
// N'oubliez pas d'enregistrer le shortcode si ce n'est pas déjà fait
add_shortcode('gce_schedule_form', 'gce_display_schedule_form');