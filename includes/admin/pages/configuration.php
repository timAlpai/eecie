<?php
defined('ABSPATH') || exit;

$api_key = get_option('gce_baserow_api_key', '');
$baserow_url = get_option('gce_baserow_url', '');

if (isset($_POST['gce_config_submit'])) {
    check_admin_referer('gce_config_save', 'gce_config_nonce');

    update_option('gce_baserow_url', esc_url_raw($_POST['gce_baserow_url']));
    update_option('gce_baserow_api_key', sanitize_text_field($_POST['gce_baserow_api_key']));

    echo '<div class="notice notice-success"><p>Configuration enregistrée.</p></div>';
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

        <?php submit_button('Enregistrer'); ?>
    </form>
</div>
