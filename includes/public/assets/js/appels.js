// Fichier : includes/public/assets/js/appels.js (VERSION FINALE CORRIGÉE)

/**
 * Configure et retourne les colonnes pour la table des interactions DANS LE MODAL.
 */
function getInteractionColumnsConfig() {
    // La fonction de vérification lit maintenant directement le champ "Lock".
    const isRowLocked = (cell) => cell.getRow().getData().Lock === true;

    return [
        { formatter: "responsiveCollapse", width: 30, minWidth: 30, hozAlign: "center", resizable: false, headerSort: false },
        { title: "Type", field: "types_interactions", widthGrow: 1.5, minWidth: 120, responsive: 0, 
          formatter: (cell) => { const val = cell.getValue(); if(!val) return ''; return `<span class="gce-badge gce-color-${val.color}">${val.value}</span>`; },
          editable: (cell) => !isRowLocked(cell)
        },
        { title: "Date", field: "date_heure", width: 150, responsive: 0, formatter: "datetime", formatterParams: { outputFormat: "dd/MM/yy HH:mm" },
          editable: (cell) => !isRowLocked(cell)
        },
        { title: "Compte Rendu", field: "compte_rendu", formatter: "html", widthGrow: 2,
          cellClick: (e, cell) => { if (!isRowLocked(cell)) gceShowModal(cell.getRow().getData(), "interactions", "ecriture"); }
        },
        { title: "Résultat", field: "resultat", widthGrow: 1, minWidth: 100, responsive: 0,
          formatter: (cell) => { const val = cell.getValue(); if(!val) return ''; return `<span class="gce-badge gce-color-${val.color}">${val.value}</span>`; },
          editable: (cell) => !isRowLocked(cell)
        },
        { title: "Qualité", field: "Quality", hozAlign: "center", width: 100,
          formatter: (cell) => { const value = cell.getValue() || 0; let stars = ''; for (let i = 1; i <= 5; i++) { stars += `<span style="color: ${i <= value ? '#f5b041' : '#ccc'}; font-size: 1.1em;">★</span>`; } return stars; },
          editable: (cell) => !isRowLocked(cell)
        },
        { title: "Actions", field: "actions", responsive: 0, headerSort: false, width: 80, hozAlign: "center",
          formatter: (cell) => {
              if (isRowLocked(cell)) return "🔒";
              const rowData = cell.getRow().getData();
              const editIcon = `<a href="#" class="gce-popup-link" data-id="${rowData.id}" data-table="interactions" data-mode="ecriture" title="Modifier">✏️</a>`;
              const deleteIcon = `<a href="#" class="gce-delete-interaction-btn" data-id="${rowData.id}" title="Supprimer">❌</a>`;
              return `${editIcon}   ${deleteIcon}`;
          }
        },
        { title: "ID", field: "id", visible: false }
    ];
}

