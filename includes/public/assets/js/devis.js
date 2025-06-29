document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('gce-devis-table');
    if (!container) return;

    container.innerHTML = 'Chargement des devis…';

    const userEmail = window.GCE_CURRENT_USER?.email;
    if (!userEmail) {
        container.innerHTML = '<p>Utilisateur non identifié.</p>';
        return;
    }

    Promise.all([
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/devis', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/articles_devis', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/devis/schema', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/articles_devis/schema', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json())
    ])
        .then(([devisData, articlesData, devisSchema, articlesSchema]) => {
            window.gceSchemas = window.gceSchemas || {};
            window.gceSchemas["devis"] = devisSchema;
            window.gceSchemas["articles_devis"] = articlesSchema;

            const devis = devisData.results || [];
            const articles = articlesData.results || [];

            const groupedArticles = {};
            articles.forEach(art => {
                const devisField = Object.keys(art).find(k => k.toLowerCase().startsWith("devis"));
                if (!devisField || !Array.isArray(art[devisField])) return;
                art[devisField].forEach(link => {
                    if (!groupedArticles[link.id]) groupedArticles[link.id] = [];
                    groupedArticles[link.id].push(art);
                });
            });

            const devisAvecChildren = devis.map(d => ({
                ...d,
                _children: groupedArticles[d.id] || []
            }));

            const devisColumns = getTabulatorColumnsFromSchema(devisSchema);
            devisColumns.push({
                title: "➕ Article",
                formatter: () => "<button class='btn-ajout-article'>Ajouter Article</button>",
                width: 140,
                hozAlign: "center",
                headerSort: false,
                cellClick: async (e, cell) => {
                    const devis = cell.getRow().getData();

                    // Récupération du schéma s’il n’est pas déjà en cache
                    if (!window.gceSchemas?.articles_devis) {
                        try {
                            const res = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/articles_devis/schema`, {
                                headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
                            });
                            if (!res.ok) throw new Error("Erreur chargement schéma");
                            const schema = await res.json();
                            window.gceSchemas = window.gceSchemas || {};
                            window.gceSchemas.articles_devis = schema;
                        } catch (err) {
                            console.error("❌ Erreur chargement schéma :", err);
                            alert("Impossible de charger le schéma.");
                            return;
                        }
                    }

                    const schema = window.gceSchemas.articles_devis;

                    // Trouver le vrai ID du champ "Devis"
                    const champDevis = schema.find(f => f.name === "Devis");
                    const popupData = champDevis
                        ? { [`field_${champDevis.id}`]: [{ id: devis.id, value: `${devis.id}` }] }
                        : {};

                    // Ouvre le modal avec les bons champs visibles
                    gceShowModal(popupData, "articles_devis", "écriture", ["Nom", "Quantités", "Prix_unitaire", `field_${champDevis?.id}`]);
                }

            });

            const articleColumns = getTabulatorColumnsFromSchema(articlesSchema);

            const tableEl = document.createElement('div');
            tableEl.className = 'gce-tabulator';
            container.innerHTML = '';
            container.appendChild(tableEl);

            const table = new Tabulator(tableEl, {
                data: devisAvecChildren,
                layout: "fitColumns",
                columns: devisColumns,
                columnDefaults: {
                    resizable: true,
                    widthGrow: 1
                },
                height: "auto",
                placeholder: "Aucun devis trouvé.",
                responsiveLayout: "collapse",

                rowFormatter: function (row) {
                    const data = row.getData()._children;
                    if (!Array.isArray(data) || data.length === 0) return;

                    const holderEl = document.createElement("div");


                    const tableEl = document.createElement("div");
                    holderEl.appendChild(tableEl);
                    row.getElement().appendChild(holderEl);

                    const innerTable = new Tabulator(tableEl, {
                        data: data,
                        layout: "fitColumns",
                        height: "auto",
                        columns: getTabulatorColumnsFromSchema(window.gceSchemas["articles_devis"]),
                        columnDefaults: {
                            resizable: true,
                            widthGrow: 1
                        },
                        placeholder: "Aucun article dans ce devis.",

                    });

                    innerTable.on("cellEdited", function (cell) {
                        const row = cell.getRow().getData();
                        const schema = window.gceSchemas?.["articles_devis"];
                        if (!schema) return;

                        const cleaned = sanitizeRowBeforeSave(row, schema);

                        fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/articles_devis/${row.id}`, {
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
                                console.log("✅ Article_devis mis à jour :", json);

                                const field = cell.getField();
                                const schemaField = schema.find(f => f.name === field);

                                if (schemaField?.type === 'single_select' && Array.isArray(schemaField.select_options)) {
                                    const selectedId = cleaned[`field_${schemaField.id}`];
                                    const selectedObj = schemaField.select_options.find(opt => opt.id === selectedId);
                                    if (selectedObj) {
                                        cell.setValue(selectedObj, true);
                                        cell.getRow().getCell(cell.getField()).render();
                                    }
                                }
                            })
                            .catch(err => {
                                console.error("❌ Erreur article_devis :", err);
                            });
                    });
                }
            });

            table.on("cellEdited", function (cell) {
                const row = cell.getRow().getData();
                const schema = window.gceSchemas?.["devis"];
                if (!schema) return;

                const cleaned = sanitizeRowBeforeSave(row, schema);

                fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/devis/${row.id}`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': EECIE_CRM.nonce
                    },
                    body: JSON.stringify(cleaned)
                })
                    .then(res => res.json())
                    .then(json => {
                        console.log("✅ Devis mis à jour :", json);

                        const field = cell.getField();
                        const schemaField = schema.find(f => f.name === field);

                        if (schemaField?.type === 'single_select' && Array.isArray(schemaField.select_options)) {
                            const selectedId = cleaned[`field_${schemaField.id}`];
                            const selectedObj = schemaField.select_options.find(opt => opt.id === selectedId);
                            if (selectedObj) {
                                cell.setValue(selectedObj, true);
                                cell.getRow().getCell(cell.getField()).render();
                            }
                        }
                    })
                    .catch(err => {
                        console.error("❌ Erreur devis :", err);
                    });
            });

            window.devisTable = table;
        })
        .catch(err => {
            console.error("❌ Erreur devis.js :", err);
            container.innerHTML = `<p>Erreur réseau ou serveur : ${err.message}</p>`;
        });
});
