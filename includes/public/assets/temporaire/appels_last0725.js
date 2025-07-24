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

                const appelsTableId = appelsSchema[0].table_id;
                const appelLinkFieldInInteractions = interactionsSchema.find(f => f.type === 'link_row' && f.link_row_table_id === appelsTableId);
                
                if (!appelLinkFieldInInteractions) {
                    alert("Erreur: Le champ de liaison vers les Appels est introuvable dans les Interactions.");
                    return;
                }

                // --- DÉBUT DE LA MODIFICATION PRINCIPALE ---
                // On prépare un objet qui contiendra toutes les données à pré-remplir
                const popupData = {};

                // 1. Lier l'interaction à l'appel parent (ce que vous aviez déjà)
                popupData[appelLinkFieldInInteractions.name] = [{ id: appel.id, value: `Appel #${appel.id}` }];

                // 2. Transférer les informations liées de l'appel vers l'interaction
                // On fait correspondre les champs de l'appel aux champs de l'interaction.
                // Ex: Le champ "Opportunité" de l'appel correspond au champ "opportunité" de l'interaction.
                if (appel.Opportunité) popupData.opportunité = appel.Opportunité;
                if (appel.Employé)     popupData.effectue_par = appel.Employé;
                if (appel.Contact)      popupData.contact = appel.Contact;
                
                // 3. Pré-remplir le type d'interaction sur "Appel Telephonique"
                const typeInteractionField = interactionsSchema.find(f => f.name === 'types_interactions');
                if (typeInteractionField) {
                    const optionAppel = typeInteractionField.select_options.find(opt => opt.value === 'Appel Telephonique');
                    if (optionAppel) {
                        // On passe l'objet complet pour que le popup puisse afficher la valeur et utiliser l'ID
                        popupData[typeInteractionField.name] = { id: optionAppel.id, value: optionAppel.value };
                    }
                }

                // 4. Pré-remplir la date et l'heure actuelles
                const dateField = interactionsSchema.find(f => f.name === 'date_heure');
                if(dateField) {
                    // Formate la date en 'YYYY-MM-DDTHH:mm' pour l'input datetime-local
                    const now = new Date();
                    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
                    popupData[dateField.name] = now.toISOString().slice(0,16);
                }

                // On appelle le modal avec le nouvel objet `popupData` enrichi
                gceShowModal(popupData, "interactions", "ecriture");
                // --- FIN DE LA MODIFICATION PRINCIPALE ---
            }
        });

        const tableEl = document.createElement('div');
        tableEl.className = 'gce-tabulator';
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

                const interactionColumns = getTabulatorColumnsFromSchema(window.gceSchemas["interactions"], 'interactions');
                interactionColumns.push({
                    title: "Actions",
                    headerSort: false,
                    width: 100,
                    hozAlign: "center",
                    formatter: (cell) => {
                        const rowData = cell.getRow().getData();
                        const editIcon = `<a href="#" class="gce-popup-link" data-id="${rowData.id}" data-table="interactions" data-mode="ecriture" title="Modifier">✏️</a>`;
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
                                location.reload();
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
                    columns: interactionColumns,
                    placeholder: "Aucune interaction.",
                });

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
                        location.reload();
                    })
                    .catch(err => {
                        console.error("❌ Erreur de mise à jour de l'interaction :", err);
                        alert("La sauvegarde a échoué.");
                    });
                });
            }
        });

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
                location.reload();
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