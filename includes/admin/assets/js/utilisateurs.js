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
            //ATTENTION MODIF

            columns.forEach(col => {
                if (!col.editor && typeof col.field === 'string') {
                    // Forcer un éditeur par défaut pour test
                    if (col.field.toLowerCase() === "active") {
                        col.editor = "tickCross";
                    } else {
                        col.editor = "input";
                    }
                }
            });
            columns.forEach(col => {
                if (!col.editor) col.editor = "input";

                // 🔍 forcer un validateur bidon
                col.validator = [
                    { type: "required" }
                ];
            });


            //FIN MODIF
            // Nettoyer le conteneur
            container.innerHTML = '';

            const tableEl = document.createElement('div');
            container.appendChild(tableEl);

            new Tabulator(tableEl, {
                data: data.results,
                layout: "fitColumns",
                reactiveData: false, // important !
                dataTree: false,
                autoResize: true,
                height: "auto",
                columns: columns,

                cellClick: function (e, cell) {
                    console.log("🖱️ Click sur :", cell.getField(), "→", cell.getValue());
                },

                cellEdited: function (cell) {
                    console.log("✅ Édition captée !");
                    const row = cell.getRow().getData();
                    const cleaned = sanitizeRowBeforeSave(row, schema);

                    fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/utilisateurs/' + row.id, {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': EECIE_CRM.nonce
                        },
                        body: JSON.stringify(cleaned)
                    }).then(res => res.json()).then(json => {
                        console.log("✅ Réponse Baserow :", json);
                    }).catch(err => {
                        console.error("🔥 Erreur PUT :", err);
                    });
                }
            });

        })
        .catch(error => {
            container.innerHTML = '<p>Erreur de chargement : ' + error.message + '</p>';
        });
});
