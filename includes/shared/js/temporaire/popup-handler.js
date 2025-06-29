document.addEventListener('DOMContentLoaded', () => {
  document.body.addEventListener('click', async (e) => {
    const link = e.target.closest('.gce-popup-link');
    if (!link) return;

    e.preventDefault();
    const normalize = s => s.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();

    const rawTable = link.dataset.table;
    const normTable = normalize(rawTable);

    const table = ({
      assigne: 'utilisateurs',
      contact: 'contacts',
      opportunite: 'task_input',
      task_input: 'task_input',
      t1_user: 'utilisateurs',
      appel: 'appels',
      interaction: 'interactions',
      devis: 'devis',
      article: 'articles_devis',
      articles_devis: 'articles_devis'
    })[normTable] || normTable;

    const id = link.dataset.id;
    const mode = link.dataset.mode || "lecture";

    if (!table) return;

    if (mode === "écriture" && !id) {
      // Création sans fetch
      gceShowModal({}, table, mode);
      return;
    }

    if (!id) return;

    try {
      const res = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/row/${table}/${id}`, {
        headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
      });
      if (!res.ok) throw new Error('Erreur HTTP ' + res.status);
      const data = await res.json();
      gceShowModal(data, table, mode);
    } catch (err) {
      console.error('Erreur popup fetch:', err);
      alert("Impossible de charger les détails.");
    }
  });
});

function gceShowModal(data = {}, tableName, mode = "lecture") {
  const overlay = document.createElement('div');
  overlay.className = 'gce-modal-overlay';

  const modal = document.createElement('div');
  modal.className = 'gce-modal';

  const title = data.id ? `${tableName.charAt(0).toUpperCase() + tableName.slice(1)} #${data.id}` : `Nouveau ${tableName}`;

  const contentHtml = Object.entries(data).map(([k, v]) => {
    let value = '';
    if (Array.isArray(v)) {
      value = v.map(item => typeof item === 'object' ? item.value || JSON.stringify(item) : item).join(', ');
    } else if (typeof v === 'object' && v !== null) {
      value = v.value || JSON.stringify(v);
    } else {
      value = v || '';
    }

    if (mode === "écriture") {
      return `
        <div class="gce-field-row">
          <label for="${k}"><strong>${k}</strong></label>
          <input type="text" id="${k}" name="${k}" value="${value}">
        </div>`;
    } else {
      return `
        <div class="gce-field-row">
          <strong>${k}</strong><div>${value}</div>
        </div>`;
    }
  }).join('');

  modal.innerHTML = `
    <button class="gce-modal-close">✖</button>
    <h3>${title}</h3>
    <form class="gce-modal-content">
      ${contentHtml}
      ${mode === "écriture" ? `<button type="submit" class="gce-submit-btn">💾 Enregistrer</button>` : ''}
    </form>
  `;

  overlay.appendChild(modal);
  document.body.appendChild(overlay);

  const close = () => overlay.remove();

  overlay.addEventListener('click', (e) => {
    if (e.target === overlay) close();
  });

  modal.querySelector('.gce-modal-close').addEventListener('click', close);

  if (mode === "écriture") {
    modal.querySelector('form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const formData = new FormData(e.target);
      const payload = {};
      formData.forEach((v, k) => payload[k] = v);

      const method = data.id ? 'PATCH' : 'POST';
      const url = data.id
        ? `${EECIE_CRM.rest_url}eecie-crm/v1/${tableName}/${data.id}`
        : `${EECIE_CRM.rest_url}eecie-crm/v1/${tableName}`;

      try {
        const res = await fetch(url, {
          method: method,
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': EECIE_CRM.nonce
          },
          body: JSON.stringify(payload)
        });

        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`);
        const result = await res.json();
        console.log(`✅ ${tableName} ${method === 'POST' ? 'créé' : 'mis à jour'} :`, result);
        close();
        if (window.devisTable?.replaceData) window.devisTable.replaceData(); // refresh tableau si défini
      } catch (err) {
        console.error("❌ Erreur enregistrement :", err);
        alert("Erreur lors de l'enregistrement.");
      }
    });
  }
}
