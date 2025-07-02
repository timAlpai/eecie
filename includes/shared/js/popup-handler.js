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
            'article': 'articles_devis'
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
            if (Array.isArray(value)) {
                displayValue = value.map(v => (typeof v === 'object' && v !== null) ? v.value : v).join(', ');
            } else if (typeof value === 'object' && value !== null) {
                displayValue = value.value || '';
            } else {
                displayValue = value || '';
            }
            return `<div class="gce-field-row"><strong>${field.name}</strong><div>${displayValue}</div></div>`;
        } else { // Mode 'ecriture'
            if (field.read_only) {
                return `<div class="gce-field-row">${label}<input type="text" value="${value || ''}" readonly disabled></div>`;
            }

            if (field.type === "link_row") {
                const linkedRecord = Array.isArray(value) ? value[0] : null;
                const recordId = linkedRecord ? linkedRecord.id : '';
                return `
                    <div class="gce-field-row">
                        ${label}
                        <input type="text" id="${fieldKey}" name="${fieldKey}" value="${recordId}" readonly style="background:#f0f0f0; border:1px solid #ddd; color: #555;">
                    </div>`;
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
            const formData = new FormData(e.target);
            const payload = {};

            for (let field of filteredSchema) {
                if (field.read_only) continue;

                const key = `field_${field.id}`;
                let rawValue;

                // Logique spéciale pour récupérer le contenu de l'éditeur riche
                if (field.type === 'long_text' && window.tinymce && window.tinymce.get(key)) {
                    // On récupère le contenu HTML directement depuis l'instance de l'éditeur
                    rawValue = window.tinymce.get(key).getContent();
                } else {
                    // Logique standard pour tous les autres types de champs
                    if (!formData.has(key)) continue; // Le champ n'est pas dans le formulaire, on passe
                    rawValue = formData.get(key);
                }

                // On ignore les champs vides, sauf pour les booléens (car 'false' est une valeur valide)
                if (rawValue === null || (rawValue === '' && field.type !== 'boolean')) {
                    continue;
                }

                // Traitement de la valeur en fonction du type de champ Baserow
                if (field.type === "boolean") {
                    payload[key] = rawValue === "true";
                } else if (field.type === "number" || field.type === "decimal") {
                    payload[key] = Number(rawValue);
                } else if (field.type === "single_select") {
                    // On s'assure de ne pas envoyer de valeur vide si rien n'est sélectionné
                    const intValue = parseInt(rawValue, 10);
                    if (!isNaN(intValue)) {
                        payload[key] = intValue;
                    }
                } else if (field.type === "link_row") {
                    const id = parseInt(rawValue, 10);
                    if (!isNaN(id)) {
                        payload[key] = [id]; // L'API attend un tableau d'IDs
                    }
                } else {
                    // Pour les champs 'text', 'long_text', 'date', etc.
                    payload[key] = rawValue;
                }
            }

            const method = data.id ? "PATCH" : "POST";
            const url = data.id ?
                `${EECIE_CRM.rest_url}eecie-crm/v1/${tableName}/${data.id}` :
                `${EECIE_CRM.rest_url}eecie-crm/v1/${tableName}`;

            try {
                const res = await fetch(url, {
                    method,
                    headers: {
                        "Content-Type": "application/json",
                        "X-WP-Nonce": EECIE_CRM.nonce,
                    },
                    body: JSON.stringify(payload),
                });

                if (!res.ok) {
                    const errorData = await res.json();
                    console.error('Erreur API:', errorData);
                    throw new Error(`Erreur HTTP ${res.status}: ${errorData.message || 'Erreur inconnue'}`);
                }

                const result = await res.json();
                console.log(`✅ Record ${method === 'POST' ? 'created' : 'updated'}:`, result);

                close();
                // On rafraîchit la table principale pour voir les changements
                if (typeof gceRefreshVisibleTable === 'function') {
                    gceRefreshVisibleTable();
                }
                location.reload(); // Solution simple et efficace pour tout mettre à jour

            } catch (err) {
                console.error("❌ Erreur de sauvegarde:", err);
                alert("Une erreur est survenue lors de la sauvegarde : " + err.message);
            }
        });
    }
}