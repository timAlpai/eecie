/**
 * Fichier : includes/shared/js/popup-handler.js
 *
 * Gère les popups (modals) pour visualiser et éditer les enregistrements Baserow.
 * Utilise la délégation d'événements pour fonctionner avec les liens générés dynamiquement par Tabulator.
 */

/**
 * Rafraîchit les tables de données principales sur la page après une modification.
 * Cette version est conçue pour fonctionner avec des tables principales pouvant contenir des sous-tables.
 */
function gceRefreshVisibleTable() {
    console.log("🔁 Attempting to refresh main tables...");

    // Liste des variables globales contenant nos instances principales de Tabulator
    const mainTableInstances = [
        window.devisTable,
        window.appelsTable,
        window.gceUserTable
        // Ajoutez d'autres instances de tables principales ici si nécessaire
    ];

    mainTableInstances.forEach(tableInstance => {
        // Vérifie si l'instance existe et est une table Tabulator valide
        if (tableInstance && typeof tableInstance.replaceData === 'function') {
            console.log(`✅ Found main table instance. Refreshing data...`);
            // replaceData() va re-lancer la source de données d'origine (l'appel API),
            // ce qui reconstruira la table principale et toutes ses sous-tables avec des données fraîches.
            tableInstance.replaceData();
        }
    });
}

function initializeRatingInputs(modal) {
    modal.querySelectorAll('.gce-rating-input').forEach(container => {
        const hiddenInput = container.querySelector('input[type="hidden"]');
        const stars = container.querySelectorAll('.star');

        const updateStars = (rating) => {
            stars.forEach(star => {
                star.classList.toggle('selected', star.dataset.value <= rating);
            });
        };

        container.addEventListener('mouseover', (e) => {
            if (e.target.classList.contains('star')) {
                updateStars(e.target.dataset.value);
            }
        });

        container.addEventListener('mouseout', () => {
            updateStars(hiddenInput.value);
        });

        container.addEventListener('click', (e) => {
            if (e.target.classList.contains('star')) {
                const value = e.target.dataset.value;
                hiddenInput.value = value;
                updateStars(value);
            }
        });
    });
}

/**
 * Initialise l'écouteur d'événements principal pour les liens de popup.
 */
document.addEventListener('DOMContentLoaded', () => {
    document.body.addEventListener('click', async (e) => {
        const link = e.target.closest('.gce-popup-link');
        if (!link) return;

        e.preventDefault();

        // Cette map est la clé. Elle traduit le NOM du champ de liaison (brut)
        // vers le SLUG de l'API REST.
        const rawNameToSlugMap = {
            't1_user': 'utilisateurs',
            'assigne': 'utilisateurs',
            'contacts': 'contacts',
            'contact': 'contacts',
            'task_input': 'opportunites',
            'opportunite': 'opportunites',
            'opportunité': 'opportunites', // Gère le nom avec accent
            'appel': 'appels',
            'appels': 'appels',
            'interaction': 'interactions',
            'interactions': 'interactions',
            'devis': 'devis',
            'articles_devis': 'articles_devis',
            'article': 'articles_devis',
            'fournisseur': 'fournisseurs',
            'fournisseurs': 'fournisseurs'
        };

        const rawTableName = link.dataset.table;
        const rowId = link.dataset.id;
        const mode = link.dataset.mode || "lecture";

        // On traduit le nom brut en slug REST.
        let tableSlug = rawNameToSlugMap[rawTableName.toLowerCase()];

        if (!tableSlug) {
            console.warn(`Aucune traduction pour '${rawTableName}', utilisation en tant que slug.`);
            tableSlug = rawTableName.toLowerCase();
        }

        if (!rowId) {
            console.error(`ID de ligne manquant.`);
            return;
        }

        const url = `${EECIE_CRM.rest_url}eecie-crm/v1/row/${tableSlug}/${rowId}`;

        try {
            const res = await fetch(url, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } });

            if (!res.ok) {
                throw new Error(`Erreur HTTP ${res.status}`);
            }

            const data = await res.json();
            gceShowModal(data, tableSlug, mode);

        } catch (err) {
            console.error('Erreur lors de la récupération des données pour le popup:', err);
            alert("Impossible de charger les détails.");
        }
    });
});

