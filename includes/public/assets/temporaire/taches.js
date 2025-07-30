document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('gce-taches-table');
    if (!container) return;

    container.innerHTML = 'Initialisation de la table des tâches...';
       /**
     * Vérifie l'URL et ouvre un modal de tâche si les paramètres sont présents.
     * @param {Array} tableData - Les données fraîchement chargées par Tabulator.
     */
    function handleAutoOpenFromUrl(tableData) {
        console.log('ici tim on ouvbre la fonction');
        const urlParams = new URLSearchParams(window.location.search);
        const taskIdToOpen = urlParams.get('open-task-id');
        const modeToOpen = urlParams.get('open-mode') || 'lecture'; // 'lecture' par défaut

        if (taskIdToOpen) {
            const taskIdInt = parseInt(taskIdToOpen, 10);
            console.log(`[AutoOpen] Tentative d'ouverture de la tâche #${taskIdInt} en mode '${modeToOpen}'`);

            // On cherche la tâche dans les données que Tabulator vient de charger
            const taskData = tableData.find(task => task.id == taskIdInt);

            if (taskData) {
                console.log(`[AutoOpen] Tâche trouvée. Ouverture du modal.`);
                gceShowModal(taskData, "taches", modeToOpen);

                // Optionnel : nettoyer l'URL pour éviter de rouvrir le modal au rechargement
                const newUrl = window.location.pathname + '?gce-page=taches';
                window.history.replaceState({}, document.title, newUrl);

            } else {
                console.warn(`[AutoOpen] Tâche #${taskIdInt} non trouvée dans vos tâches.`);
                alert(`La tâche #${taskIdInt} n'a pas pu être trouvée ou ne vous est pas assignée.`);
            }
        }
    }
    const userEmail = window.GCE_CURRENT_USER?.email;
    if (!userEmail) {
        container.innerHTML = '<p>Utilisateur non identifié.</p>';
        return;
    }

    // On ne charge plus les tâches ici, seulement le schéma et les utilisateurs pour le contexte
    Promise.all([
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/taches/schema', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/utilisateurs', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json())
    ])
        .then(([taskSchema, usersData]) => {
            if (!Array.isArray(taskSchema) || !usersData?.results) {
                throw new Error("Le schéma ou les données utilisateur n'ont pas pu être chargés.");
            }

            // On stocke le schéma pour une utilisation ultérieure (ex: popups)
            window.gceSchemas = window.gceSchemas || {};
            window.gceSchemas['taches'] = taskSchema;

            // On garde la logique pour trouver l'ID de l'utilisateur courant, c'est utile pour le bouton "Accepter"
            const userRow = usersData.results.find(u => u.Email?.toLowerCase() === userEmail.toLowerCase());
            const userId = userRow?.id || null;

            container.innerHTML = ''; // On vide le conteneur de chargement
            const tableEl = document.createElement('div');
            tableEl.className = 'gce-tabulator';
            container.appendChild(tableEl);

            // On prépare les colonnes comme avant
            const cols = getTabulatorColumnsFromSchema(taskSchema, 'taches');
            cols.unshift({
                title: "✅ Accepter",
                formatter: "buttonTick",
                width: 100,
                hozAlign: "center",
                headerSort: false,
                cellClick: function (e, cell) {
                    // ... (cette logique ne change pas, elle est correcte)
                    const rowData = cell.getRow().getData();
                    const currentStatus = (rowData.statut?.value || rowData.statut?.name || '').toLowerCase();

                    if (currentStatus !== 'creation') {
                        alert("❌ Cette tâche ne peut pas être acceptée car elle n'est pas en statut 'Creation'.");
                        return;
                    }

                    const button = e.target;
                    button.disabled = true;

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
                            showStatusUpdate('Tâche mise à jour avec succès !');
                            
                                // Au lieu de recharger la page, on rafraîchit les données de la table
                                tableInstance.replaceData();
                            
                        })
                        .catch(err => {
                            console.error("Erreur proxy :", err);
                            showStatusUpdate('❌ Erreur lors de l’envoi.', false);
                            button.disabled = false;
                        });
                }
            });
            console.log("on entre dans le tableau");
        const tableInstance = new Tabulator(tableEl, {
            // ---- On passe en pagination LOCALE ----
            pagination: "local",
            paginationSize: 20,
            paginationSizeSelector: [10, 20, 50, 100],

            ajaxURL: EECIE_CRM.rest_url + 'eecie-crm/v1/taches',
            ajaxConfig: {
                method: "GET",
                headers: { 'X-WP-Nonce': EECIE_CRM.nonce },
            },
            
            // La réponse est maintenant un simple tableau, Tabulator la comprend nativement.
            // Plus besoin de ajaxResponse, paginationDataField, etc.
             
            // ---- Configuration générale ----
            layout: "fitColumns",
            columns: cols,
            placeholder: "Aucune tâche active ne vous est assignée.",
            
        });
            

            // On stocke l'instance pour pouvoir la manipuler depuis d'autres scripts si besoin
            window.tachesTable = tableInstance;
            handleAutoOpenFromUrl(tachesData);
            // Le handler pour l'édition en ligne ne change pas
            tableInstance.on("cellEdited", function (cell) {
            const row = cell.getRow().getData();
            const cleaned = sanitizeRowBeforeSave(row, taskSchema);

            // On affiche un message temporaire
            showStatusUpdate('Sauvegarde...');

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
                    // Si la sauvegarde initiale échoue, on arrête tout
                    throw new Error("La sauvegarde a échoué. Le serveur a répondu avec un statut " + res.status);
                }
                // La sauvegarde a réussi. Maintenant, on attend et on recharge la ligne.
                console.log("✅ Sauvegarde initiale réussie. Attente de 1 seconde pour les webhooks...");

                // On utilise setTimeout pour attendre 1 seconde (1000 millisecondes)
                setTimeout(() => {
                    console.log("...Rechargement de la ligne #" + row.id + " depuis le serveur.");
                    
                    // On fait un deuxième appel pour récupérer la "source de vérité"
                    fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/row/taches/${row.id}`, {
                        headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error("Le rechargement de la ligne a échoué.");
                        }
                        return response.json();
                    })
                    .then(updatedRowFromServer => {
                        // 'updatedRowFromServer' contient maintenant les données 100% à jour
                        console.log("Données fraîches reçues:", updatedRowFromServer);
                        
                        // On met à jour la table avec ces nouvelles données
                        tableInstance.updateData([updatedRowFromServer]);
                        
                        // On affiche le message de succès final
                        showStatusUpdate('Mise à jour réussie !');
                    });

                }, 1000); // Délai de 1 seconde

            })
            .catch(err => {
                console.error("❌ Erreur lors du processus de mise à jour :", err);
                showStatusUpdate("Erreur: " + err.message, false); // Affiche l'erreur en rouge
                
                // En cas d'erreur, on recharge la table pour être sûr
                tableInstance.replaceData();
            });
        });
        })
        .catch(err => {
            console.error("Erreur lors de l'initialisation de la page des tâches :", err);
            container.innerHTML = `<p style="color:red;">Erreur de chargement : ${err.message}</p>`;
        });
       
});