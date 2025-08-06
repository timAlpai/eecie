// FICHIER : includes/public/assets/js/opportunites.js (VERSION FINALE, COMPLÈTE ET VÉRIFIÉE)

document.addEventListener('DOMContentLoaded', () => {
    const mainContainer = document.getElementById('gce-opportunites-table');
    if (!mainContainer) return;

    mainContainer.innerHTML = 'Chargement des opportunités...';
    const userEmail = window.GCE_CURRENT_USER?.email;
    if (!userEmail) {
        mainContainer.innerHTML = '<p>Utilisateur non identifié.</p>';
        return;
    }

    const opportunitesViewConfig = {
        summaryRenderer: (opportunite) => {
            const card = document.createElement('div');
            card.className = 'gce-opportunite-card';
            card.dataset.status = opportunite.Status?.value || 'N/A';
            const statut = opportunite.Status?.value || 'N/A';
            const progression = parseInt(opportunite.Progression, 10) || 0;
            const resetCount = opportunite.Reset_count || 0;
            let resetVisual = resetCount > 0 ? `<span title="${resetCount} réinitialisation(s)" style="font-size: 0.8em; color: #e67e22; margin-left: auto;">🔄 ${resetCount}</span>` : '';
            const resetButton = `<button class="gce-card-reset-btn" data-id="${opportunite.id}" title="Réinitialiser le dossier" style="background: #e74c3c; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 0.8em;">Reset</button>`;
            const taskCount = opportunite['Compte Tache'] || 0;
            card.innerHTML = `
            <div class="gce-opportunite-card-header">
                <h4>${opportunite.NomClient || 'Opportunité sans nom'}</h4>
                <div style="display: flex; align-items: center; gap: 10px;">
                    ${resetVisual}
                    <span class="gce-badge gce-color-${opportunite.Status?.color || 'gray'}">${statut}</span>
                </div>
            </div>
            <div class="gce-opportunite-card-body">
                <p>👤 <strong>Contact :</strong> ${opportunite.Contacts?.[0]?.value || 'Non spécifié'}</p>
                <p>📍 <strong>Ville :</strong> ${opportunite.Ville || 'N/A'}</p>
                <div class="gce-progress-bar-container" style="margin-top: 10px;">
                    <div class="gce-progress-bar-fill" style="width: ${progression}%;" data-progress-level="high">${progression}%</div>
                </div>
            </div>
            <div class="gce-opportunite-card-footer">
                ${statut.toLowerCase() === 'assigner' ? `<button class="gce-card-accept-btn" data-id="${opportunite.id}" data-action="accept">✅ Accepter</button>` : `<span>📋 ${taskCount} tâche(s)</span>`}
                ${resetButton}
            </div>`;
            return card;
        },
        detailRenderer: (opportunite) => {
            const container = document.createElement('div');
            container.className = 'gce-appel-card';
            const progression = parseInt(opportunite.Progression, 10) || 0;
            const contactLink = opportunite.Contacts?.[0] ? `<a href="#" class="gce-popup-link" data-table="contacts" data-id="${opportunite.Contacts[0].id}">${opportunite.Contacts[0].value}</a>` : 'Non spécifié';
            const employeLink = opportunite.T1_user?.[0] ? `<a href="#" class="gce-popup-link" data-table="utilisateurs" data-id="${opportunite.T1_user[0].id}">${opportunite.T1_user[0].value}</a>` : 'Non assigné';
            const statutBadge = `<span class="gce-badge gce-color-${opportunite.Status?.color || 'gray'}">${opportunite.Status?.value || 'N/A'}</span>`;
            const travauxHtml = opportunite.Travaux ? `<div style="margin-top:10px; padding:10px; background-color:#f9f9f9; border-radius:4px;">${opportunite.Travaux}</div>` : '<p><i>Aucune description des travaux.</i></p>';

            container.innerHTML = `
                <div class="gce-appel-header">
                    <h3>Dossier: ${opportunite.NomClient}</h3>
                    <div class="header-actions" style="display: flex; gap: 10px;">
                        <button class="button button-secondary gce-popup-link" data-table="opportunites" data-id="${opportunite.id}" data-mode="ecriture">✏️ Modifier</button>
                        <button class="button button-primary gce-add-task-btn">➕ Tâche</button>
                        <button class="button gce-add-interaction-btn" disabled title="Un appel doit exister pour ajouter une interaction.">➕ Interaction</button>
                    </div>
                </div>
                <div class="gce-appel-details">
                    <p><strong>Contact:</strong> ${contactLink}</p>
                    <p><strong>Chargé de projet:</strong> ${employeLink}</p>
                    <p><strong>Ville:</strong> ${opportunite.Ville || 'N/A'}</p>
                    <p><strong>Statut:</strong> ${statutBadge}</p>
                    <p style="margin-top: 15px;"><strong>Progression :</strong></p>
                    <div class="gce-progress-bar-container">
                        <div class="gce-progress-bar-fill" style="width: ${progression}%;">${progression}%</div>
                    </div>
                    <h4 style="margin-top: 20px;">Description des travaux</h4>
                    ${travauxHtml}
                </div>
                <div class="gce-appel-interactions-container">
                    <h4>Tâches associées</h4>
                    <div class="sub-table-container-taches"><p>Chargement...</p></div>
                </div>
                <div class="gce-appel-interactions-container">
                    <h4>Interactions associées</h4>
                    <div class="sub-table-container-interactions"><p>Chargement...</p></div>
                </div>
                <!-- AJOUTER CETTE NOUVELLE DIV -->
                 <div class="gce-appel-interactions-container">
                    <h4>Devis associé</h4>
                    <div class="sub-table-container-devis"><p>Chargement...</p></div>
                </div>
            `;

            container.querySelector('.gce-add-task-btn').addEventListener('click', () => {
                gceShowModal({ opportunite: [{ id: opportunite.id, value: opportunite.NomClient }], contact: opportunite.Contacts || [], assigne: opportunite.T1_user || [], statut: { id: 3039, value: 'Creation' } }, "taches", "ecriture");
            });

            (async () => {
                try {
                    const res = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/opportunites/${opportunite.id}/related-data`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } });
                    if (!res.ok) throw new Error('Échec du chargement des données.');
                    const relatedData = await res.json();

                    const addInteractionBtn = container.querySelector('.gce-add-interaction-btn');
                    const existingCall = relatedData.appels && relatedData.appels.length > 0 ? relatedData.appels[0] : null;
                    if (existingCall) {
                        addInteractionBtn.disabled = false;
                        addInteractionBtn.title = "Ajouter une nouvelle interaction à l'appel existant";
                        addInteractionBtn.addEventListener('click', () => {
                            gceShowModal({ opportunité: [{ id: opportunite.id, value: opportunite.NomClient }], contact: opportunite.Contacts || [], effectue_par: opportunite.T1_user || [], Appels: [{ id: existingCall.id, value: `Appel #${existingCall.id}` }] }, "interactions", "ecriture");
                        });
                    }

                    const tableTachesDiv = container.querySelector('.sub-table-container-taches');
                    tableTachesDiv.innerHTML = '';
                    const tachesSchema = window.gceSchemas.taches;
                    let tachesColumns = getTabulatorColumnsFromSchema(tachesSchema, 'taches');

                    tachesColumns.unshift({
                        title: "Actions", headerSort: false, width: 100, hozAlign: "center",
                        formatter: (cell) => {
                            const statut = (cell.getRow().getData().statut?.value || '').toLowerCase();
                            if (statut === 'creation') return `<button class="button button-small gce-task-action-btn" data-action="accept">✅ Accepter</button>`;
                            if (statut === 'en_cours') return `<button class="button button-small gce-task-action-btn" data-action="complete">🏁 Terminer</button>`;
                            return "";
                        },
                        cellClick: async (e, cell) => {
                            const actionBtn = e.target.closest('.gce-task-action-btn');
                            if (!actionBtn) return;
                            const action = actionBtn.dataset.action;
                            const rowData = cell.getRow().getData();
                            actionBtn.disabled = true; actionBtn.textContent = '...';
                            const oppId = rowData.opportunite[0].id;

                            try {
                                if (action === 'accept') {
                                    const res = await fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/proxy/start-task', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': EECIE_CRM.nonce }, body: JSON.stringify(rowData) });
                                    if (!res.ok) throw new Error('Le workflow "Accepter" a échoué.');
                                    showStatusUpdate('Tâche acceptée, synchronisation...', true);
                                } else if (action === 'complete') {
                                    const statutTerminer = tachesSchema.find(f => f.name === 'statut').select_options.find(o => o.value === 'Terminer');
                                    const payload = { [`field_${tachesSchema.find(f => f.name === 'statut').id}`]: statutTerminer.id, [`field_${tachesSchema.find(f => f.name === 'terminer').id}`]: true };
                                    const res = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/taches/${rowData.id}`, { method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': EECIE_CRM.nonce }, body: JSON.stringify(payload) });
                                    if (!res.ok) throw new Error('La mise à jour de la tâche a échoué.');
                                    showStatusUpdate('Tâche terminée, synchronisation...', true);
                                }

                                await new Promise(resolve => setTimeout(resolve, 2500));
                                const updatedOppRes = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/row/opportunites/${oppId}`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } });
                                if (!updatedOppRes.ok) throw new Error("Échec de la récupération de l'opportunité.");
                                const updatedOppData = await updatedOppRes.json();

                                gce.viewManager.updateItem(oppId, updatedOppData);
                                gce.viewManager.updateDetailModalIfOpen(oppId, updatedOppData);

                                showStatusUpdate('Vue mise à jour !', true);
                            } catch (err) {
                                showStatusUpdate(`Erreur: ${err.message}`, false);
                                actionBtn.disabled = false;
                                actionBtn.textContent = action === 'accept' ? '✅ Accepter' : '🏁 Terminer';
                            }
                        }
                    });

                    const visibleTaskColumns = ['Actions', 'titre', 'description', 'statut', 'priorite', 'date_echeance'];
                    tachesColumns.forEach(col => { if (!visibleTaskColumns.includes(col.field) && col.title !== 'Actions') { col.visible = false; } });

                    setTimeout(() => {
                        const tachesTable = new Tabulator(tableTachesDiv, {
                            data: relatedData.taches || [],
                            layout: "fitColumns", columns: tachesColumns, placeholder: "Aucune tâche.",
                        });
                        tachesTable.on("cellEdited", function (cell) {
                            const rowData = cell.getRow().getData();
                            const cleanedData = sanitizeRowBeforeSave(rowData, tachesSchema);
                            showStatusUpdate('Sauvegarde...');
                            fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/taches/${rowData.id}`, { method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': EECIE_CRM.nonce }, body: JSON.stringify(cleanedData) })
                                .then(res => { if (!res.ok) { cell.restoreOldValue(); throw new Error("Sauvegarde échouée."); } return res.json(); })
                                .then(updatedTask => { showStatusUpdate('Tâche mise à jour !', true); cell.getRow().update(updatedTask); })
                                .catch(err => { showStatusUpdate(`Erreur: ${err.message}`, false); });
                        });
                    }, 50);
                    const tableInteractionsDiv = container.querySelector('.sub-table-container-interactions');
                    tableInteractionsDiv.innerHTML = '';
                    const interactionsSchema = window.gceSchemas.interactions;

                    // On définit les colonnes que l'on veut voir.
                    const visibleInteractionFields = ['types_interactions', 'date_heure', 'resultat', 'compte_rendu'];
                    let interactionsColumns = getTabulatorColumnsFromSchema(interactionsSchema, 'interactions')
                        .filter(c => visibleInteractionFields.includes(c.field));

                    // On ajoute notre colonne d'actions personnalisée.
                    interactionsColumns.push({
                        title: "Actions",
                        headerSort: false,
                        width: 100,
                        hozAlign: "center",
                        formatter: (cell) => {
                            const rowData = cell.getRow().getData();
                            if (rowData.Lock === true) return "🔒";
                            const editIcon = `<a href="#" class="gce-popup-link" data-id="${rowData.id}" data-table="interactions" data-mode="ecriture" title="Modifier">✏️</a>`;
                            const deleteIcon = `<a href="#" class="gce-delete-interaction-btn" data-id="${rowData.id}" title="Supprimer">❌</a>`;
                            return `${editIcon}   ${deleteIcon}`;
                        },
                        cellClick: (e, cell) => {
                            const deleteBtn = e.target.closest('.gce-delete-interaction-btn');
                            if (deleteBtn) {
                                e.preventDefault();
                                const rowData = cell.getRow().getData();
                                if (confirm(`Êtes-vous sûr de vouloir supprimer cette interaction ?`)) {
                                    showStatusUpdate('Suppression en cours...');
                                    fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/interactions/${rowData.id}`, { method: 'DELETE', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } })
                                        .then(async res => {
                                            if (!res.ok) throw new Error('Échec de la suppression.');
                                            showStatusUpdate('Interaction supprimée ! Mise à jour...', true);
                                            const oppId = parseInt(container.closest('.gce-detail-modal').dataset.itemId, 10);
                                            const oppRes = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/row/opportunites/${oppId}`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } });
                                            if (oppRes.ok) {
                                                const updatedOppData = await oppRes.json();
                                                gce.viewManager.updateDetailModalIfOpen(oppId, updatedOppData);
                                            } else {
                                                location.reload();
                                            }
                                        })
                                        .catch(err => alert(`Erreur: ${err.message}`));
                                }
                            }
                        }
                    });
                    setTimeout(() => {
                        new Tabulator(tableInteractionsDiv, {
                            data: relatedData.interactions || [],
                            layout: "fitColumns",
                            columns: interactionsColumns,
                            placeholder: "Aucune interaction."
                        });
                    }, 50);
                    const tableDevisDiv = container.querySelector('.sub-table-container-devis');
                    tableDevisDiv.innerHTML = ''; // Vider le message de chargement
                    const devisSchema = window.gceSchemas.devis;
                    const articlesSchema = window.gceSchemas.articles_devis;

                    if (relatedData.devis && relatedData.devis.length > 0) {
                        const devis = relatedData.devis[0]; // On prend le premier devis trouvé
                        const articles = relatedData.articles || [];

                        // Afficher les détails du devis et les boutons d'action
                        tableDevisDiv.innerHTML = `
                        <div class="devis-header" style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 10px; margin-bottom: 10px; border-bottom: 1px solid #eee;">
                            <div>
                                <strong>Statut :</strong> <span class="gce-badge gce-color-${devis.Status?.color || 'gray'}">${devis.Status?.value || 'N/A'}</span>
                                <strong style="margin-left: 15px;">Total HT :</strong> ${parseFloat(devis.Montant_total_ht || 0).toFixed(2)} $
                            </div>
                            <div class="devis-actions" style="display: flex; gap: 10px;">
                                <button class="button button-secondary gce-assign-fournisseur-btn" data-devis-id="${devis.id}">🚚 Assigner Fournisseur</button>
                                <button class="button gce-add-article-btn">➕ Article</button>
                                <button class="button button-primary gce-calculate-devis-btn">⚡ Calculer</button>
                            </div>
                        </div>
                        <div class="articles-sub-table"></div>
                    `;

                        // Logique du bouton "Ajouter Article"
                        container.querySelector('.gce-add-article-btn').addEventListener('click', () => {
                            gceShowModal({ "Devis": [{ id: devis.id, value: `Devis #${devis.DevisId}` }] }, "articles_devis", "ecriture", ["Nom", "Quantités", "Prix_unitaire"]);
                        });
                        container.querySelector('.gce-assign-fournisseur-btn').addEventListener('click', (e) => {
                            e.preventDefault();
                            const devisId = e.target.dataset.devisId;

                            // On a déjà les données complètes du devis dans la variable 'devis'
                            console.log(`Ouverture du popup pour assigner un fournisseur au devis #${devisId}`);

                            // On appelle gceShowModal en lui demandant d'afficher UNIQUEMENT le champ "Fournisseur"
                            gceShowModal(devis, 'devis', 'ecriture', ['Fournisseur']);
                        });
                        // Logique du bouton "Calculer"
                        container.querySelector('.gce-calculate-devis-btn').addEventListener('click', async (e) => {
                            const btn = e.target;
                            const oppId = opportunite.id;
                            btn.disabled = true; btn.textContent = 'Calcul...';
                            showStatusUpdate('Calcul du devis en cours...', true);
                            try {
                                const res = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/proxy/calculate-devis`, {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': EECIE_CRM.nonce },
                                    body: JSON.stringify({ devis_id: devis.id })
                                });
                                if (!res.ok) throw new Error('Échec du calcul.');
                                await res.json();
                                showStatusUpdate('Calcul terminé ! Synchronisation...', true);

                                await new Promise(resolve => setTimeout(resolve, 4000)); // Délai plus long

                                const updatedOppRes = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/row/opportunites/${oppId}`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } });
                                if (!updatedOppRes.ok) throw new Error("Échec de la récupération de l'opportunité.");
                                const updatedOppData = await updatedOppRes.json();
                                gce.viewManager.updateItem(oppId, updatedOppData);
                                gce.viewManager.updateDetailModalIfOpen(oppId, updatedOppData);
                                showStatusUpdate('Vue mise à jour !', true);
                            } catch (err) {
                                showStatusUpdate(`Erreur: ${err.message}`, false);
                            } finally { btn.disabled = false; btn.textContent = '⚡ Calculer'; }
                        });

                        // Création de la sous-table des articles

                        const articlesTableDiv = container.querySelector('.articles-sub-table');
                        // On construit manuellement les colonnes pour avoir un contrôle total
                        let articlesColumns = getTabulatorColumnsFromSchema(articlesSchema, 'articles_devis')
                            .filter(col => col.field !== 'Devis'); // On exclut la colonne "Devis"

                        // On ajoute la colonne d'actions à la fin
                        articlesColumns.push({
                            title: "Actions",
                            headerSort: false,
                            width: 100,
                            hozAlign: "center",
                            formatter: (cell) => {
                                const rowData = cell.getRow().getData();
                                const editIcon = `<a href="#" class="gce-popup-link" data-id="${rowData.id}" data-table="articles_devis" data-mode="ecriture" title="Modifier">✏️</a>`;
                                const deleteIcon = `<a href="#" class="gce-delete-article-btn" data-id="${rowData.id}" title="Supprimer">❌</a>`;
                                return `${editIcon}   ${deleteIcon}`;
                            },
                            cellClick: (e, cell) => {
                                // On gère le clic sur le bouton de suppression
                                const deleteBtn = e.target.closest('.gce-delete-article-btn');
                                if (deleteBtn) {
                                    e.preventDefault();
                                    const rowData = cell.getRow().getData();

                                    // Fenêtre de confirmation
                                    if (confirm(`Êtes-vous sûr de vouloir supprimer l'article "${rowData.Nom || 'cet article'}" ?`)) {
                                        showStatusUpdate('Suppression en cours...');
                                        fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/articles_devis/${rowData.id}`, {
                                            method: 'DELETE',
                                            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
                                        })
                                            .then(async res => {
                                                if (!res.ok) throw new Error('Échec de la suppression.');
                                                showStatusUpdate('Article supprimé !', true);

                                                const oppId = parseInt(container.closest('.gce-detail-modal').dataset.itemId, 10);
                                                const oppRes = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/row/opportunites/${oppId}`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } });
                                                if (oppRes.ok) {
                                                    const updatedOppData = await oppRes.json();
                                                    gce.viewManager.updateDetailModalIfOpen(oppId, updatedOppData);
                                                } else {
                                                    location.reload(); // Fallback
                                                }
                                            })
                                            .catch(err => {
                                                alert(`Erreur: ${err.message}`);
                                            });
                                    }
                                }
                            }
                        });
                        const articlesTable = new Tabulator(articlesTableDiv, {
                            data: articles,
                            layout: "fitColumns",
                            columns: articlesColumns,
                            placeholder: "Aucun article dans ce devis."
                        });

                        // Activer l'édition en ligne pour les articles
                        articlesTable.on("cellEdited", function (cell) {
                            const rowData = cell.getRow().getData();
                            const cleanedData = sanitizeRowBeforeSave(rowData, articlesSchema);
                            showStatusUpdate('Sauvegarde article...');
                            fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/articles_devis/${rowData.id}`, {
                                method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': EECIE_CRM.nonce }, body: JSON.stringify(cleanedData)
                            })
                                .then(res => { if (!res.ok) { cell.restoreOldValue(); throw new Error("Sauvegarde échouée."); } return res.json(); })
                                .then(updatedArticle => { showStatusUpdate('Article mis à jour !', true); cell.getRow().update(updatedArticle); })
                                .catch(err => { showStatusUpdate(`Erreur: ${err.message}`, false); });
                        });

                    } else {
                        tableDevisDiv.innerHTML = '<p>Aucun devis n\'a encore été créé pour cette opportunité.</p>';
                    }
                } catch (err) { container.querySelector('.sub-table-container-taches').innerHTML = `<p style="color:red;">${err.message}</p>`; }
            })();
            return container;
        }
    };

    function handleAutoOpenFromUrl() {
        const urlParams = new URLSearchParams(window.location.search);
        const oppIdToOpen = urlParams.get('open-opp-id');
        if (oppIdToOpen) {
            const oppIdInt = parseInt(oppIdToOpen, 10);
            const opportuniteData = gce.viewManager.dataStore.find(opp => opp.id === oppIdInt);
            if (opportuniteData) { gce.showDetailModal(opportuniteData, opportunitesViewConfig.detailRenderer); }
            else { alert(`L'opportunité #${oppIdToOpen} n'a pas pu être trouvée.`); }
        }
    }

    Promise.all([
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/opportunites', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/opportunites/schema', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/taches/schema', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/appels/schema', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/interactions/schema', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        // AJOUTER CES DEUX LIGNES
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/devis/schema', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/articles_devis/schema', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/fournisseurs', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json())
    ]).then(([oppData, oppSchema, tachesSchema, appelsSchema, interactionsSchema, devisSchema, articlesDevisSchema, fournisseursData]) => {// Ajouter les nouvelles variables ici
        window.gceSchemas = {
            ...window.gceSchemas,
            "opportunites": oppSchema,
            "taches": tachesSchema,
            "appels": appelsSchema,
            "interactions": interactionsSchema,
            "devis": devisSchema, // Ajouter ici
            "articles_devis": articlesDevisSchema, // Ajouter ici

        };
        // NOUVEAU : On met les données des fournisseurs dans le cache global
        window.gceDataCache = {
            ...window.gceDataCache,
            fournisseurs: fournisseursData.results || []
        };
        const myOpps = oppData.results || [];
        gce.viewManager.initialize(mainContainer, myOpps, opportunitesViewConfig);
        handleAutoOpenFromUrl();
        const filterContainer = document.getElementById('gce-status-filters');
        if (filterContainer) {
            const allCheckboxes = filterContainer.querySelectorAll('input[type="checkbox"]');
            const applyFilters = () => {
                const selectedStatuses = Array.from(filterContainer.querySelectorAll('input:checked')).map(checkbox => checkbox.value);
                mainContainer.querySelectorAll('.gce-opportunite-card').forEach(card => { card.style.display = selectedStatuses.includes(card.dataset.status) ? '' : 'none'; });
            };
            filterContainer.addEventListener('change', applyFilters);
            applyFilters();
            document.getElementById('gce-filter-select-all')?.addEventListener('click', e => { e.preventDefault(); allCheckboxes.forEach(cb => cb.checked = true); filterContainer.dispatchEvent(new Event('change')); });
            document.getElementById('gce-filter-clear-all')?.addEventListener('click', e => { e.preventDefault(); allCheckboxes.forEach(cb => cb.checked = false); filterContainer.dispatchEvent(new Event('change')); });
        }
    }).catch(err => { mainContainer.innerHTML = `<p style="color:red;">Erreur : ${err.message}</p>`; console.error(err); });

    mainContainer.addEventListener('click', async (e) => {
        const acceptBtn = e.target.closest('[data-action="accept"]');
        if (!acceptBtn) return;
        e.preventDefault(); e.stopPropagation();
        const oppId = acceptBtn.dataset.id;
        acceptBtn.disabled = true; acceptBtn.textContent = '...';
        const statusField = window.gceSchemas.opportunites.find(f => f.name === "Status");
        const traitementOption = statusField?.select_options.find(opt => opt.value === "Traitement");
        if (!traitementOption) { alert("Erreur: Statut 'Traitement' introuvable."); acceptBtn.disabled = false; acceptBtn.textContent = '✅ Accepter'; return; }
        const payload = { [`field_${statusField.id}`]: traitementOption.id };
        try {
            const res = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/opportunites/${oppId}`, { method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': EECIE_CRM.nonce }, body: JSON.stringify(payload) });
            if (!res.ok) throw new Error('Échec du workflow.');
            showStatusUpdate('Dossier accepté, synchronisation...', true);
            await new Promise(resolve => setTimeout(resolve, 2000));
            const updatedOppRes = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/row/opportunites/${oppId}`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } });
            if (!updatedOppRes.ok) throw new Error("Échec de la récupération.");
            const updatedOppData = await updatedOppRes.json();
            gce.viewManager.updateItem(parseInt(oppId, 10), updatedOppData);
            showStatusUpdate('Synchronisation terminée !', true);
        } catch (err) { showStatusUpdate("Une erreur est survenue.", false); acceptBtn.textContent = 'Erreur'; }
    });

    mainContainer.addEventListener('click', async (e) => {
        const resetBtn = e.target.closest('.gce-card-reset-btn');
        if (!resetBtn) return;
        e.preventDefault(); e.stopPropagation();
        const oppId = resetBtn.dataset.id;
        if (!confirm(`ATTENTION !\n\nRéinitialiser l'opportunité #${oppId} supprimera TOUS les devis, appels, tâches et interactions liés de manière IRRÉVERSIBLE. Continuer ?`)) return;
        resetBtn.disabled = true; resetBtn.textContent = '...';
        showStatusUpdate('Réinitialisation en cours...', true);
        try {
            const res = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/opportunites/${oppId}/reset`, { method: 'POST', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } });
            if (!res.ok) { const errData = await res.json(); throw new Error(errData.message || `Erreur HTTP ${res.status}`); }
            showStatusUpdate('Réinitialisation réussie ! Rechargement...', true);
            setTimeout(() => location.reload(), 1500);
        } catch (err) { showStatusUpdate(`Erreur: ${err.message}`, false); resetBtn.disabled = false; resetBtn.textContent = 'Reset'; }
    });
});
