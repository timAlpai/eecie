<?php
defined('ABSPATH') || exit;
require_once GCE_PLUGIN_DIR. 'includes/api/baserow-proxy.php';

//config des tables de baserow
$known_usages = [
    'Contacts'      => 'contacts',
    'Taches'        => 'taches',
    'Task_input'    => 'opportunites', 
    'Devis'         => 'devis',
    'Interactions'  => 'interactions',
    'Appels'        => 'appels',
    'Fournisseur'   => 'fournisseurs',
    'T1_user'       => 'utilisateurs',
];


$api_key = get_option('gce_baserow_api_key', '');
$baserow_url = get_option('gce_baserow_url', '');

if (isset($_POST['gce_config_submit'])) {
    check_admin_referer('gce_config_save', 'gce_config_nonce');

    update_option('gce_baserow_url', esc_url_raw($_POST['gce_baserow_url']));
    update_option('gce_baserow_api_key', sanitize_text_field($_POST['gce_baserow_api_key']));
    foreach ($known_usages as $slug) {
    $key = 'gce_baserow_table_' . $slug;
    if (isset($_POST[$key])) {
        update_option($key, sanitize_text_field($_POST[$key]));
    }
}

    echo '<div class="notice notice-success"><p>Configuration enregistrée.</p></div>';
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
        </table>
        <h2>Tables détectées dans la base Baserow</h2>
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
</table>



        <?php submit_button('Enregistrer'); ?>
    </form>


<h2>Explorer la structure Baserow</h2>
<p><button id="gce-explorer-structure" class="button">🔍 Voir la structure</button></p>
<div id="gce-baserow-structure-output" style="white-space: pre-wrap; background: #f8f8f8; padding: 10px; border: 1px solid #ccc; max-height: 400px; overflow: auto;"></div>

</div>

