function gceRefreshVisibleTable() {
  let parentTable = null;
  document.querySelectorAll(".tabulator").forEach(el => {
    const tab = el.__tabulator__;
    if (tab && el.offsetParent !== null) { // uniquement si visible
      parentTable = tab;
    }
  });

  if (parentTable && parentTable.replaceData) {
    console.log("🔁 Rafraîchissement de la table principale visible");
    parentTable.replaceData();
  }
}

document.addEventListener("DOMContentLoaded", () => {
  const modal = document.getElementById("gce-modal");
  const modalTitle = modal.querySelector("h2");
  const form = modal.querySelector("form");
  const container = modal.querySelector(".gce-fields");
  const submitBtn = modal.querySelector("button[type=submit]");

  let schemaCache = {};
  let popupData = {};
  let currentMode = "lecture"; // ou "écriture"
  let currentTable = "";

  async function loadSchema(table) {
    if (schemaCache[table]) return schemaCache[table];
    const response = await fetch(`/wp-json/eecie-crm/v1/${table}/schema`);
    if (!response.ok) throw new Error("Erreur schéma HTTP " + response.status);
    const json = await response.json();
    schemaCache[table] = json;
    return json;
  }

  function renderField(field, value, mode, label, fieldKey) {
    if (mode === "lecture") {
      if (field.type === "link_row") {
        const display = value?.[0]?.value || value?.[0]?.name || `ID ${value?.[0]?.id || ''}`;
        return `<div class="gce-field-row"><strong>${label}</strong><div>${display}</div></div>`;
      } else {
        return `<div class="gce-field-row"><strong>${label}</strong><div>${value || ""}</div></div>`;
      }
    }

    if (field.type === "link_row") {
      const id = value?.[0]?.id || "";
      return `<div class="gce-field-row">${label}<input type="text" id="${fieldKey}" name="${fieldKey}" value="${id}"></div>`;
    }

    const inputType = field.type === "long_text" ? "textarea" : "input";
    const val = value || "";
    return `
      <div class="gce-field-row">
        ${label}
        ${inputType === "textarea" ? `<textarea id="${fieldKey}" name="${fieldKey}">${val}</textarea>` :
        `<input type="${field.type === "number" ? "number" : "text"}" id="${fieldKey}" name="${fieldKey}" value="${val}">`}
      </div>
    `;
  }

  async function gceShowModal(data, table, mode = "lecture", visibleFields = null) {
    try {
      popupData = data;
      currentTable = table;
      currentMode = mode;
      const schema = await loadSchema(table);

      const record = {};
      for (const [key, val] of Object.entries(data)) {
        if (Array.isArray(val) && val.length && typeof val[0] === "object" && "id" in val[0]) {
          record[`field_${key}`] = val;
        }
      }

      container.innerHTML = "";

      for (const [fieldKey, field] of Object.entries(schema.fields)) {
        const label = field.name;
        const value = record[fieldKey];
        const showField = !visibleFields || visibleFields.includes(field.name) || visibleFields.includes(label);
        if (!showField) continue;
        container.innerHTML += renderField(field, value, mode, label, fieldKey);
      }

      modalTitle.textContent = `${mode === "écriture" ? "Nouveau" : "Fiche"} ${table}`;
      form.dataset.table = table;
      form.dataset.mode = mode;
      form.dataset.schema = JSON.stringify(schema);
      modal.style.display = "block";
    } catch (err) {
      console.error("❌ Erreur chargement schéma :", err);
    }
  }

  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    const formData = new FormData(form);
    const table = form.dataset.table;
    const schema = JSON.parse(form.dataset.schema);
    const data = {};

    for (const [key, field] of Object.entries(schema.fields)) {
      const val = formData.get(key);
      if (val === null || val === "") continue;
      if (field.type === "number") {
        data[key] = parseFloat(val);
      } else if (field.type === "link_row") {
        data[key] = [{ id: parseInt(val) }];
      } else {
        data[key] = val;
      }
    }

    try {
      const res = await fetch(`/wp-json/eecie-crm/v1/${table}`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(data)
      });

      if (!res.ok) throw new Error("Erreur HTTP " + res.status);
      const result = await res.json();
      console.log(`✅ ${table} créé :`, result);
      //modal.style.display = "none";

      // 🔁 Rafraîchissement ciblé
      if (popupData._gceRefreshRow && typeof popupData._gceRefreshRow.update === "function") {
        console.log("🔁 Rafraîchissement ligne parent");
        popupData._gceRefreshRow.update();
      } else {
        console.log("🔁 Rafraîchissement tableau visible");
        gceRefreshVisibleTable();
      }

    } catch (err) {
      console.error("❌ Erreur enregistrement :", err);
    }
  });

  window.gceShowModal = gceShowModal;

  document.querySelectorAll(".gce-popup-link").forEach(link => {
    link.addEventListener("click", async (e) => {
      e.preventDefault();
      const table = link.dataset.table;
      const id = link.dataset.id;
      try {
        const schema = await loadSchema(table);
        const response = await fetch(`/wp-json/eecie-crm/v1/${table}/${id}`);
        if (!response.ok) throw new Error("Erreur HTTP " + response.status);
        const json = await response.json();
        gceShowModal(json, table);
      } catch (err) {
        console.error("❌ Schéma manquant pour", table, err);
      }
    });
  });
});


