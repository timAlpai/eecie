function getTabulatorColumnsFromSchema(schema, tableName = "") {
    return schema.map(field => {
        const isRelation = field.type === "link_row";
        const isBoolean = field.type === "boolean";
        const isDate = field.type === "date";
        const isSelect = field.type === "single_select" || field.type === "multiple_select";
        const isRollup = field.type === "formula" || field.type === "lookup";
        const isAttachment = field.type === "file";
        const isStatus = field.name === "Status";
        const isStatut = field.name === "statut";

        const column = {
            title: field.name,
            field: field.name,
            editor: false,
            formatter: false,
        };

        if (isAttachment) {
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

        } else if (isSelect && Array.isArray(field.select_options)) {
            const options = field.select_options.map(opt => opt.value || opt.name || opt.id);
            column.editor = selectEditor(options);
            column.formatter = function (cell) {
                const val = cell.getValue();
                if (!val || typeof val !== 'object') return '';
                const label = val.value || val.name || '';
                const color = val.color || 'gray';
                return `<span class="gce-badge gce-color-${color}">${label}</span>`;
            };
            column.formatterParams = { allowHTML: true };

        } else  if (isRelation) {
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
        }  else if (isBoolean) {
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
