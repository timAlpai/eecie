// Fichier : includes/public/assets/js/fournisseurs.js

// On déclare la variable de la table à l'extérieur pour qu'elle persiste
// entre les appels de la fonction de rafraîchissement.
let fournisseursTable = null;

// C'est notre nouvelle fonction principale, réutilisable.
function fetchDataAndBuildFournisseursTable() {
    const container = document.getElementById('gce-fournisseurs-table');
    if (!container) return;

    // Affiche un message de chargement uniquement si la table n'est pas encore construite.
    if (!fournisseursTable) {
        container.innerHTML = 'Chargement des données...';
    }

    Promise.all([
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
        const contacts = contactsData.results || [];
        const zones = zonesData.results || [];

        // ... la logique de groupement reste identique ...
        const groupedContacts = {};
        contacts.forEach(c => {
            if (Array.isArray(c.Fournisseur)) {
                c.Fournisseur.forEach(f => {
                    if (!groupedContacts[f.id]) groupedContacts[f.id] = [];
                    groupedContacts[f.id].push(c);
                });
            }
        });
        const groupedZones = {};
        zones.forEach(z => {
            if (Array.isArray(z.Fournisseur)) {
                z.Fournisseur.forEach(f => {
                    if (!groupedZones[f.id]) groupedZones[f.id] = [];
                    groupedZones[f.id].push(z);
                });
            }
        });
        const fournisseursAvecChildren = fournisseurs.map(f => ({
            ...f,
            _childrenContacts: groupedContacts[f.id] || [],
            _childrenZones: groupedZones[f.id] || []
        }));

        // === LOGIQUE DE MISE A JOUR vs CRÉATION ===
        if (fournisseursTable) {
            // Si la table existe déjà, on met simplement à jour ses données.
            console.log("🔄 Mise à jour des données de Tabulator.");
            fournisseursTable.setData(fournisseursAvecChildren);
        } else {
            // Si c'est le premier chargement, on crée la table.
            console.log("✨ Création initiale de la table Tabulator.");
            const f_columns = getTabulatorColumnsFromSchema(f_schema, 'fournisseurs');
            
            container.innerHTML = '';
            const tableEl = document.createElement('div');
            container.appendChild(tableEl);

            const table = new Tabulator(tableEl, {
                data: fournisseursAvecChildren,
                layout: "fitColumns",
                columns: f_columns,
                height: "auto",
                placeholder: "Aucun fournisseur trouvé.",
                rowFormatter: (row) => {
                    // La logique du rowFormatter reste identique
                    const data = row.getData();
                    const contacts = data._childrenContacts;
                    const zones = data._childrenZones;

                    if (contacts.length === 0 && zones.length === 0) return;

                    const holderEl = document.createElement("div");
                    holderEl.style.padding = "10px";
                    holderEl.style.borderTop = "2px solid #666";
                    row.getElement().appendChild(holderEl);

                    if (contacts.length > 0) {
                        holderEl.innerHTML += '<h4>Contacts</h4>';
                        const contactsTableEl = document.createElement("div");
                        holderEl.appendChild(contactsTableEl);
                        new Tabulator(contactsTableEl, {
                            data: contacts,
                            layout: "fitColumns",
                            columns: getTabulatorColumnsFromSchema(c_schema, 'contacts'),
                        });
                    }
                    if (zones.length > 0) {
                        holderEl.innerHTML += '<h4 style="margin-top:15px;">Zones Desservies</h4>';
                        const zonesTableEl = document.createElement("div");
                        holderEl.appendChild(zonesTableEl);
                        new Tabulator(zonesTableEl, {
                            data: zones,
                            layout: "fitColumns",
                            columns: getTabulatorColumnsFromSchema(z_schema, 'zone_geo'),
                        });
                    }
                }
            });
            fournisseursTable = table; // On stocke l'instance pour les futurs rafraîchissements
        }

    }).catch(err => {
        console.error("Erreur lors de la construction de la table fournisseurs :", err);
        container.innerHTML = `<p style="color:red;">Erreur de chargement : ${err.message}</p>`;
    });
}

// L'écouteur initial qui lance tout.
document.addEventListener('DOMContentLoaded', () => {
    // Premier appel pour construire la table
    fetchDataAndBuildFournisseursTable();
    
    const addBtn = document.getElementById('gce-add-fournisseur-btn');
    if(addBtn) {
        addBtn.addEventListener('click', () => {
            gceShowModal({}, 'fournisseurs', 'ecriture');
        });
    }
});