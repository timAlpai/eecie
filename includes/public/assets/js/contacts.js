document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('gce-contacts-table');
    if (!container) return;

    container.innerHTML = 'Chargement des contacts…';

    const userEmail = GCE_CURRENT_USER?.email;
    if (!userEmail) {
        container.innerHTML = '<p>Utilisateur non identifié.</p>';
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
        }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/contacts', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json()),
        fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/contacts/schema', {
            headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
        }).then(r => r.json())
    ])
    .then(([oppData, oppSchema, usersData, contactData, contactSchema]) => {
        const opportunites = oppData.results || [];
        const utilisateurs = usersData.results || [];
        const contacts = contactData.results || [];

        // Trouver l'utilisateur courant
        const user = utilisateurs.find(u => u.Email?.toLowerCase() === userEmail.toLowerCase());
        if (!user) {
            container.innerHTML = '<p>Aucun utilisateur associé trouvé.</p>';
            return;
        }

        const userId = user.id;

        // Trouver le champ link_row qui relie les opportunités aux utilisateurs
        const linkField = oppSchema.find(f => f.type === "link_row" && f.name === "T1_user")?.name || "T1_user";

        // Extraire les contacts depuis les opportunités assignées
        const contactMap = new Map();

        opportunites.forEach(opp => {
            const assigned = opp[linkField];
            const isAssigned = Array.isArray(assigned) && assigned.some(u => u.id === userId);
            if (!isAssigned) return;

            const linkedContacts = opp.Contacts;
            if (Array.isArray(linkedContacts)) {
                linkedContacts.forEach(c => contactMap.set(c.id, c));
            }
        });

        const filteredContacts = contacts.filter(c => contactMap.has(c.id));

        // Rendu Tabulator
        const columns = getTabulatorColumnsFromSchema(contactSchema);

        const tableEl = document.createElement('div');
        container.innerHTML = '';
        container.appendChild(tableEl);

        new Tabulator(tableEl, {
            data: filteredContacts,
            layout: "fitColumns",
            height: "auto",
            columns: columns,
            placeholder: "Aucun contact lié à vos opportunités.",
        });
    })
    .catch(err => {
        console.error("Erreur contacts.js :", err);
        container.innerHTML = `<p>Erreur lors du chargement : ${err.message}</p>`;
    });

});
