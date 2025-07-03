<?php
defined('ABSPATH') || exit;
?>

<h2>🚚 Gestion des Fournisseurs</h2>
<p>
    Cette interface permet de visualiser, modifier et gérer les fournisseurs, leurs contacts associés et leurs zones desservies.
</p>
<div style="margin-top: 20px;">
    <button id="gce-add-fournisseur-btn" class="button button-primary">➕ Ajouter un Fournisseur</button>
</div>

<div id="gce-fournisseurs-table" class="gce-tabulator" style="margin-top: 1em;">
    Chargement des fournisseurs...
</div>