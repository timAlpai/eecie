// Fichier : includes/shared/js/card-view-handler.js (VERSION FINALE ET ROBUSTE)

window.gce = window.gce || {};

gce.viewManager = {
    dataStore: [],
    config: {},
    container: null,

    initialize: function(container, data, config) {
        this.container = container;
        this.dataStore = data;
        this.config = config;
        this.container.innerHTML = '';
        if (!this.dataStore || this.dataStore.length === 0) {
            this.container.innerHTML = '<p>Aucun élément à afficher.</p>';
            return;
        }
        this.dataStore.forEach(item => {
            const summaryCard = this.config.summaryRenderer(item);
            summaryCard.dataset.itemId = item.id; 
            
            // CORRECTION IMPORTANTE : Ne pas ouvrir le modal si on clique sur un bouton
            summaryCard.addEventListener('click', (e) => {
                if (e.target.closest('button')) {
                    return; // C'était un clic sur un bouton, on ne fait rien.
                }
                gce.showDetailModal(item, this.config.detailRenderer);
            });
            
            this.container.appendChild(summaryCard);
        });
    },

    updateItem: function(itemId, newItemData) {
        const itemIndex = this.dataStore.findIndex(item => item.id === itemId);
        if (itemIndex > -1) {
            this.dataStore[itemIndex] = newItemData;
        } else {
            this.dataStore.push(newItemData);
        }
        const cardToUpdate = this.container.querySelector(`[data-item-id="${itemId}"]`);
        if (cardToUpdate) {
            const newCard = this.config.summaryRenderer(newItemData);
            newCard.dataset.itemId = newItemData.id;
             newCard.addEventListener('click', (e) => {
                if (e.target.closest('button')) return;
                gce.showDetailModal(newItemData, this.config.detailRenderer);
            });
            cardToUpdate.replaceWith(newCard);
        }
    },

    /**
     * Si le modal de détail est ouvert pour un item, le ferme et le rouvre
     * avec les nouvelles données pour forcer une recréation complète.
     * @param {number} itemId - L'ID de l'item dont le modal est ouvert.
     * @param {Object} newItemData - Les nouvelles données complètes pour cet item.
     */
    updateDetailModalIfOpen: function(itemId, newItemData) {
        // 1. Chercher si un modal pour cet item est actuellement ouvert
        const modal = document.querySelector('.gce-detail-modal[data-item-id="' + itemId + '"]');

        // 2. Si le modal est bien ouvert, on applique la logique "détruire et reconstruire"
        if (modal) {
            console.log(`Réactivité: Le modal pour l'item #${itemId} est ouvert. Recréation complète.`);
            
            // 3. Trouver son parent (l'overlay noir) pour tout supprimer proprement
            const overlay = modal.closest('.gce-detail-modal-overlay');
            if (overlay) {
                overlay.remove();
            }
            
            // 4. Appeler la fonction originale pour créer un modal tout neuf avec les données fraîches.
            // Ceci garantit que Tabulator est ré-initialisé correctement.
            gce.showDetailModal(newItemData, this.config.detailRenderer);
        }
    }
};

gce.showDetailModal = function(item, detailRenderer) {
    const existingOverlay = document.querySelector('.gce-detail-modal-overlay');
    if (existingOverlay) existingOverlay.remove();

    const overlay = document.createElement('div');
    overlay.className = 'gce-detail-modal-overlay';
    const modal = document.createElement('div');
    modal.className = 'gce-detail-modal';
    modal.dataset.itemId = item.id;
    
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