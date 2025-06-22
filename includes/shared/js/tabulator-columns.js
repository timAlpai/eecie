function getTabulatorColumnsFromSchema(schema) {
  return schema.map(field => {
    const isRelation = field.type === "link_row";
    const isBoolean = field.type === "boolean";
    const isDate = field.type === "date";
    const isSelect = field.type === "single_select" || field.type === "multiple_select";
    const isRollup = field.type === "formula" || field.type === "lookup";
    const label = `🧩 Colonne interprétée : ${field.name} ${field.type}`;
    console.log(label);

    const column = {
      title: field.name,
      field: field.name,
      editor: false,
      formatter: false,
    };

    // Relations : lecture seule + lien cliquable
    if (isRelation) {
      column.formatter = function (cell) {
        const value = cell.getValue();
        if (!Array.isArray(value)) return '';
        const page = field.name.toLowerCase();
        return value.map(obj => `<a href="?page=gce-${page}&id=${obj.id}">${obj.value}</a>`).join(', ');
      };
      column.formatterParams = { allowHTML: true };
    }

    // Booléens
    else if (isBoolean) {
      column.editor = tickCrossEditor;
      column.formatter = "tickCross";
    }

    // Dates
    else if (isDate) {
      column.editor = dateEditor;
    }

    // Choix (liste déroulante)
    else if (isSelect && Array.isArray(field.select_options)) {
      const options = field.select_options.map(opt => opt.value || opt.name || opt.id);
      column.editor = selectEditor(options);
    }

    // Champs calculés → lecture seule
    else if (isRollup) {
      column.editor = false;
    }

    // Par défaut : champ texte ou numérique
    else {
      column.editor = "input";
    }

    return column;
  });
}
