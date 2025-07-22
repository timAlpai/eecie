function getTabulatorColumnsFromSchema(schema, tableName = "") {
    return schema.map(field => {
        const isRelation = field.type === "link_row";
        const isBoolean = field.type === "boolean";
        const isDate = field.type === "date";
        const isSelect = field.type === "single_select";
        const isMultiSelect = field.type === "multiple_select";
        const isZoneDesservie = field.name === "Zone_desservie";
        const isRollup = field.type === "formula" || field.type === "lookup";
        const isAttachment = field.type === "file";
        const isStatus = field.name === "Status";
        const isStatut = field.name === "statut";
        const isSec1 = field.name === 'sec1'; 


        const column = {
            title: field.name,
            field: field.name,
            editor: false,
            formatter: false,
        };
        if (isSec1) {
            column.headerSort = false;
            column.formatter = function(cell, formatterParams, onRendered) {
                // On retourne un bouton qui déclenchera une action dans le JS principal
                return `<button class="button button-small gce-change-password-btn">Changer MDP</button>`;
            };
            column.formatterParams = { allowHTML: true };
            column.editor = false; // Jamais éditable en ligne
            column.editable = false;
        }
        else if (isAttachment) {
            column.formatter = function (cell) {
                const files = cell.getValue();
                if (!Array.isArray(files)) return '';
                return files.map(f =>
                    `<a href="${f.url}" target="_blank">${f.visible_name || f.name}</a>`
                ).join('<br>');
            };
            column.formatterParams = { allowHTML: true };

        } else if (isStatus || isStatut) {
            column.formatter = function (cell) {
                const val = cell.getValue();
                if (!val || typeof val !== 'object') return '';
                const colorClass = 'gce-color-' + (val.color || 'gray');
                const label = val.value || val.name || '';
                return `<span class="gce-badge ${colorClass}">${label}</span>`;
            };
            column.formatterParams = { allowHTML: true };

        }
        else if (isSelect && Array.isArray(field.select_options)) {
            const options = field.select_options.map(opt => opt.value || opt.name || opt.id);

            // The formatter is the same for all single-selects, so we set it here.
            column.formatter = function (cell) {
                const val = cell.getValue();
                if (!val || typeof val !== 'object') return '';
                const label = val.value || val.name || '';
                const color = val.color || 'gray';
                return `<span class="gce-badge gce-color-${color}">${label}</span>`;
            };
            column.formatterParams = { allowHTML: true };

            // Now, we decide if it should be editable or not.
            if (tableName === 'appels' && field.name === 'Appel_result') {
                // This specific column should NOT be editable.
                console.log("Blocking editor for 'Appel_result' in 'appels' table.");
                column.editor = false; // Explicitly set editor to false
                column.editable = function (cell) { return false; }; // The most robust way to block editing.
            } else {
                // All other single-select columns ARE editable.
                column.editor = selectEditor(options);
            }
        } else if (isMultiSelect && Array.isArray(field.select_options)) {
            column.editor = false; // L'édition en ligne est désactivée
            column.formatter = function (cell) {
                const values = cell.getValue();
                if (!Array.isArray(values) || values.length === 0) return '—';

                return values.map(val => {
                    if (!val || typeof val !== 'object') return '';
                    const label = val.value || val.name || '';
                    const color = val.color || 'gray';
                    return `<span class="gce-badge gce-color-${color}" style="margin-right: 4px;">${label}</span>`;
                }).join(' ');
            };
            column.formatterParams = { allowHTML: true };

        } else if (isZoneDesservie) {
            column.editor = false; // Pas d'édition en ligne
            column.formatter = function (cell) {
                const values = cell.getValue(); // Devrait être un tableau d'objets [{id: 3, value: "Montreal"}]
                if (!Array.isArray(values) || values.length === 0) return '—';

                // On utilise le même rendu que pour un multi-select, mais sans couleur spécifique
                return values.map(val => {
                    if (!val || typeof val !== 'object') return '';
                    const label = val.value || val.name || `ID ${val.id}`;
                    // On utilise une couleur par défaut
                    return `<span class="gce-badge gce-color-blue" style="margin-right: 4px;">${label}</span>`;
                }).join(' ');
            };
            column.formatterParams = { allowHTML: true };
        } else if (field.type === 'long_text') {
            column.editor = false; // On désactive l'éditeur en ligne
            column.cellClick = function (e, cell) {
                // Au clic, on ouvre le popup en mode édition pour la ligne entière
                const rowData = cell.getRow().getData();
                gceShowModal(rowData, tableName, "ecriture", null); // null pour afficher tous les champs
            };
            // Ajout d'un petit style pour indiquer que le champ est cliquable
            column.cssClass = "gce-clickable-cell";


        } else if (isRelation) {
            column.formatter = function (cell) {
                const value = cell.getValue();
                if (!Array.isArray(value) || !field.link_row_table_id) return '';

                const targetTableSlug = window.EECIE_CRM.tableIdMap[field.link_row_table_id];

                if (!targetTableSlug) {
                    console.warn(`No slug found for table ID ${field.link_row_table_id}.`);
                    return value.map(obj => obj.value).join(', ');
                }

                // === MODIFICATION RECOMMANDÉE CI-DESSOUS ===
                return value.map(obj =>
                    // On enveloppe le lien et l'icône dans un conteneur pour un meilleur alignement
                    `<span style="display: inline-flex; align-items: center; gap: 5px;">
                        <!-- Lien pour la LECTURE (mode par défaut) -->
                        <a href="#" class="gce-popup-link" data-table="${field.name}" data-id="${obj.id}">${obj.value}</a>
                        
                        <!-- Icône pour l'ÉCRITURE (mode="ecriture") -->
                        <a href="#" class="gce-popup-link" data-table="${field.name}" data-id="${obj.id}" data-mode="ecriture" title="Modifier">✏️</a>
                    </span>`
                ).join(', ');
                // === FIN DE LA MODIFICATION ===
            };
            column.formatterParams = { allowHTML: true };
            column.editor = false;
        } else if (isBoolean) {
            column.editor = tickCrossEditor;
            column.formatter = "tickCross";

        } else if (isDate) {
            column.editor = dateEditor;

        } else if (isRollup) {
            column.editor = false;

        } else {
            column.editor = inputEditor;
        }

        if (field.name.toLowerCase() === 'tel') {
            column.formatter = function (cell) {
                const value = cell.getValue();
                if (!value) return '';
                const clean = value.replace(/\s+/g, '');
                return `<a href="tel:${clean}">${value}</a>`;
            };
            column.formatterParams = { allowHTML: true };
            column.editor = false;
        }



        return column;
    });
}
