// Fichier : includes/public/assets/js/devis.js (VERSION FINALE AVEC CORRECTION DÉFINITIVE)

async function refreshDevisData(devisId) {
    try {
        showStatusUpdate('Synchronisation...', true);

        const [devisRes, articlesRes] = await Promise.all([
            fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/row/devis/${devisId}`, { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }),
            fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/articles_devis`, { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } })
        ]);

        if (!devisRes.ok || !articlesRes.ok) throw new Error("Impossible de recharger les données.");
        
        const updatedDevisFromServer = await devisRes.json();
        const articlesData = await articlesRes.json();
        
        const articles = articlesData.results || [];
        const linkedArticles = articles.filter(art => art.Devis && art.Devis.some(d => d.id === devisId));
        updatedDevisFromServer._children = linkedArticles;
        
        gce.viewManager.updateItem(devisId, updatedDevisFromServer);

        const modal = document.querySelector('.gce-detail-modal');
        if (modal) {
            const subTableInstance = Tabulator.findTable(modal.querySelector('.tabulator'))[0];
            if (subTableInstance) {
                subTableInstance.setData(updatedDevisFromServer._children || []);
            }
        }
        showStatusUpdate('Données synchronisées !', true);
    } catch (err) {
        showStatusUpdate(`Erreur de synchronisation: ${err.message}`, false);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const mainContainer = document.getElementById('gce-devis-table');
    if (!mainContainer) return;

    mainContainer.innerHTML = 'Chargement des devis...';

    const devisViewConfig = {
        summaryRenderer: (devis) => {
            const card = document.createElement('div');
            card.className = 'gce-devis-summary-card'; 
            const oppName = devis.Task_input?.[0]?.value || 'Opportunité non liée';
            const statut = devis.Status?.value || 'N/A';
            const statutColor = devis.Status?.color || 'gray';
            const total = devis.Montant_total_ht ? parseFloat(devis.Montant_total_ht).toFixed(2) + ' $' : '0.00 $';
            card.innerHTML = `<h4>Devis #${devis.DevisId}</h4><p><strong>Opportunité:</strong> ${oppName}</p><p><strong>Total HT:</strong> ${total}</p><p><strong>Statut:</strong> <span class="gce-badge gce-color-${statutColor}">${statut}</span></p>`;
            return card;
        },
        detailRenderer: (devis) => {
            const container = document.createElement('div');
            container.className = 'gce-devis-card';
            const oppLink = `<a href="#" class="gce-popup-link" data-table="opportunites" data-id="${devis.Task_input?.[0]?.id}">${devis.Task_input?.[0]?.value || ''}</a>`;
            const header = document.createElement('div');
            header.className = 'gce-devis-header'; 
            header.innerHTML = `<h3>Détails du Devis #${devis.DevisId}</h3>`;
            const actions = document.createElement('div');
            const addArticleBtn = document.createElement('button');
            addArticleBtn.className = 'button';
            addArticleBtn.textContent = '➕ Article';
            addArticleBtn.onclick = () => {
                const schema = window.gceSchemas.articles_devis;
                const champDevis = schema.find(f => f.name === "Devis");
                const popupData = champDevis ? { "Devis": [{ id: devis.id, value: `Devis #${devis.DevisId}` }] } : {};
                gceShowModal(popupData, "articles_devis", "ecriture", ["Nom", "Quantités", "Prix_unitaire", "Devis"]);
            };
            const calculateBtn = document.createElement('button');
            calculateBtn.className = 'button button-primary';
            calculateBtn.textContent = '⚡ Calculer et Envoyer';
            calculateBtn.onclick = (e) => {
                const button = e.target;
                button.disabled = true; button.textContent = 'Calcul en cours...';
                fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/proxy/calculate-devis`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': EECIE_CRM.nonce },
                    body: JSON.stringify({ devis_id: devis.id })
                })
                .then(res => { if (!res.ok) throw new Error('Échec du calcul.'); return res.json(); })
                .then(() => { alert("Calcul terminé !"); location.reload(); })
                .catch(err => { alert(`Erreur: ${err.message}`); button.disabled = false; button.textContent = '⚡ Calculer et Envoyer'; });
            };
            actions.appendChild(addArticleBtn); actions.appendChild(calculateBtn); header.appendChild(actions);
            const statutBadge = `<span class="gce-badge gce-color-${devis.Status?.color || 'gray'}">${devis.Status?.value || 'N/A'}</span>`;
            const details = document.createElement('div');
            details.className = 'gce-devis-details';
            details.innerHTML = `<p><strong>Opportunité:</strong> ${oppLink}</p><p><strong>Statut:</strong> ${statutBadge}</p>`;
            const articlesContainer = document.createElement('div');
            articlesContainer.className = 'gce-devis-articles-container';
            articlesContainer.innerHTML = '<h4>Articles</h4>';
            const tableDiv = document.createElement('div');
            articlesContainer.appendChild(tableDiv);
            container.appendChild(header); container.appendChild(details); container.appendChild(articlesContainer);

            const articlesSchema = window.gceSchemas.articles_devis;
            if (!articlesSchema) {
                container.innerHTML = "<p>Erreur: le schéma des articles n'a pas pu être chargé.</p>";
                return container;
            }

            const articlesColumns = getTabulatorColumnsFromSchema(articlesSchema, 'articles_devis');
            
            // Forçons l'éditeur pour les champs critiques pour être sûrs
            articlesColumns.forEach(col => {
                if (col.field === 'Quantités' || col.field === 'Prix_unitaire') {
                    console.log(`Forçage de l'éditeur pour la colonne: ${col.field}`);
                    col.editor = "input"; // Utilise l'éditeur texte de base de Tabulator
                }
            });
            articlesColumns.push({
                title: "Actions",
                headerSort: false, width: 100, hozAlign: "center",
                formatter: (cell) => {
                    const rowData = cell.getRow().getData();
                    const editIcon = `<a href="#" class="gce-popup-link" data-id="${rowData.id}" data-table="articles_devis" data-mode="ecriture" title="Modifier">✏️</a>`;
                    const deleteIcon = `<a href="#" class="gce-delete-article-btn" title="Supprimer">❌</a>`;
                    return `${editIcon}   ${deleteIcon}`;
                },
                cellClick: (e, cell) => {
                    if (e.target.closest('.gce-delete-article-btn')) {
                        e.preventDefault();
                        const rowData = cell.getRow().getData();
                        if (confirm(`Supprimer "${rowData.Nom || 'cet article'}" ?`)) {
                            fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/articles_devis/${rowData.id}`, { method: 'DELETE', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } })
                            .then(res => {
                                if (!res.ok) throw new Error('Échec de la suppression.');
                                refreshDevisData(devis.id);
                            }).catch(err => alert(err.message));
                        }
                    }
                }
            });

            // ===================================================================
            // ==                 LA CORRECTION EST CI-DESSOUS                  ==
            // ===================================================================
             const subTable = new Tabulator(tableDiv, {
                data: devis._children || [],
                layout: "fitColumns",
                columns: articlesColumns,
                placeholder: "Aucun article dans ce devis.",
                // On retire cellEdited d'ici !
            });

            // Étape 2 : Attacher l'événement en utilisant la méthode .on()
            subTable.on("cellEdited", function(cell) {
                console.log("✅ cellEdited DÉCLENCHÉ CORRECTEMENT !", cell.getField(), cell.getValue());
                const rowData = cell.getRow().getData();
                const cleaned = sanitizeRowBeforeSave(rowData, articlesSchema);
                showStatusUpdate('Sauvegarde de l\'article...', true);

                fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/articles_devis/${rowData.id}`, {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': EECIE_CRM.nonce },
                    body: JSON.stringify(cleaned)
                })
                .then(res => {
                    if (!res.ok) throw new Error("La sauvegarde a échoué.");
                    return refreshDevisData(devis.id);
                })
                .catch(err => {
                    showStatusUpdate(`Erreur: ${err.message}`, false);
                    cell.restoreOldValue();
                });
            });
            // ===================================================================
            // ==                   FIN DE LA CORRECTION                      ==
            // ===================================================================
            
            return container;
        }
    };

    Promise.all([
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/devis', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/articles_devis', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/devis/schema', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/articles_devis/schema', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } })
    ]).then(async ([devisData, articlesData, devisSchema, articlesSchemaResponse]) => {
        const articlesSchema = await articlesSchemaResponse.json();
        window.gceSchemas = { ...window.gceSchemas, "devis": devisSchema, "articles_devis": articlesSchema };
        const devis = devisData.results || [];
        const articles = articlesData.results || [];
        const groupedArticles = {};
        articles.forEach(art => {
            if (Array.isArray(art.Devis)) {
                art.Devis.forEach(link => {
                    if (!groupedArticles[link.id]) groupedArticles[link.id] = [];
                    groupedArticles[link.id].push(art);
                });
            }
        });
        const devisAvecChildren = devis.map(d => ({ ...d, _children: groupedArticles[d.id] || [] }));
        gce.viewManager.initialize(mainContainer, devisAvecChildren, devisViewConfig);
    }).catch(err => {
        mainContainer.innerHTML = `<p style="color:red;">Erreur: ${err.message}</p>`;
    });
});