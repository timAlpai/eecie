// Fichier : includes/public/assets/js/appels.js (VERSION FINALE AVEC MODAL DYNAMIQUE)

document.addEventListener('DOMContentLoaded', () => {
    const mainContainer = document.getElementById('gce-appels-table');
    if (!mainContainer) return;
    mainContainer.innerHTML = 'Chargement du flux de travail...';

    // 1. Définir la configuration de la vue pour les "Appels"
    const appelsViewConfig = {
        
        // Fonction pour dessiner la carte de résumé (inchangée)
        summaryRenderer: (appel) => {
            const card = document.createElement('div');
            card.className = 'gce-appel-summary-card'; 
            const oppName = appel.Opportunité?.[0]?.value || 'Opportunité inconnue';
            const contactName = appel.Contact?.[0]?.value || 'Non spécifié';
            const statutAppel = appel.Appel_result?.value || 'N/A';
            const statutColor = appel.Appel_result?.color || 'gray';
            card.innerHTML = `
                <h4>${oppName}</h4>
                <p><strong>Contact:</strong> ${contactName}</p>
                <p><strong>Statut:</strong> <span class="gce-badge gce-color-${statutColor}">${statutAppel}</span></p>
            `;
            return card;
        },

        // Fonction pour dessiner la vue détaillée dans le modal (mise à jour majeure)
        detailRenderer: (appel) => {
            const container = document.createElement('div');
            container.className = 'gce-appel-card';

            const oppLink = `<a href="#" class="gce-popup-link" data-table="opportunites" data-id="${appel.Opportunité?.[0]?.id}">${appel.Opportunité?.[0]?.value || ''}</a>`;
            const contactLink = `<a href="#" class="gce-popup-link" data-table="contacts" data-id="${appel.Contact?.[0]?.id}">${appel.Contact?.[0]?.value || ''}</a>`;
            const employeLink = `<a href="#" class="gce-popup-link" data-table="utilisateurs" data-id="${appel.Employé?.[0]?.id}">${appel.Employé?.[0]?.value || ''}</a>`;
            const statutBadge = `<span class="gce-badge gce-color-${appel.Appel_result?.color || 'gray'}">${appel.Appel_result?.value || 'N/A'}</span>`;
            
            // --- CORRECTION : CRÉATION DU HEADER ET DU BOUTON ---
            const header = document.createElement('div');
            header.className = 'gce-appel-header';
            header.innerHTML = `<h3>Dossier: ${oppLink}</h3>`;
            
            const addButton = document.createElement('button');
            addButton.className = 'button button-primary'; // Style plus visible
            addButton.textContent = '➕ Interaction';
            addButton.onclick = () => { 
                const popupData = {};
                const interactionsSchema = window.gceSchemas.interactions;
                const appelsTableId = window.gceSchemas.appels[0].table_id;
                const linkField = interactionsSchema.find(f => f.type === 'link_row' && f.link_row_table_id === appelsTableId);
                if (linkField) popupData[linkField.name] = [{ id: appel.id, value: `Appel #${appel.id}` }];
                if (appel.Opportunité) popupData.opportunité = appel.Opportunité;
                if (appel.Employé) popupData.effectue_par = appel.Employé;
                if (appel.Contact) popupData.contact = appel.Contact;
                const typeField = interactionsSchema.find(f => f.name === 'types_interactions');
                if(typeField) {
                    const option = typeField.select_options.find(o => o.value === 'Appel Telephonique');
                    if(option) popupData[typeField.name] = { id: option.id, value: option.value };
                }
                const dateField = interactionsSchema.find(f => f.name === 'date_heure');
                if(dateField) {
                    const now = new Date();
                    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
                    popupData[dateField.name] = now.toISOString().slice(0,16);
                }
                gceShowModal(popupData, "interactions", "ecriture");
            };
            header.appendChild(addButton);
            // --- FIN CORRECTION ---

            const details = document.createElement('div');
            details.className = 'gce-appel-details';
            details.innerHTML = `
                <p><strong>Contact:</strong> ${contactLink}</p>
                <p><strong>Chargé de projet:</strong> ${employeLink}</p>
                <p><strong>Statut du dossier:</strong> ${statutBadge}</p>
            `;
            
            const interactionsContainer = document.createElement('div');
            interactionsContainer.className = 'gce-appel-interactions-container';
            const tableDiv = document.createElement('div');
            interactionsContainer.appendChild(tableDiv);

            container.appendChild(header);
            container.appendChild(details);
            container.appendChild(interactionsContainer);

            // --- CORRECTION : INITIALISATION DE TABULATOR DANS LE MODAL ---
            const interactionColumns = getTabulatorColumnsFromSchema(window.gceSchemas["interactions"], 'interactions');
            interactionColumns.push({
                title: "Actions", headerSort: false, width: 80, hozAlign: "center",
                formatter: (cell) => {
                    const rowData = cell.getRow().getData();
                    const editIcon = `<a href="#" class="gce-popup-link" data-id="${rowData.id}" data-table="interactions" data-mode="ecriture" title="Modifier">✏️</a>`;
                    const deleteIcon = `<a href="#" class="gce-delete-interaction-btn" data-id="${rowData.id}" title="Supprimer">❌</a>`;
                    return `${editIcon}   ${deleteIcon}`;
                }
            });

            const interactionsTable = new Tabulator(tableDiv, {
                data: appel._children || [],
                layout: "fitColumns",
                columns: interactionColumns,
                placeholder: "Aucune interaction pour ce dossier.",
                cellEdited: function(cell) {
                    const rowData = cell.getRow().getData();
                    const cleaned = sanitizeRowBeforeSave(rowData, window.gceSchemas.interactions);

                    fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/interactions/${rowData.id}`, {
                        method: 'PATCH',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': EECIE_CRM.nonce },
                        body: JSON.stringify(cleaned)
                    })
                    .then(res => {
                        if (!res.ok) throw new Error("La sauvegarde a échoué.");
                        console.log("Interaction mise à jour. Rechargement...");
                        // Recharger la page est la solution la plus simple pour garantir la fraîcheur de toutes les données.
                        location.reload();
                    })
                    .catch(err => {
                        alert("Erreur de sauvegarde: " + err.message);
                        location.reload(); // Recharger même en cas d'erreur pour annuler le changement visuel.
                    });
                }
            });
            
            return container;
        }
    };

    // Charger les données et initialiser la vue (inchangé)
    Promise.all([
        fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/appels/schema`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/interactions/schema`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/appels`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json())
    ]).then(([appelsSchema, interactionsSchema, appelsData]) => {
        window.gceSchemas = { ...window.gceSchemas, "appels": appelsSchema, "interactions": interactionsSchema };
        gce.initializeCardView(mainContainer, appelsData, appelsViewConfig);
    }).catch(err => {
        mainContainer.innerHTML = `<p style="color:red;">Erreur: ${err.message}</p>`;
    });

    // Gestionnaire délégué pour les boutons de suppression (inchangé)
    document.body.addEventListener('click', (e) => {
        const deleteBtn = e.target.closest('.gce-delete-interaction-btn');
        if (deleteBtn) {
            e.preventDefault();
            const interactionId = deleteBtn.dataset.id;
            if (confirm(`Supprimer l'interaction #${interactionId} ?`)) {
                fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/interactions/${interactionId}`, {
                    method: 'DELETE',
                    headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
                }).then(res => {
                    if (!res.ok) throw new Error('Échec de la suppression.');
                    location.reload();
                }).catch(err => alert(err.message));
            }
        }
    });
});