/**
 * Fichier : includes/public/assets/js/popup-handler-fournisseur.js
 * Gère les popups pour la page de gestion des FOURNISSEURS.
 */

function gceRefreshFournisseursTable() {
    console.log("▶️ Demande de rafraîchissement de la table des fournisseurs...");
    if (typeof fetchDataAndBuildFournisseursTable === 'function') {
        fetchDataAndBuildFournisseursTable();
    } else {
        console.warn("La fonction fetchDataAndBuildFournisseursTable() n'a pas été trouvée. Rechargement de la page par défaut.");
        location.reload();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.body.addEventListener('click', async (e) => {
        const link = e.target.closest('.gce-popup-link');
        if (!link || !document.getElementById('gce-fournisseurs-table')) return;

        e.preventDefault();
        const rawTableName = link.dataset.table;
        const rowId = link.dataset.id;
        const mode = link.dataset.mode || "lecture";
        const rawNameToSlugMap = { 'fournisseur': 'fournisseurs', 'fournisseurs': 'fournisseurs', 'contacts': 'contacts', 'contact': 'contacts', 'zone_desservie': 'zone_geo' };
        let tableSlug = rawNameToSlugMap[rawTableName.toLowerCase()] || rawTableName.toLowerCase();

        if (mode === 'ecriture' && !rowId) {
            gceShowModal({}, tableSlug, mode);
            return;
        }

        if (!rowId) return console.error(`ID de ligne manquant pour le popup.`);
        const url = `${EECIE_CRM.rest_url}eecie-crm/v1/row/${tableSlug}/${rowId}`;

        try {
            const res = await fetch(url, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } });
            if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`);
            const data = await res.json();
            gceShowModal(data, tableSlug, mode);
        } catch (err) {
            console.error('Erreur (popup-handler-fournisseur):', err);
            alert("Impossible de charger les détails.");
        }
    });
});

function gceShowModal(data = {}, tableName, mode = "lecture", visibleFields = null) {
    const isCreating = (mode === 'ecriture' && !data.id);

    if (tableName === 'fournisseurs' && isCreating) {
        Promise.all([
            window.gceSchemas?.fournisseurs || fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/fournisseurs/schema`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
            window.gceSchemas?.contacts || fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/contacts/schema`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json())
        ]).then(async ([f_schema, c_schema]) => {
            window.gceSchemas = { ...window.gceSchemas, fournisseurs: f_schema, contacts: c_schema };
            const overlay = document.createElement("div");
            overlay.className = "gce-modal-overlay";
            const modal = document.createElement("div");
            modal.className = "gce-modal";
            modal.innerHTML = `<button class="gce-modal-close">✖</button><h3>Nouveau Fournisseur</h3>`;
            const form = document.createElement('form');
            form.className = 'gce-modal-content';
            let formHtml = '';
            f_schema.forEach(field => { formHtml += renderField(field, '', data); });
            formHtml += `<hr style="margin: 20px 0;"><h4 style="margin-top:0;">Contact Principal</h4>`;
            c_schema.forEach(field => { formHtml += renderField(field, 'contact_', data); });
            form.innerHTML = formHtml + `<button type="submit" class="button button-primary" style="margin-top: 15px;">💾 Enregistrer</button>`;
            modal.appendChild(form);
            overlay.appendChild(modal);
            document.body.appendChild(overlay);
            await initializeRichTextEditors(form);

            form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const payload = {
        fournisseur_data: {},
        contact_data: {}
    };

    // --- Boucle corrigée pour fournisseur_data ---
    for (const field of f_schema) {
        if (field.read_only || field.name === 'Contacts') continue;
        const key = `field_${field.id}`; // La clé pour l'API
        const formElement = form.querySelector(`[name="${key}"]`);
        if (!formElement) continue;

        if (field.type === 'long_text' && window.tinymce && window.tinymce.get(key)) {
            payload.fournisseur_data[key] = tinymce.get(key).getContent(); // <- CORRIGÉ
        } else if (field.type === 'multiple_select' || (field.type === 'link_row' && formElement.multiple)) {
            payload.fournisseur_data[key] = Array.from(formElement.selectedOptions).map(opt => parseInt(opt.value, 10)); // <- CORRIGÉ
        } else if (field.type === 'boolean') {
            payload.fournisseur_data[key] = formElement.value === 'true'; // <- CORRIGÉ
        } else if (formElement.value) {
            payload.fournisseur_data[key] = formElement.value; // <- CORRIGÉ
        }
    }

    // --- Boucle corrigée pour contact_data ---
    for (const field of c_schema) {
        if (excludedContactFields.includes(field.name)) continue;
        const keyInForm = `contact_field_${field.id}`; // Le nom du champ dans le formulaire
        const keyForApi = `field_${field.id}`; // La clé que l'API attend
        const formElement = form.querySelector(`[name="${keyInForm}"]`);
        if (!formElement) continue;

        if (field.type === 'long_text' && window.tinymce && window.tinymce.get(keyInForm)) {
            payload.contact_data[keyForApi] = tinymce.get(keyInForm).getContent(); // <- CORRIGÉ
        } else if (field.type === 'boolean') {
            payload.contact_data[keyForApi] = formElement.value === 'true'; // <- CORRIGÉ
        } else if (formElement.value) {
            payload.contact_data[keyForApi] = formElement.value; // <- CORRIGÉ
        }
    }

    // --- Assignation du champ 'Type' corrigée ---
    const typeContactField = c_schema.find(f => f.name === 'Type');
    if (typeContactField) {
        const optionFournisseur = typeContactField.select_options.find(opt => opt.value === 'Fournisseur');
        if (optionFournisseur) {
            const keyForApi = `field_${typeContactField.id}`; // La clé pour l'API
            payload.contact_data[keyForApi] = optionFournisseur.id; // <- CORRIGÉ
        }
    }
    
    // Le reste de la fonction (l'appel fetch) est déjà correct
    const url = `${EECIE_CRM.rest_url}eecie-crm/v1/fournisseurs/create-with-contact`;
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: { "Content-Type": "application/json", "X-WP-Nonce": EECIE_CRM.nonce },
            body: JSON.stringify(payload)
        });
        if (!res.ok) {
             const errData = await res.json();
             throw new Error(`Erreur ${res.status}: ${errData.message || 'Erreur API'}`);
        }
        overlay.remove();
        gceRefreshFournisseursTable();
    } catch (err) {
        alert(`Échec de la création: ${err.message}`);
    }
});
            const close = () => overlay.remove();
            overlay.addEventListener("click", ev => ev.target === overlay && close());
            modal.querySelector(".gce-modal-close").addEventListener("click", close);
        }).catch(err => { alert("Impossible de préparer le formulaire de création: " + err.message); });
        return;
    }

    const schemaPromise = (window.gceSchemas?.[tableName])
        ? Promise.resolve(window.gceSchemas[tableName])
        : fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/${tableName}/schema`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.ok ? r.json() : Promise.reject(`Échec`)).then(s => { window.gceSchemas[tableName] = s; return s; });

    schemaPromise.then(async (schema) => {
        const filteredSchema = Array.isArray(visibleFields) ? schema.filter(f => visibleFields.includes(f.name)) : schema;
        const overlay = document.createElement("div");
        overlay.className = "gce-modal-overlay";
        const modal = document.createElement("div");
        modal.className = "gce-modal";
        const title = `Édition ${tableName} #${data.id}`;
        const contentHtml = filteredSchema.map(field => renderField(field, '', data)).join("");
        modal.innerHTML = `<button class="gce-modal-close">✖</button><h3>${title}</h3><form class="gce-modal-content">${contentHtml}<button type="submit" class="button button-primary">💾 Enregistrer</button></form>`;
        overlay.appendChild(modal);
        document.body.appendChild(overlay);
        const form = modal.querySelector('form');
        await initializeRichTextEditors(form);

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (window.tinymce) tinymce.triggerSave();
            const payload = {};
            filteredSchema.forEach(field => {
                const value = getFieldValueFromForm(form, field, '');
                if (value !== undefined) payload[`field_${field.id}`] = value;
            });
            try {
                const res = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/${tableName}/${data.id}`, {
                    method: 'PATCH',
                    headers: { "Content-Type": "application/json", "X-WP-Nonce": EECIE_CRM.nonce },
                    body: JSON.stringify(payload),
                });
                if (!res.ok) {
                    const errData = await res.json();
                    throw new Error(`Erreur ${res.status}: ${errData.detail?.[0]?.error || 'Erreur API'}`);
                }
                const updatedData = await res.json();
                alert("tim la c'est avant fermeture modale")
                overlay.remove();
                if (window.fournisseursTable) {
                    window.fournisseursTable.updateData([updatedData]);
                    console.log(`✅ Ligne #${updatedData.id} mise à jour localement dans Tabulator.`);
                } else { gceRefreshFournisseursTable(); }
            } catch (err) { alert(`Échec de la sauvegarde: ${err.message}`); }
        });
        const close = () => overlay.remove();
        overlay.addEventListener("click", ev => ev.target === overlay && close());
        modal.querySelector(".gce-modal-close").addEventListener("click", close);
    }).catch(err => { alert("Impossible d'afficher le popup d'édition: " + err.message); });
}
function getFieldValueFromForm(form, field, prefix) {
    if (field.read_only) return undefined;
    
    const key = `${prefix}field_${field.id}`;
    const formElement = form.querySelector(`[name="${key}"]`);
    if (!formElement) return undefined;

    switch (field.type) {
        case 'long_text':
            // La méthode la plus fiable reste celle-ci
            if (window.tinymce && tinymce.get(key)) {
                return tinymce.get(key).getContent();
            }
            // Le fallback est maintenant fiable grâce à la correction PHP
            return formElement.value;

        case 'link_row':
        case 'multiple_select':
            return Array.from(formElement.selectedOptions)
                .map(opt => parseInt(opt.value, 10))
                .filter(v => !isNaN(v));

        case 'single_select':
        case 'number':
            const numValue = parseInt(formElement.value, 10);
            return !isNaN(numValue) ? numValue : null;

        case 'boolean':
            return formElement.value === 'true';

        default: 
            if (formElement.value) {
                return formElement.value;
            }
            return undefined;
    }
}