/**
 * Crée et affiche un modal pour un enregistrement donné.
 * @param {object} data - Les données de l'enregistrement.
 * @param {string} tableName - Le slug de la table (ex: 'contacts', 'articles_devis').
 * @param {string} mode - 'lecture' (lecture seule) ou 'ecriture' (formulaire d'édition).
 * @param {string[]|null} visibleFields - Tableau optionnel de noms de champs à afficher.
 */

function gceShowModal(data = {}, tableName, mode = "lecture", visibleFields = null) {
    const schema = window.gceSchemas?.[tableName];
    if (!schema || !Array.isArray(schema)) {
        console.warn(`Schema for '${tableName}' not pre-loaded. Attempting to fetch.`);
        fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/${tableName}/schema`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } })
            .then(r => r.ok ? r.json() : Promise.reject('Failed to fetch schema'))
            .then(fetchedSchema => {
                window.gceSchemas = window.gceSchemas || {};
                window.gceSchemas[tableName] = fetchedSchema;
                gceShowModal(data, tableName, mode, visibleFields);
            })
            .catch(err => {
                console.error(`❌ Schema missing or failed to load for ${tableName}:`, err);
                alert("The schema for this item is not available, cannot display details.");
            });
        return;
    }

    let filteredSchema = schema;
    if (Array.isArray(visibleFields)) {
        filteredSchema = schema.filter(f =>
            visibleFields.includes(f.name) || visibleFields.includes(`field_${f.id}`)
        );
    }
    if (mode === 'ecriture' && data.Lock === true) {
        alert("Cet enregistrement est verrouillé et ne peut pas être modifié.");
        mode = 'lecture'; // On bascule en mode lecture
    }

    const overlay = document.createElement("div");
    overlay.className = "gce-modal-overlay";

    const modal = document.createElement("div");
    modal.className = "gce-modal";

    const title = data.id ?
        `${tableName.charAt(0).toUpperCase() + tableName.slice(1)} #${data.id}` :
        `Nouveau ${tableName}`;


  const contentHtml = filteredSchema.map((field) => {
    // --- DÉBUT DE LA MODIFICATION 1 : Identification du champ ---
    // On ajoute cette logique au tout début de la boucle.
    const recurrenceFieldNames = ['Frequence', 'Intervalle', 'Date_Debut_Recurrence', 'Date_Fin_Recurrence'];
    const isRecurrenceField = tableName === 'opportunites' && recurrenceFieldNames.includes(field.name);
    // --- FIN DE LA MODIFICATION 1 ---


    // TOUT VOTRE CODE EXISTANT RESTE INTACT ICI
    const fieldKey = `field_${field.id}`;
    const label = `<label for="${fieldKey}"><strong>${field.name}</strong></label>`;
    let value = data[field.name];
    if (value === undefined) {
        value = data[fieldKey];
    }

    let fieldHtml; // On déclare une variable pour stocker le HTML du champ

    if (mode === "lecture") {
        let displayValue = '';

        if (field.type === "link_row" && Array.isArray(value)) {
            const rawNameToSlugMap = { 't1_user': 'utilisateurs', 'assigne': 'utilisateurs', 'contacts': 'contacts', 'contact': 'contacts', 'task_input': 'opportunites', 'opportunite': 'opportunites', 'opportunité': 'opportunites', 'appel': 'appels', 'appels': 'appels', 'interaction': 'interactions', 'interactions': 'interactions', 'devis': 'devis', 'articles_devis': 'articles_devis', 'article': 'articles_devis', 'fournisseur': 'fournisseurs', 'fournisseurs': 'fournisseurs' };
            const tableSlug = rawNameToSlugMap[field.name.toLowerCase()] || field.name.toLowerCase();
            displayValue = value.map(obj => {
                if (!obj || !obj.id) return '';
                return `<span style="display: inline-flex; align-items: center; gap: 5px;"><a href="#" class="gce-popup-link" data-table="${tableSlug}" data-id="${obj.id}" data-mode="lecture">${obj.value || 'Détail'}</a><a href="#" class="gce-popup-link" data-table="${tableSlug}" data-id="${obj.id}" data-mode="ecriture" title="Modifier">✏️</a></span>`;
            }).join(', ');
        } else if (field.type === "file" && Array.isArray(value)) {
            displayValue = value.map(file => `<a href="${file.url}" target="_blank" rel="noopener noreferrer">${file.visible_name}</a>`).join('<br>');
        } else if (field.type === "rating") {
            const currentValue = data[field.name] || 0;
            const maxRating = field.max_value || 5;
            let starsHtml = '';
            for (let i = 1; i <= maxRating; i++) {
                const isSelected = i <= currentValue ? 'selected' : '';
                starsHtml += `<span class="star ${isSelected}" data-value="${i}">★</span>`;
            }
            fieldHtml = `<div class="gce-field-row">${label}<div class="gce-rating-input">${starsHtml}<input type="hidden" name="${fieldKey}" value="${currentValue}"></div></div>`;
        } else if (typeof value === 'object' && value !== null) {
            displayValue = value.value || '';
        } else {
            displayValue = value || '';
        }
        
        if (field.type !== "rating") {
             fieldHtml = `<div class="gce-field-row"><strong>${field.name}</strong><div>${displayValue}</div></div>`;
        }

    } else { // Mode 'ecriture'
        if (field.type === "file") {
            let existingFilesHtml = '';
            if (Array.isArray(value) && value.length > 0) {
                existingFilesHtml = '<div>Fichiers actuels: ' + value.map(f => `<a href="${f.url}" target="_blank">${f.visible_name}</a>`).join(', ') + '</div>';
            }
            fieldHtml = `<div class="gce-field-row" style="flex-direction: column; align-items: stretch;">${label}${existingFilesHtml}<input type="file" id="${fieldKey}" name="${fieldKey}" multiple style="margin-top: 5px;"></div>`;
        } else if (field.type === "link_row") {
            if (tableName === 'devis' && field.name === 'Fournisseur') {
                const targetSlug = 'fournisseurs';
                const options = window.gceDataCache?.[targetSlug] || [];
                const selectedIds = Array.isArray(value) ? value.map(v => v.id) : [];
                const optionHtml = options.map(opt => `<option value="${opt.id}" ${selectedIds.includes(opt.id) ? 'selected' : ''}>${opt.Nom || opt.Name || `ID: ${opt.id}`}</option>`).join('');
                fieldHtml = `<div class="gce-field-row" style="flex-direction: column; align-items: stretch;">${label}<select name="${fieldKey}" id="${fieldKey}" multiple style="min-height: 100px;">${optionHtml}</select></div>`;
            } else {
                let displayValue = '—';
                let hiddenInputValue = '';

               if (Array.isArray(value) && value.length > 0) {
                    const rawNameToSlugMap = { 't1_user': 'utilisateurs', 'assigne': 'utilisateurs', 'contacts': 'contacts', 'contact': 'contacts', 'task_input': 'opportunites', 'opportunite': 'opportunites', 'opportunité': 'opportunites', 'appel': 'appels', 'appels': 'appels', 'interaction': 'interactions', 'interactions': 'interactions', 'devis': 'devis', 'articles_devis': 'articles_devis', 'article': 'articles_devis' };
                    const tableSlug = rawNameToSlugMap[field.name.toLowerCase()] || field.name.toLowerCase();
                    displayValue = value.map(obj => {
                        if (!obj || !obj.id) return '';
                        return `<a href="#" class="gce-popup-link" data-table="${tableSlug}" data-id="${obj.id}" data-mode="lecture">${obj.value || 'Détail'}</a>`;
                    }).join(', ');
                    hiddenInputValue = JSON.stringify(value.map(v => v.id));

                }
                fieldHtml = `<div class="gce-field-row">${label}<div>${displayValue} <input type="hidden" name="${fieldKey}" value='${hiddenInputValue}'></div></div>`;
            }
        } else if (field.type === "date") {
            let dateValue = value || '';
            if (dateValue) {
                const d = new Date(dateValue);
                d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
                if (field.date_include_time) {
                    dateValue = d.toISOString().slice(0, 16);
                } else {
                    dateValue = d.toISOString().slice(0, 10);
                }
            }
            const inputType = field.date_include_time ? "datetime-local" : "date";
            fieldHtml = `<div class="gce-field-row">${label}<input type="${inputType}" id="${fieldKey}" name="${fieldKey}" value="${dateValue}"></div>`;
        } else if (field.read_only) {
            fieldHtml = `<div class="gce-field-row">${label}<input type="text" value="${value || ''}" readonly disabled></div>`;
        } else if (field.name === 'Progression') {
            const currentValue = value || 0;
            fieldHtml = `<div class="gce-field-row" style="align-items: center;">${label}<div style="display: flex; align-items: center; gap: 10px; flex-grow: 1;"><input type="range" min="0" max="100" value="${currentValue}" id="${fieldKey}" name="${fieldKey}" style="flex-grow: 1;" oninput="this.nextElementSibling.textContent = this.value + '%'"><span style="font-weight: bold; min-width: 40px; text-align: right;">${currentValue}%</span></div></div>`;
        } else if (field.type === "boolean") {
            fieldHtml = `<div class="gce-field-row" style="align-items: center;">${label}<input type="checkbox" id="${fieldKey}" name="${fieldKey}" ${value === true ? "checked" : ""} style="width: 20px; height: 20px;"></div>`;
        } else if (field.type === "single_select" && field.select_options) {
            const options = field.select_options.map(opt => `<option value="${opt.id}" ${value?.id === opt.id ? "selected" : ""}>${opt.value}</option>`).join("");
            fieldHtml = `<div class="gce-field-row">${label}<select name="${fieldKey}" id="${fieldKey}"><option value="">-</option>${options}</select></div>`;
        } else if (field.type === 'long_text' && mode === 'ecriture') {
            fieldHtml = `<div class="gce-field-row" style="flex-direction: column; align-items: stretch;">${label}<div class="gce-wp-editor-container" data-field-key="${fieldKey}" data-initial-content="${encodeURIComponent(value || '')}">Chargement de l'éditeur...</div></div>`;
        } else if (field.type === "rating") {
            const currentValue = data[field.name] || 0;
            const maxRating = field.max_value || 5;
            let starsHtml = '';
            for (let i = 1; i <= maxRating; i++) {
                const isSelected = i <= currentValue ? 'selected' : '';
                starsHtml += `<span class="star ${isSelected}" data-value="${i}">★</span>`;
            }
            fieldHtml = `<div class="gce-field-row">${label}<div class="gce-rating-input">${starsHtml}<input type="hidden" name="${fieldKey}" value="${currentValue}"></div></div>`;
        } else {
            const inputType = field.type === 'number' ? 'number' : 'text';
            const numberAttributes = (inputType === 'number') ? 'step="0.01"' : '';
            fieldHtml = `<div class="gce-field-row">${label}<input type="${inputType}" id="${fieldKey}" name="${fieldKey}" value="${value || ''}" ${numberAttributes}></div>`;
        }
    }

    // --- DÉBUT DE LA MODIFICATION 3 : Enveloppement conditionnel ---
    // A la fin de la boucle, avant de retourner le résultat pour ce champ.
    if (isRecurrenceField) {
        // Si c'est un champ de récurrence, on l'enveloppe dans la div cachée.
        return `<div class="gce-recurrence-field" style="display: none;">${fieldHtml}</div>`;
    } else {
        // Sinon, on le retourne normalement.
        return fieldHtml;
    }
    // --- FIN DE LA MODIFICATION 3 ---

}).join("");
    modal.innerHTML = `
        <button class="gce-modal-close">✖</button>
            <h3>${title}</h3>
            <div class="gce-modal-scroll-content">
                <form class="gce-modal-content" id="gce-main-edit-form">
                    ${contentHtml}
                    <button type="submit" class="button button-primary" style="margin-top: 15px;">💾 Enregistrer les modifications</button>
                </form>
                
                <!-- Conteneur dédié et indépendant pour le formulaire de planification -->
                <div id="first-intervention-wrapper"></div>
            </div>
    `;

    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    // Gérer le chargement asynchrone des éditeurs WP Editor
    modal.querySelectorAll('.gce-wp-editor-container').forEach(async (container) => {
        const fieldKey = container.dataset.fieldKey;
        const initialContent = decodeURIComponent(container.dataset.initialContent);

        try {
            const res = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/get-wp-editor`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': EECIE_CRM.nonce
                },
                body: JSON.stringify({
                    content: initialContent,
                    editor_id: fieldKey // Utiliser la clé du champ comme ID unique
                })
            });

            if (!res.ok) throw new Error('Failed to load editor HTML');

            const data = await res.json();
            container.innerHTML = data.html;

            const editorSettings = data.settings || {}; // Récupère les settings de l'API
            editorSettings.selector = `#${fieldKey}`; // L'API ne connaît pas l'ID, on le définit ici

            if (window.tinymce) {
                window.tinymce.execCommand('mceRemoveEditor', true, fieldKey);
                window.tinymce.init(editorSettings);
            }
        } catch (err) {
            container.innerHTML = `<textarea id="${fieldKey}" name="${fieldKey}" rows="5">${initialContent}</textarea><p style="color:red;">L'éditeur riche n'a pas pu être chargé.</p>`;
            console.error("WP Editor load failed:", err);
        }
    });
        // --- DÉBUT DE LA LOGIQUE D'AFFICHAGE CONDITIONNEL (MODIFIÉE) ---
        if (tableName === 'opportunites' && mode === 'ecriture') {
            const form = modal.querySelector('#gce-main-edit-form');
            const wrapper = modal.querySelector('#first-intervention-wrapper'); // On cible notre nouveau conteneur
            const typeOppFieldSchema = schema.find(f => f.name === 'Type_Opportunite');
            
            if (typeOppFieldSchema) {
                const typeOppSelect = form.querySelector(`[name="field_${typeOppFieldSchema.id}"]`);
                const recurrenceFieldsContainer = form.querySelectorAll('.gce-recurrence-field');

                // --- INJECTION DU MINI-FORMULAIRE ---
                const firstInterventionContainer = document.createElement('div');
                firstInterventionContainer.id = 'first-intervention-container';
                firstInterventionContainer.style.display = 'none'; // Caché par défaut

                const fournisseurOptionsHtml = '<option value="">Choisir un fournisseur...</option>' + 
                    (window.gceDataCache.fournisseurs || []).map(f => `<option value="${f.id}">${f.Nom}</option>`).join('');

                firstInterventionContainer.innerHTML = `
                    <h4>Planifier la Première Intervention</h4>
                    <p>Cette action créera un rendez-vous et enverra les notifications par email au client et au fournisseur.</p>
                    <div class="gce-field-row">
                        <label for="first_intervention_date"><strong>Date & Heure</strong></label>
                        <input type="datetime-local" id="first_intervention_date" name="first_intervention_date" required>
                    </div>
                    <div class="gce-field-row">
                        <label for="first_intervention_fournisseur"><strong>Fournisseur</strong></label>
                        <select id="first_intervention_fournisseur" name="first_intervention_fournisseur" required>${fournisseurOptionsHtml}</select>
                    </div>
                    <button type="button" id="gce-schedule-first-btn" class="button">Planifier et Notifier</button>
                `;
                // On ajoute le formulaire dans son conteneur dédié, en dehors du formulaire principal.
                wrapper.appendChild(firstInterventionContainer);

                if (typeOppSelect && recurrenceFieldsContainer.length > 0) {
                    const toggleRecurrenceFields = () => {
                        const recurrenteOption = typeOppFieldSchema.select_options.find(o => o.value === 'Récurrente');
                        if (!recurrenteOption) return;

                        const shouldShow = typeOppSelect.value == recurrenteOption.id;
                        
                        recurrenceFieldsContainer.forEach(el => {
                            el.style.display = shouldShow ? '' : 'none';
                        });
                        // On bascule aussi l'affichage de notre nouveau formulaire
                        firstInterventionContainer.style.display = shouldShow ? 'block' : 'none';
                    };

                    typeOppSelect.addEventListener('change', toggleRecurrenceFields);
                    toggleRecurrenceFields();
                }
                document.getElementById('gce-schedule-first-btn').addEventListener('click', async (e) => {
                const btn = e.target;
                const date = document.getElementById('first_intervention_date').value;
                const fournisseurId = document.getElementById('first_intervention_fournisseur').value;
                const opportuniteId = data.id;

                if (!date || !fournisseurId) {
                    alert("Veuillez sélectionner une date et un fournisseur.");
                    return;
                }

                btn.disabled = true;
                btn.textContent = 'Planification...';
                showStatusUpdate('Déclenchement du workflow de planification...', true);

                try {
                    const response = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/interventions/schedule-first`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': EECIE_CRM.nonce },
                        body: JSON.stringify({
                            opportunite_id: opportuniteId,
                            fournisseur_id: parseInt(fournisseurId, 10),
                            date_heure: date
                        })
                    });

                    if (!response.ok) {
                        const errData = await response.json();
                        throw new Error(errData.message || 'Le serveur a retourné une erreur.');
                    }
                    
                    showStatusUpdate('Workflow déclenché avec succès ! Les notifications ont été envoyées.', true, 5000);
                    // On peut éventuellement vider les champs après succès
                    document.getElementById('first_intervention_date').value = '';
                    document.getElementById('first_intervention_fournisseur').value = '';

                } catch (error) {
                    showStatusUpdate(`Erreur : ${error.message}`, false, 5000);
                } finally {
                    btn.disabled = false;
                    btn.textContent = 'Planifier et Notifier';
                }
            });
            }
        }


    const close = () => overlay.remove();
    overlay.addEventListener("click", (e) => {
        if (e.target === overlay) close();
    });
    modal.querySelector(".gce-modal-close").addEventListener("click", close);

    if (mode === "ecriture") {
        const mainForm = modal.querySelector("#gce-main-edit-form");
            if(mainForm) {
               modal.querySelector("form").addEventListener("submit", async (e) => {
            e.preventDefault();

            const submitButton = e.target.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.textContent = 'Sauvegarde...';

            try {
                // ================== NOUVELLE LOGIQUE D'UPLOAD ==================
                const form = e.target;
                const fileInputs = form.querySelectorAll('input[type="file"]');
                const newFilesPayload = {};

                // Boucle sur chaque champ de fichier pour uploader les fichiers
                for (const input of fileInputs) {
                    if (input.files.length > 0) {
                        const fieldKey = input.name;
                        newFilesPayload[fieldKey] = [];

                        // Uploader chaque fichier sélectionné pour ce champ
                        for (const file of input.files) {
                            const formData = new FormData();
                            formData.append('file', file);

                            const res = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/upload-file`, {
                                method: 'POST',
                                headers: { 'X-WP-Nonce': EECIE_CRM.nonce },
                                body: formData
                            });

                            if (!res.ok) throw new Error(`L'upload du fichier ${file.name} a échoué.`);

                            const uploadedFile = await res.json();
                            newFilesPayload[fieldKey].push(uploadedFile);
                        }
                    }
                }
                // ================== FIN DE LA LOGIQUE D'UPLOAD ==================

                if (window.tinymce) tinymce.triggerSave();
                const standardFormData = new FormData(form);
                const payload = {};
                if (!data.id) { // On est en mode création
                    for (const key in data) {
                        const fieldInSchema = schema.find(f => f.name === key);
                        // Si le champ existe dans le schéma et n'était pas visible dans le formulaire...
                        if (fieldInSchema && (!visibleFields || !visibleFields.includes(key))) {
                            const fieldApiId = `field_${fieldInSchema.id}`;

                            // On gère spécifiquement les champs de liaison
                            if (fieldInSchema.type === 'link_row' && Array.isArray(data[key])) {
                                payload[fieldApiId] = data[key].map(item => item.id);
                            } else {
                                payload[fieldApiId] = data[key];
                            }
                        }
                    }
                }

                for (let field of filteredSchema) {
                    if (field.read_only) continue;

                    const key = `field_${field.id}`;
                    // On déclare rawValue une seule fois au début, accessible à tous les 'else if'.
                    let rawValue = standardFormData.get(key);

                    if (rawValue === null || (rawValue === '' && field.type !== 'boolean')) continue;


                    // ================== LOGIQUE DE PAYLOAD MODIFIÉE ==================
                    if (field.type === "file") {
                        const existingFiles = Array.isArray(data[field.name]) ? data[field.name] : [];
                        const newFiles = newFilesPayload[key] || [];
                        // On combine les fichiers existants avec les nouveaux
                        payload[key] = [...existingFiles, ...newFiles];
                        continue; // On passe au champ suivant
                    }

                    if (field.type === 'link_row' && tableName === 'devis' && field.name === 'Fournisseur') {
                        // On récupère toutes les valeurs sélectionnées pour le champ fournisseur
                        payload[key] = Array.from(form.querySelector(`select[name="${key}"]`).selectedOptions).map(opt => parseInt(opt.value));
                        continue; // On passe au champ suivant
                    }
                    if (field.type === "rating") {
                        const intValue = parseInt(rawValue, 10);
                        if (!isNaN(intValue)) { // On vérifie que c'est bien un nombre
                            payload[key] = intValue;
                        }
                    }

                    if (!standardFormData.has(key)) continue;


                    if (field.type === "boolean") {
                        // La nouvelle logique pour une case à cocher
                        payload[key] = form.querySelector(`[name="${key}"]`).checked;
                    }
                    else if (["number", "decimal"].includes(field.type)) payload[key] = parseFloat(String(rawValue).replace(',', '.'));
                    else if (["single_select"].includes(field.type)) payload[key] = parseInt(rawValue, 10);
                    else if (field.type === "link_row") {
                        try {
                            // On essaie de parser la valeur comme un tableau JSON (pour notre champ caché).
                            // L'apostrophe simple (') autour de la valeur du champ caché peut être problématique.
                            // On la remplace par une double apostrophe pour un JSON valide.
                            const ids = JSON.parse(rawValue.replace(/'/g, '"'));
                            if (Array.isArray(ids)) {
                                payload[key] = ids;
                            }
                        } catch (e) {
                            // Si le parsing échoue, c'est probablement un ID simple d'un <select>.
                            // On revient au comportement par défaut.
                            const singleId = parseInt(rawValue, 10);
                            if (!isNaN(singleId)) {
                                payload[key] = [singleId];
                            }
                        }
                    }
                    else payload[key] = rawValue;
                }
                // ================== FIN DE LA MODIFICATION ==================
                const method = data.id ? "PATCH" : "POST";
                const url = data.id ?
                    `${EECIE_CRM.rest_url}eecie-crm/v1/${tableName}/${data.id}` :
                    `${EECIE_CRM.rest_url}eecie-crm/v1/${tableName}`;

                const res = await fetch(url, {
                    method,
                    headers: { "Content-Type": "application/json", "X-WP-Nonce": EECIE_CRM.nonce },
                    body: JSON.stringify(payload),
                });

                if (!res.ok) {
                    const errorData = await res.json();
                    throw new Error(`Erreur HTTP ${res.status}: ${errorData.message || 'Erreur inconnue'}`);
                }
                const responseData = await res.json();
                console.log("✅ Réponse du serveur reçue, sauvegarde confirmée:", responseData);

                close(); // Ferme le popup d'édition (ex: interaction)

                // ===================================================================
                // ==                 LOGIQUE DE RAFRAÎCHISSEMENT UNIVERSELLE       ==
                // ===================================================================
                // Cette logique s'applique après TOUTE sauvegarde réussie depuis un popup
                // pour s'assurer que l'interface sous-jacente est mise à jour.

                // 1. On vérifie si un modal de détail d'opportunité est actuellement ouvert en arrière-plan.
                const opportuniteModal = document.querySelector('.gce-detail-modal[data-item-id]');

                // Si le modal d'opportunité est ouvert ET que le viewManager existe...
                if (opportuniteModal && gce.viewManager) {
                    // 2. On récupère l'ID de l'opportunité depuis le modal parent.
                    const oppId = parseInt(opportuniteModal.dataset.itemId, 10);
                    console.log(`[Popup] Modification détectée. Rafraîchissement du modal d'opportunité #${oppId}`);

                    try {
                        // 3. On attend un court instant pour s'assurer que le backend a traité la modification.
                        await new Promise(resolve => setTimeout(resolve, 3000));

                        // 4. On recharge les données complètes de l'opportunité.
                        const oppRes = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/row/opportunites/${oppId}`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } });
                        if (oppRes.ok) {
                            const updatedOppData = await oppRes.json();
                            // 5. On met à jour la carte de résumé ET le modal de détail.
                            gce.viewManager.updateItem(oppId, updatedOppData);
                            gce.viewManager.updateDetailModalIfOpen(oppId, updatedOppData);
                            showStatusUpdate('Vue mise à jour !', true);
                        } else {
                            // En cas d'échec du rechargement des données, on se rabat sur un rechargement de page complet
                            throw new Error("Failed to refresh opportunity data.");
                        }
                    } catch (refreshError) {
                        console.error("Erreur lors du rafraîchissement du modal:", refreshError);
                        location.reload(); // Fallback
                    }
                } else {
                    // 6. Comportement par défaut : si on n'est pas dans un modal d'opportunité (ex: page Tâches), on recharge la page.
                    location.reload();
                }
            } catch (err) {
                console.error("❌ Erreur de sauvegarde:", err);
                alert("Une erreur est survenue lors de la sauvegarde : " + err.message);
                submitButton.disabled = false;
                submitButton.textContent = '💾 Enregistrer';
            }
            });
            }
    }

}

/**
 * Rafraîchit la table Tabulator actuellement active sur la page.
 * Repose sur une variable globale window.gceActiveTableInstance définie par chaque page.
 */
function gceRefreshCurrentTable() {
    console.log("🔁 Tentative de rafraîchissement de la table active...");
    if (window.gceActiveTableInstance && typeof window.gceActiveTableInstance.replaceData === 'function') {
        // En mode AJAX, replaceData() force Tabulator à recharger les données depuis son ajaxURL.
        window.gceActiveTableInstance.replaceData();
        console.log("✅ Table rafraîchie via AJAX.");
    } else {
        console.warn("Aucune instance de table active (window.gceActiveTableInstance) n'a été trouvée. Rechargement de la page par défaut.");
        location.reload(); // Solution de secours
    }
}



async function uploadFile(file) {
    const formData = new FormData();
    formData.append('file', file);

    const res = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/upload-file`, {
        method: 'POST',
        headers: {
            'X-WP-Nonce': EECIE_CRM.nonce
        },
        body: formData
    });

    if (!res.ok) throw new Error('Erreur lors de l’upload du fichier');

    return await res.json();
}

async function handleFiles(files) {
    const uploadedFiles = [];

    for (const file of files) {
        const result = await uploadFile(file);
        uploadedFiles.push(result.file_info);
    }

    return uploadedFiles; // Tableau compatible Baserow
}
