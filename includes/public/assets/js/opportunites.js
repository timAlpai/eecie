// FICHIER : includes/public/assets/js/opportunites.js (VERSION FINALE AVEC FILTRES INTÉGRÉS)

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
            // On ajoute une classe pour le ciblage et une classe spécifique au statut pour le filtrage
            card.className = 'gce-opportunite-card';
            card.dataset.status = opportunite.Status?.value || 'N/A'; // On stocke le statut ici !

            const statut = opportunite.Status?.value || 'N/A';
            const statutColor = opportunite.Status?.color || 'gray';
            const contactName = opportunite.Contacts?.[0]?.value || 'Non spécifié';
            const ville = opportunite.Ville || 'N/A';
            const progression = parseInt(opportunite.Progression, 10) || 0;
            let progressLevel = 'low';
            if (progression > 66) progressLevel = 'high';
            else if (progression > 33) progressLevel = 'medium';

            let footerContent = '';
            if (statut.toLowerCase() === 'assigner') {
                footerContent = `
                    <button class="gce-card-accept-btn" data-id="${opportunite.id}" data-action="accept">
                        ✅ Accepter le dossier
                    </button>
                `;
            } else {
                const nbTaches = opportunite._children?.length || 0;
                let derniereInteraction = 'Aucune';
                if (opportunite['Dernière Interaction']) {
                    derniereInteraction = luxon.DateTime.fromISO(opportunite['Dernière Interaction']).toRelative({ locale: 'fr' });
                }
                footerContent = `
                    <span>📋 ${nbTaches} tâche(s)</span>
                    <span>🕒 Dern. interaction : ${derniereInteraction}</span>
                `;
            }

            card.innerHTML = `
                <div class="gce-opportunite-card-header">
                    <h4>${opportunite.NomClient || 'Opportunité sans nom'}</h4>
                    <span class="gce-badge gce-color-${statutColor}">${statut}</span>
                </div>
                <div class="gce-opportunite-card-body">
                    <p>👤 <strong>Contact :</strong> ${contactName}</p>
                    <p>📍 <strong>Ville :</strong> ${ville}</p>
                    <div class="gce-progress-bar-container" style="margin-top: 10px;">
                        <div class="gce-progress-bar-fill" style="width: ${progression}%;" data-progress-level="${progressLevel}">
                             ${progression}%
                        </div>
                    </div>
                </div>
                <div class="gce-opportunite-card-footer">
                    ${footerContent}
                </div>
            `;
            return card;
        },
        detailRenderer: (opportunite) => {
            // ... (cette fonction reste identique à votre version)
            const container = document.createElement('div');
            container.className = 'gce-appel-card'; 
            const progression = parseInt(opportunite.Progression, 10) || 0;
            let progressLevel = 'low';
            if (progression > 66) progressLevel = 'high';
            else if (progression > 33) progressLevel = 'medium';
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
                    </div>
                </div>
                <div class="gce-appel-details">
                    <p><strong>Contact:</strong> ${contactLink}</p>
                    <p><strong>Chargé de projet:</strong> ${employeLink}</p>
                    <p><strong>Ville:</strong> ${opportunite.Ville || 'N/A'}</p>
                    <p><strong>Statut:</strong> ${statutBadge}</p>
                    <p style="margin-top: 15px;"><strong>Progression :</strong></p>
                    <div class="gce-progress-bar-container">
                        <div class="gce-progress-bar-fill" style="width: ${progression}%;" data-progress-level="${progressLevel}">
                            ${progression}%
                        </div>
                    </div>
                    <h4 style="margin-top: 20px;">Description des travaux</h4>
                    ${travauxHtml}
                </div>
                <div class="gce-appel-interactions-container">
                    <h4>Tâches associées</h4>
                    <div class="sub-table-container"></div>
                </div>
            `;
            
            container.querySelector('.gce-add-task-btn').addEventListener('click', () => {
                const popupData = {
                    opportunite: [{ id: opportunite.id, value: opportunite.NomClient }],
                    contact: opportunite.Contacts || [],
                    assigne: opportunite.T1_user || [],
                    statut: { id: 3039, value: 'Creation' }
                };
                gceShowModal(popupData, "taches", "ecriture");
            });

            const tableDiv = container.querySelector('.sub-table-container');
            const tachesSchema = window.gceSchemas.taches;
            
            const colonnesVisibles = ['titre', 'statut', 'priorite', 'date_echeance'];
            const tachesColumns = getTabulatorColumnsFromSchema(tachesSchema, 'taches')
                .filter(col => colonnesVisibles.includes(col.field));

            tachesColumns.unshift({
                title: "Actions",
                headerSort: false,
                width: 100,
                hozAlign: "center",
                formatter: (cell) => {
                    const rowData = cell.getRow().getData();
                    const statut = (rowData.statut?.value || '').toLowerCase();
                    if (statut === 'creation') return `<button class="button button-small gce-task-action-btn" data-action="accept">✅ Accepter</button>`;
                    if (statut === 'en_cours') return `<button class="button button-small gce-task-action-btn" data-action="complete">🏁 Terminer</button>`;
                    return "";
                },
                cellClick: async (e, cell) => {
                    const actionBtn = e.target.closest('.gce-task-action-btn');
                    if (!actionBtn) return;

                    const action = actionBtn.dataset.action;
                    const rowData = cell.getRow().getData();
                    actionBtn.disabled = true;
                    actionBtn.textContent = '...';

                    if (action === 'accept') {
                        try {
                            const res = await fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/proxy/start-task', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': EECIE_CRM.nonce },
                                body: JSON.stringify(rowData)
                            });
                            if (!res.ok) throw new Error('Échec de la requête.');
                            
                            // Mettre à jour l'UI localement
                            const statutEnCours = tachesSchema.find(f=>f.name==='statut').select_options.find(o=>o.value==='En_cours');
                            cell.getRow().update({ statut: statutEnCours });
                            showStatusUpdate('Tâche acceptée !', true);

                        } catch (err) {
                            showStatusUpdate('Erreur lors de l’acceptation.', false);
                            actionBtn.disabled = false;
                            actionBtn.textContent = '✅ Accepter';
                        }
                    } else if (action === 'complete') {
                        try {
                            const statutTerminer = tachesSchema.find(f=>f.name==='statut').select_options.find(o=>o.value==='Terminer');
                            const payload = {
                                [`field_${tachesSchema.find(f=>f.name==='statut').id}`]: statutTerminer.id,
                                [`field_${tachesSchema.find(f=>f.name==='terminer').id}`]: true
                            };

                            const res = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/taches/${rowData.id}`, {
                                method: 'PATCH',
                                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': EECIE_CRM.nonce },
                                body: JSON.stringify(payload)
                            });
                            if (!res.ok) throw new Error('Échec de la requête.');
                            
                            // Mettre à jour l'UI localement
                            cell.getRow().update({ statut: statutTerminer, terminer: true });
                            showStatusUpdate('Tâche terminée !', true);

                        } catch (err) {
                            showStatusUpdate('Erreur lors de la finalisation.', false);
                            actionBtn.disabled = false;
                            actionBtn.textContent = '🏁 Terminer';
                        }
                    }
                }
            });
             new Tabulator(tableDiv, { data: opportunite._children || [], layout: "fitColumns", columns: tachesColumns, placeholder: "Aucune tâche associée." });
            
            return container;
        }
    };
    /**
     * Vérifie si l'URL contient un paramètre pour ouvrir un modal au chargement.
     * CETTE FONCTION EST MAINTENANT APPELÉE AU BON MOMENT.
     */
    function handleAutoOpenFromUrl() {
        const urlParams = new URLSearchParams(window.location.search);
        const oppIdToOpen = urlParams.get('open-opp-id');

        if (oppIdToOpen) {
            const oppIdInt = parseInt(oppIdToOpen, 10);
            console.log(`[AutoOpen] Tentative d'ouverture du modal pour l'opportunité #${oppIdInt}`);

            const opportuniteData = gce.viewManager.dataStore.find(opp => opp.id === oppIdInt);
            
            if (opportuniteData) {
                console.log(`[AutoOpen] Opportunité trouvée. Ouverture du modal.`);
                gce.showDetailModal(opportuniteData, opportunitesViewConfig.detailRenderer);
            } else {
                console.warn(`[AutoOpen] Opportunité #${oppIdInt} non trouvée dans les données de l'utilisateur.`);
                alert(`L'opportunité #${oppIdInt} n'a pas pu être trouvée ou ne vous est pas assignée.`);
            }
        }
    }
    // 2. Chargement de toutes les données nécessaires
    Promise.all([
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/opportunites', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/taches', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/opportunites/schema', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/taches/schema', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json())
    ]).then(([oppData, tachesData, oppSchema, tachesSchema]) => {
        
        window.gceSchemas = { ...window.gceSchemas, "opportunites": oppSchema, "taches": tachesSchema };
        
        const myOpps = oppData.results || [];

        const tachesByOppId = {};
        (tachesData || []).forEach(tache => {
            const oppLink = tache.opportunite?.[0];
            if (oppLink) {
                if (!tachesByOppId[oppLink.id]) tachesByOppId[oppLink.id] = [];
                tachesByOppId[oppLink.id].push(tache);
            }
        });
        const myOppsWithChildren = myOpps.map(opp => ({ ...opp, _children: tachesByOppId[opp.id] || [] }));

        gce.viewManager.initialize(mainContainer, myOppsWithChildren, opportunitesViewConfig);
        handleAutoOpenFromUrl();

// --- MODIFIEZ CETTE SECTION ---
const filterContainer = document.getElementById('gce-status-filters');
if (filterContainer) {
    const allCheckboxes = filterContainer.querySelectorAll('input[type="checkbox"]');
    
    // La logique de filtrage quand une case change
    const applyFilters = () => {
        const selectedStatuses = Array.from(filterContainer.querySelectorAll('input:checked'))
            .map(checkbox => checkbox.value);

        const allCards = mainContainer.querySelectorAll('.gce-opportunite-card');

        allCards.forEach(card => {
            const cardStatus = card.dataset.status;
            if (selectedStatuses.length === 0 || selectedStatuses.includes(cardStatus)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    };
    
    filterContainer.addEventListener('change', applyFilters);

    // --- DÉBUT DE L'AJOUT POUR LES BOUTONS D'ACTION ---
    const selectAllBtn = document.getElementById('gce-filter-select-all');
    const clearAllBtn = document.getElementById('gce-filter-clear-all');

    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', (e) => {
            e.preventDefault();
            allCheckboxes.forEach(cb => cb.checked = true);
            // On déclenche manuellement l'événement pour que le filtre s'applique
            filterContainer.dispatchEvent(new Event('change'));
        });
    }

    if (clearAllBtn) {
        clearAllBtn.addEventListener('click', (e) => {
            e.preventDefault();
            allCheckboxes.forEach(cb => cb.checked = false);
            // On déclenche manuellement l'événement pour que le filtre s'applique
            filterContainer.dispatchEvent(new Event('change'));
        });
    }
    // --- FIN DE L'AJOUT POUR LES BOUTONS D'ACTION ---
}
        // --- FIN DE LA LOGIQUE DE FILTRAGE AJOUTÉE ---

    }).catch(err => {
        mainContainer.innerHTML = `<p style="color:red;">Erreur de chargement : ${err.message}</p>`;
        console.error(err);
    });
    // --- NOUVEAU GESTIONNAIRE D'ÉVÉNEMENTS POUR LE BOUTON "ACCEPTER" ---
