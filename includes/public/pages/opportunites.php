
<h2>💼 Mes Opportunités</h2>

<p>Chaque carte ci-dessous représente une opportunité qui vous est assignée. Cliquez sur une carte pour voir les détails et gérer les tâches associées.</p>
<?php
// --- DÉBUT DE L'AJOUT ---

// On s'assure que les fonctions du proxy sont disponibles
require_once GCE_PLUGIN_DIR . 'includes/api/baserow-proxy.php';

$status_options = [];
// On essaie de récupérer les options de statut pour générer les filtres
try {
    $table_id = get_option('gce_baserow_table_opportunites') ?: eecie_crm_guess_table_id('Task_input');
    if ($table_id) {
        $fields = eecie_crm_baserow_get_fields($table_id);
        if (!is_wp_error($fields)) {
            $status_field = array_values(array_filter($fields, function($field) {
                return $field['name'] === 'Status';
            }))[0] ?? null;

            if ($status_field && isset($status_field['select_options'])) {
                $status_options = $status_field['select_options'];
            }
        }
    }
} catch (Exception $e) {
    // Ne rien faire en cas d'erreur, les filtres ne s'afficheront pas.
}

// Si on a bien récupéré les options, on affiche les cases à cocher
 if (!empty($status_options)) : ?>
    <div id="gce-status-filters" class="gce-filters-container">
        <strong>Filtrer par statut :</strong>
        
        <?php foreach ($status_options as $option) : ?>
            <label class="gce-filter-label">
                <input type="checkbox" name="status_filter" value="<?php echo esc_attr($option['value']); ?>">
                <span class="gce-badge gce-color-<?php echo esc_attr($option['color']); ?>">
                    <?php echo esc_html($option['value']); ?>
                </span>
            </label>
        <?php endforeach; ?>

        <!-- DÉBUT DE L'AJOUT -->
        <div class="gce-filter-actions">
            <a id="gce-filter-select-all">Tout sélectionner</a>
            <a id="gce-filter-clear-all">Effacer</a>
        </div>
        <!-- FIN DE L'AJOUT -->

    </div>
<?php endif; ?>  
<div id="gce-opportunites-table" class="gce-opportunites-container" style="margin-top: 1em;">
    <!-- Les cartes des opportunités seront injectées ici par JavaScript -->
</div>
