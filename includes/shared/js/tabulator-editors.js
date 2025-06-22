// tabulator-editors.js

// ✅ TickCross Editor (booléens)
function tickCrossEditor(cell, onRendered, success, cancel) {
  const value = !!cell.getValue();
  const input = document.createElement("input");
  input.type = "checkbox";
  input.checked = value;
  input.style.margin = "auto";
  input.style.display = "block";

  onRendered(() => input.focus());

  function onChange() {
    success(input.checked);
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
function selectEditor(values = []) {
  return function(cell, onRendered, success, cancel) {
    const value = cell.getValue();
    const input = document.createElement("select");
    input.style.width = "100%";
    input.style.padding = "4px";

    values.forEach(val => {
      const opt = document.createElement("option");
      opt.value = val.value;
      opt.textContent = val.label || val.value;
      if (val.value === value) opt.selected = true;
      input.appendChild(opt);
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
  }
}

// ✅ Date Editor (format dd/MM/yyyy ↔ yyyy-MM-dd)
function dateEditor(cell, onRendered, success, cancel) {
  const raw = cell.getValue();
  let formatted = raw;
  try {
    formatted = luxon.DateTime.fromFormat(raw, "dd/MM/yyyy").toFormat("yyyy-MM-dd");
  } catch (e) {}

  const input = document.createElement("input");
  input.type = "date";
  input.value = formatted;
  input.style.width = "100%";
  input.style.padding = "4px";

  onRendered(() => input.focus());

  function onChange() {
    try {
      const output = luxon.DateTime.fromFormat(input.value, "yyyy-MM-dd").toFormat("dd/MM/yyyy");
      success(output);
    } catch (e) {
      cancel();
    }
  }

  input.addEventListener("change", onChange);
  input.addEventListener("blur", onChange);
  input.addEventListener("keydown", e => {
    if (e.key === "Enter") onChange();
    if (e.key === "Escape") cancel();
  });

  return input;
}

// Exports dans la portée globale
window.tickCrossEditor = tickCrossEditor;
window.selectEditor = selectEditor;
window.dateEditor = dateEditor;
