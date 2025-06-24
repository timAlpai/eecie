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
                return Array.isArray(link) && link.some(x => x.id === userId);
            }) : [];

        container.innerHTML = '';
        if (myTasks.length === 0) {
            container.innerHTML = '<p>Aucune tâche assignée pour l’instant.</p>';
            return;
        }

        const tableEl = document.createElement('div');
        container.appendChild(tableEl);

        const cols = getTabulatorColumnsFromSchema(taskSchema);
        new Tabulator(tableEl, {
            data: myTasks,
            layout: "fitColumns",
            columns: cols,
            reactiveData: false,
            height: "auto",
        });
    })
    .catch(err => {
        container.innerHTML = '<p>Erreur de chargement : ' + err.message + '</p>';
    });
});