function gceShowModal(data = {}, tableName, mode = "lecture", visibleFields = null) {
  const schema = window.gceSchemas?.[tableName];
  if (!schema || !Array.isArray(schema)) {
    console.error(`❌ Schéma manquant pour ${tableName}`);
    alert("Schéma non disponible.");
    return;
  }

  // Filtrage des champs visibles (par noms ou field_ids)
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

  const title = data.id
    ? `${tableName.charAt(0).toUpperCase() + tableName.slice(1)} #${data.id}`
    : `Nouveau ${tableName}`;

  const contentHtml = filteredSchema.map((field) => {
    const fieldKey = `field_${field.id}`;
    const label = `<label for="${fieldKey}"><strong>${field.name}</strong></label>`;
    let value = data?.[fieldKey] ?? "";

    // Formatage lecture (lecture uniquement)
    if (mode === "lecture") {
      if (Array.isArray(value)) {
        value = value.map(v =>
          typeof v === "object" ? (v.value || JSON.stringify(v)) : v
        ).join(', ');
      } else if (typeof value === "object" && value !== null) {
        value = value?.value ?? JSON.stringify(value);
      }
      return `<div class="gce-field-row">${label}<div>${value}</div></div>`;
    }

    // Formatage écriture
    if (field.type === "boolean") {
      return `
        <div class="gce-field-row">${label}
          <select name="${fieldKey}" id="${fieldKey}">
            <option value="">—</option>
            <option value="true" ${value === true || value === "true" ? "selected" : ""}>Oui</option>
            <option value="false" ${value === false || value === "false" ? "selected" : ""}>Non</option>
          </select>
        </div>`;
    }

    if (field.type === "single_select" && field.select_options) {
      const options = field.select_options.map(opt =>
        `<option value="${opt.id}" ${opt.id == value?.id ? "selected" : ""}>${opt.value}</option>`
      ).join("");
      return `<div class="gce-field-row">${label}<select name="${fieldKey}" id="${fieldKey}">${options}</select></div>`;
    }

    if (field.type === "link_row") {
      const first = Array.isArray(value) ? value[0] : null;
      const display = first?.value || first?.name || `ID ${first?.id || ''}`;
      const hiddenId = first?.id || "";
      return `
        <div class="gce-field-row">${label}
          <input type="text" value="${display}" readonly>
          <input type="hidden" name="${fieldKey}" id="${fieldKey}" value="${hiddenId}">
        </div>`;
    }

    return `
      <div class="gce-field-row">${label}
        <input type="text" id="${fieldKey}" name="${fieldKey}" value="${value}">
      </div>`;
  }).join("");

  modal.innerHTML = `
    <button class="gce-modal-close">✖</button>
    <h3>${title}</h3>
    <form class="gce-modal-content">
      ${contentHtml}
      ${mode === "écriture" ? `<button type="submit" class="gce-submit-btn">💾 Enregistrer</button>` : ""}
    </form>
  `;

  overlay.appendChild(modal);
  document.body.appendChild(overlay);

  const close = () => overlay.remove();
  overlay.addEventListener("click", (e) => {
    if (e.target === overlay) close();
  });
  modal.querySelector(".gce-modal-close").addEventListener("click", close);

  // Gestion enregistrement
  if (mode === "écriture") {
    modal.querySelector("form").addEventListener("submit", async (e) => {
      e.preventDefault();
      const formData = new FormData(e.target);
      const payload = {};

      for (let field of filteredSchema) {
        const key = `field_${field.id}`;
        const raw = formData.get(key);
        if (raw === null || raw === "") continue;

        if (field.type === "boolean") {
          payload[key] = raw === "true";
        } else if (["number", "decimal"].includes(field.type)) {
          payload[key] = isNaN(raw) ? raw : Number(raw);
        } else if (field.type === "single_select") {
          payload[key] = parseInt(raw);

        } else if (field.type === "link_row") {
          // On suppose que raw est un nombre (déjà injecté dans input readonly)
          const id = parseInt(raw);
          if (!isNaN(id)) payload[key] = [id]; // 👈 C’est bien un tableau d’un entier
        }
        else if (field.type === "link_row") {
          payload[key] = [{ id: parseInt(raw) }];
        } else {
          payload[key] = raw;
        }
      }
            const method = data.id ? "PATCH" : "POST";
      const url = data.id
        ? `${EECIE_CRM.rest_url}eecie-crm/v1/${tableName}/${data.id}`
        : `${EECIE_CRM.rest_url}eecie-crm/v1/${tableName}`;

      try {
        const res = await fetch(url, {
          method,
          headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": EECIE_CRM.nonce,
          },
          body: JSON.stringify(payload),
        });

        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`);
        const result = await res.json();
        console.log(`✅ ${tableName} ${method === "POST" ? "créé" : "mis à jour"} :`, result);
        gceRefreshVisibleTable();
        close();

        // 🔁 Forcer le rafraîchissement complet de toutes les tables visibles
        const allTabulators = document.querySelectorAll(".tabulator");
        allTabulators.forEach(el => {
          const tab = el.__tabulator__;
          if (tab?.replaceData) {
            tab.replaceData();
          }
        });

      } catch (err) {
        console.error("❌ Erreur enregistrement :", err);
        alert("Erreur lors de l'enregistrement.");
      }

    });
  }
}
