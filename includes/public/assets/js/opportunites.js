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

        // On cherche l’utilisateur Baserow lié à cet email
        const userRow = usersData.results.find(u => u.Email === GCE_CURRENT_USER.email);
        const userId = userRow ? userRow.id : null;

        // Filtrer les opportunités assignées à cet utilisateur (si champ link_row « Assigned » par ex)
        const myOpps = userId
          ? oppData.results.filter(o => {
              const link = o.Assigned; // remplacer par le nom exact du champ
              return Array.isArray(link) && link.some(x => x.id === userId);
            })
          : [];

        container.innerHTML = '';
        const tableEl = document.createElement('div');
        container.appendChild(tableEl);

        const cols = getTabulatorColumnsFromSchema(oppSchema);
        const table = new Tabulator(tableEl, {
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
