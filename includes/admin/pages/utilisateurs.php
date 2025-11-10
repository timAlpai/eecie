

<<div class="wrap">
    <h1>Gestion des utilisateurs Baserow</h1>
    <button id="gce-add-user-btn" class="button button-primary">➕ Nouvel utilisateur</button>

<div id="gce-add-user-modal" style="display:none; margin-top: 20px;">
    <h3>Ajouter un utilisateur</h3>
    <label>Nom<br><input type="text" id="gce-new-user-nom" style="width: 100%;"></label><br><br>
    <label>Email<br><input type="email" id="gce-new-user-email" style="width: 100%;"></label><br><br>
    
    <!-- Ajout du champ de sélection de rôle -->
    <label>Rôle<br>
        <select id="gce-new-user-role" style="width: 100%;">
            <option value="3041">Chargé de projet</option>
            <option value="3043">Fournisseur</option>
            <option value="3042">Vente</option>
        </select>
    </label><br><br>

    <label>Actif<br>
        <select id="gce-new-user-actif">
            <option value="true">Oui</option>
            <option value="false">Non</option>
        </select>
    </label><br><br>
    <button id="gce-submit-user" class="button button-primary">Créer</button>
    <button id="gce-cancel-user" class="button">Annuler</button>
</div>

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

wp_enqueue_script(
    'gce-tabulator-editors',
    plugin_dir_url(__FILE__) . '../../shared/js/tabulator-editors.js',
    ['tabulator-js'],
    GCE_VERSION,
    true
);

wp_enqueue_script(
    'gce-tabulator-columns',
    plugin_dir_url(__FILE__) . '../../shared/js/tabulator-columns.js',
    ['tabulator-js', 'gce-tabulator-editors'],
    GCE_VERSION,
    true
);

wp_enqueue_script(
    'gce-utilisateurs-js',
    plugin_dir_url(__FILE__) . '../assets/js/utilisateurs.js',
    ['tabulator-js', 'gce-tabulator-editors', 'gce-tabulator-columns'],
    GCE_VERSION,
    true
);


?>
