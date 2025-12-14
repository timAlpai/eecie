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
            let contactHtml;
            const contact = opportunite.Contacts?.[0];

            if (contact) {
                // Lien pour voir le contact (lecture) + bouton pour l'éditer (ecriture)
                contactHtml = `
        <span style="display: inline-flex; align-items: center; gap: 10px;">
            <a href="#" class="gce-popup-link" data-table="contacts" data-id="${contact.id}" data-mode="ecriture">${contact.value}</a>
            <button class="button button-small gce-popup-link" data-table="contacts" data-id="${contact.id}" data-mode="ecriture" title="Modifier ce contact">✏️</button>
        </span>`;
            } else {
                contactHtml = '<i>Non spécifié</i>';
            } const employeLink = opportunite.T1_user?.[0] ? `<a href="#" class="gce-popup-link" data-table="utilisateurs" data-id="${opportunite.T1_user[0].id}">${opportunite.T1_user[0].value}</a>` : 'Non assigné';
            const statutBadge = `<span class="gce-badge gce-color-${opportunite.Status?.color || 'gray'}">${opportunite.Status?.value || 'N/A'}</span>`;
            const travauxHtml = opportunite.Travaux ? `<div style="margin-top:10px; padding:10px; background-color:#f9f9f9; border-radius:4px;">${opportunite.Travaux}</div>` : '<p><i>Aucune description des travaux.</i></p>';

            container.innerHTML = `
                <div class="gce-appel-header">
                    <h3>Dossier: ${opportunite.NomClient}</h3>
                    <div class="header-actions" style="display: flex; gap: 10px;">
                        <button class="button button-secondary gce-popup-link" data-table="opportunites" data-id="${opportunite.id}" data-mode="ecriture">✏️ Modifier</button>
                        <button class="button button-primary gce-add-task-btn">➕ Tâche</button>
                        <button class="button gce-reassign-provider-btn">🔄 Réassigner Fournisseur</button>
                        <button class="button gce-transfer-opp-btn">👤 Transférer</button>
                        <button class="button gce-add-interaction-btn" disabled title="Un appel doit exister pour ajouter une interaction.">➕ Interaction</button>
                    </div>
                </div>
                <div class="gce-appel-details">
                    <p><strong>Contact:</strong> ${contactHtml}</p>
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
                    const transferBtn = container.querySelector('.gce-transfer-opp-btn');
                    transferBtn.addEventListener('click', () => {
                        const currentOwnerId = opportunite.T1_user?.[0]?.id;

                        // On filtre la liste pour ne pas pouvoir se transférer à soi-même
                        const userOptions = (window.gceDataCache.utilisateurs || [])
                            .filter(user => user.id !== currentOwnerId)
                            .map(user => `<option value="${user.id}">${user.Name}</option>`)
                            .join('');

                        // Créer un modal simple pour la sélection
                        const modalContent = document.createElement('div');
                        modalContent.style.padding = '20px';
                        modalContent.innerHTML = `
        <h4>Transférer le dossier à</h4>
        <select id="gce-new-owner-select" style="width: 100%; margin-bottom: 15px;">
            <option value="">-- Choisir un chargé de projet --</option>
            ${userOptions}
        </select>
        <button id="gce-confirm-transfer" class="button button-primary">Confirmer le Transfert</button>
    `;

                        const overlay = document.createElement('div');
                        overlay.className = 'gce-modal-overlay';
                        const modal = document.createElement('div');
                        modal.className = 'gce-modal';
                        modal.appendChild(modalContent);
                        overlay.appendChild(modal);
                        document.body.appendChild(overlay);

                        const closeModal = () => overlay.remove();
                        overlay.addEventListener('click', e => { if (e.target === overlay) closeModal(); });

                        document.getElementById('gce-confirm-transfer').addEventListener('click', async () => {
                            const newUserId = document.getElementById('gce-new-owner-select').value;
                            if (!newUserId) {
                                alert('Veuillez sélectionner un nouveau responsable.');
                                return;
                            }

                            if (!confirm('Êtes-vous sûr de vouloir transférer ce dossier et toutes ses tâches actives ? Cette action est irréversible.')) {
                                return;
                            }

                            showStatusUpdate('Transfert en cours...', true);
                            closeModal();

                            try {
                                const res = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/opportunites/${opportunite.id}/transfer`, {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': EECIE_CRM.nonce },
                                    body: JSON.stringify({ new_user_id: parseInt(newUserId, 10) })
                                });

                                if (!res.ok) {
                                    const errData = await res.json();
                                    throw new Error(errData.message || 'Le serveur a retourné une erreur.');
                                }

                                showStatusUpdate('Dossier transféré avec succès ! La page va se rafraîchir.', true, 4000);

                                // On ferme le modal de détail qui est en arrière-plan
                                const detailModal = document.querySelector('.gce-detail-modal-overlay');
                                if (detailModal) detailModal.remove();

                                // On rafraîchit la vue principale après un court délai
                                setTimeout(() => location.reload(), 1500);

                            } catch (err) {
                                showStatusUpdate(`Erreur : ${err.message}`, false, 5000);
                            }
                        });
                    });
                    const reassignBtn = container.querySelector('.gce-reassign-provider-btn');
                    reassignBtn.addEventListener('click', () => {
                        // Créer le HTML du modal de sélection
                        const modalContent = document.createElement('div');
                        modalContent.style.padding = '20px';

                        const fournisseurOptions = (window.gceDataCache.fournisseurs || [])
                            .map(f => `<option value="${f.id}">${f.Nom}</option>`)
                            .join('');

                        modalContent.innerHTML = `
        <h4>Sélectionnez le nouveau fournisseur</h4>
        <select id="gce-new-provider-select" style="width: 100%; margin-bottom: 15px;">
            <option value="">-- Choisir un fournisseur --</option>
            ${fournisseurOptions}
        </select>
        <button id="gce-confirm-reassign" class="button button-primary">Confirmer la réassignation</button>
    `;

                        // Afficher le modal (on peut utiliser une simple alerte ou un vrai modal; ici un modal simple)
                        const overlay = document.createElement('div');
                        overlay.className = 'gce-modal-overlay';
                        const modal = document.createElement('div');
                        modal.className = 'gce-modal';
                        modal.appendChild(modalContent);
                        overlay.appendChild(modal);
                        document.body.appendChild(overlay);

                        const closeModal = () => overlay.remove();
                        overlay.addEventListener('click', e => { if (e.target === overlay) closeModal(); });

                        document.getElementById('gce-confirm-reassign').addEventListener('click', async () => {
                            const select = document.getElementById('gce-new-provider-select');
                            const newProviderId = select.value;
                            if (!newProviderId) {
                                alert('Veuillez sélectionner un fournisseur.');
                                return;
                            }

                            if (!confirm('Confirmez-vous la réassignation ? Un nouveau courriel de planification sera envoyé.')) {
                                return;
                            }

                            showStatusUpdate('Réassignation en cours...', true);
                            closeModal();

                            try {
                                const res = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/opportunites/${opportunite.id}/reassign-provider`, {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': EECIE_CRM.nonce },
                                    body: JSON.stringify({ new_provider_id: parseInt(newProviderId, 10) })
                                });

                                if (!res.ok) {
                                    const errData = await res.json();
                                    throw new Error(errData.message || 'Le serveur a retourné une erreur.');
                                }

                                showStatusUpdate('Fournisseur réassigné ! Le workflow est relancé.', true, 5000);

                                // Rafraîchir la vue pour afficher le nouveau fournisseur
                                setTimeout(() => location.reload(), 2000);

                            } catch (err) {
                                showStatusUpdate(`Erreur : ${err.message}`, false, 5000);
                            }
                        });
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
                        // 1. On crée une variable pour contenir le HTML du lien PDF
                        let pdfLinkHtml = '';

                        // 2. On vérifie si le champ 'File' du devis contient des données
                        if (devis.File && Array.isArray(devis.File) && devis.File.length > 0) {
                            const pdfFile = devis.File[0]; // On prend le premier fichier
                            // 3. On construit le bouton avec le lien
                            pdfLinkHtml = `
                                <a href="${pdfFile.url}" target="_blank" class="button" title="${pdfFile.visible_name}">
                                    📄 Voir le PDF
                                </a>`;
                        }
                        sendDraftButtonHtml = `<button class="button button-primary gce-send-draft-btn" data-devis-id="${devis.id}">📧 Envoyer au client</button>`;
                        const paiementImmediat = devis.Paiement_Immediat === true;
                        const paiementSelectHtml = `
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <label for="payment-type-select-${devis.id}" style="font-weight:bold;">Type de Paiement :</label>
                                <select id="payment-type-select-${devis.id}" class="gce-payment-type-select" data-devis-id="${devis.id}">
                                    <option value="true" ${paiementImmediat ? 'selected' : ''}>Immédiat</option>
                                    <option value="false" ${!paiementImmediat ? 'selected' : ''}>Différé (Net 21)</option>
                                </select>
                            </div>
                        `;
                        // 4. On injecte la variable (qui sera soit le bouton, soit une chaîne vide)
                        tableDevisDiv.innerHTML = `
                        <div>
                                <strong>Statut :</strong> <span class="gce-badge gce-color-${devis.Status?.color || 'gray'}">${devis.Status?.value || 'N/A'}</span>
                                <strong style="margin-left: 15px;">Total HT :</strong> ${parseFloat(devis.Montant_total_ht || 0).toFixed(2)} $
                                ${paiementSelectHtml}
                            </div>
                        <div class="devis-header" style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 10px; margin-bottom: 10px; border-bottom: 1px solid #eee;">
                            
                            <div class="devis-actions" style="display: flex; gap: 10px;">
                             
                                ${pdfLinkHtml} <!-- NOTRE NOUVEAU BOUTON EST INJECTÉ ICI -->
                                <button class="button button-secondary gce-assign-fournisseur-btn" data-devis-id="${devis.id}">🚚 Assigner Fournisseur</button>
                                <button class="button gce-add-article-btn">➕ Article</button>
                                <button class="button button-primary gce-calculate-devis-btn">⚡ Calculer</button>
                                 ${sendDraftButtonHtml}
                            </div>
                        </div>
                        <div class="articles-sub-table"></div>
                    `;
                        //const paymentSelect = container.querySelector('.gce-payment-type-select');
                        const paymentSelect = tableDevisDiv.querySelector('.gce-payment-type-select');
                        if (paymentSelect) {
                            paymentSelect.addEventListener('change', async (e) => {
                                const devisId = e.target.dataset.devisId;
                                const isImmediat = e.target.value === 'true';
                                showStatusUpdate('Mise à jour du type de paiement...', true);

                                // On cherche l'ID du champ "Paiement_Immediat" dans le schéma. ID: 7407
                                const paiementFieldId = 7407;

                                const payload = {
                                    [`field_${paiementFieldId}`]: isImmediat
                                };

                                try {
                                    const res = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/devis/${devisId}`, {
                                        method: 'PATCH',
                                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': EECIE_CRM.nonce },
                                        body: JSON.stringify(payload)
                                    });
                                    if (!res.ok) throw new Error('La sauvegarde a échoué.');
                                    showStatusUpdate('Type de paiement mis à jour !', true);
                                } catch (err) {
                                    showStatusUpdate(`Erreur : ${err.message}`, false);
                                    // En cas d'erreur, on remet la valeur initiale
                                    e.target.value = !isImmediat;
                                }
                            });
                        }

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

                        const sendDraftBtn = container.querySelector('.gce-send-draft-btn');
                        if (sendDraftBtn) {
                            sendDraftBtn.addEventListener('click', async (e) => {
                                const btn = e.target;
                                const devisId = btn.dataset.devisId;

                                if (!confirm(`Êtes-vous sûr de vouloir envoyer ce devis au client ?\n\n`)) {
                                    return;
                                }

                                btn.disabled = true;
                                btn.textContent = 'Envoi...';
                                showStatusUpdate('Transmission de la demande d\'envoi...', true);

                                try {
                                    const res = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/devis/${devisId}/send-draft`, {
                                        method: 'POST',
                                        headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
                                    });

                                    if (!res.ok) {
                                        const errorData = await res.json();
                                        throw new Error(errorData.message || 'Le serveur a retourné une erreur.');
                                    }

                                    showStatusUpdate('Devis envoyé !', true);

                                    // On attend quelques secondes pour laisser le temps à n8n de mettre à jour le statut
                                    await new Promise(resolve => setTimeout(resolve, 3000));

                                    const oppId = opportunite.id;
                                    const updatedOppRes = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/row/opportunites/${oppId}`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } });
                                    if (updatedOppRes.ok) {
                                        const updatedOppData = await updatedOppRes.json();
                                        gce.viewManager.updateItem(oppId, updatedOppData);
                                        gce.viewManager.updateDetailModalIfOpen(oppId, updatedOppData);
                                    } else {
                                        location.reload(); // Solution de secours
                                    }

                                } catch (err) {
                                    showStatusUpdate(`Erreur: ${err.message}`, false);
                                    btn.disabled = false;
                                    btn.textContent = '📧 Envoyer au client';
                                }
                            });
                        }

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
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/fournisseurs', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/utilisateurs', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json())
    ]).then(([oppData, oppSchema, tachesSchema, appelsSchema, interactionsSchema, devisSchema, articlesDevisSchema, fournisseursData, utilisateursData]) => {// Ajouter les nouvelles variables ici
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
            fournisseurs: fournisseursData.results || [],
            utilisateurs: utilisateursData.results || []
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
