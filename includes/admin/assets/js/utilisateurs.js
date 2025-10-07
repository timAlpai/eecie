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

        // On exclut le champ 'sec1' de l'affichage automatique
         const filteredSchema = fetchedSchema.filter(field => field.name !== 'sec1' && field.name !== 'ncsec');
        const columns = getTabulatorColumnsFromSchema(filteredSchema, 'utilisateurs');
        
        // --- DÉBUT DE LA MODIFICATION PRINCIPALE ---
        const nextcloudPasswordColumn = {
            title: "NC MDP",
            headerSort: false,
            width: 150,
            hozAlign: "center",
            formatter: function(cell, formatterParams, onRendered) {
                return `<button class="button button-small">Configurer NC</button>`;
            },
            cellClick: function(e, cell) {
                const rowData = cell.getRow().getData();
                const newPassword = prompt(`Entrez le mot de passe d'application Nextcloud pour "${rowData.Name}":`);

                if (newPassword === null) return; // Annulé

                if (newPassword.trim() === "") {
                    alert("Le mot de passe ne peut pas être vide.");
                    return;
                }

                // Appel au nouvel endpoint sécurisé
                fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/utilisateurs/${rowData.id}/update-nc-password`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': EECIE_CRM.nonce
                    },
                    body: JSON.stringify({ nc_password: newPassword })
                })
                .then(res => {
                    if (!res.ok) {
                        alert("Erreur lors de la mise à jour du mot de passe Nextcloud !");
                        throw new Error("Nextcloud password update failed");
                    }
                    return res.json();
                })
                .then(() => {
                    alert("Le mot de passe d'application Nextcloud a été enregistré avec succès.");
                })
                .catch(err => {
                    console.error("Erreur PATCH Nextcloud password:", err);
                });
            }
        };
        // Colonne pour changer le mot de passe
        const passwordColumn = {
            title: "Mot de passe",
            headerSort: false,
            width: 150,
            hozAlign: "center",
            formatter: function(cell, formatterParams, onRendered) {
                return `<button class="button button-small">Changer MDP</button>`;
            },
            cellClick: function(e, cell) {
                const rowData = cell.getRow().getData();
                const newPassword = prompt(`Entrez le nouveau mot de passe pour l'utilisateur "${rowData.Name}":`);

                if (newPassword === null) return; // L'utilisateur a cliqué sur "Annuler"

                if (newPassword.trim() === "") {
                    alert("Le mot de passe ne peut pas être vide.");
                    return;
                }

                // Appel à la nouvelle route API sécurisée
                fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/utilisateurs/${rowData.id}/update-password`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': EECIE_CRM.nonce
                    },
                    body: JSON.stringify({ password: newPassword })
                })
                .then(res => {
                    if (!res.ok) {
                        alert("Erreur lors de la mise à jour du mot de passe !");
                        throw new Error("Password update failed");
                    }
                    return res.json();
                })
                .then(json => {
                    console.log("✅ Mot de passe mis à jour !");
                    alert("Le mot de passe a été mis à jour avec succès.");
                })
                .catch(err => {
                    console.error("Erreur PATCH password:", err);
                });
            }
        };

        // Colonne pour supprimer l'utilisateur
        const deleteColumn = {
            title: "Supprimer",
            formatter: "buttonCross",
            width: 100,
            hozAlign: "center",
            headerSort: false,
            cellClick: function (e, cell) {
                const row = cell.getRow();
                const data = row.getData();
                if (confirm(`Supprimer l'utilisateur ${data.Name || 'inconnu'} ?`)) {
                    fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/utilisateurs/${data.id}`, {
                        method: 'DELETE',
                        headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
                    }).then(res => {
                        if (!res.ok) {
                            alert("Erreur de suppression !");
                        } else {
                            row.delete();
                            console.log("✅ Utilisateur supprimé !");
                        }
                    });
                }
            }
        };
        
        // On ajoute les colonnes d'action au début
        columns.unshift(nextcloudPasswordColumn);
        columns.unshift(passwordColumn);
        columns.unshift(deleteColumn);
        
        container.innerHTML = '';
        const tableEl = document.createElement('div');
        container.appendChild(tableEl);

        const tableInstance = new Tabulator(tableEl, {
            data: data.results,
            layout: "fitColumns",
            columns: columns,
            reactiveData: false,
            height: "auto",
            // ON SUPPRIME LE cellClick GÉNÉRAL QUI CAUSAIT LE PROBLÈME
        });

        // --- FIN DE LA MODIFICATION PRINCIPALE ---


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
