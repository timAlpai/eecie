<?php
defined('ABSPATH') || exit;
require_once GCE_PLUGIN_DIR . 'includes/api/baserow-proxy.php';

//config des tables de baserow
$known_usages = [
    'Contacts'      => 'contacts',
    'Taches'        => 'taches',
    'Task_input'    => 'opportunites',
    'Devis'         => 'devis',
    'Interactions'  => 'interactions',
    'Appels'        => 'appels',
    'Fournisseur'   => 'fournisseurs',
    'Zone_geo'      => 'zone_geo',
    'T1_user'       => 'utilisateurs',
    'Articles_devis' => 'articles_devis',
    'Rappels'       => 'rappels',
    'Input_mail_history' => 'input_mail_history',
    'Log_reset_opportunite' => 'log_reset_opportunite',
    'Devis_Signatures' => 'devis_signatures',
    'Rendez_vous_fournisseur' => 'rendezvous_fournisseur',
];

$api_key = get_option('gce_baserow_api_key', '');
$baserow_url = get_option('gce_baserow_url', '');
$workspace_id = get_option('gce_baserow_workspace_id', ''); // Ajout
$database_id = get_option('gce_baserow_database_id', '');
$tps_rate = get_option('gce_tps_rate', '0.05');
$tvq_rate = get_option('gce_tvq_rate', '0.09975');
$service_email = get_option('gce_baserow_service_email', '');

if (isset($_POST['submit'])) {
    check_admin_referer('gce_config_save', 'gce_config_nonce');

    update_option('gce_baserow_url', esc_url_raw($_POST['gce_baserow_url']));
    update_option('gce_baserow_workspace_id', sanitize_text_field($_POST['gce_baserow_workspace_id'])); // Ajout de la sauvegarde
    update_option('gce_baserow_database_id', sanitize_text_field($_POST['gce_baserow_database_id']));
    update_option('gce_baserow_api_key', sanitize_text_field($_POST['gce_baserow_api_key']));

    if (isset($_POST['gce_tps_rate']) && is_numeric($_POST['gce_tps_rate'])) {
        update_option('gce_tps_rate', $_POST['gce_tps_rate']);
    }
    if (isset($_POST['gce_tvq_rate']) && is_numeric($_POST['gce_tvq_rate'])) {
        update_option('gce_tvq_rate', $_POST['gce_tvq_rate']);
    }
    update_option('gce_baserow_service_email', sanitize_email($_POST['gce_baserow_service_email']));

    // Gère le mot de passe : on ne le sauvegarde que s'il est renseigné
    if (!empty($_POST['gce_baserow_service_password'])) {
        // On chiffre le mot de passe avant de le sauvegarder
        $password = $_POST['gce_baserow_service_password'];
        $encrypted_password = gce_encrypt_password($password);
        update_option('gce_baserow_service_password_encrypted', $encrypted_password);
    }

    foreach ($known_usages as $name => $slug) {
        $key = 'gce_baserow_table_' . $slug;
        if (isset($_POST[$key])) {
            update_option($key, sanitize_text_field($_POST[$key]));
        }
    }

    echo '<div class="notice notice-success"><p>Configuration enregistrée.</p></div>';

    // Re-récupérer les valeurs après sauvegarde
    $api_key = get_option('gce_baserow_api_key', '');
    $baserow_url = get_option('gce_baserow_url', '');
    $workspace_id = get_option('gce_baserow_workspace_id', ''); // Ajout
    $database_id = get_option('gce_baserow_database_id', '');
    $tps_rate = get_option('gce_tps_rate', '0.05');
    $tvq_rate = get_option('gce_tvq_rate', '0.09975');
}

$tables = eecie_crm_baserow_get_all_tables();
if (is_wp_error($tables)) {
    $error_message = $tables->get_error_message();
    echo '<div class="notice notice-error"><p>Erreur lors de la récupération des tables : ' . esc_html($error_message) . '</p></div>';
    $tables = null;
}

?>

