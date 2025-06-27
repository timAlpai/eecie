document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('gce-appels-table');
    if (!container) return;

    container.innerHTML = 'Chargement des appels…';

    const userEmail = window.GCE_CURRENT_USER?.email;
    if (!userEmail) {
        container.innerHTML = '<p>Utilisateur non identifié.</p>';
        return;
    }

    Promise.all([
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/appels', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/interactions', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/appels/schema', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json())
    ])
    .then(([appelsData, interactionsData, appelsSchema]) => {
        if (!appelsData.results || !interactionsData.results || !Array.isArray(appelsSchema)) {
            container.innerHTML = '<p>Erreur lors de la récupération des données.</p>';
            return;
        }

        const appels = appelsData.results;
        const interactions = interactionsData.results;

        // Grouper les interactions par ID d'appel
        const groupedInteractions = {};
        interactions.forEach(inter => {
            if (!inter.Appel || !Array.isArray(inter.Appel)) return;
            inter.Appel.forEach(link => {
                if (!groupedInteractions[link.id]) groupedInteractions[link.id] = [];
                groupedInteractions[link.id].push(inter);
            });
        });

        // Injecter les interactions comme _children dans chaque appel
        const appelsAvecChildren = appels.map(appel => ({
            ...appel,
            _children: groupedInteractions[appel.id] || []
        }));

        // Colonnes dynamiques depuis ton système
        const columns = getTabulatorColumnsFromSchema(appelsSchema);

        // Créer et injecter le tableau
        const tableEl = document.createElement('div');
        container.innerHTML = '';
        container.appendChild(tableEl);

        new Tabulator(tableEl, {
            data: appelsAvecChildren,
            dataTree: true,
            dataTreeStartExpanded: false,
            layout: "fitColumns",
            columns: columns,
            height: "auto",
            placeholder: "Aucun appel trouvé.",
        });
    })
    .catch(err => {
        console.error("Erreur appels.js :", err);
        container.innerHTML = `<p>Erreur réseau ou serveur : ${err.message}</p>`;
    });
});
