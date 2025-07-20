fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/contacts', {
  headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
})
document.addEventListener('DOMContentLoaded', () => {
  const btn = document.getElementById('gce-explorer-structure');
  if (!btn) return;

  btn.addEventListener('click', async () => {
    const output = document.getElementById('gce-baserow-structure-output');
    output.textContent = 'Chargement…';

    try {
      const res = await fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/structure', {
        headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
      });
      if (!res.ok) throw new Error('Erreur HTTP ' + res.status);
      const data = await res.json();
      output.textContent = JSON.stringify(data, null, 2);
    } catch (err) {
      output.textContent = '❌ Erreur : ' + err.message;
    }
  });

 const btnExplorer = document.getElementById('gce-explorer-structure');
    if (btnExplorer) {
        btnExplorer.addEventListener('click', async () => {
            const output = document.getElementById('gce-baserow-structure-output');
            output.textContent = 'Chargement…';
            try {
                const res = await fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/structure', {
                    headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
                });
                if (!res.ok) throw new Error('Erreur HTTP ' + res.status);
                const data = await res.json();
                output.textContent = JSON.stringify(data, null, 2);
            } catch (err) {
                output.textContent = '❌ Erreur : ' + err.message;
            }
        });
    }

 

});
 

