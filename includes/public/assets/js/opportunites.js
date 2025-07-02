// FICHIER : includes/public/assets/js/opportunites.js

// Ce script charge les opportunités Baserow assignées à l'utilisateur WordPress connecté

document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('gce-opportunites-table');
    if (!container) return;

    container.innerHTML = 'Chargement des opportunités...';

    const userEmail = window.GCE_CURRENT_USER?.email;
    if (!userEmail) {
        container.innerHTML = '<p>Utilisateur non identifié côté client.</p>';
        return;
    }

    Promise.all([
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/opportunites', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/opportunites/schema', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/utilisateurs', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json())
    ])
        .then(([oppData, oppSchema, usersData]) => {
            // Debug global temporaire
            window._debugOpps = {
                usersData,
                oppData,
                oppSchema,
                currentEmail: userEmail
            };

            if (!oppData.results || !oppSchema || !usersData.results) {
                container.innerHTML = '<p>Erreur dans la réception des données.</p>';
                return;
            }

            // Match email (insensible à la casse)
            const userRow = usersData.results.find(u =>
                u.Email?.toLowerCase() === userEmail.toLowerCase()
            );
            const userId = userRow ? userRow.id : null;

            // Identifier dynamiquement le champ de liaison avec les utilisateurs
            const assignedField = (() => {
                const fromSchema = oppSchema.find(f =>
                    f.type === 'link_row' && f.name === 'T1_user'
                );
                return fromSchema?.name || 'T1_user';
            })();

            const myOpps = userId
                ? oppData.results.filter(o => {
                    const link = o[assignedField];
                    return Array.isArray(link) && link.some(x => x.id === userId);
                })
                : [];

            container.innerHTML = '';

            if (myOpps.length === 0) {
                container.innerHTML = '<p>Aucune opportunité assignée pour l’instant.</p>';
                return;
            }

            const tableEl = document.createElement('div');
            tableEl.className = 'gce-tabulator';
            container.appendChild(tableEl);

            const cols = getTabulatorColumnsFromSchema(oppSchema, 'opportunites');
            cols.unshift({
                title: "✅ Accepter",
                formatter: "buttonTick",
                width: 100,
                hozAlign: "center",
                headerSort: false,
                cellClick: function (e, cell) {
                    const rowData = cell.getRow().getData();
                    const current = (rowData.Status?.value || rowData.Status?.name || '').toLowerCase();

                    if (current !== "assigner") {
                        alert(`Ce dossier est actuellement en "${rowData.Status?.value}" et ne peut pas être accepté.`);
                        return;
                    }


                    // Trouver le field ID correspondant à "Status"
                    const statusField = oppSchema.find(f => f.name === "Status");
                    if (!statusField || statusField.type !== 'single_select') {
                        alert("Champ 'Status' introuvable ou mal configuré.");
                        return;
                    }

                    // Trouver l'option "En traitement"
                    const option = statusField.select_options.find(opt =>
                        (opt.value || opt.name || '').toLowerCase() === "traitement"
                    );

                    if (!option) {
                        alert("Option 'Traitement' non trouvée dans les statuts.");
                        return;
                    }

                    const payload = {};
                    payload["field_" + statusField.id] = option.id;

                    fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/opportunites/${rowData.id}`, {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': EECIE_CRM.nonce
                        },
                        body: JSON.stringify(payload)
                    })
                        .then(res => res.json())
                        .then(updated => {
                            cell.getRow().update({ Status: option }); // mise à jour UI locale
                            alert("✅ Opportunité passée en traitement.");
                        })
                        .catch(err => {
                            console.error("Erreur mise à jour :", err);
                            alert("Erreur lors de la mise à jour.");
                        });
                }
            });

            new Tabulator(tableEl, {
                data: myOpps,
                layout: "fitColumns",
                responsiveLayout: "collapse", // <- active le mode responsive natif
                responsiveLayoutCollapseStartOpen: false,
                columns: cols,
                reactiveData: false,
                height: "auto",
            });
        })
        .catch(err => {
            container.innerHTML = '<p>Erreur de chargement : ' + err.message + '</p>';
        });
});
