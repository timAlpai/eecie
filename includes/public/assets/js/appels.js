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
        }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/interactions/schema', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json())
    ])
    .then(([appelsData, interactionsData, appelsSchema, interactionsSchema]) => {
        const appels = appelsData.results || [];
        const interactions = interactionsData.results || [];

        const groupedInteractions = {};
        interactions.forEach(inter => {
            const appelField = Object.keys(inter).find(k => k.toLowerCase().startsWith("appel"));
            if (!appelField || !Array.isArray(inter[appelField])) return;
            inter[appelField].forEach(link => {
                if (!groupedInteractions[link.id]) groupedInteractions[link.id] = [];
                groupedInteractions[link.id].push(inter);
                console.log(`🧷 Interaction ${inter.id} liée à appel ${link.id}`);
            });
        });

        const appelsAvecChildren = appels.map(appel => {
            const enfants = groupedInteractions[appel.id];
            console.log(`📞 Appel ID ${appel.id} → ${enfants?.length || 0} interaction(s)`);
            return {
                ...appel,
                _children: Array.isArray(enfants) && enfants.length > 0 ? enfants : []
            };
        });

        const columns = getTabulatorColumnsFromSchema(appelsSchema);
        const interactionColumns = getTabulatorColumnsFromSchema(interactionsSchema);

        const tableEl = document.createElement('div');
        container.innerHTML = '';
        container.appendChild(tableEl);

       const table =  new Tabulator(tableEl, {
    data: appelsAvecChildren,
    layout: "fitColumns",
    columns: columns,
    columnDefaults: {
        resizable: true,
        widthGrow: 1
    },
    height: "auto",
    placeholder: "Aucun appel trouvé.",
    responsiveLayout: "collapse",

    rowFormatter: function (row) {
        const data = row.getData()._children;
        if (!Array.isArray(data) || data.length === 0) return;

        const holderEl = document.createElement("div");
        holderEl.style.margin = "10px";
        holderEl.style.borderTop = "1px solid #ddd";

        const table = document.createElement("div");
        holderEl.appendChild(table);
        row.getElement().appendChild(holderEl);

       new Tabulator(table, {
            data: data,
            layout: "fitColumns",
            height: "auto",
            columns: [
                { title: "ID", field: "id" },
                { title: "Type", field: "types_interactions.value" },
                { title: "Compte rendu", field: "compte_rendu" },
                { title: "Lien", field: "id", formatter: (cell) => {
                    const id = cell.getValue();
                    return `<a href="#" class="gce-popup-link" data-table="interactions" data-id="${id}">🔍 voir</a>`;
                }}
            ],
            renderComplete: () => {
                // ⚠️ IMPORTANT : on redéclenche le popup handler
                if (typeof initializePopupHandlers === "function") {
                    initializePopupHandlers();
                }
            }
        });
    }
});

        
        window.appelsTable = table;
    })
    .catch(err => {
        console.error("Erreur appels.js :", err);
        container.innerHTML = `<p>Erreur réseau ou serveur : ${err.message}</p>`;
    });
});
