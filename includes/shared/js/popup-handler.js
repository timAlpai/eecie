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

    if (!table || !id) return;

    try {
      const res = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/row/${table}/${id}`, {
        headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
      });
      if (!res.ok) throw new Error('Erreur HTTP ' + res.status);
      const data = await res.json();
      gceShowModal(data, table);
    } catch (err) {
      console.error('Erreur popup fetch:', err);
      alert("Impossible de charger les détails.");
    }
  });
});

// Simple modal renderer
function gceShowModal(data, tableName) {
  const overlay = document.createElement('div');
  overlay.className = 'gce-modal-overlay';

  const modal = document.createElement('div');
  modal.className = 'gce-modal';
  modal.innerHTML = `
    <button class="gce-modal-close">✖</button>
    <h3>${tableName.charAt(0).toUpperCase() + tableName.slice(1)} #${data.id}</h3>
    <div class="gce-modal-content">
      ${Object.entries(data).map(([k, v]) => {
    let value;
    if (Array.isArray(v)) {
      value = v.map(item => typeof item === 'object' ? item.value || JSON.stringify(item) : item).join(', ');
    } else if (typeof v === 'object' && v !== null) {
      value = v.value || JSON.stringify(v);
    } else {
      value = v;
    }

    return `<div class="gce-field-row"><strong>${k}</strong><div>${value}</div></div>`;
  }).join('')}
    </div>
  `;

  overlay.appendChild(modal);
  document.body.appendChild(overlay);

  const close = () => overlay.remove();

  overlay.addEventListener('click', (e) => {
    if (e.target === overlay) close();
  });

  modal.querySelector('.gce-modal-close').addEventListener('click', close);
}
