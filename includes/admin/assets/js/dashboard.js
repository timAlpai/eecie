document.addEventListener('DOMContentLoaded', function() {
    // --- Références DOM ---
    const connectedUsersWidget = document.querySelector('#connected-users-widget .inside');
    const threadsListContainer = document.querySelector('#threads-list .inside');
    const messagesView = document.getElementById('messages-view');
    const messagesDisplay = document.getElementById('messages-display');
    const messageForm = document.getElementById('message-form');
    const messageInput = document.getElementById('message-input');
    const messagesViewTitle = document.getElementById('messages-view-title');
    const messageThreadIdInput = document.getElementById('message-thread-id');
    const messagesPlaceholder = document.getElementById('messages-placeholder');

    // --- État de l'application ---
    let socket = null;
    let connectedUsers = {};
    let currentUser = null; 
    let currentThreadId = null;
    
    // --- Fonctions d'affichage ---
    function updateConnectedUsersWidget() {
        const userList = Object.values(connectedUsers);
        connectedUsersWidget.innerHTML = `
            <p><strong>Total : ${userList.length}</strong></p>
            <ul>
                ${userList.length > 0 ? userList.map(user => `<li>${user.display_name || `Utilisateur #${user.id}`}</li>`).join('') : '<li>Aucun employé connecté.</li>'}
            </ul>
        `;
    }

    async function fetchUserDetails(userId) {
        if (connectedUsers[userId]) return connectedUsers[userId];
        try {
            const response = await fetch(`${EECIE_CRM.rest_url}wp/v2/users/${userId}?context=view`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } });
            if (!response.ok) return { id: userId, display_name: `Utilisateur #${userId}` };
            const userData = await response.json();
            const userDetail = { id: userId, display_name: userData.name };
            connectedUsers[userId] = userDetail;
            return userDetail;
        } catch (e) { return { id: userId, display_name: `Utilisateur #${userId}` }; }
    }

    async function displayThreads() {
        try {
            const res = await fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/messages/my-threads', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } });
            const threads = await res.json();
            
            threadsListContainer.innerHTML = '<ul>' + threads.map(thread => `
                <li style="cursor: pointer; padding: 8px; border-bottom: 1px solid #eee;" data-thread-id="${thread.id}" data-thread-name="${thread.opportunite[0]?.value || 'Inconnue'}">
                    <strong>${thread.opportunite[0]?.value || 'Inconnue'}</strong><br>
                    <small>Avec : ${thread.Participant.map(p => p.value).join(', ')}</small>
                </li>
            `).join('') + '</ul>';
        } catch (e) { threadsListContainer.innerHTML = '<p>Erreur de chargement des discussions.</p>'; }
    }
    
async function displayMessages(threadId, threadName) {
    // 1. Gérer l'interface : cacher le placeholder, afficher la vue du chat
    messagesPlaceholder.style.display = 'none';
    messagesView.style.display = 'flex'; // <-- On utilise 'flex' qui est nécessaire pour le nouveau layout

    // 2. Mettre en surbrillance le thread actif dans la liste de gauche
    document.querySelectorAll('#threads-list li').forEach(li => li.classList.remove('active'));
    document.querySelector(`#threads-list li[data-thread-id="${threadId}"]`).classList.add('active');

    // 3. Mettre à jour l'état de l'application
    currentThreadId = threadId;
    messagesViewTitle.textContent = `Discussion : ${threadName}`;
    messageThreadIdInput.value = threadId;
    messagesDisplay.innerHTML = '<p>Chargement des messages...</p>';

    // 4. Charger l'historique des messages depuis l'API
    try {
        const res = await fetch(`${EECIE_CRM.rest_url}eecie-crm/v1/threads/${threadId}/messages`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } });
        if (!res.ok) throw new Error('La requête a échoué.'); // Mieux gérer les erreurs
        const messages = await res.json();
        renderMessages(messages);
    } catch (e) { 
        messagesDisplay.innerHTML = '<p>Erreur de chargement des messages.</p>'; 
    }
}
    function renderMessages(messages) {
        messagesDisplay.innerHTML = messages.map(msg => {
            // On récupère l'ID T1_user de l'expéditeur depuis Baserow
            const senderT1Id = msg.Expediteur[0]?.id; 
            // On compare avec l'ID T1_user de l'admin actuellement connecté
            const isMe = msg.Expediteur[0]?.id === currentUser?.t1_user_id;
            
            return `
                <div class="message ${isMe ? 'sent' : 'received'}">
                    <strong>${msg.Expediteur[0]?.value || 'Inconnu'}:</strong>
                    <p>${msg.contenu.replace(/\n/g, '<br>')}</p>
                </div>`;
        }).join('');
        messagesDisplay.scrollTop = messagesDisplay.scrollHeight;
    }

   // Fichier: includes/admin/assets/js/dashboard.js

