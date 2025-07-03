// Fichier : includes/public/assets/js/fournisseurs.js
// VERSION SIMPLIFIÉE - SANS TABLES IMBRIQUÉES

let fournisseursTable = null;

function fetchDataAndBuildFournisseursTable() {
    const container = document.getElementById('gce-fournisseurs-table');
    if (!container) return;

    if (!fournisseursTable) {
        container.innerHTML = 'Chargement des données...';
    }

    Promise.all([
        // Les données des tables liées sont toujours nécessaires pour les popups d'édition.
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/fournisseurs', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/contacts', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/zone_geo', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/fournisseurs/schema', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/contacts/schema', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/zone_geo/schema', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json())
    ]).then(([fournisseursData, contactsData, zonesData, f_schema, c_schema, z_schema]) => {
        
        window.gceSchemas = {
            ...window.gceSchemas,
            fournisseurs: f_schema,
            contacts: c_schema,
            zone_geo: z_schema
        };
        
        window.gceDataCache = {
            fournisseurs: fournisseursData.results || [],
            contacts: contactsData.results || [],
            zone_geo: zonesData.results || []
        };

        const fournisseurs = fournisseursData.results || [];
        
        // --- SUPPRESSION DE LA LOGIQUE DE GROUPEMENT ---
        // La logique qui créait `fournisseursAvecChildren` a été retirée.
        // Nous allons maintenant passer directement la liste des fournisseurs à Tabulator.

        if (fournisseursTable) {
            console.log("🔄 Mise à jour des données de la table fournisseurs.");
            fournisseursTable.setData(fournisseurs);
        } else {
            console.log("✨ Création initiale de la table fournisseurs.");
            const f_columns = getTabulatorColumnsFromSchema(f_schema, 'fournisseurs');
            
            container.innerHTML = '';
            const tableEl = document.createElement('div');
            container.appendChild(tableEl);

            const table = new Tabulator(tableEl, {
                data: fournisseurs, // On utilise directement les données brutes des fournisseurs
                layout: "fitColumns",
                columns: f_columns,
                height: "auto",
                placeholder: "Aucun fournisseur trouvé.",
                
                // --- SUPPRESSION DU ROW FORMATTER ---
                // La propriété `rowFormatter` a été entièrement supprimée.
                // Cela va forcer Tabulator à utiliser le formatter par défaut pour les champs
                // de liaison, qui est défini dans `tabulator-columns.js`.
            });

            // Gérer l'édition directe dans la table pour les champs simples
            table.on("cellEdited", function (cell) {
                const row = cell.getRow().getData();
                const cleaned = sanitizeRowBeforeSave(row, f_schema);

                fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/fournisseurs/${row.id}`, {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': EECIE_CRM.nonce },
                    body: JSON.stringify(cleaned)
                })
                .then(res => {
                    if (!res.ok) throw new Error("La sauvegarde a échoué.");
                    console.log(`✅ Fournisseur #${row.id} mis à jour.`);
                })
                .catch(err => {
                    alert("Erreur de sauvegarde: " + err.message);
                    // En cas d'échec, on rafraîchit pour réinitialiser la vue
                    gceRefreshFournisseursTable();
                });
            });

            fournisseursTable = table;
        }

    }).catch(err => {
        console.error("Erreur lors de la construction de la table fournisseurs :", err);
        container.innerHTML = `<p style="color:red;">Erreur de chargement : ${err.message}</p>`;
    });
}

// L'écouteur initial reste identique.
document.addEventListener('DOMContentLoaded', () => {
    fetchDataAndBuildFournisseursTable();
    
    const addBtn = document.getElementById('gce-add-fournisseur-btn');
    if(addBtn) {
        addBtn.addEventListener('click', () => {
            gceShowModal({}, 'fournisseurs', 'ecriture');
        });
    }
});