

<div class="wrap">
    <h1>Gestion des utilisateurs Baserow</h1>
    <div id="gce-users-admin-table">
        <div id="tabulator-users" style="margin-top: 20px;"></div>
    </div>
</div>
<?php
// Enqueue Tabulator CSS et JS
wp_enqueue_style(
    'tabulator-css',
    'https://unpkg.com/tabulator-tables@5.5.2/dist/css/tabulator.min.css',
    [],
    '5.5.2'
);

wp_enqueue_script(
    'tabulator-js',
    'https://unpkg.com/tabulator-tables@5.5.2/dist/js/tabulator.min.js',
    [],
    '5.5.2',
    true
);

// Script de ton plugin (utilisateurs.js)
wp_enqueue_script(
    'gce-utilisateurs-js',
    plugin_dir_url(__FILE__) . '../assets/js/utilisateurs.js',
    ['tabulator-js'], // dépendance
    GCE_VERSION,
    true
);


wp_enqueue_script(
    'gce-tabulator-columns',
    plugin_dir_url(__FILE__) . '../../shared/js/tabulator-columns.js',
    [],
    GCE_VERSION,
    true
);
?>
