document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('gce-appels-table');
    if (!container) return;

    container.innerHTML = 'Chargement des appels…';

    const userEmail = window.GCE_CURRENT_USER?.email;
    if (!userEmail) {
        container.innerHTML = '<p>Utilisateur non identifié.</p>';
        return;
    }

    Promise.all([
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/appels', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/interactions', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/appels/schema', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/interactions/schema', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json())
    ])
    .then(([appelsData, interactionsData, appelsSchema, interactionsSchema]) => {
        window.gceSchemas = window.gceSchemas || {};
        window.gceSchemas["appels"] = appelsSchema;
        window.gceSchemas["interactions"] = interactionsSchema;

        const appels = appelsData.results || [];
        const interactions = interactionsData.results || [];

        const groupedInteractions = {};
        interactions.forEach(inter => {
            const appelField = Object.keys(inter).find(k => k.toLowerCase().startsWith("appel"));
            if (!appelField || !Array.isArray(inter[appelField])) return;
            inter[appelField].forEach(link => {
                if (!groupedInteractions[link.id]) groupedInteractions[link.id] = [];
                groupedInteractions[link.id].push(inter);
            });
        });

        const appelsAvecChildren = appels.map(appel => ({
            ...appel,
            _children: groupedInteractions[appel.id] || [],
        }));

        const columns = getTabulatorColumnsFromSchema(appelsSchema, 'appels');

        // Ajout du bouton pour créer une nouvelle interaction
        columns.push({
            title: "➕ Interaction",
            formatter: () => "<button class='button button-small'>+ Interaction</button>",
            width: 140,
            hozAlign: "center",
            headerSort: false,
            cellClick: (e, cell) => {
                const appel = cell.getRow().getData();
                
                if (!appelsSchema.length || !interactionsSchema.length) {
                    alert("Erreur: Les schémas de données sont incomplets.");
                    return;
                }

                // Trouve l'ID de la table des appels à partir de son schéma
                const appelsTableId = appelsSchema[0].table_id;
                // Trouve le champ dans les interactions qui pointe vers la table des appels
                const linkField = interactionsSchema.find(f => f.type === 'link_row' && f.link_row_table_id === appelsTableId);

                if (!linkField) {
                    alert("Erreur: Le champ de liaison vers les Appels est introuvable.");
                    return;
                }

                const popupData = { [linkField.name]: [{ id: appel.id, value: `Appel #${appel.id}` }] };
                gceShowModal(popupData, "interactions", "ecriture", ["Nom", "Type", "Description", linkField.name]);
            }
        });

        const tableEl = document.createElement('div');
        tableEl.className = 'gce-tabulator'; // On ajoute la classe pour le style
        container.innerHTML = '';
        container.appendChild(tableEl);

        const table = new Tabulator(tableEl, {
            data: appelsAvecChildren,
            layout: "fitColumns",
            columns: columns,
            columnDefaults: { resizable: true, widthGrow: 1 },
            height: "auto",
            placeholder: "Aucun appel trouvé.",
            responsiveLayout: "collapse",

            rowFormatter: function (row) {
                const data = row.getData()._children;
                if (!Array.isArray(data) || data.length === 0) return;

                const holderEl = document.createElement("div");
                holderEl.style.margin = "10px";
                holderEl.style.borderTop = "1px solid #ddd";

                const tableEl = document.createElement("div");
                holderEl.appendChild(tableEl);
                row.getElement().appendChild(holderEl);

                // ============== DÉBUT DE LA MODIFICATION ==============
                // 1. Obtenir les colonnes de base pour les interactions
                const interactionColumns = getTabulatorColumnsFromSchema(window.gceSchemas["interactions"], 'interactions');

                // 2. Ajouter la colonne "Actions" avec les icônes
                interactionColumns.push({
                    title: "Actions",
                    headerSort: false,
                    width: 100,
                    hozAlign: "center",
                    formatter: (cell) => {
                        const rowData = cell.getRow().getData();
                        // L'icône Modifier utilise le popup-handler existant
                        const editIcon = `<a href="#" class="gce-popup-link" data-id="${rowData.id}" data-table="interactions" data-mode="ecriture" title="Modifier">✏️</a>`;
                        // L'icône Supprimer est gérée par cellClick
                        const deleteIcon = `<a href="#" class="gce-delete-interaction-btn" title="Supprimer">❌</a>`;
                        return `${editIcon}   ${deleteIcon}`;
                    },
                    cellClick: (e, cell) => {
                        if (!e.target.closest('.gce-delete-interaction-btn')) return;
                        e.preventDefault();
                        const rowData = cell.getRow().getData();
                        const nomInteraction = rowData.Nom || `l'interaction #${rowData.id}`;

                        if (confirm(`Voulez-vous vraiment supprimer "${nomInteraction}" ?`)) {
                            fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/interactions/${rowData.id}`, {
                                method: 'DELETE',
                                headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
                            })
                            .then(res => {
                                if (!res.ok) throw new Error('La suppression a échoué.');
                                console.log(`✅ Interaction ${rowData.id} supprimée.`);
                                location.reload(); // Recharger la page
                            })
                            .catch(err => {
                                console.error('❌ Erreur de suppression:', err);
                                alert(err.message);
                            });
                        }
                    }
                });

                const innerTable = new Tabulator(tableEl, {
                    data: data,
                    layout: "fitColumns",
                    height: "auto",
                    columns: interactionColumns, // Utilisation des colonnes modifiées
                    placeholder: "Aucune interaction.",
                });

                // 3. Mettre à jour le handler "cellEdited" pour recharger la page
                innerTable.on("cellEdited", function (cell) {
                    const rowData = cell.getRow().getData();
                    const schema = window.gceSchemas["interactions"];
                    const cleaned = sanitizeRowBeforeSave(rowData, schema);

                    fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/interactions/${rowData.id}`, {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': EECIE_CRM.nonce
                        },
                        body: JSON.stringify(cleaned)
                    })
                    .then(res => {
                        if (!res.ok) throw new Error("Erreur HTTP " + res.status);
                        console.log("✅ Interaction mise à jour.");
                        location.reload(); // Recharger la page
                    })
                    .catch(err => {
                        console.error("❌ Erreur de mise à jour de l'interaction :", err);
                        alert("La sauvegarde a échoué.");
                    });
                });
                // =============== FIN DE LA MODIFICATION ===============
            }
        });

        // Mettre à jour également le handler de la table principale pour la cohérence
        table.on("cellEdited", function (cell) {
            const rowData = cell.getRow().getData();
            const schema = window.gceSchemas["appels"];
            const cleaned = sanitizeRowBeforeSave(rowData, schema);

            fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/appels/${rowData.id}`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': EECIE_CRM.nonce },
                body: JSON.stringify(cleaned)
            })
            .then(res => {
                if (!res.ok) throw new Error("La sauvegarde de l'appel a échoué.");
                console.log(`✅ Appel ${rowData.id} mis à jour.`);
                location.reload(); // Recharger la page
            })
            .catch(err => {
                console.error("❌ Erreur de mise à jour de l'appel :", err);
                alert(err.message);
            });
        });

        window.appelsTable = table;
    })
    .catch(err => {
        console.error("Erreur appels.js :", err);
        container.innerHTML = `<p>Erreur réseau ou serveur : ${err.message}</p>`;
    });
});