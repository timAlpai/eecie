// global function dispo dans la page
const columns = getTabulatorColumnsFromSchema(schema);


document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('gce-users-admin-table');
    if (!container) return;

    container.innerHTML = 'Chargement des utilisateurs...';

    Promise.all([
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/utilisateurs', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/utilisateurs/schema', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json())
    ])
    .then(([data, schema]) => {
        if (!Array.isArray(data.results) || !Array.isArray(schema)) {
            container.innerHTML = '<p>Erreur de structure des données reçues.</p>';
            return;
        }
    const columns = schema.map(field => {
    const isRelation = field.type === "link_row";
    const isBoolean  = field.type === "boolean";

    return {
        title: field.name,
        field: field.name,
        editor: isRelation ? false : (isBoolean ? "tickCross" : "input"),
        formatter: isRelation
    ? function (cell) {
        const value = cell.getValue();
        if (!Array.isArray(value)) return '';
        const page = field.name.toLowerCase(); // ex: Taches → taches
        return value.map(obj => `<a href="?page=gce-${page}&id=${obj.id}">${obj.value}</a>`).join(', ');
    }
    : (isBoolean ? "tickCross" : undefined),

    };
    
});


        // Nettoyer le conteneur
        container.innerHTML = '';

        // Créer dynamiquement le conteneur Tabulator
        const tableEl = document.createElement('div');
        container.appendChild(tableEl);

        new Tabulator(tableEl, {
            data: data.results,
            layout: "fitColumns",
            columns: columns,
            cellEdited: function (cell) {
                const row = cell.getRow().getData();
                // console.log('Données modifiées:', row);
                fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/utilisateurs/' + row.id, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': EECIE_CRM.nonce
                    },
                    body: JSON.stringify(row)
                }).then(res => {
                    if (!res.ok) alert("Erreur de sauvegarde");
                });
            }
        });
    })
    .catch(error => {
        container.innerHTML = '<p>Erreur de chargement : ' + error.message + '</p>';
    });
});
