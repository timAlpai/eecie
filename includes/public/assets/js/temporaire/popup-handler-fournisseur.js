/**
 * Fichier : includes/public/assets/js/popup-handler-fournisseur.js
 *
 * Gère les popups pour la page de gestion des FOURNISSEURS.
 * Ce fichier est une version dédiée pour éviter les conflits et garder la logique isolée.
 */

/**
 * Fonction de rafraîchissement propre qui appelle la fonction de chargement principale.
 */
function gceRefreshFournisseursTable() {
    console.log("▶️ Demande de rafraîchissement de la table des fournisseurs...");
    // On vérifie que la fonction principale existe bien avant de l'appeler
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
        if (!link) return;

        // Vérifie si ce handler doit agir.
        if (!document.getElementById('gce-fournisseurs-table')) {
            return;
        }

        e.preventDefault();

        const rawTableName = link.dataset.table;
        const rowId = link.dataset.id;
        const mode = link.dataset.mode || "lecture";

        const rawNameToSlugMap = {
            'fournisseur': 'fournisseurs',
            'fournisseurs': 'fournisseurs',
            'contacts': 'contacts',
            'contact': 'contacts',
            'zone_desservie': 'zone_geo',
        };
        
        let tableSlug = rawNameToSlugMap[rawTableName.toLowerCase()];
        if (!tableSlug) {
            console.warn(`[Handler Fournisseur] Pas de slug pour '${rawTableName}', utilisation en fallback.`);
            tableSlug = rawTableName.toLowerCase();
        }

        if (!rowId) {
            console.error(`ID de ligne manquant pour le popup.`);
            return;
        }

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

/**
 * La fonction qui crée et affiche le modal.
 * VERSION AMÉLIORÉE qui gère les listes de sélection pour les champs de liaison.
 */
function gceShowModal(data = {}, tableName, mode = "lecture", visibleFields = null) {
    const schemaPromise = (window.gceSchemas && window.gceSchemas[tableName])
        ? Promise.resolve(window.gceSchemas[tableName])
        : fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/${tableName}/schema`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } })
            .then(r => r.ok ? r.json() : Promise.reject(`Échec du chargement du schéma pour ${tableName}`))
            .then(fetchedSchema => {
                window.gceSchemas = window.gceSchemas || {};
                window.gceSchemas[tableName] = fetchedSchema;
                return fetchedSchema;
            });

    schemaPromise.then(schema => {
        let filteredSchema = schema;
        if (Array.isArray(visibleFields)) {
            filteredSchema = schema.filter(f => visibleFields.includes(f.name));
        }

        const overlay = document.createElement("div");
        overlay.className = "gce-modal-overlay";
        const modal = document.createElement("div");
        modal.className = "gce-modal";

        const title = data.id ?
            `${tableName.charAt(0).toUpperCase() + tableName.slice(1)} #${data.id}` :
            `Nouveau ${tableName}`;

        const contentHtml = filteredSchema.map(field => {
             if (field.read_only && !data.id) return '';

            const fieldKey = `field_${field.id}`;
            const label = `<label for="${fieldKey}"><strong>${field.name}</strong></label>`;
            let value = data[field.name];

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
            } else { // 'ecriture'
                if (field.type === "link_row") {
                    const isMultiple = field.link_row_multiple_relationships;
                    const targetTableId = field.link_row_table_id;
                    const targetSlug = EECIE_CRM.tableIdMap[targetTableId];
                    
                    if (!targetSlug || !window.gceDataCache[targetSlug]) {
                        console.error(`Données de cache introuvables pour le slug : ${targetSlug}`);
                        return `<div class="gce-field-row">${label}<p style="color:red">Erreur de configuration</p></div>`;
                    }

                    const options = window.gceDataCache[targetSlug];
                    const selectedIds = Array.isArray(value) ? value.map(v => v.id) : [];

                    const optionHtml = options.map(opt => 
                        `<option value="${opt.id}" ${selectedIds.includes(opt.id) ? 'selected' : ''}>${opt.Nom || opt.Name || opt.value || `ID: ${opt.id}`}</option>`
                    ).join('');

                    if (isMultiple) {
                        return `<div class="gce-field-row">${label}<select name="${fieldKey}" id="${fieldKey}" multiple style="min-height: 80px;">${optionHtml}</select></div>`;
                    } else {
                        return `<div class="gce-field-row">${label}<select name="${fieldKey}" id="${fieldKey}"><option value="">-- Aucun --</option>${optionHtml}</select></div>`;
                    }
                }
                if (field.type === "boolean") {
                    return `<div class="gce-field-row">${label}<select name="${fieldKey}" id="${fieldKey}"><option value="true" ${value === true ? "selected" : ""}>Oui</option><option value="false" ${value !== true ? "selected" : ""}>Non</option></select></div>`;
                }
                if (field.type === "single_select" && field.select_options) {
                    const options = field.select_options.map(opt => `<option value="${opt.id}" ${value?.id === opt.id ? "selected" : ""}>${opt.value}</option>`).join("");
                    return `<div class="gce-field-row">${label}<select name="${fieldKey}" id="${fieldKey}"><option value="">-</option>${options}</select></div>`;
                }
                 if (field.type === "multiple_select" && field.select_options) {
                    const selectedValues = Array.isArray(value) ? value.map(v => v.id) : [];
                    const options = field.select_options.map(opt => `<option value="${opt.id}" ${selectedValues.includes(opt.id) ? "selected" : ""}>${opt.value}</option>`).join("");
                    return `<div class="gce-field-row">${label}<select name="${fieldKey}" id="${fieldKey}" multiple style="min-height: 80px;">${options}</select></div>`;
                }
                if (field.type === 'long_text') {
                    return `<div class="gce-field-row" style="flex-direction: column; align-items: stretch;">${label}<textarea id="${fieldKey}" name="${fieldKey}" rows="5">${value || ''}</textarea></div>`;
                }
                const inputType = field.type === 'number' ? 'number' : (field.type === 'email' ? 'email' : 'text');
                return `<div class="gce-field-row">${label}<input type="${inputType}" id="${fieldKey}" name="${fieldKey}" value="${value || ''}"></div>`;
            }
        }).join("");

        modal.innerHTML = `<button class="gce-modal-close">✖</button><h3>${title}</h3><form class="gce-modal-content">${contentHtml}${mode === "ecriture" ? `<button type="submit" class="button button-primary">💾 Enregistrer</button>` : ""}</form>`;
        overlay.appendChild(modal);
        document.body.appendChild(overlay);

        const close = () => overlay.remove();
        overlay.addEventListener("click", e => e.target === overlay && close());
        modal.querySelector(".gce-modal-close").addEventListener("click", close);

        if (mode === "ecriture") {
            modal.querySelector("form").addEventListener("submit", async (e) => {
                e.preventDefault();
                const form = e.target;
                const payload = {};

                for (const field of filteredSchema) {
                    if (field.read_only) continue;
                    const key = `field_${field.id}`;
                    const formElement = form.querySelector(`[name="${key}"]`);
                    if (!formElement) continue;

                    if (field.type === 'link_row' || field.type === 'multiple_select') {
                        payload[key] = Array.from(formElement.selectedOptions).map(opt => parseInt(opt.value, 10));
                    } else if (field.type === 'boolean') {
                        payload[key] = formElement.value === 'true';
                    } else if (field.type === 'number' || field.type === 'single_select') {
                        const numValue = parseInt(formElement.value, 10);
                        if (!isNaN(numValue)) {
                           payload[key] = numValue;
                        }
                    } else {
                        payload[key] = formElement.value;
                    }
                }
                
                const method = data.id ? "PATCH" : "POST";
                const url = data.id ? `${EECIE_CRM.rest_url}eecie-crm/v1/${tableName}/${data.id}` : `${EECIE_CRM.rest_url}eecie-crm/v1/${tableName}`;

                try {
                    const res = await fetch(url, {
                        method,
                        headers: { "Content-Type": "application/json", "X-WP-Nonce": EECIE_CRM.nonce },
                        body: JSON.stringify(payload),
                    });
                    if (!res.ok) {
                        const errData = await res.json();
                        throw new Error(`Erreur ${res.status}: ${errData.detail?.[0]?.error || 'Erreur API'}`);
                    }
                    close();
                    gceRefreshFournisseursTable();
                
                
                } catch (err) {
                    alert(`Échec de la sauvegarde: ${err.message}`);
                }
            }); 
        } 
    }).catch(err => {
        alert("Impossible d'afficher le popup: " + err.message);
    });
} 