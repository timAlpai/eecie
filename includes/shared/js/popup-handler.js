/**
 * Handles popup modals for viewing and editing Baserow records.
 * Uses event delegation to work with dynamically generated Tabulator links.
 */

/**
 * Refreshes the data of any visible Tabulator table on the page.
 * This is used to update the UI after a record is created or updated in a modal.
 */
function gceRefreshVisibleTable() {
    document.querySelectorAll(".tabulator").forEach(el => {
        const tabulatorInstance = el.tabulator;
        // Check if the table is visible and is a valid Tabulator instance
        if (tabulatorInstance && el.offsetParent !== null) {
            console.log("🔁 Refreshing visible table:", tabulatorInstance.element.id || 'N/A');
            tabulatorInstance.replaceData();
        }
    });
}

/**
 * Initializes the main event listener for popup links.
 */
document.addEventListener('DOMContentLoaded', () => {
    document.body.addEventListener('click', async (e) => {
        const link = e.target.closest('.gce-popup-link');
        if (!link) return; // Exit if the click was not on a popup link

        e.preventDefault();

        // Mapping from data-table attributes to actual REST endpoint slugs
        const tableSlugMap = {
            'assigne': 'utilisateurs',
            'contact': 'contacts',
            'contacts': 'contacts',
            'opportunite': 'opportunites',
            'task_input': 'opportunites',
            't1_user': 'utilisateurs',
            'appel': 'appels',
            'appels': 'appels',
            'interaction': 'interactions',
            'interactions': 'interactions',
            'devis': 'devis',
            'article': 'articles_devis',
            'articles_devis': 'articles_devis'
        };

        const rawTableSlug = link.dataset.table;
        const tableSlug = tableSlugMap[rawTableSlug.toLowerCase()];
        const rowId = link.dataset.id;
        const mode = link.dataset.mode || "lecture";

        if (!tableSlug || !rowId) {
            console.error(`Missing table slug ('${tableSlug}') or row ID ('${rowId}') for popup.`);
            return;
        }

        try {
            // Use the generic /row/{table_slug}/{id} endpoint for fetching data
            const res = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/row/${tableSlug}/${rowId}`, {
                headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
            });

            if (!res.ok) {
                throw new Error(`HTTP Error ${res.status} while fetching row data.`);
            }

            const data = await res.json();
            // Call the modal display function
            gceShowModal(data, tableSlug, mode);

        } catch (err) {
            console.error('Error fetching data for popup:', err);
            alert("Could not load the details for this item.");
        }
    });
});

/**
 * Creates and displays a modal for a given record.
 * @param {object} data The data for the record.
 * @param {string} tableName The slug of the table (e.g., 'contacts').
 * @param {string} mode 'lecture' (read-only) or 'ecriture' (edit form).
 * @param {string[]|null} visibleFields Optional array of field names to display.
 */
function gceShowModal(data = {}, tableName, mode = "lecture", visibleFields = null) {
    const schema = window.gceSchemas?.[tableName];
    if (!schema || !Array.isArray(schema)) {
        // As a fallback, try to fetch the schema if it's not pre-loaded
        console.warn(`Schema for '${tableName}' not pre-loaded. Attempting to fetch.`);
        fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/${tableName}/schema`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce }})
            .then(r => r.ok ? r.json() : Promise.reject('Failed to fetch schema'))
            .then(fetchedSchema => {
                window.gceSchemas = window.gceSchemas || {};
                window.gceSchemas[tableName] = fetchedSchema;
                gceShowModal(data, tableName, mode, visibleFields); // Retry with fetched schema
            })
            .catch(err => {
                console.error(`❌ Schema missing or failed to load for ${tableName}:`, err);
                alert("The schema for this item is not available, cannot display details.");
            });
        return;
    }

    // Filter fields to display if an allow-list is provided
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
        `New ${tableName}`;

    // Generate the HTML for each field based on its type and the current mode
    const contentHtml = filteredSchema.map((field) => {
        const fieldKey = `field_${field.id}`; // Baserow API uses field_id
        const label = `<label for="${fieldKey}"><strong>${field.name}</strong></label>`;
        let value = data[field.name]; // Tabulator data uses field names

        if (mode === "lecture") {
            let displayValue = '';
            if (Array.isArray(value)) {
                displayValue = value.map(v => typeof v === 'object' && v !== null ? v.value : v).join(', ');
            } else if (typeof value === 'object' && value !== null) {
                displayValue = value.value || '';
            } else {
                displayValue = value || '';
            }
            return `<div class="gce-field-row"><strong>${field.name}</strong><div>${displayValue}</div></div>`;
        }

        // --- Start Edit Mode ('ecriture') ---
        if (field.read_only) {
            return `<div class="gce-field-row">${label}<input type="text" value="${value || ''}" readonly disabled></div>`;
        }

        if (field.type === "boolean") {
            return `
                <div class="gce-field-row">${label}
                  <select name="${fieldKey}" id="${fieldKey}">
                    <option value="true" ${value === true ? "selected" : ""}>Yes</option>
                    <option value="false" ${value !== true ? "selected" : ""}>No</option>
                  </select>
                </div>`;
        }

        if (field.type === "single_select" && field.select_options) {
            const options = field.select_options.map(opt =>
                `<option value="${opt.id}" ${value?.id === opt.id ? "selected" : ""}>${opt.value}</option>`
            ).join("");
            return `<div class="gce-field-row">${label}<select name="${fieldKey}" id="${fieldKey}"><option value="">-</option>${options}</select></div>`;
        }

        if (field.type === "link_row") {
            const display = Array.isArray(value) ? value.map(v => v.value).join(', ') : '';
            return `<div class="gce-field-row">${label}<input type="text" value="${display}" readonly title="Cannot be edited from this view."></div>`;
        }
        
        const inputType = field.type === 'number' ? 'number' : 'text';
        return `
            <div class="gce-field-row">${label}
                <input type="${inputType}" id="${fieldKey}" name="${fieldKey}" value="${value || ''}">
            </div>`;

    }).join("");

    modal.innerHTML = `
        <button class="gce-modal-close">✖</button>
        <h3>${title}</h3>
        <form class="gce-modal-content">
            ${contentHtml}
            ${mode === "ecriture" ? `<button type="submit" class="button button-primary">💾 Save</button>` : ""}
        </form>
    `;

    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    const close = () => overlay.remove();
    overlay.addEventListener("click", (e) => {
        if (e.target === overlay) close();
    });
    modal.querySelector(".gce-modal-close").addEventListener("click", close);

    // --- Form submission logic for 'ecriture' mode ---
    if (mode === "ecriture") {
        modal.querySelector("form").addEventListener("submit", async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const payload = {};

            for (let field of filteredSchema) {
                if (field.read_only) continue;
                const key = `field_${field.id}`;
                if (!formData.has(key)) continue;

                const rawValue = formData.get(key);
                
                if (field.type === "boolean") {
                    payload[key] = rawValue === "true";
                } else if (field.type === "number" || field.type === "decimal") {
                    payload[key] = Number(rawValue);
                } else if (field.type === "single_select") {
                    payload[key] = rawValue ? parseInt(rawValue, 10) : null;
                } else {
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

                if (!res.ok) throw new Error(`HTTP Error ${res.status}`);
                
                const result = await res.json();
                console.log(`✅ Record ${method === 'POST' ? 'created' : 'updated'}:`, result);
                
                close();
                gceRefreshVisibleTable();

            } catch (err) {
                console.error("❌ Save error:", err);
                alert("An error occurred while saving.");
            }
        });
    }
}