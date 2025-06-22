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

        schema = fetchedSchema;
        window.gceUserSchema = schema;

        const columns = getTabulatorColumnsFromSchema(schema);
        container.innerHTML = '';
        const tableEl = document.createElement('div');
        container.appendChild(tableEl);

        const tableInstance = new Tabulator(tableEl, {
            data: data.results,
            layout: "fitColumns",
            columns: columns,
            reactiveData: false,
            height: "auto",
            cellClick: function (e, cell) {
                console.log("Click sur :", cell.getField(), "→", cell.getValue());
            }
        });

        window.gceUserTable = tableInstance;

        tableInstance.on("cellEdited", function(cell){
            console.log(" cellEdited déclenché →", cell.getField(), cell.getValue());

            const row = cell.getRow().getData();
            const cleaned = sanitizeRowBeforeSave(row, gceUserSchema);

            console.log("Données envoyées :", cleaned);

            fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/utilisateurs/' + row.id, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': EECIE_CRM.nonce
                },
                body: JSON.stringify(cleaned)
            })
            .then(res => {
                if (!res.ok) {
                    console.warn("Erreur HTTP", res.status);
                    alert("Erreur lors de la sauvegarde.");
                }
                return res.json();
            })
            .then(json => {
                console.log(" Réponse Baserow :", json);
            })
            .catch(err => {
                console.error(" Erreur PUT :", err);
            });
        });
    })
    .catch(error => {
        container.innerHTML = '<p>Erreur de chargement : ' + error.message + '</p>';
    });
});
document.getElementById('gce-add-user-btn').addEventListener('click', () => {
    document.getElementById('gce-add-user-modal').style.display = 'block';
});

document.getElementById('gce-cancel-user').addEventListener('click', () => {
    document.getElementById('gce-add-user-modal').style.display = 'none';
});

document.getElementById('gce-submit-user').addEventListener('click', () => {
    const nom = document.getElementById('gce-new-user-nom').value.trim();
    const email = document.getElementById('gce-new-user-email').value.trim();
    const actif = document.getElementById('gce-new-user-actif').value === 'true';

    if (!nom || !email) {
        alert("Le nom et l'email sont requis.");
        return;
    }

    const newUser = {
        Name: nom,
        Email: email,
        Active: actif,
        Task_in_progress: 0
    };

    const cleaned = sanitizeRowBeforeSave(newUser, gceUserSchema);

    fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/utilisateurs', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': EECIE_CRM.nonce
        },
        body: JSON.stringify(cleaned)
    })
    .then(res => res.json())
    .then(json => {
        if (json && json.id) {
            window.gceUserTable.addData([json], true);
            document.getElementById('gce-add-user-modal').style.display = 'none';
            document.getElementById('gce-new-user-nom').value = '';
            document.getElementById('gce-new-user-email').value = '';
        } else {
            console.warn("Erreur création :", json);
            alert("Échec de la création.");
        }
    })
    .catch(err => {
        console.error("Erreur POST:", err);
        alert("Erreur réseau ou serveur.");
    });
});
