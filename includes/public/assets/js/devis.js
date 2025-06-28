
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
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/devis/schema', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/articles_devis', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/articles_devis/schema', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json())
    ])
        .then(([devisData, devisSchema, articlesData, articlesSchema]) => {
            window.gceSchemas = window.gceSchemas || {};
            window.gceSchemas["devis"] = devisSchema;
            window.gceSchemas["articles_devis"] = articlesSchema;

            const groupedArticles = {};
            articlesData.results.forEach(article => {
                const lien = article.Devis?.[0]?.id;
                if (!lien) return;
                if (!groupedArticles[lien]) groupedArticles[lien] = [];
                groupedArticles[lien].push(article);
            });

            const devisAvecChildren = devisData.results.map(devis => ({
                ...devis,
                _children: groupedArticles[devis.id] || []
            }));

            const columns = getTabulatorColumnsFromSchema(devisSchema);
            const articleColumns = getTabulatorColumnsFromSchema(articlesSchema);

            const tableEl = document.createElement('div');
            tableEl.style.maxWidth = '100%'; // ✅ Empêche l'étirement horizontal
            container.innerHTML = '';
            container.appendChild(tableEl);

            const table = new Tabulator(tableEl, {
                data: devisAvecChildren,
                layout: "fitColumns",
                columns: columns,
                columnDefaults: {
                    resizable: true,
                    widthGrow: 1
                },
                height: "auto",
                placeholder: "Aucun devis trouvé.",
                responsiveLayout: "collapse",

                rowFormatter: function (row) {
                    const children = row.getData()._children;
                    if (!Array.isArray(children) || children.length === 0) return;

                    const holderEl = document.createElement("div");
                    holderEl.style.margin = "10px";
                    holderEl.style.borderTop = "1px solid #ddd";

                    const nestedTableEl = document.createElement("div");
                    holderEl.appendChild(nestedTableEl);
                    row.getElement().appendChild(holderEl);

                    new Tabulator(nestedTableEl, {
                        data: children,
                        layout: "fitColumns", // ✅ Pas de débordement
                        height: "auto",
                        columns: articleColumns,
                        columnDefaults: {
                            resizable: true,
                            widthGrow: 1
                        },
                        placeholder: "Aucun article dans ce devis.",
                        cellEdited: function (cell) {
                            const row = cell.getRow().getData();
                            const cleaned = sanitizeRowBeforeSave(row, articlesSchema);

                            fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/articles_devis/${row.id}`, {
                                method: 'PATCH',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-WP-Nonce': EECIE_CRM.nonce
                                },
                                body: JSON.stringify(cleaned)
                            })
                                .then(r => r.json())
                                .then(resp => {
                                    console.log("✅ Article mis à jour", resp);
                                })
                                .catch(err => {
                                    console.error("❌ Erreur PATCH article :", err);
                                });
                        }
                    });
                },

                cellEdited: function (cell) {
                    const row = cell.getRow().getData();
                    const cleaned = sanitizeRowBeforeSave(row, devisSchema);

                    fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/devis/${row.id}`, {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': EECIE_CRM.nonce
                        },
                        body: JSON.stringify(cleaned)
                    })
                        .then(r => r.json())
                        .then(resp => {
                            console.log("✅ Devis mis à jour", resp);
                        })
                        .catch(err => {
                            console.error("❌ Erreur PATCH devis :", err);
                        });
                }
            });

            window.devisTable = table;
        })
        .catch(err => {
            console.error("Erreur devis.js :", err);
            container.innerHTML = `<p>Erreur de chargement : ${err.message}</p>`;
        });
});
