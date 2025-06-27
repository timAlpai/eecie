<h2>🧾 Articles de Devis</h2>
<div id="gce-articles-devis-table" style="margin-top:1em;">Chargement…</div>

<?php
wp_enqueue_style('tabulator-css', 'https://unpkg.com/tabulator-tables@6.3/dist/css/tabulator.min.css', [], '6.3');
wp_enqueue_script('tabulator-js', 'https://unpkg.com/tabulator-tables@6.3/dist/js/tabulator.min.js', [], '6.3', true);
wp_enqueue_script('luxon-js', 'https://cdn.jsdelivr.net/npm/luxon@3.4.3/build/global/luxon.min.js', [], '3.4.3', true);

wp_enqueue_script('gce-tabulator-editors', plugin_dir_url(__FILE__) . '../../shared/js/tabulator-editors.js', ['tabulator-js'], GCE_VERSION, true);
wp_enqueue_script('gce-tabulator-columns', plugin_dir_url(__FILE__) . '../../shared/js/tabulator-columns.js', ['tabulator-js', 'gce-tabulator-editors'], GCE_VERSION, true);

wp_enqueue_script('gce-articles-devis-js', plugin_dir_url(__FILE__) . '../assets/js/articles_devis.js', ['eecie-crm-rest', 'tabulator-js', 'gce-tabulator-editors', 'gce-tabulator-columns'], GCE_VERSION, true);

$current_user = wp_get_current_user();
wp_localize_script('gce-articles-devis-js', 'GCE_CURRENT_USER', [
    'email' => $current_user->user_email,
]);
?>