<div class="wrap">
    <h1><?php _e('Configuration Baserow', 'gestion-crm-eecie'); ?></h1>
    <form method="post">
        <?php wp_nonce_field('gce_config_save', 'gce_config_nonce'); ?>

        <table class="form-table">
            <tr>
                <th scope="row"><label for="gce_baserow_url">URL de l’instance Baserow</label></th>
                <td>
                    <input type="url" name="gce_baserow_url" id="gce_baserow_url" class="regular-text" value="<?php echo esc_attr($baserow_url); ?>" />
                    <p class="description">Ex : https://baserow.eecie.ca (sans slash à la fin)</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="gce_baserow_api_key">Clé API Baserow</label></th>
                <td>
                    <input type="text" name="gce_baserow_api_key" id="gce_baserow_api_key" class="regular-text" value="<?php echo esc_attr($api_key); ?>" />
                    <p class="description">Clé d’API personnelle liée à ton compte Baserow.</p>
                </td>
            </tr>
            <!-- CHAMP WORKSPACE AJOUTÉ -->
            <tr>
                <th scope="row"><label for="gce_baserow_workspace_id">ID du Workspace Principal</label></th>
                <td>
                    <input type="number" name="gce_baserow_workspace_id" id="gce_baserow_workspace_id" class="regular-text" value="<?php echo esc_attr($workspace_id); ?>" />
                    <p class="description">L'ID numérique du Workspace à utiliser pour les sauvegardes et autres opérations.</p>
                </td>
            </tr>
            <!-- FIN CHAMP WORKSPACE -->
            <tr>
                <th scope="row"><label for="gce_baserow_database_id">ID de la Base de Données</label></th>
                <td>
                    <input type="number" name="gce_baserow_database_id" id="gce_baserow_database_id" class="regular-text" value="<?php echo esc_attr($database_id); ?>" />
                    <p class="description">L'ID numérique de la base de données Baserow à utiliser. Cela permet de cibler une structure précise.</p>
                </td>
            </tr>
        </table>

        <h2><?php _e('Configuration des Taxes', 'gestion-crm-eecie'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="gce_tps_rate">Taux TPS</label></th>
                <td>
                    <input type="text" name="gce_tps_rate" id="gce_tps_rate" class="small-text" value="<?php echo esc_attr($tps_rate); ?>" />
                    <p class="description">Exemple : <code>0.05</code> pour 5%.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="gce_tvq_rate">Taux TVQ</label></th>
                <td>
                    <input type="text" name="gce_tvq_rate" id="gce_tvq_rate" class="small-text" value="<?php echo esc_attr($tvq_rate); ?>" />
                    <p class="description">Exemple : <code>0.09975</code> pour 9.975%.</p>
                </td>
            </tr>
        </table>

        <h2>Association automatique des tables CRM détectées</h2>
        <table class="form-table">
            <?php foreach ($known_usages as $expected_name => $slug):
                $auto_id = eecie_crm_guess_table_id($expected_name);
                $manual_key = 'gce_baserow_table_' . $slug;
                $manual_value = get_option($manual_key);
            ?>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr($manual_key); ?>">Table pour <?php echo esc_html($expected_name); ?></label></th>
                    <td>
                        <?php if ($auto_id): ?>
                            <p>Détectée automatiquement : <code><?php echo esc_html($auto_id); ?></code> (<?php echo esc_html($expected_name); ?>)</p>
                        <?php else: ?>
                            <p><em>Pas de table nommée "<?php echo esc_html($expected_name); ?>" détectée</em></p>
                        <?php endif; ?>
                        <input type="text" name="<?php echo esc_attr($manual_key); ?>"
                            id="<?php echo esc_attr($manual_key); ?>"
                            value="<?php echo esc_attr($manual_value); ?>"
                            placeholder="<?php echo esc_attr($auto_id); ?>" />
                        <p class="description">Laisser vide pour utiliser l’ID détecté automatiquement.</p>
                    </td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <th scope="row"><label for="gce_baserow_service_email">Email du compte de service Baserow</label></th>
                <td>
                    <input type="email" name="gce_baserow_service_email" id="gce_baserow_service_email" class="regular-text" value="<?php echo esc_attr(get_option('gce_baserow_service_email', '')); ?>" />
                    <p class="description">L'email d'un utilisateur Baserow ayant les droits d'admin sur le workspace.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="gce_baserow_service_password">Mot de passe du compte de service</label></th>
                <td>
                    <input type="password" name="gce_baserow_service_password" id="gce_baserow_service_password" class="regular-text" value="" />
                    <p class="description">Laissez vide pour ne pas changer le mot de passe. Il sera chiffré dans la base de données.</p>
                </td>
            </tr>



            IGNORE_WHEN_COPYING_START

        </table>

        <?php submit_button('Enregistrer'); ?>
    </form>


    <h2>Explorer la structure Baserow</h2>
    <p><button id="gce-explorer-structure" class="button">🔍 Voir la structure</button></p>
    <div id="gce-baserow-structure-output" style="white-space: pre-wrap; background: #f8f8f8; padding: 10px; border: 1px solid #ccc; max-height: 400px; overflow: auto;"></div>

</div>