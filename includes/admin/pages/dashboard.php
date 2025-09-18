<div class="wrap">
    <h1><?php esc_html_e('Dashboard CRM EECIE', 'gestion-crm-eecie'); ?></h1>
    
    <div id="gce-dashboard-container" class="gce-dashboard-grid">

        <div id="gce-dashboard-main" class="gce-dashboard-column">
            <h2>Activité en Temps Réel</h2>
            <div id="connected-users-widget" class="postbox">
                <div class="postbox-header"><h3>Employés Connectés</h3></div>
                <div class="inside"><p>Initialisation...</p></div>
            </div>
            <!-- On déplace la liste des threads ici -->
            <div id="threads-list" class="postbox">
                <div class="postbox-header"><h3>Fils de discussion</h3></div>
                <div class="inside"><p>Chargement...</p></div>
            </div>
        </div>

        <div id="gce-dashboard-messaging" class="gce-dashboard-column">
            <!-- La vue des messages prendra toute la hauteur de cette colonne -->
            <div id="messages-view" class="postbox" style="display: none;">
                <div class="postbox-header"><h3 id="messages-view-title">Sélectionnez une discussion</h3></div>
                <div class="inside">
                    <div id="messages-display"></div>
                    <form id="message-form">
                        <input type="hidden" id="message-thread-id" value="">
                        <textarea id="message-input" required placeholder="Écrire un message..."></textarea>
                        <button type="submit" class="button button-primary">Envoyer</button>
                    </form>
                </div>
            </div>
            <!-- Message d'accueil si aucune discussion n'est sélectionnée -->
            <div id="messages-placeholder" class="postbox">
                 <div class="inside" style="text-align: center; padding: 50px;">
                    <p>💬</p>
                    <p>Sélectionnez une discussion sur la gauche pour voir les messages.</p>
                </div>
            </div>
        </div>

    </div>
</div>