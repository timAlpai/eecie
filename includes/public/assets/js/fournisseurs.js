// Fichier : includes/public/assets/js/fournisseurs.js
// VERSION FULL AJAX

// La variable globale pour l'instance de la table est essentielle maintenant.
let fournisseursTable = null;

function initializeFournisseursTable() {
    const container = document.getElementById('gce-fournisseurs-table');
    if (!container) return;
    container.innerHTML = 'Chargement des schémas et du cache...';

    // 1. Charger les schémas et les données de cache nécessaires pour les popups et les colonnes.
    // On ne charge PAS les données des fournisseurs ici.
    Promise.all([
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/contacts', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/zone_geo', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/fournisseurs/schema', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/contacts/schema', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/zone_geo/schema', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json())
    ]).then(([contactsData, zonesData, f_schema, c_schema, z_schema]) => {
        
        // Stockage des schémas et du cache (essentiel pour le popup-handler)
        window.gceSchemas = {
            ...window.gceSchemas,
            fournisseurs: f_schema,
            contacts: c_schema,
            zone_geo: z_schema
        };
        
        window.gceDataCache = {
            // Note: On ne met pas les fournisseurs dans le cache ici, Tabulator les gérera.
            contacts: contactsData.results || [],
            zone_geo: zonesData.results || []
        };

        // 2. Initialisation de Tabulator en mode AJAX
        console.log("✨ Initialisation de la table fournisseurs en mode AJAX.");
        const f_columns = getTabulatorColumnsFromSchema(f_schema, 'fournisseurs');
        
        container.innerHTML = '';
        const tableEl = document.createElement('div');
        container.appendChild(tableEl);

        const table = new Tabulator(tableEl, {
            // Configuration AJAX
            ajaxURL: EECIE_CRM.rest_url + 'eecie-crm/v1/fournisseurs',
            ajaxConfig: {
                method: "GET",
                headers: {
                    'X-WP-Nonce': EECIE_CRM.nonce,
                },
            },
            // Fonction pour adapter la réponse de Baserow/WP REST à ce que Tabulator attend
            ajaxResponse: function(url, params, response) {
                // Baserow renvoie { count: X, results: [...] }. Tabulator attend [...].
                return response.results || []; 
            },
            
            layout: "fitColumns",
            columns: f_columns,
            height: "auto",
            placeholder: "Aucun fournisseur trouvé.",
        });

        // Gestionnaire d'édition (reste le même)
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
                // En cas d'erreur, on force le rechargement Ajax
                if (window.fournisseursTable) {
                    window.fournisseursTable.replaceData();
                }
            });
        });

        // On stocke l'instance dans la variable globale
        fournisseursTable = table;
        // On rend l'instance accessible globalement pour le popup-handler
        window.fournisseursTable = table;

    }).catch(err => {
        console.error("Erreur lors de l'initialisation de la table fournisseurs :", err);
        container.innerHTML = `<p style="color:red;">Erreur de chargement : ${err.message}</p>`;
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initializeFournisseursTable();
    
    const addBtn = document.getElementById('gce-add-fournisseur-btn');
    if(addBtn) {
        addBtn.addEventListener('click', () => {
            gceShowModal({}, 'fournisseurs', 'ecriture');
        });
    }
});