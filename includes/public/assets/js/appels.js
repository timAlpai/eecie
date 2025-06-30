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
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/appels', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/interactions', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/appels/schema', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/interactions/schema', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json())
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

            const appelsAvecChildren = appels.map(appel => {
                const enfants = groupedInteractions[appel.id];
                return {
                    ...appel,
                    _children: Array.isArray(enfants) && enfants.length > 0 ? enfants : []
                };
            });

            const columns = getTabulatorColumnsFromSchema(appelsSchema);
            const interactionColumns = getTabulatorColumnsFromSchema(interactionsSchema);

            const tableEl = document.createElement('div');
            container.innerHTML = '';
            container.appendChild(tableEl);

            const table = new Tabulator(tableEl, {
                data: appelsAvecChildren,
                layout: "fitColumns",
                columns: columns,
                columnDefaults: {
                    resizable: true,
                    widthGrow: 1
                },
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

                    const innerTable = new Tabulator(tableEl, {
                        data: data,
                        layout: "fitColumns",
                        height: "auto",
                        columns: getTabulatorColumnsFromSchema(window.gceSchemas["interactions"]),

                        placeholder: "Aucune interaction.",
                        
                    });

                    innerTable.on("cellEdited", function (cell) {
                        const row = cell.getRow().getData();
                        const schema = window.gceSchemas?.["interactions"];
                        if (!schema) return;

                        const cleaned = sanitizeRowBeforeSave(row, schema);

                        fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/interactions/${row.id}`, {
                            method: 'PATCH',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': EECIE_CRM.nonce
                            },
                            body: JSON.stringify(cleaned)
                        })
                            .then(res => {
                                if (!res.ok) throw new Error("Erreur HTTP " + res.status);
                                return res.json();
                            })
                            .then(json => {
                                console.log("✅ Interaction mise à jour :", json);
                                const field = cell.getField();
                                const schemaField = schema.find(f => f.name === field);
                                if (schemaField?.type === 'single_select') {
                                    const selectedId = cleaned[`field_${schemaField.id}`];
                                    const selectedObj = schemaField.select_options.find(opt => opt.id === selectedId);
                                    if (selectedObj) {
                                        cell.setValue(selectedObj);
                                        const el = cell.getElement();
                                        const label = selectedObj.value || selectedObj.name || '';
                                        const color = selectedObj.color || 'gray';
                                        el.innerHTML = `<span class="gce-badge gce-color-${color}">${label}</span>`;
                                    }
                                }
                            })
                            .catch(err => {
                                console.error("❌ Erreur interaction :", err);
                            });
                    });
                }
            });

            table.on("cellEdited", function (cell) {
                const row = cell.getRow().getData();
                const schema = window.gceSchemas?.["appels"];
                if (!schema) return;

                const cleaned = sanitizeRowBeforeSave(row, schema);

                fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/appels/${row.id}`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': EECIE_CRM.nonce
                    },
                    body: JSON.stringify(cleaned)
                })
                    .then(res => res.json())
                    .then(json => {
                        console.log("✅ Appel mis à jour :", json);
                        const field = cell.getField();
                        const schemaField = schema.find(f => f.name === field);
                        if (schemaField?.type === 'single_select') {
                            const selectedId = cleaned[`field_${schemaField.id}`];
                            const selectedObj = schemaField.select_options.find(opt => opt.id === selectedId);
                            if (selectedObj) {
                                cell.setValue(selectedObj);
                                const el = cell.getElement();
                                const label = selectedObj.value || selectedObj.name || '';
                                const color = selectedObj.color || 'gray';
                                el.innerHTML = `<span class="gce-badge gce-color-${color}">${label}</span>`;
                            }
                        }
                    })
                    .catch(err => console.error("❌ Erreur appel :", err));
            });

            window.appelsTable = table;
        })
        .catch(err => {
            console.error("Erreur appels.js :", err);
            container.innerHTML = `<p>Erreur réseau ou serveur : ${err.message}</p>`;
        });
});
