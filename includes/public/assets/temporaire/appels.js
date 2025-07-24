// Fichier : includes/public/assets/js/appels.js (NOUVELLE VERSION)

document.addEventListener('DOMContentLoaded', () => {
    const mainContainer = document.getElementById('gce-appels-table');
    if (!mainContainer) return;

    mainContainer.innerHTML = 'Chargement du flux de travail...';

    // 1. Charger les schémas en premier pour les avoir à disposition
    Promise.all([
        fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/appels/schema`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/interactions/schema`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json())
    ]).then(([appelsSchema, interactionsSchema]) => {
        window.gceSchemas = window.gceSchemas || {};
        window.gceSchemas["appels"] = appelsSchema;
        window.gceSchemas["interactions"] = interactionsSchema;

        // 2. Maintenant, charger les données des "dossiers d'appel"
        fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/appels`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } })
            .then(res => res.json())
            .then(appelsData => {
                mainContainer.innerHTML = ''; // Nettoyer le message de chargement

                if (!appelsData || appelsData.length === 0) {
                    mainContainer.innerHTML = '<p>Aucun dossier actif dans votre flux de travail.</p>';
                    return;
                }

                // 3. Créer une carte pour chaque dossier d'appel
                appelsData.forEach(appel => {
                    const card = createAppelCard(appel);
                    mainContainer.appendChild(card);
                });
            })
            .catch(err => {
                mainContainer.innerHTML = `<p style="color:red;">Erreur lors du chargement des données: ${err.message}</p>`;
            });

    }).catch(err => {
        mainContainer.innerHTML = `<p style="color:red;">Erreur lors du chargement des schémas: ${err.message}</p>`;
    });
});

/**
 * Crée une carte HTML pour un dossier d'appel.
 * @param {object} appel - L'objet de données pour l'appel.
 * @returns {HTMLElement} L'élément div de la carte.
 */
function createAppelCard(appel) {
    const card = document.createElement('div');
    card.className = 'gce-appel-card';

    // Header de la carte
    const header = document.createElement('div');
    header.className = 'gce-appel-header';
    const oppName = appel.Opportunité?.[0]?.value || 'Opportunité inconnue';
    header.innerHTML = `<h3>Dossier: ${oppName}</h3>`;

    // Bouton pour ajouter une interaction
    const addButton = document.createElement('button');
    addButton.className = 'button button-small';
    addButton.textContent = '➕ Interaction';
    addButton.addEventListener('click', () => {
        // Logique pour ouvrir le popup de création d'interaction (adaptée de l'ancien code)
        const popupData = {};
        const interactionsSchema = window.gceSchemas.interactions;
        const appelsTableId = window.gceSchemas.appels[0].table_id;

        const linkField = interactionsSchema.find(f => f.type === 'link_row' && f.link_row_table_id === appelsTableId);
        if (linkField) {
            popupData[linkField.name] = [{ id: appel.id, value: `Appel #${appel.id}` }];
        }
        
        // Pré-remplissage des autres champs
        if (appel.Opportunité) popupData.opportunité = appel.Opportunité;
        if (appel.Employé)     popupData.effectue_par = appel.Employé;
        if (appel.Contact)      popupData.contact = appel.Contact;
        
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
    });
    header.appendChild(addButton);

    // Détails de l'appel
    const details = document.createElement('div');
    details.className = 'gce-appel-details';
    const contactName = appel.Contact?.[0]?.value || 'Non spécifié';
    const employeName = appel.Employé?.[0]?.value || 'Non assigné';
    const statutAppel = appel.Appel_result?.value || 'N/A';
    const statutColor = appel.Appel_result?.color || 'gray';
    
    details.innerHTML = `
        <p><strong>Contact:</strong> ${contactName}</p>
        <p><strong>Chargé de projet:</strong> ${employeName}</p>
        <p><strong>Statut du dossier:</strong> <span class="gce-badge gce-color-${statutColor}">${statutAppel}</span></p>
    `;

    // Conteneur pour la table des interactions
    const interactionsContainer = document.createElement('div');
    interactionsContainer.className = 'gce-appel-interactions-container';
    const interactionsTableDiv = document.createElement('div');
    interactionsContainer.appendChild(interactionsTableDiv);

    card.appendChild(header);
    card.appendChild(details);
    card.appendChild(interactionsContainer);

    // Initialiser Tabulator pour les interactions de cette carte
    if (appel._children && appel._children.length > 0) {
        const interactionColumns = getTabulatorColumnsFromSchema(window.gceSchemas["interactions"], 'interactions');
        interactionColumns.push({
            title: "Actions",
            headerSort: false,
            width: 80,
            hozAlign: "center",
            formatter: (cell) => {
                const rowData = cell.getRow().getData();
                const editIcon = `<a href="#" class="gce-popup-link" data-id="${rowData.id}" data-table="interactions" data-mode="ecriture" title="Modifier">✏️</a>`;
                const deleteIcon = `<a href="#" class="gce-delete-interaction-btn" data-id="${rowData.id}" title="Supprimer">❌</a>`;
                return `${editIcon}   ${deleteIcon}`;
            }
        });

        const interactionsTable = new Tabulator(interactionsTableDiv, {
            data: appel._children,
            layout: "fitColumns",
            columns: interactionColumns,
            placeholder: "Aucune interaction pour ce dossier.",
        });

        interactionsTable.on("cellClick", (e, cell) => {
             if (e.target.closest('.gce-delete-interaction-btn')) {
                e.preventDefault();
                const rowData = cell.getRow().getData();
                if (confirm(`Supprimer l'interaction #${rowData.id} ?`)) {
                    fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/interactions/${rowData.id}`, {
                        method: 'DELETE',
                        headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
                    }).then(res => {
                        if (!res.ok) throw new Error('Échec de la suppression.');
                        location.reload(); // Recharger pour voir les changements
                    }).catch(err => alert(err.message));
                }
            }
        });
    } else {
        interactionsTableDiv.innerHTML = "<p>Aucune interaction enregistrée pour ce dossier.</p>";
    }

    return card;
}