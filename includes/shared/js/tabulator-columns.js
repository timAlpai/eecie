function getTabulatorColumnsFromSchema(schema) {
    return schema.map(field => {
        const name = field.name;
        const type = field.type;

        const isRelation     = type === "link_row";
        const isBoolean      = type === "boolean";
        const isRollup       = ["formula", "lookup", "rollup"].includes(type);
        const isSingleSelect = type === "single_select";
        const isMultipleSelect = type === "multiple_select";
        const isDate         = type === "date";
        const isTime         = isDate && field.date_include_time === true;

        const col = {
            title: name,
            field: name,
            editor: false,
            formatter: undefined,
            formatterParams: {}
        };

        // 🔗 Relations (non éditables, affichés en liens)
        if (isRelation) {
            col.formatter = function (cell) {
                const value = cell.getValue();
                if (!Array.isArray(value)) return '';
                const page = name.toLowerCase();
                return value.map(obj =>
                    `<a href="?page=gce-${page}&id=${obj.id}">${obj.value}</a>`
                ).join(', ');
            };
            col.formatterParams.allowHTML = true;
        }

        // ✅ Booléens
        else if (isBoolean) {
            col.editor = "tickCross";
            col.formatter = "tickCross";
        }

        // 🚫 Champs calculés
        else if (isRollup) {
            col.editor = false;
        }

        // 🎚️ Single select
        else if (isSingleSelect && field.select_options) {
            const optionsMap = field.select_options.reduce((acc, opt) => {
                acc[opt.id] = opt.value;
                return acc;
            }, {});

            col.editor = "select";
            col.editorParams = {
                values: optionsMap
            };
            col.formatter = function (cell) {
                const id = cell.getValue();
                return optionsMap[id] || '';
            };
        }

        // 🟣 Multiple select (lecture seule)
        else if (isMultipleSelect && field.select_options) {
            col.editor = false;
            col.formatter = function (cell) {
                const ids = cell.getValue();
                if (!Array.isArray(ids)) return '';
                return ids.map(id => {
                    const opt = field.select_options.find(o => o.id === id);
                    return opt ? opt.value : '[?]';
                }).join(', ');
            };
        }

        // 📅 Dates
        else if (isDate) {
            col.editor = "input";
            col.editorParams = {
                elementAttributes: {
                    type: isTime ? "datetime-local" : "date"
                }
            };
        }

        // 📝 Texte ou valeur simple
        else {
            col.editor = "input";
        }

        return col;
    });
}
function sanitizeRowBeforeSave(row, schema) {
    const cleaned = { ...row };
    schema.forEach(field => {
        const key = field.name;
        const val = cleaned[key];

        if (field.type === "link_row") {
            cleaned[key] = Array.isArray(val)
                ? val.map(obj => ({ id: obj.id }))
                : [];
        }

        if (field.type === "single_select") {
            if (val && typeof val === 'object' && val.id) {
                cleaned[key] = val.id;
            }
        }

        if (field.type === "multiple_select") {
            if (Array.isArray(val)) {
                cleaned[key] = val.map(v => (typeof v === 'object' && v.id ? v.id : v));
            }
        }
    });

    return cleaned;
}