function addMessageToDisplay(message) { // Le paramètre est 'message'
    if (!message || !message.Expediteur || !message.Expediteur[0]) {
        console.error("Données de message invalides reçues:", message);
        return; // Sécurité pour éviter d'autres erreurs
    }

    const senderT1Id = message.Expediteur[0]?.id;
    const isMe = senderT1Id === currentUser?.t1_user_id;

    const messageEl = document.createElement('div');
    messageEl.className = `message ${isMe ? 'sent' : 'received'}`;
    
    // On utilise 'message' partout ici
    messageEl.innerHTML = `
        <strong>${message.Expediteur[0]?.value || 'Inconnu'}:</strong>
        <p>${(message.contenu || '').replace(/\n/g, '<br>')}</p>
    `;
    
    messagesDisplay.appendChild(messageEl);
    messagesDisplay.scrollTop = messagesDisplay.scrollHeight;
}
    // --- Logique WebSocket ---
    async function connectRealtime() {
        try {
            const tokenResponse = await fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/admin-ws-token', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } });
            const { token } = await tokenResponse.json();
            
            socket = io("wss://portal.eecie.ca", { path: "/socket.io/", transports: ["websocket"] });

            socket.on('connect', () => socket.emit('authenticate', { token }));
            
            socket.on('authenticated', async (data) => {
    const userRes = await fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/me/t1-user', { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } });
    const t1User = await userRes.json();
    
    // On récupère aussi les détails de l'utilisateur WP
    const wpUserRes = await fetch(`${EECIE_CRM.rest_url}wp/v2/users/${data.user_id}?context=view`, { headers: { 'X-WP-Nonce': EECIE_CRM.nonce } });
    const wpUser = await wpUserRes.json();

    currentUser = { 
        wp_id: data.user_id, 
        t1_user_id: t1User.id,
        display_name: wpUser.name // On stocke le nom pour l'affichage
    };
});
            socket.on('user_list_update', async (data) => {
                if (data.action === 'connect') { await fetchUserDetails(data.user_id); } 
                else if (data.action === 'disconnect') { delete connectedUsers[data.user_id]; } 
                else if (data.ids) {
                    connectedUsers = {};
                    for (const id of data.ids) { await fetchUserDetails(id); }
                }
                updateConnectedUsersWidget();
            });

            socket.on('new_message', (data) => {
                const message = data.data;
                if (message.Threads[0].id === currentThreadId) {
                    addMessageToDisplay(message);
                } else {
                    const threadLi = threadsListContainer.querySelector(`li[data-thread-id="${message.Threads[0].id}"]`);
                    if (threadLi && !threadLi.querySelector('.notification-dot')) {
                        threadLi.insertAdjacentHTML('beforeend', '<span class="notification-dot" style="color:red; font-weight:bold;"> ●</span>');
                    }
                }
            });

        } catch (error) { console.error(error); connectedUsersWidget.innerHTML = '<p>Erreur de connexion au service temps réel.</p>'; }
    }

    // --- Gestionnaires d'événements DOM ---
    threadsListContainer.addEventListener('click', (e) => {
        const li = e.target.closest('li[data-thread-id]');
        if (li) {
            const threadId = parseInt(li.dataset.threadId, 10);
            const threadName = li.dataset.threadName;
            displayMessages(threadId, threadName);
            const dot = li.querySelector('.notification-dot');
            if(dot) dot.remove();
        }
    });
    
    // --- CORRECTION ET AJOUT DU GESTIONNAIRE D'ENVOI ---
messageForm.addEventListener('submit', (e) => {
    e.preventDefault();
    const content = messageInput.value.trim();
    const threadId = parseInt(messageThreadIdInput.value, 10);
    const submitButton = e.target.querySelector('button');

    if (content && threadId && socket && socket.connected) {
        submitButton.disabled = true;
        submitButton.textContent = 'Envoi...';

        const tempMessageData = {
            contenu: content,
            Expediteur: [{ 
                id: currentUser.t1_user_id, 
                value: currentUser.display_name || 'Moi'
            }],
            Threads: [{ id: threadId }]
        };

        socket.emit('send_message', {
            thread_id: threadId,
            content: content
        }, (response) => {
            if (response.status === 'ok') {
                // Le serveur a bien reçu, on peut ajouter notre message au DOM
                addMessageToDisplay(tempMessageData);
                messageInput.value = '';
                messageInput.focus();
            } else {
                alert(`Erreur du serveur : ${response.error || 'Erreur inconnue.'}`);
            }
            submitButton.disabled = false;
            submitButton.textContent = 'Envoyer';
        });

    } else {
        alert("Impossible d'envoyer le message. La connexion temps réel n'est pas active.");
    }
});

    // --- Initialisation ---
    updateConnectedUsersWidget();
    displayThreads();
    connectRealtime();
});