mainContainer.addEventListener('click', async (e) => {
        const acceptBtn = e.target.closest('[data-action="accept"]');
        if (!acceptBtn) return;

        e.preventDefault();
        e.stopPropagation();

        const oppId = acceptBtn.dataset.id;
        acceptBtn.disabled = true;
        acceptBtn.textContent = '...';

        const oppSchema = window.gceSchemas.opportunites;
        const statusField = oppSchema.find(f => f.name === "Status");
        const traitementOption = statusField?.select_options.find(opt => opt.value === "Traitement");

        if (!traitementOption) {
            alert("Erreur: L'option de statut 'Traitement' est introuvable.");
            acceptBtn.disabled = false;
            acceptBtn.textContent = '✅ Accepter le dossier';
            return;
        }

        const payload = { [`field_${statusField.id}`]: traitementOption.id };

        try {
            // 1. Déclencher le workflow en mettant à jour le statut
            const initialRes = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/opportunites/${oppId}`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': EECIE_CRM.nonce },
                body: JSON.stringify(payload)
            });

            if (!initialRes.ok) throw new Error('Le déclenchement du workflow a échoué.');

            showStatusUpdate('Dossier accepté, synchronisation des données...', true);

            // 2. Attendre 2 secondes pour laisser le temps à N8N de s'exécuter
            await new Promise(resolve => setTimeout(resolve, 2000));

            // 3. Récupérer les données fraîches en parallèle pour plus d'efficacité
            const [updatedOppRes, tachesRes] = await Promise.all([
                fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/row/opportunites/${oppId}`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }),
                fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/taches', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } })
            ]);

            if (!updatedOppRes.ok || !tachesRes.ok) throw new Error("Échec de la récupération des données mises à jour.");

            const updatedOppData = await updatedOppRes.json();
            const allTachesData = await tachesRes.json();
            const oppIdInt = parseInt(oppId, 10);
            
            // 4. Reconstruire l'objet complet avec les nouvelles tâches
            // Note: l'endpoint /taches renvoie directement un tableau, pas un objet {results:[]}
            updatedOppData._children = (allTachesData || []).filter(tache =>
                tache.opportunite?.[0]?.id === oppIdInt
            );

            // 5. Mettre à jour la carte avec les données 100% à jour
            gce.viewManager.updateItem(oppIdInt, updatedOppData);
            showStatusUpdate('Synchronisation terminée !', true);

        } catch (err) {
            console.error("Erreur lors du processus d'acceptation :", err);
            showStatusUpdate("Une erreur est survenue. L'interface peut être désynchronisée.", false);
            // On ne réactive pas le bouton pour éviter les doubles clics sur une erreur
            acceptBtn.textContent = 'Erreur';
        }
    });
});