function renderField(field, prefix = '', data = {}) {
    const isCreating = !data.id;
    if (isCreating && ((prefix === '' && field.name === 'Active') || (prefix === 'contact_' && (field.name === 'Type' || field.name === 'Fournisseur')))) {
        return '';
    }
    if (field.read_only && !data.id) return '';
    const fieldKey = `${prefix}field_${field.id}`;
    const label = `<label for="${fieldKey}"><strong>${field.name}</strong></label>`;
    let value = data[field.name];
    let initialContent = (value !== null && value !== undefined) ? value : '';
    if (field.type === "link_row") {
        const isMultiple = field.link_row_multiple_relationships;
        const targetTableId = field.link_row_table_id;
        const targetSlug = EECIE_CRM.tableIdMap[targetTableId];
        if (!targetSlug || !window.gceDataCache || !window.gceDataCache[targetSlug]) {   return `<div class="gce-field-row">${label}<p style="color:red">Erreur: cache manquant pour ${targetSlug}</p></div>`; }
        const options = window.gceDataCache[targetSlug];
        const selectedIds = Array.isArray(value) ? value.map(v => v.id) : [];
        const optionHtml = options.map(opt => `<option value="${opt.id}" ${selectedIds.includes(opt.id) ? 'selected' : ''}>${opt.Nom || opt.Name || opt.value || `ID: ${opt.id}`}</option>`).join('');
        const selectAttributes = isMultiple ? 'multiple style="min-height: 80px;"' : '';
        return `<div class="gce-field-row">${label}<select name="${fieldKey}" id="${fieldKey}" ${selectAttributes}>${!isMultiple ? '<option value="">-- Aucun --</option>' : ''}${optionHtml}</select></div>`;
    }
    if (field.type === "boolean") { return `<div class="gce-field-row">${label}<select name="${fieldKey}" id="${fieldKey}"><option value="true" ${value === true ? "selected" : ""}>Oui</option><option value="false" ${value !== true ? "selected" : ""}>Non</option></select></div>`; }
    if (field.type === "single_select" && field.select_options) {
        const optionsHtml = field.select_options.map(opt => `<option value="${opt.id}" ${value?.id === opt.id ? "selected" : ""}>${opt.value}</option>`).join("");
        return `<div class="gce-field-row">${label}<select name="${fieldKey}" id="${fieldKey}"><option value="">-</option>${optionsHtml}</select></div>`;
    }
    if (field.type === "multiple_select" && field.select_options) {
        const selectedValues = Array.isArray(value) ? value.map(v => v.id) : [];
        const optionsHtml = field.select_options.map(opt => `<option value="${opt.id}" ${selectedValues.includes(opt.id) ? "selected" : ""}>${opt.value}</option>`).join("");
        return `<div class="gce-field-row">${label}<select name="${fieldKey}" id="${fieldKey}" multiple style="min-height: 80px;">${optionsHtml}</select></div>`;
    }
    if (field.type === 'long_text') {
        const editorHtml = field.long_text_enable_rich_text ?
            `<div class="gce-wp-editor-container" data-field-key="${fieldKey}" data-initial-content="${encodeURIComponent(initialContent)}">Chargement de l'éditeur...</div>` :
            `<textarea id="${fieldKey}" name="${fieldKey}" rows="5">${initialContent}</textarea>`;
        return `<div class="gce-field-row" style="flex-direction: column; align-items: stretch;">${label}${editorHtml}</div>`;
    }
    const inputType = field.type === 'number' ? 'number' : (field.type === 'email' ? 'email' : 'text');
    return `<div class="gce-field-row">${label}<input type="${inputType}" id="${fieldKey}" name="${fieldKey}" value="${initialContent}"></div>`;
}

async function initializeRichTextEditors(formElement) {
    const editorContainers = formElement.querySelectorAll('.gce-wp-editor-container');
    if (editorContainers.length === 0) return;
    await Promise.all(Array.from(editorContainers).map(async (container) => {
        const fieldKey = container.dataset.fieldKey;
        const initialContent = decodeURIComponent(container.dataset.initialContent);
        try {
            const res = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/get-wp-editor`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': EECIE_CRM.nonce },
                body: JSON.stringify({ content: initialContent, editor_id: fieldKey })
            });
            if (!res.ok) throw new Error('Failed to load editor HTML');
            const data = await res.json();
            container.innerHTML = data.html;
            if (window.tinymce) {
                const editorSettings = data.settings || {};
                editorSettings.selector = `#${fieldKey}`;
                window.tinymce.execCommand('mceRemoveEditor', true, fieldKey);
                window.tinymce.init(editorSettings);
            }
        } catch (err) {
            console.error("WP Editor load failed for", fieldKey, err);
            container.innerHTML = `<textarea id="${fieldKey}" name="${fieldKey}" rows="5">${initialContent}</textarea><p style="color:red;">Éditeur riche indisponible.</p>`;
        }
    }));
}