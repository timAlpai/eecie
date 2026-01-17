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

 
    const syncBtn = document.getElementById('gce-sync-interv-btn');
    const resultMsg = document.getElementById('sync-result-msg');

    // Dans configuration.js
if (syncBtn) {
    syncBtn.addEventListener('click', async () => {
        if (!confirm("Lancer la synchronisation ?")) return;

        syncBtn.disabled = true;
        resultMsg.innerHTML = 'Traitement...';

        try {
            const response = await fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/interventions/sync-all', {
                method: 'POST',
                headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
            });

            const data = await response.json();
            
            // On affiche le message de résumé
            let html = `<div>${data.message}</div>`;
            
            // Si on a 0 créations, on affiche les logs de debug
            if (data.debug_logs) {
                html += `<details style="margin-top:10px; font-size:11px; font-weight:normal;">
                            <summary>Voir le détail du traitement (Logs)</summary>
                            <div style="background:#eee; padding:5px; border:1px solid #ccc; max-height:200px; overflow:auto;">
                                ${data.debug_logs.join('<br>')}
                            </div>
                         </details>`;
            }

            resultMsg.innerHTML = html;
            resultMsg.style.color = response.ok ? 'green' : 'red';

        } catch (error) {
            resultMsg.textContent = '❌ ' + error.message;
        } finally {
            syncBtn.disabled = false;
        }
    });
}

});
 

