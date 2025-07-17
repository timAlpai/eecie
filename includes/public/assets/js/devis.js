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
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/articles_devis/schema', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/fournisseurs', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/fournisseurs/schema', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json())
   
    ])
    .then(([devisData, articlesData, devisSchema, articlesSchema, fournisseursData, fournisseursSchema]) => {
        window.gceSchemas = window.gceSchemas || {};
        window.gceSchemas["devis"] = devisSchema;
        window.gceSchemas["articles_devis"] = articlesSchema;
        // === AJOUTS CI-DESSOUS ===
        window.gceSchemas["fournisseurs"] = fournisseursSchema;
        window.gceDataCache = window.gceDataCache || {};
        window.gceDataCache["fournisseurs"] = fournisseursData.results || [];


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

        const devisColumns = getTabulatorColumnsFromSchema(devisSchema, "devis");
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
        devisColumns.push({
            title:"⚡ Calculer Devis",
            formatter: () => "<button class='btn-ajout-article'>Calculer Devis</button>",
            width: 140,
            hozAlign: "center",
            headerSort: false,
            cellClick: (e, cell) => {
                const button = e.target;
                const rowData = cell.getRow().getData();
                const devisId = rowData.id;

                if (!devisId) {
                    alert("ID du devis introuvable.");
                    return;
                }

                // Prévenir les clics multiples et informer l'utilisateur
                button.disabled = true;
                button.textContent = 'Calcul en cours...';

                // On envoie SEULEMENT l'ID du devis. Le backend s'occupe du reste.
                fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/proxy/calculate-devis`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': EECIE_CRM.nonce
                    },
                    body: JSON.stringify({ devis_id: devisId }) // <-- ENVOI SIMPLIFIÉ
                })
                .then(res => {
                    if (!res.ok) {
                        return res.json().then(err => Promise.reject(err));
                    }
                    return res.json();
                })
                .then(response => {
                    console.log("✅ Workflow n8n terminé avec succès.", response);
                    alert("Le calcul du devis est terminé ! La page va se recharger.");
                    location.reload();
                })
                .catch(err => {
                    console.error("❌ Erreur lors du calcul du devis via n8n:", err);
                    const errorMessage = err.message || "Une erreur inconnue est survenue.";
                    alert(`Le calcul a échoué : ${errorMessage}`);
                    button.disabled = false;
                    button.textContent = 'Calculer Devis';
                });
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

                const articlesColumns = getTabulatorColumnsFromSchema(window.gceSchemas["articles_devis"], 'articles_devis');
                
                articlesColumns.push({
                    title: "Actions",
                    headerSort: false,
                    width: 100,
                    hozAlign: "center",
                    formatter: (cell) => {
                        const rowData = cell.getRow().getData();
                        const editIcon = `<a href="#" class="gce-popup-link" data-id="${rowData.id}" data-table="articles_devis" data-mode="ecriture" title="Modifier">✏️</a>`;
                        const deleteIcon = `<a href="#" class="gce-delete-article-btn" title="Supprimer">❌</a>`;
                        return `${editIcon}   ${deleteIcon}`;
                    },
                    cellClick: (e, cell) => {
                        if (!e.target.closest('.gce-delete-article-btn')) return;

                        e.preventDefault();
                        const rowData = cell.getRow().getData();
                        const nomArticle = rowData.Nom || `l'article #${rowData.id}`;

                        if (confirm(`Voulez-vous vraiment supprimer "${nomArticle}" ?`)) {
                            fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/articles_devis/${rowData.id}`, {
                                method: 'DELETE',
                                headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
                            })
                            .then(res => {
                                if (!res.ok) throw new Error('La suppression a échoué.');
                                console.log(`✅ Article ${rowData.id} supprimé.`);
                                gceRefreshVisibleTable(); 
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
                    columns: articlesColumns,
                    columnDefaults: { resizable: true, widthGrow: 1 },
                    placeholder: "Aucun article dans ce devis."
                });

                innerTable.on("cellEdited", function (cell) {
                    const rowData = cell.getRow().getData();
                    const schema = window.gceSchemas["articles_devis"];
                    const cleaned = sanitizeRowBeforeSave(rowData, schema);

                    fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/articles_devis/${rowData.id}`, {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': EECIE_CRM.nonce
                        },
                        body: JSON.stringify(cleaned)
                    })
                    .then(res => {
                        if (!res.ok) throw new Error('La sauvegarde a échoué.');
                        console.log(`✅ Article ${rowData.id} mis à jour, rafraîchissement.`);
                        gceRefreshVisibleTable();
                         location.reload();
                    })
                    .catch(err => {
                        console.error("❌ Erreur de sauvegarde de l'article :", err);
                        alert("La sauvegarde de l'article a échoué.");
                    });
                });
            }
        });

        table.on("cellEdited", function (cell) {
             gceRefreshVisibleTable();
              location.reload();
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

