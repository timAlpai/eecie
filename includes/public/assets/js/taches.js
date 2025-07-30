// FICHIER : includes/public/assets/js/taches.js (CORRECTION FINALE)

document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('gce-taches-table');
    if (!container) return;

    container.innerHTML = 'Initialisation de la table des tâches...';

    /**
     * Vérifie l'URL et ouvre un modal de tâche si les paramètres sont présents.
     */
    function handleAutoOpenFromUrl(tableData) {
        const urlParams = new URLSearchParams(window.location.search);
        const taskIdToOpen = urlParams.get('open-task-id');
        const modeToOpen = urlParams.get('open-mode') || 'lecture';

        if (taskIdToOpen) {
            const taskIdInt = parseInt(taskIdToOpen, 10);
            const taskData = tableData.find(task => task.id == taskIdInt);
            if (taskData) {
                console.log(`[AutoOpen] Tâche #${taskIdInt} trouvée. Ouverture du modal.`);
                gceShowModal(taskData, "taches", modeToOpen);
                const newUrl = window.location.pathname + '?gce-page=taches';
                window.history.replaceState({}, document.title, newUrl);
            } else {
                alert(`La tâche #${taskIdInt} n'a pas pu être trouvée ou ne vous est pas assignée.`);
            }
        }
    }

    // --- PROMISE.ALL CORRIGÉ ---
    // On ne charge que ce qui est strictement nécessaire pour l'initialisation.
    Promise.all([
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/taches/schema', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/taches', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } }).then(r => r.json())
    ])
    .then(([taskSchema, tachesData]) => {
        if (!Array.isArray(taskSchema) || !Array.isArray(tachesData)) {
            throw new Error("Les données des tâches ou le schéma n'ont pas pu être chargés correctement.");
        }

        window.gceSchemas = window.gceSchemas || {};
        window.gceSchemas['taches'] = taskSchema;

        container.innerHTML = '';
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
                if ((rowData.statut?.value || '').toLowerCase() !== 'creation') {
                    alert("Cette tâche ne peut pas être acceptée.");
                    return;
                }
                const button = e.target;
                button.disabled = true;
                fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/proxy/start-task', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': EECIE_CRM.nonce },
                    body: JSON.stringify(rowData)
                })
                .then(res => { if (!res.ok) throw new Error('La requête a échoué.'); return res.json(); })
                .then(() => {
                    showStatusUpdate('Tâche mise à jour !');
                    // On recharge les données de la table pour voir les changements
                    // C'est nécessaire car le statut a changé côté serveur
                    fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/taches', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } })
                        .then(r => r.json())
                        .then(newData => tableInstance.setData(newData));
                })
                .catch(err => {
                    showStatusUpdate('Erreur lors de l’envoi.', false);
                    button.disabled = false;
                });
            }
        });

        const tableInstance = new Tabulator(tableEl, {
            data: tachesData, 
            pagination: "local",
            paginationSize: 20,
            paginationSizeSelector: [10, 20, 50, 100],
            layout: "fitColumns",
            columns: cols,
            placeholder: "Aucune tâche active ne vous est assignée.",
        });

        window.tachesTable = tableInstance;
        
        handleAutoOpenFromUrl(tachesData);

        tableInstance.on("cellEdited", function (cell) {
            const row = cell.getRow().getData();
            const cleaned = sanitizeRowBeforeSave(row, taskSchema);
            showStatusUpdate('Sauvegarde...');
            fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/taches/${row.id}`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': EECIE_CRM.nonce },
                body: JSON.stringify(cleaned)
            })
            .then(res => {
                if (!res.ok) throw new Error("La sauvegarde a échoué. " + res.status);
                setTimeout(() => {
                    fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/taches', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } })
                        .then(r => r.json())
                        .then(newData => {
                            tableInstance.setData(newData);
                            showStatusUpdate('Synchronisation réussie !');
                        });
                }, 1000);
            })
            .catch(err => {
                console.error("❌ Erreur de mise à jour :", err);
                showStatusUpdate("Erreur: " + err.message, false);
            });
        });
    })
    .catch(err => {
        console.error("Erreur d'initialisation de la page des tâches :", err);
        container.innerHTML = `<p style="color:red;">Erreur de chargement : ${err.message}</p>`;
    });
});