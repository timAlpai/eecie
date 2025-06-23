// FICHIER : includes/public/assets/js/opportunites.js

// Ce script charge les opportunités Baserow assignées à l'utilisateur WordPress connecté

document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('gce-opportunites-table');
    if (!container) return;

    container.innerHTML = 'Chargement des opportunités...';

    Promise.all([
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/opportunites', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/opportunites/schema', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/utilisateurs', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json())
    ])
    .then(([oppData, oppSchema, usersData]) => {
        if (!oppData.results || !oppSchema || !usersData.results) {
            container.innerHTML = '<p>Erreur dans la réception des données.</p>';
            return;
        }

        // Match email (insensible à la casse)
        const userRow = usersData.results.find(u =>
            u.Email?.toLowerCase() === GCE_CURRENT_USER.email?.toLowerCase()
        );
        const userId = userRow ? userRow.id : null;

        // Identifier dynamiquement le champ de liaison avec les utilisateurs
        const assignedField = (() => {
    const fromSchema = oppSchema.find(f =>
        f.type === 'link_row' && f.name === 'T1_user'
    );
    return fromSchema?.name || 'T1_user';
})();

        const myOpps = userId ? oppData.results.filter(o => {
      const link = o[assignedField];
      return Array.isArray(link) && link.some(x => x.id === userId);
  })
  : [];


        container.innerHTML = '';

        if (myOpps.length === 0) {
            container.innerHTML = '<p>Aucune opportunité assignée pour l’instant.</p>';
            return;
        }

        const tableEl = document.createElement('div');
        container.appendChild(tableEl);

        const cols = getTabulatorColumnsFromSchema(oppSchema);
        new Tabulator(tableEl, {
            data: myOpps,
            layout: "fitColumns",
            columns: cols,
            reactiveData: false,
            height: "auto",
        });
    })
    .catch(err => {
        container.innerHTML = '<p>Erreur de chargement : ' + err.message + '</p>';
    });
});
