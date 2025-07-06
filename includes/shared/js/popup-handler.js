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

    const overlay = document.createElement("div");
    overlay.className = "gce-modal-overlay";

    const modal = document.createElement("div");
    modal.className = "gce-modal";

    const title = data.id ?
        `${tableName.charAt(0).toUpperCase() + tableName.slice(1)} #${data.id}` :
        `Nouveau ${tableName}`;

    const contentHtml = filteredSchema.map((field) => {
        const fieldKey = `field_${field.id}`;
        const label = `<label for="${fieldKey}"><strong>${field.name}</strong></label>`;
        let value = data[field.name];
        if (value === undefined) {
            value = data[fieldKey];
        }

        if (mode === "lecture") {
            let displayValue = '';

                

                if (field.type === "link_row" && Array.isArray(value)) {
                    // Si c'est un champ de liaison, on crée des liens cliquables
                    const rawNameToSlugMap = {'t1_user':'utilisateurs','assigne':'utilisateurs','contacts':'contacts','contact':'contacts','task_input':'opportunites','opportunite':'opportunites','opportunité':'opportunites','appel':'appels','appels':'appels','interaction':'interactions','interactions':'interactions','devis':'devis','articles_devis':'articles_devis','article':'articles_devis','fournisseur': 'fournisseurs', 'fournisseurs': 'fournisseurs'};
                    const tableSlug = rawNameToSlugMap[field.name.toLowerCase()] || field.name.toLowerCase();
                    
                    displayValue = value.map(obj => {
                        if (!obj || !obj.id) return '';
                        return `<span style="display: inline-flex; align-items: center; gap: 5px;">
                                    <a href="#" class="gce-popup-link" data-table="${tableSlug}" data-id="${obj.id}" data-mode="lecture">${obj.value || 'Détail'}</a>
                                    <a href="#" class="gce-popup-link" data-table="${tableSlug}" data-id="${obj.id}" data-mode="ecriture" title="Modifier">✏️</a>
                                </span>`;
                    }).join(', ');

                } else if (field.type === "file" && Array.isArray(value)) { // Ajout pour afficher les fichiers existants
                    displayValue = value.map(file => 
                        `<a href="${file.url}" target="_blank" rel="noopener noreferrer">${file.visible_name}</a>`
                    ).join('<br>');
                }
                 else if (typeof value === 'object' && value !== null) {
                displayValue = value.value || '';
            } else {
                displayValue = value || '';
            }
            return `<div class="gce-field-row"><strong>${field.name}</strong><div>${displayValue}</div></div>`;
        } else { // Mode 'ecriture'

            if (field.type === "file") {
                let existingFilesHtml = '';
                if (Array.isArray(value) && value.length > 0) {
                    existingFilesHtml = '<div>Fichiers actuels: ' + value.map(f => `<a href="${f.url}" target="_blank">${f.visible_name}</a>`).join(', ') + '</div>';
                }
                // Note : On ne peut pas pré-remplir un <input type="file"> pour des raisons de sécurité.
                // On affiche les fichiers existants et on propose un champ pour en ajouter de nouveaux.
                return `
                    <div class="gce-field-row" style="flex-direction: column; align-items: stretch;">
                        ${label}
                        ${existingFilesHtml}
                        <input type="file" id="${fieldKey}" name="${fieldKey}" multiple style="margin-top: 5px;">
                    </div>`;
            }
            
           if (field.type === "link_row") {
                    // CAS SPÉCIAL : Le champ 'Fournisseur' quand on édite un 'devis'.
                    if (tableName === 'devis' && field.name === 'Fournisseur') {
                        const targetSlug = 'fournisseurs';
                        const options = window.gceDataCache?.[targetSlug] || [];
                        const selectedIds = Array.isArray(value) ? value.map(v => v.id) : [];
                        const optionHtml = options.map(opt => `<option value="${opt.id}" ${selectedIds.includes(opt.id) ? 'selected' : ''}>${opt.Nom || opt.Name || `ID: ${opt.id}`}</option>`).join('');
                        return `<div class="gce-field-row" style="flex-direction: column; align-items: stretch;">${label}<select name="${fieldKey}" id="${fieldKey}" multiple style="min-height: 100px;">${optionHtml}</select></div>`;
                    } else {
                       let displayValue = '—';
                    let hiddenInputValue = '';
                    if (Array.isArray(value) && value.length > 0) {
                        if(value[0]?.id) { hiddenInputValue = value[0].id; }
                        const rawNameToSlugMap = {'t1_user':'utilisateurs','assigne':'utilisateurs','contacts':'contacts','contact':'contacts','task_input':'opportunites','opportunite':'opportunites','opportunité':'opportunites','appel':'appels','appels':'appels','interaction':'interactions','interactions':'interactions','devis':'devis','articles_devis':'articles_devis','article':'articles_devis'};
                        const tableSlug = rawNameToSlugMap[field.name.toLowerCase()] || field.name.toLowerCase();
                        displayValue = value.map(obj => {
                            if (!obj || !obj.id) return '';
                            return `<a href="#" class="gce-popup-link" data-table="${tableSlug}" data-id="${obj.id}" data-mode="lecture">${obj.value || 'Détail'}</a>`;
                        }).join(', ');
                    }
                    return `<div class="gce-field-row">${label}<div>${displayValue}</div><input type="hidden" id="${fieldKey}" name="${fieldKey}" value="${hiddenInputValue}"></div>`;
               }
                }
            
            
            if (field.read_only) {
                return `<div class="gce-field-row">${label}<input type="text" value="${value || ''}" readonly disabled></div>`;
            }

            
            if (field.type === "boolean") {
                return `
                    <div class="gce-field-row">${label}
                      <select name="${fieldKey}" id="${fieldKey}">
                        <option value="true" ${value === true ? "selected" : ""}>Oui</option>
                        <option value="false" ${value !== true ? "selected" : ""}>Non</option>
                      </select>
                    </div>`;
            }

            if (field.type === "single_select" && field.select_options) {
                const options = field.select_options.map(opt =>
                    `<option value="${opt.id}" ${value?.id === opt.id ? "selected" : ""}>${opt.value}</option>`
                ).join("");
                return `<div class="gce-field-row">${label}<select name="${fieldKey}" id="${fieldKey}"><option value="">-</option>${options}</select></div>`;
            }
            if (field.type === 'long_text' && mode === 'ecriture') {
                // Pour le texte riche, on crée un conteneur qui sera rempli plus tard par l'API
                // On ajoute un attribut data pour le retrouver et lui passer le contenu initial
                return `
                    <div class="gce-field-row" style="flex-direction: column; align-items: stretch;">
                        ${label}
                        <div class="gce-wp-editor-container" 
                             data-field-key="${fieldKey}"
                             data-initial-content="${encodeURIComponent(value || '')}">
                            Chargement de l'éditeur...
                        </div>
                    </div>`;
            }
          
            const inputType = field.type === 'number' ? 'number' : 'text';
            return `
                <div class="gce-field-row">${label}
                    <input type="${inputType}" id="${fieldKey}" name="${fieldKey}" value="${value || ''}">
                </div>`;
        }
    }).join("");

    modal.innerHTML = `
        <button class="gce-modal-close">✖</button>
        <h3>${title}</h3>
        <form class="gce-modal-content">
            ${contentHtml}
            ${mode === "ecriture" ? `<button type="submit" class="button button-primary">💾 Enregistrer</button>` : ""}
        </form>
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



    const close = () => overlay.remove();
    overlay.addEventListener("click", (e) => {
        if (e.target === overlay) close();
    });
    modal.querySelector(".gce-modal-close").addEventListener("click", close);

   if (mode === "ecriture") {
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

                for (let field of filteredSchema) {
                    if (field.read_only) continue;

                    const key = `field_${field.id}`;
                    
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
                    if (!standardFormData.has(key)) continue;
                    let rawValue = standardFormData.get(key);

                    if (rawValue === null || (rawValue === '' && field.type !== 'boolean')) continue;

                    if (field.type === "boolean") payload[key] = rawValue === "true";
                    else if (["number", "decimal", "single_select"].includes(field.type)) payload[key] = parseInt(rawValue, 10);
                    else if (field.type === "link_row") payload[key] = [parseInt(rawValue, 10)];
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

                close();
                location.reload(); // La solution la plus simple pour voir tous les changements

            } catch (err) {
                console.error("❌ Erreur de sauvegarde:", err);
                alert("Une erreur est survenue lors de la sauvegarde : " + err.message);
                submitButton.disabled = false;
                submitButton.textContent = '💾 Enregistrer';
            }
        });
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