document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('gce-users-admin-table');
    if (!container) return;

    container.innerHTML = 'Chargement des utilisateurs...';

    let schema = [];

    Promise.all([
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/utilisateurs', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/utilisateurs/schema', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json())
    ])
        .then(([data, fetchedSchema]) => {
            if (!Array.isArray(data.results) || !Array.isArray(fetchedSchema)) {
                container.innerHTML = '<p>Erreur de structure des données reçues.</p>';
                return;
            }

            schema = fetchedSchema; // stocker pour la sauvegarde
            const columns = getTabulatorColumnsFromSchema(schema);

            // Nettoyer le conteneur
            container.innerHTML = '';

            const tableEl = document.createElement('div');
            container.appendChild(tableEl);

            new Tabulator(tableEl, {
                data: data.results,
                layout: "fitColumns",
                columns: columns,
                cellEdited: function (cell) {
                    const row = cell.getRow().getData();
                    const cleaned = sanitizeRowBeforeSave(row, schema);

                    console.log("🔁 Données à envoyer (cleaned):", cleaned);

                    fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/utilisateurs/' + row.id, {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': EECIE_CRM.nonce
                        },
                        body: JSON.stringify(cleaned)
                    }).then(res => {
                        if (!res.ok) {
                            console.warn("❌ Erreur HTTP", res.status);
                            alert("Erreur de sauvegarde");
                        }
                        return res.json();
                    }).then(json => {
                        console.log("✅ Réponse Baserow :", json);
                    }).catch(err => {
                        console.error("🔥 Échec PUT:", err);
                    });
                }
            });
        })
        .catch(error => {
            container.innerHTML = '<p>Erreur de chargement : ' + error.message + '</p>';
        });
});
