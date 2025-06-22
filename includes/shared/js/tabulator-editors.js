// tabulator-editors.js

// ✅ TickCross Editor (booléens)
function tickCrossEditor(cell, onRendered, success, cancel) {
    const value = cell.getValue();
    const input = document.createElement("input");
    input.type = "checkbox";
    input.checked = !!value;
    input.style.margin = "auto";
    input.style.display = "block";

    onRendered(() => input.focus());

    function onChange() {
        success(input.checked); // ✅ C'est ça qui déclenche cellEdited
    }

    input.addEventListener("change", onChange);
    input.addEventListener("blur", onChange);
    input.addEventListener("keydown", e => {
        if (e.key === "Enter") onChange();
        if (e.key === "Escape") cancel();
    });

    return input;
}

// ✅ Select Editor (single_choice, multiple_choice)
function selectEditor(options = []) {
    return function(cell, onRendered, success, cancel) {
        const value = cell.getValue();
        const input = document.createElement("select");

        options.forEach(opt => {
            const o = document.createElement("option");
            o.value = opt;
            o.textContent = opt;
            if (opt === value) o.selected = true;
            input.appendChild(o);
        });

        onRendered(() => input.focus());

        function onChange() {
            success(input.value);
        }

        input.addEventListener("change", onChange);
        input.addEventListener("blur", onChange);
        input.addEventListener("keydown", e => {
            if (e.key === "Enter") onChange();
            if (e.key === "Escape") cancel();
        });

        return input;
    };
}

// ✅ Date Editor (format dd/MM/yyyy ↔ yyyy-MM-dd)
function dateEditor(cell, onRendered, success, cancel) {
    const luxon = window.luxon;
    const cellValue = luxon.DateTime.fromFormat(cell.getValue(), "dd/MM/yyyy").toFormat("yyyy-MM-dd");
    const input = document.createElement("input");

    input.setAttribute("type", "date");
    input.style.padding = "4px";
    input.style.width = "100%";
    input.style.boxSizing = "border-box";
    input.value = cellValue;

    onRendered(() => {
        input.focus();
        input.style.height = "100%";
    });

    function onChange() {
        if (input.value !== cellValue) {
            success(luxon.DateTime.fromFormat(input.value, "yyyy-MM-dd").toFormat("dd/MM/yyyy"));
        } else {
            cancel();
        }
    }

    input.addEventListener("blur", onChange);
    input.addEventListener("keydown", function(e) {
        if (e.key === "Enter") onChange();
        if (e.key === "Escape") cancel();
    });

    return input;
}
// Exports dans la portée globale
window.tickCrossEditor = tickCrossEditor;
window.selectEditor = selectEditor;
window.dateEditor = dateEditor;
