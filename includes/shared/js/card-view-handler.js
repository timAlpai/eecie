// Fichier : includes/shared/js/card-view-handler.js (VERSION RÉACTIVE)

window.gce = window.gce || {};

// Création d'un manager pour contenir l'état et les fonctions
gce.viewManager = {
    dataStore: [],
    config: {},
    container: null,

    /**
     * Initialise le manager et la vue.
     */
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
            // On ajoute un identifiant unique à la carte pour la retrouver plus tard
            summaryCard.dataset.itemId = item.id; 

            summaryCard.addEventListener('click', () => {
                gce.showDetailModal(item, this.config.detailRenderer);
            });

            this.container.appendChild(summaryCard);
        });
    },

    /**
     * Met à jour un item dans le store et rafraîchit sa carte dans le DOM.
     * C'est le cœur de notre système réactif.
     * @param {number} itemId - L'ID de l'item à mettre à jour.
     * @param {Object} newItemData - Les nouvelles données complètes pour cet item.
     */
    updateItem: function(itemId, newItemData) {
        // 1. Mettre à jour les données dans notre source de vérité
        const itemIndex = this.dataStore.findIndex(item => item.id === itemId);
        if (itemIndex > -1) {
            this.dataStore[itemIndex] = newItemData;
        } else {
            // Optionnellement, ajouter l'item s'il est nouveau
            this.dataStore.push(newItemData);
        }

        // 2. Trouver la carte correspondante dans le DOM
        const cardToUpdate = this.container.querySelector(`[data-item-id="${itemId}"]`);
        
        if (cardToUpdate) {
            console.log(`Réactivité: Rafraîchissement de la carte #${itemId}`);
            // 3. Créer une nouvelle carte avec les données à jour
            const newCard = this.config.summaryRenderer(newItemData);
            newCard.dataset.itemId = newItemData.id;
             newCard.addEventListener('click', () => {
                gce.showDetailModal(newItemData, this.config.detailRenderer);
            });
            
            // 4. Remplacer l'ancienne carte par la nouvelle
            cardToUpdate.replaceWith(newCard);
        }
    }
};

// La fonction showDetailModal reste inchangée, elle est déjà générique.
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