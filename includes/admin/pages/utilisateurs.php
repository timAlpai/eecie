

<div class="wrap">
    <h1>Gestion des utilisateurs Baserow</h1>
    <div id="gce-users-admin-table"></div>
</div>
<?php
// Enqueue Tabulator CSS et JS
wp_enqueue_style(
    'tabulator-css',
    'https://unpkg.com/tabulator-tables@6.3/dist/css/tabulator.min.css',
    [],
    '6.3'
);

wp_enqueue_script(
    'tabulator-js',
    'https://unpkg.com/tabulator-tables@6.3/dist/js/tabulator.min.js',
    [],
    '6.3',
    true
);



wp_enqueue_script(
    'luxon-js',
    'https://cdn.jsdelivr.net/npm/luxon@3.4.3/build/global/luxon.min.js',
    [],
    '3.4.3',
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


wp_enqueue_script(
    'gce-tabulator-editors',
    plugin_dir_url(__FILE__) . '../../shared/js/tabulator-editors.js',
    [],
    GCE_VERSION,
    true
);

?>
