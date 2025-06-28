//  tickCrossEditor : pour les booléens
function tickCrossEditor(cell, onRendered, success, cancel) {
    const input = document.createElement("input");
    input.type = "checkbox";
    input.checked = !!cell.getValue();
    input.style.margin = "auto";
    input.style.display = "block";

    onRendered(() => input.focus());

    const onChange = () => success(input.checked);
    input.addEventListener("change", onChange);
    input.addEventListener("blur", onChange);
    input.addEventListener("keydown", e => {
        if (e.key === "Enter") onChange();
        if (e.key === "Escape") cancel();
    });

    return input;
}

//  selectEditor : pour les single_select / multiple_select
function selectEditor(options = []) {
    return function (cell, onRendered, success, cancel) {
        const input = document.createElement("select");
        options.forEach(opt => {
            const o = document.createElement("option");
            o.value = opt;
            o.textContent = opt;
            if (opt === cell.getValue()) o.selected = true;
            input.appendChild(o);
        });

        onRendered(() => input.focus());

        input.addEventListener("change", () => success(input.value));
        input.addEventListener("blur", () => success(input.value));
        input.addEventListener("keydown", e => {
            if (e.key === "Enter") success(input.value);
            if (e.key === "Escape") cancel();
        });

        return input;
    };
}

//  dateEditor : utilise Luxon
function dateEditor(cell, onRendered, success, cancel) {
    const luxon = window.luxon;
    const cellValue = luxon.DateTime.fromFormat(cell.getValue(), "dd/MM/yyyy").toFormat("yyyy-MM-dd");
    const input = document.createElement("input");

    input.type = "date";
    input.value = cellValue;
    input.style.padding = "4px";
    input.style.width = "100%";
    input.style.boxSizing = "border-box";

    onRendered(() => {
        input.focus();
        input.style.height = "100%";
    });

    input.addEventListener("blur", () => {
        if (input.value !== cellValue) {
            success(luxon.DateTime.fromFormat(input.value, "yyyy-MM-dd").toFormat("dd/MM/yyyy"));
        } else {
            cancel();
        }
    });

    input.addEventListener("keydown", e => {
        if (e.key === "Enter") {
            success(luxon.DateTime.fromFormat(input.value, "yyyy-MM-dd").toFormat("dd/MM/yyyy"));
        }
        if (e.key === "Escape") cancel();
    });

    return input;
}

//  inputEditor : pour texte / number simple
function inputEditor(cell, onRendered, success, cancel) {
    const input = document.createElement("input");
    input.type = "text";
    input.value = cell.getValue() || "";
    input.style.width = "100%";

    onRendered(() => input.focus());

    let initialValue = input.value;

    const applyChange = () => {
        if (input.value !== initialValue) {
            console.log("✅ Changement détecté →", input.value);
            success(input.value);
        } else {
            console.log("🚫 Aucun changement détecté");
            cancel();
        }
    };

    input.addEventListener("blur", applyChange);
    input.addEventListener("keydown", e => {
        if (e.key === "Enter") applyChange();
        if (e.key === "Escape") cancel();
    });

    return input;
}


// sanitizeRowBeforeSave : nettoie les champs avant envoi vers Baserow
function sanitizeRowBeforeSave(row, schema) {
    const cleaned = {};
    console.log("🧼 sanitizeRowBeforeSave → row brut :", row);

    schema.forEach(field => {
        const name = field.name;
        const type = field.type;
        const id = field.id;

        // ❌ Ne pas envoyer les champs non modifiables
        if (["formula", "lookup", "rollup", "autonumber", "created_on", "modified_on"].includes(type)) {
            return;
        }

        // Recherche la valeur dans row par le vrai nom OU l'ID réel (champ Tabulator ou Baserow brut)
        let value = row[name] ?? row["field_" + id];


        if (type === "boolean") {
            cleaned["field_" + id] = !!value;

        } else if (type === "single_select" && value && typeof value === "object") {
            cleaned["field_" + id] = value.id;

        } else if (type === "multiple_select" && Array.isArray(value)) {
            cleaned["field_" + id] = value.map(v => v.id);

        } else if (type === "link_row" && Array.isArray(value)) {
            cleaned["field_" + id] = value.map(v => v.id);

        } else {
            cleaned["field_" + id] = value;
        }
    });

    return cleaned;
}

window.sanitizeRowBeforeSave = sanitizeRowBeforeSave;


window.gceTabulatorSaveHandler = function (tableName) {
    return function (cell) {
        console.log("🧪 cellEdited déclenché → table:", tableName, "champ:", cell.getField());


        const row = cell.getRow().getData();
        const id = row.id;
        const schema = window.gceSchemas?.[tableName];

        if (!schema) {
            console.warn("⚠️ Aucun schéma trouvé pour", tableName);
            return;
        }
        console.log("ben ici c'est encore vivant");
        const cleaned = sanitizeRowBeforeSave(row, schema);
        console.log("📤 Envoi PATCH vers :", `${EECIE_CRM.rest_url}eecie-crm/v1/${tableName}/${id}`);
        console.log("📦 Données envoyées :", JSON.stringify(cleaned));
        fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/${tableName}/${id}`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': EECIE_CRM.nonce
            },
            body: JSON.stringify(cleaned)
        }).then(res => {
            if (!res.ok) throw new Error(`Erreur ${res.status}`);
            console.log(`✅ ${tableName} #${id} mis à jour`);
        }).catch(err => {
            console.error("❌ Échec de mise à jour", err);
        });
    };
};

