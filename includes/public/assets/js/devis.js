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
        }).then(r => r.json())
    ])
    .then(([devisData, articlesData, devisSchema]) => {
        if (!devisData.results || !articlesData.results || !Array.isArray(devisSchema)) {
            container.innerHTML = '<p>Erreur lors de la récupération des données.</p>';
            return;
        }

        const devis = devisData.results;
        const articles = articlesData.results;

        // Grouper les articles par ID de devis
        const groupedArticles = {};
        articles.forEach(article => {
            if (!article.Devis || !Array.isArray(article.Devis)) return;
            article.Devis.forEach(link => {
                if (!groupedArticles[link.id]) groupedArticles[link.id] = [];
                groupedArticles[link.id].push(article);
            });
        });

        // Injecter les articles comme _children dans chaque devis
        const devisAvecChildren = devis.map(item => ({
            ...item,
            _children: groupedArticles[item.id] || []
        }));

        const columns = getTabulatorColumnsFromSchema(devisSchema);

        const tableEl = document.createElement('div');
        container.innerHTML = '';
        container.appendChild(tableEl);

        new Tabulator(tableEl, {
            data: devisAvecChildren,
            dataTree: true,
            dataTreeStartExpanded: false,
            layout: "fitColumns",
            columns: columns,
            height: "auto",
            placeholder: "Aucun devis trouvé.",
            cellEdited: function (cell) {
                const row = cell.getRow().getData();
                const schema = window.gceDevisSchema;
                const cleaned = sanitizeRowBeforeSave(row, schema);
                const isArticle = !!row.Devis; // Si présent, c'est un article

                const endpoint = isArticle
                    ? `articles_devis/${row.id}`
                    : `devis/${row.id}`;

                fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/${endpoint}`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': EECIE_CRM.nonce
                    },
                    body: JSON.stringify(cleaned)
                })
                .then(res => res.json())
                .then(json => {
                    console.log("✅ Modification sauvegardée :", json);
                })
                .catch(err => {
                    console.error("❌ Erreur lors de l'enregistrement :", err);
                });
            }
        });

        window.gceDevisSchema = devisSchema;
    })
    .catch(err => {
        console.error("Erreur devis.js :", err);
        container.innerHTML = `<p>Erreur réseau : ${err.message}</p>`;
    });
});
