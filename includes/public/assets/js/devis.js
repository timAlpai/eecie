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
        const devis = devisData.results || [];
        const articles = articlesData.results || [];

        // Groupement des articles par devis
        const groupedArticles = {};
        articles.forEach(article => {
            const devisField = Object.keys(article).find(k => k.toLowerCase().startsWith("devis"));
            if (!devisField || !Array.isArray(article[devisField])) return;
            article[devisField].forEach(link => {
                if (!groupedArticles[link.id]) groupedArticles[link.id] = [];
                groupedArticles[link.id].push(article);
                console.log(`🧾 Article ${article.id} lié au devis ${link.id}`);
            });
        });

        // Injecter les articles comme _children dans chaque devis
        const devisAvecChildren = devis.map(d => {
            const enfants = groupedArticles[d.id];
            return {
                ...d,
                _children: Array.isArray(enfants) && enfants.length > 0 ? enfants : []
            };
        });

        const columns = getTabulatorColumnsFromSchema(devisSchema);
        const articleColumns = [
            { title: "ID", field: "id" },
            { title: "Désignation", field: "designation" },
            { title: "Quantité", field: "quantite" },
            { title: "Prix unitaire", field: "prix_unitaire" },
            { title: "Total", field: "total" },
            {
                title: "Lien", field: "id", formatter: (cell) => {
                    const id = cell.getValue();
                    return `<a href="#" class="gce-popup-link" data-table="articles_devis" data-id="${id}">🔍</a>`;
                }
            }
        ];

        const tableEl = document.createElement('div');
        container.innerHTML = '';
        container.appendChild(tableEl);

        new Tabulator(tableEl, {
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
            cellEdited: window.gceTabulatorSaveHandler('devis'),

            rowFormatter: function (row) {
                const data = row.getData()._children;
                if (!Array.isArray(data) || data.length === 0) return;

                const holderEl = document.createElement("div");
                holderEl.style.margin = "10px";
                holderEl.style.borderTop = "1px solid #ddd";

                const table = document.createElement("div");
                holderEl.appendChild(table);
                row.getElement().appendChild(holderEl);

                new Tabulator(table, {
                    data: data,
                    columns: articleColumns,
                    layout: "fitColumns",
                    columnDefaults: {
                        resizable: true,
                        widthGrow: 1
                    },
                    height: "auto",
                    responsiveLayout: "collapse",
                    cellEdited: window.gceTabulatorSaveHandler('articles_devis'),

                    renderComplete: () => {
                        if (typeof initializePopupHandlers === "function") {
                            initializePopupHandlers();
                        }
                    }
                });
            }
        });
    })
    .catch(err => {
        console.error("Erreur devis.js :", err);
        container.innerHTML = `<p>Erreur réseau ou serveur : ${err.message}</p>`;
    });
});
