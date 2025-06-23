function getTabulatorColumnsFromSchema(schema) {
    return schema.map(field => {
        const isRelation = field.type === "link_row";
        const isBoolean = field.type === "boolean";
        const isDate = field.type === "date";
        const isSelect = field.type === "single_select" || field.type === "multiple_select";
        const isRollup = field.type === "formula" || field.type === "lookup";
        const isAttachment = field.name === "Attachement";
        const isStatus = field.name === "Status"; // ← pour formatter personnalisé

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

        } else if (isStatus) {
            column.formatter = function (cell) {
                const val = cell.getValue();
                if (!val || typeof val !== 'object') return '';
                const colorClass = 'gce-color-' + (val.color || 'gray');
                const label = val.value || val.name || '';
                return `<span class="gce-badge ${colorClass}">${label}</span>`;
            };
            column.formatterParams = { allowHTML: true };
            column.editor = false; // pas éditable manuellement
        } else if (isRelation) {
            column.formatter = function (cell) {
                const value = cell.getValue();
                if (!Array.isArray(value)) return '';
                const page = field.name.toLowerCase();
                return value.map(obj =>
                    `<a href="#" class="gce-popup-link" data-table="${page}" data-id="${obj.id}">${obj.value}</a>`
                ).join(', ');
            };
            column.formatterParams = { allowHTML: true };

        } else if (isBoolean) {
            column.editor = tickCrossEditor;
            column.formatter = "tickCross";

        } else if (isDate) {
            column.editor = dateEditor;

        } else if (isSelect && Array.isArray(field.select_options)) {
            const options = field.select_options.map(opt => opt.value || opt.name || opt.id);
            column.editor = selectEditor(options);

        } else if (isRollup) {
            column.editor = false;

        } else {
            column.editor = inputEditor;
        }

        return column;
    });
}
