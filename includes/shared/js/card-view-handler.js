// Fichier : includes/shared/js/card-view-handler.js (VERSION CORRIGÉE)

window.gce = window.gce || {};

/**
 * Initialise une vue basée sur des cartes cliquables qui ouvrent un modal détaillé.
 */
gce.initializeCardView = function(container, data, config) {
    container.innerHTML = '';

    if (!data || data.length === 0) {
        container.innerHTML = '<p>Aucun élément à afficher.</p>';
        return;
    }

    data.forEach(item => {
        // La fonction de rendu est maintenant 100% responsable de l'élément carte
        const summaryCard = config.summaryRenderer(item);

        summaryCard.addEventListener('click', () => {
            // --- AJOUT DE DIAGNOSTIC ---
            // Cette ligne est la plus importante. Si elle n'apparaît pas dans la console
            // au clic, c'est que l'événement n'est pas attaché correctement.
            console.log('Carte cliquée ! Données:', item);
            
            gce.showDetailModal(item, config.detailRenderer);
        });

        container.appendChild(summaryCard);
    });
};

/**
 * Affiche un modal générique.
 */
gce.showDetailModal = function(item, detailRenderer) {
    const existingOverlay = document.querySelector('.gce-detail-modal-overlay');
    if (existingOverlay) existingOverlay.remove();

    const overlay = document.createElement('div');
    overlay.className = 'gce-detail-modal-overlay';

    const modal = document.createElement('div');
    modal.className = 'gce-detail-modal';

    const detailView = detailRenderer(item);
    modal.appendChild(detailView);

    const closeButton = document.createElement('button');
    closeButton.className = 'gce-modal-close';
    closeButton.innerHTML = '✖';
    closeButton.onclick = () => overlay.remove();
    modal.prepend(closeButton);

    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    overlay.addEventListener('click', e => {
        if (e.target === overlay) overlay.remove();
    });
};