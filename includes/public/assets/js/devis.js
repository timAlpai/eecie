// Fichier : includes/public/assets/js/devis.js

// On définit la fonction de chargement qui contient toute la logique.
// Elle peut être appelée n'importe quand pour recharger la table.
function loadAndBuildDevisTable() {
    const container = document.getElementById('gce-devis-table');
    if (!container) return;

    container.innerHTML = 'Chargement des devis…';

    const userEmail = window.GCE_CURRENT_USER?.email;
    if (!userEmail) {
        container.innerHTML = '<p>Utilisateur non identifié.</p>';
        return;
    }

    Promise.all([
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/devis', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/articles_devis', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/devis/schema', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/articles_devis/schema', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json())
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
        // === LA COLONNE "AJOUTER ARTICLE" EST BIEN PRÉSENTE ICI ===
        devisColumns.push({
            title: "➕ Article",
            formatter: () => "<button class='btn-ajout-article'>Ajouter Article</button>",
            width: 140,
            hozAlign: "center",
            headerSort: false,
            cellClick: async (e, cell) => {
                const devis = cell.getRow().getData();

                if (!window.gceSchemas?.articles_devis) {
                    try {
                        const res = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/articles_devis/schema`, { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } });
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
                const champDevis = schema.find(f => f.name === "Devis");
                const popupData = champDevis ? { "Devis": [{ id: devis.id, value: `${devis.id}` }] } : {};
                gceShowModal(popupData, "articles_devis", "ecriture", ["Nom", "Quantités", "Prix_unitaire", "Devis"]);
            }
        });
        
        const tableEl = document.createElement('div');
        tableEl.className = 'gce-tabulator';
        container.innerHTML = '';
        container.appendChild(tableEl);

        const table = new Tabulator(tableEl, {
            data: devisAvecChildren,
            layout: "fitColumns",
            columns: devisColumns,
            columnDefaults: { resizable: true, widthGrow: 1 },
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
                    columnDefaults: { resizable: true, widthGrow: 1 },
                    placeholder: "Aucun article dans ce devis."
                });

                innerTable.on("cellEdited", function (cell) {
                    gceRefreshVisibleTable();
                });
            }
        });

        table.on("cellEdited", function (cell) {
             gceRefreshVisibleTable();
        });
        
        window.devisTable = table;
    })
    .catch(err => {
        console.error("❌ Erreur devis.js :", err);
        container.innerHTML = `<p>Erreur réseau ou serveur : ${err.message}</p>`;
    });
}

// L'écouteur d'événement est à l'extérieur et appelle la fonction de chargement une seule fois au démarrage.
document.addEventListener('DOMContentLoaded', () => {
    loadAndBuildDevisTable();
});