document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('gce-taches-table');
    if (!container) return;

    container.innerHTML = 'Chargement des tâches…';
    const userEmail = window.GCE_CURRENT_USER?.email;
    if (!userEmail) {
        container.innerHTML = '<p>Utilisateur non identifié.</p>';
        return;
    }

    Promise.all([
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/taches', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/taches/schema', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/utilisateurs', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json())
    ])
        .then(([taskData, taskSchema, usersData]) => {
            if (!taskData || !Array.isArray(taskData.results)) {
                // Si taskData.results n'est pas un tableau, c'est que l'API a renvoyé une erreur.
                const errorMessage = taskData.message || 'La réponse de l\'API pour les tâches est dans un format inattendu.';
                console.error('API Error Response (Taches):', taskData);
                container.innerHTML = `<p style="color: red;"><strong>Erreur API :</strong> ${errorMessage}</p>`;
                return; // Arrêter l'exécution pour éviter le crash.
            }
            window.gceSchemas = window.gceSchemas || {};
            window.gceSchemas['taches'] = taskSchema;
            const userRow = usersData.results.find(u =>
                u.Email?.toLowerCase() === userEmail.toLowerCase()
            );
            const userId = userRow?.id || null;

            const assignedField = taskSchema.find(f =>
                f.type === 'link_row' && f.name === 'assigne'
            )?.name || 'assigne';

            const myTasks = userId
                ? taskData.results.filter(t => {
                    const link = t[assignedField];
                    const isAssigned = Array.isArray(link) && link.some(x => x.id === userId);
                    const isActive = t.terminer === false || t.terminer === null;
                    return isAssigned && isActive;
                }) : [];

            container.innerHTML = '';
            if (myTasks.length === 0) {
                container.innerHTML = '<p>Aucune tâche assignée pour l’instant.</p>';
                return;
            }

            const tableEl = document.createElement('div');
            tableEl.className = 'gce-tabulator';
            container.appendChild(tableEl);

            const cols = getTabulatorColumnsFromSchema(taskSchema, 'taches');
            cols.unshift({
                title: "✅ Accepter",
                formatter: "buttonTick",
                width: 100,
                hozAlign: "center",
                headerSort: false,
                cellClick: function (e, cell) {
                    const rowData = cell.getRow().getData();
                    const currentStatus = (rowData.statut?.value || rowData.statut?.name || '').toLowerCase();

                    if (currentStatus !== 'creation') {
                        alert("❌ Cette tâche ne peut pas être acceptée car elle n'est pas en statut 'Creation'.");
                        return;
                    }

                    // Désactive le bouton pour éviter les double-clics
                    const button = e.target;
                    button.disabled = true;

                    // Envoi au webhook N8N
                    fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/proxy/start-task', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': EECIE_CRM.nonce
                        },
                        body: JSON.stringify(rowData)
                    })
                        .then(res => {
                            if (!res.ok) throw new Error('La requête a échoué.');
                            return res.json();
                        })
                        .then(resp => {
                            // Affiche la notification de succès
                            showStatusUpdate('Tâche mise à jour avec succès !');
                            
                            // Attend 2 secondes avant de recharger
                            setTimeout(() => {
                                location.reload();
                            }, 2000); // 2000 millisecondes = 2 secondes
                        })
                        .catch(err => {
                            console.error("Erreur proxy :", err);
                            // Affiche une notification d'erreur
                            showStatusUpdate('❌ Erreur lors de l’envoi.', false);
                            // Réactive le bouton en cas d'erreur
                            button.disabled = false;
                        });
                }
            });

            const tableInstance = new Tabulator(tableEl, {
                data: myTasks,
                layout: "fitColumns",
                columns: cols,
                reactiveData: false,
                height: "auto",
            });

            tableInstance.on("cellEdited", function (cell) {
                const row = cell.getRow().getData();
                const cleaned = sanitizeRowBeforeSave(row, taskSchema);

                fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/taches/${row.id}`, {
                    method: 'PATCH',
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
                        console.log("✅ Mise à jour réussie :", json);
                    })
                    .catch(err => {
                        console.error("❌ Erreur réseau :", err);
                        alert("Erreur lors de la mise à jour.");
                    });
            });


        })
        .catch(err => {
            container.innerHTML = '<p>Erreur de chargement : ' + err.message + '</p>';
        });
});
