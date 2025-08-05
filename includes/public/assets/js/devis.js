// Fichier : includes/public/assets/js/devis.js (VERSION FINALE - LAZY LOADING - SANS AUCUNE RÉGRESSION)

// La fonction de rafraîchissement est toujours utile et ne change pas.
async function refreshDevisData(devisId) {
    try {
        showStatusUpdate('Synchronisation...', true);
        const res = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/row/devis/${devisId}`, { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } });
        if (!res.ok) throw new Error("Impossible de recharger les données du devis.");
        const updatedDevisFromServer = await res.json();
        
        gce.viewManager.updateItem(devisId, updatedDevisFromServer);
        gce.viewManager.updateDetailModalIfOpen(devisId, updatedDevisFromServer); // Met à jour le modal s'il est ouvert

        showStatusUpdate('Données synchronisées !', true);
    } catch (err) {
        showStatusUpdate(`Erreur de synchronisation: ${err.message}`, false);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const mainContainer = document.getElementById('gce-devis-table');
    if (!mainContainer) return;
    mainContainer.innerHTML = 'Chargement des devis...';
    function handleAutoOpenFromUrl() {
        const urlParams = new URLSearchParams(window.location.search);
        const devisIdToOpen = urlParams.get('open-devis-id');
        const modeToOpen = urlParams.get('open-mode') || 'lecture'; // 'ecriture' si spécifié

        if (devisIdToOpen) {
            const devisIdInt = parseInt(devisIdToOpen, 10);
            // On cherche le devis dans les données déjà chargées par le viewManager
            const devisData = gce.viewManager.dataStore.find(d => d.id === devisIdInt);

            if (devisData) {
                // On utilise gceShowModal (de popup-handler.js) pour ouvrir le modal d'édition
                gceShowModal(devisData, 'devis', modeToOpen);
                
                // On nettoie l'URL pour éviter que le modal ne se rouvre à chaque rafraîchissement
                const newUrl = new URL(window.location);
                newUrl.searchParams.delete('open-devis-id');
                newUrl.searchParams.delete('open-mode');
                window.history.replaceState({}, document.title, newUrl.toString());
            } else {
                alert(`Le devis #${devisIdToOpen} est introuvable ou ne vous est pas assigné.`);
            }
        }
    }
    const devisViewConfig = {
        summaryRenderer: (devis) => {
            // Cette fonction est 100% identique à votre version, aucune modification.
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
            // CETTE FONCTION EST MAINTENANT COMPLÈTE ET GÈRE LE LAZY LOADING
            const container = document.createElement('div');
            container.className = 'gce-devis-card';

            // PARTIE 1 : Construction de l'interface du modal (sans la table).
            // Ce code est une copie exacte de votre version.
            const oppLink = `<a href="#" class="gce-popup-link" data-table="opportunites" data-id="${devis.Task_input?.[0]?.id}">${devis.Task_input?.[0]?.value || ''}</a>`;
            const header = document.createElement('div');
            header.className = 'gce-devis-header';
            header.innerHTML = `<h3>Détails du Devis #${devis.DevisId}</h3>`;
            const actions = document.createElement('div');
            actions.style.display = 'flex';
            actions.style.gap = '10px';
            const addArticleBtn = document.createElement('button');
            addArticleBtn.className = 'button';
            addArticleBtn.textContent = '➕ Article';
            addArticleBtn.onclick = () => {
                const schema = window.gceSchemas.articles_devis;
                const champDevis = schema.find(f => f.name === "Devis");
                const popupData = champDevis ? { "Devis": [{ id: devis.id, value: `Devis #${devis.DevisId}` }] } : {};
                gceShowModal(popupData, "articles_devis", "ecriture", ["Nom", "Quantités", "Prix_unitaire"]);
            };
            const editDevisBtn = document.createElement('button');
            editDevisBtn.className = 'button button-secondary gce-popup-link';
            editDevisBtn.textContent = '✏️ Modifier Devis';
            editDevisBtn.dataset.table = 'devis';
            editDevisBtn.dataset.id = devis.id;
            editDevisBtn.dataset.mode = 'ecriture';
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
            actions.appendChild(addArticleBtn);
            actions.appendChild(editDevisBtn);
            actions.appendChild(calculateBtn);
            header.appendChild(actions);
            const statutBadge = `<span class="gce-badge gce-color-${devis.Status?.color || 'gray'}">${devis.Status?.value || 'N/A'}</span>`;
            const details = document.createElement('div');
            details.className = 'gce-devis-details';
            details.innerHTML = `<p><strong>Opportunité:</strong> ${oppLink}</p><p><strong>Statut:</strong> ${statutBadge}</p>`;
            const fournisseursHtml = Array.isArray(devis.Fournisseur) && devis.Fournisseur.length > 0
                ? devis.Fournisseur.map(f => `<a href="#" class="gce-popup-link" data-table="fournisseurs" data-id="${f.id}">${f.value}</a>`).join(', ')
                : '<i>Aucun fournisseur assigné</i>';
            details.innerHTML += `<p><strong>Fournisseur(s) :</strong> ${fournisseursHtml}</p>`;
            const articlesContainer = document.createElement('div');
            articlesContainer.className = 'gce-devis-articles-container';
            articlesContainer.innerHTML = '<h4>Articles</h4>';
            const tableDiv = document.createElement('div');
            articlesContainer.appendChild(tableDiv);
            container.appendChild(header);
            container.appendChild(details);
            container.appendChild(articlesContainer);

            // PARTIE 2 : Lazy Loading des articles
            tableDiv.innerHTML = '<p>Chargement des articles...</p>';
            (async () => {
                try {
                    const res = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/devis/${devis.id}/articles`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } });
                    if (!res.ok) throw new Error('Le chargement des articles a échoué.');
                    
                    const articles = await res.json();
                    
                    const articlesSchema = window.gceSchemas.articles_devis;
                    const articlesColumns = getTabulatorColumnsFromSchema(articlesSchema, 'articles_devis');
                    
                    // On rajoute la colonne d'actions COMPLÈTE
                    articlesColumns.push({
                        title: "Actions", headerSort: false, width: 100, hozAlign: "center",
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
                                    .then(res => { if (!res.ok) throw new Error('Échec de la suppression.'); refreshDevisData(devis.id); })
                                    .catch(err => alert(err.message));
                                }
                            }
                        }
                    });

                    const subTable = new Tabulator(tableDiv, {
                        data: articles,
                        layout: "fitColumns",
                        columns: articlesColumns,
                        placeholder: "Aucun article dans ce devis.",
                    });
                    
                    // On attache le handler pour l'édition en ligne
                    subTable.on("cellEdited", function(cell) {
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
                            // Plutôt que de recharger toute la page, on rafraîchit les données du devis parent
                            return refreshDevisData(devis.id); 
                        })
                        .catch(err => {
                            showStatusUpdate(`Erreur: ${err.message}`, false);
                            cell.restoreOldValue();
                        });
                    });

                } catch (err) {
                    tableDiv.innerHTML = `<p style="color:red;">${err.message}</p>`;
                }
            })();

            return container;
        }
    };

    // Le chargement initial est maintenant optimisé ET correct
    Promise.all([
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/devis', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/fournisseurs', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/devis/schema', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/articles_devis/schema', { cache: 'no-cache', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json())
    ]).then(([devisData, fournisseursData, devisSchema, articlesSchema]) => {
        
        window.gceSchemas = { ...window.gceSchemas, "devis": devisSchema, "articles_devis": articlesSchema };
        window.gceDataCache = { ...window.gceDataCache, fournisseurs: fournisseursData.results || [] };
        
        const devisList = devisData.results || [];
        
        gce.viewManager.initialize(mainContainer, devisList, devisViewConfig);
         handleAutoOpenFromUrl();
    }).catch(err => {
        mainContainer.innerHTML = `<p style="color:red;">Erreur: ${err.message}</p>`;
    });
});