document.addEventListener('DOMContentLoaded', () => {
    const mainContainer = document.getElementById('gce-appels-table');
    if (!mainContainer) return;
    mainContainer.innerHTML = 'Chargement du flux de travail...';

    const appelsViewConfig = {
        summaryRenderer: (appel) => { /* Code inchangé */
            const card = document.createElement('div');
            card.className = 'gce-appel-summary-card';
            const oppName = appel.Opportunité?.[0]?.value || 'Opportunité inconnue';
            const contactName = appel.Contact?.[0]?.value || 'Non spécifié';
            const statutAppel = appel.Appel_result?.value || 'N/A';
            const statutColor = appel.Appel_result?.color || 'gray';
            card.innerHTML = `<h4>${oppName}</h4><p><strong>Contact:</strong> ${contactName}</p><p><strong>Statut:</strong> <span class="gce-badge gce-color-${statutColor}">${statutAppel}</span></p>`;
            return card;
        },
        detailRenderer: (appel) => {
            const container = document.createElement('div');
            container.className = 'gce-appel-card';

            // --- Header & Details (code inchangé) ---
            const oppLink = `<a href="#" class="gce-popup-link" data-table="opportunites" data-id="${appel.Opportunité?.[0]?.id}">${appel.Opportunité?.[0]?.value || ''}</a>`;
            const header = document.createElement('div');
            header.className = 'gce-appel-header';
            header.innerHTML = `<h3>Dossier: ${oppLink}</h3>`;
            const addButton = document.createElement('button');
            addButton.className = 'button button-primary';
            addButton.textContent = '➕ Interaction';
            addButton.onclick = () => {  const popupData = {}; const interactionsSchema = window.gceSchemas.interactions; const appelsTableId = window.gceSchemas.appels[0].table_id; const linkField = interactionsSchema.find(f => f.type === 'link_row' && f.link_row_table_id === appelsTableId); if (linkField) popupData[linkField.name] = [{ id: appel.id, value: `Appel #${appel.id}` }]; if (appel.Opportunité) popupData.opportunité = appel.Opportunité; if (appel.Employé) popupData.effectue_par = appel.Employé; if (appel.Contact) popupData.contact = appel.Contact; const typeField = interactionsSchema.find(f => f.name === 'types_interactions'); if(typeField) { const option = typeField.select_options.find(o => o.value === 'Appel Telephonique'); if(option) popupData[typeField.name] = { id: option.id, value: option.value }; } const dateField = interactionsSchema.find(f => f.name === 'date_heure'); if(dateField) { const now = new Date(); now.setMinutes(now.getMinutes() - now.getTimezoneOffset()); popupData[dateField.name] = now.toISOString().slice(0,16); } gceShowModal(popupData, "interactions", "ecriture"); };
            header.appendChild(addButton);
            const contactLink = `<a href="#" class="gce-popup-link" data-table="contacts" data-id="${appel.Contact?.[0]?.id}">${appel.Contact?.[0]?.value || ''}</a>`;
            const employeLink = `<a href="#" class="gce-popup-link" data-table="utilisateurs" data-id="${appel.Employé?.[0]?.id}">${appel.Employé?.[0]?.value || ''}</a>`;
            const statutBadge = `<span class="gce-badge gce-color-${appel.Appel_result?.color || 'gray'}">${appel.Appel_result?.value || 'N/A'}</span>`;
            const details = document.createElement('div');
            details.className = 'gce-appel-details';
            details.innerHTML = `<p><strong>Contact:</strong> ${contactLink}</p><p><strong>Chargé de projet:</strong> ${employeLink}</p><p><strong>Statut du dossier:</strong> ${statutBadge}</p>`;
            
            const interactionsContainer = document.createElement('div');
            interactionsContainer.className = 'gce-appel-interactions-container';
            const tableDiv = document.createElement('div');
            interactionsContainer.appendChild(tableDiv);
            container.appendChild(header); container.appendChild(details); container.appendChild(interactionsContainer);

            // --- Configuration Tabulator Corrigée ---
            new Tabulator(tableDiv, {
                data: appel._children || [],
                layout: "fitColumns", // On remet fitColumns pour un meilleur usage de l'espace
                responsiveLayout: "collapse",
                columns: getInteractionColumnsConfig(),
                placeholder: "Aucune interaction.",

                // CORRECTION 1 : Utilisation de tableBuilt pour la responsivité
                tableBuilt: function(){
                    this.redraw(true); // "this" fait référence à l'instance de la table
                },
                rowFormatter: function(row){
                    // Appliquer la classe CSS si le champ Lock est true
                    if (row.getData().Lock === true) {
                        row.getElement().classList.add("gce-row-locked");
                    }
                },
                // CORRECTION 2 : Réintroduction de la logique de sauvegarde réactive
                cellEdited: function(cell) {
                    const rowData = cell.getRow().getData();
                    const cleaned = sanitizeRowBeforeSave(rowData, window.gceSchemas.interactions);
                    showStatusUpdate('Sauvegarde...');
                    fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/interactions/${rowData.id}`, { method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': EECIE_CRM.nonce }, body: JSON.stringify(cleaned) })
                    .then(res => {
                        if (!res.ok) throw new Error("Sauvegarde échouée.");
                        return fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/row/appels/${appel.id}`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } });
                    })
                    .then(res => res.json())
                    .then(updatedAppelData => {
                        gce.viewManager.updateItem(updatedAppelData.id, updatedAppelData);
                        cell.getTable().setData(updatedAppelData._children || []);
                        showStatusUpdate('Vue mise à jour !', true);
                    })
                    .catch(err => {
                        showStatusUpdate("Erreur: " + err.message, false);
                        cell.getTable().setData(appel._children);
                    });
                }
            });
            return container;
        }
    };

    // --- Reste du fichier inchangé ---
    Promise.all([
        fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/appels/schema`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/interactions/schema`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/appels`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json())
    ]).then(([appelsSchema, interactionsSchema, appelsData]) => {
        window.gceSchemas = { ...window.gceSchemas, "appels": appelsSchema, "interactions": interactionsSchema };
        gce.viewManager.initialize(mainContainer, appelsData, appelsViewConfig);
    }).catch(err => {
        mainContainer.innerHTML = `<p style="color:red;">Erreur: ${err.message}</p>`;
    });

    document.body.addEventListener('click', (e) => { const deleteBtn = e.target.closest('.gce-delete-interaction-btn'); if (deleteBtn) { e.preventDefault(); const interactionId = deleteBtn.dataset.id; if (confirm(`Supprimer l'interaction #${interactionId} ?`)) { fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/interactions/${interactionId}`, { method: 'DELETE', headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(res => { if (!res.ok) throw new Error('Échec de la suppression.'); location.reload(); }).catch(err => alert(err.message)); } } });
});