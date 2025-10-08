// app.js (Version finale avec logique de MISE À JOUR de rapport)

document.addEventListener('DOMContentLoaded', () => {
    // --- RÉFÉRENCES AUX ÉLÉMENTS DU DOM ---
    const screens = {
        login: document.getElementById('login-screen'),
        jobs: document.getElementById('jobs-screen'),
        report: document.getElementById('report-screen'),
    };
    const loginForm = document.getElementById('login-form');
    const jobsList = document.getElementById('jobs-list');
    const reportTitle = document.getElementById('report-title');
    const submitReportBtn = document.getElementById('submit-report-btn');
    const modifyReportBtn = document.getElementById('modify-report-btn');
    const logoutBtn = document.getElementById('logout-btn');
    const backToJobsBtn = document.getElementById('back-to-jobs-btn');
    const cloturerDossierBtn = document.getElementById('cloturer-dossier-btn');
    const jobsListToday = document.getElementById('jobs-list-today'); // Nouveau
    const jobsListFuture = document.getElementById('jobs-list-future'); // Nouveau
    const tabToday = document.getElementById('tab-today'); // Nouveau
    const tabFuture = document.getElementById('tab-future'); // Nouveau
    
    let signatureCanvas = null;
    let ctx = null;
    let drawing = false;

    // --- ÉTAT DE L'APPLICATION ---
    const state = {
        authHeader: null,
        jobs: [],
        currentReport: {
            id: null, // *** IMPORTANT : Pour stocker l'ID du rapport en cours
            opportunite_id: null,
            notes: '',
            articles_supplementaires: [],
            initialTotalHT: 0,
        },
    };
    // --- GESTION DES ONGLETS ---
    function handleTabClick(e) {
        // Enlever la classe 'active' de tous les boutons et panneaux
        [tabToday, tabFuture].forEach(tab => tab.classList.remove('active'));
        [jobsListToday, jobsListFuture].forEach(panel => panel.classList.remove('active'));

        // Ajouter la classe 'active' au bouton cliqué et au panneau correspondant
        const clickedTab = e.target;
        clickedTab.classList.add('active');
        if (clickedTab.id === 'tab-today') {
            jobsListToday.classList.add('active');
        } else {
            jobsListFuture.classList.add('active');
        }
    }

    // --- GESTION DES ÉCRANS ---
    function showScreen(screenName) {
        Object.values(screens).forEach(screen => screen.classList.remove('active'));
        screens[screenName].classList.add('active');
    }

    // --- GESTION DU CANVAS ---
    function initCanvas() {
        if (!ctx) return;
        ctx.fillStyle = "white";
        ctx.fillRect(0, 0, signatureCanvas.width, signatureCanvas.height);
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.strokeStyle = '#000';
        ctx.beginPath();
    }

    function resizeCanvas() {
        if (!signatureCanvas || !ctx) return;
        const rect = signatureCanvas.getBoundingClientRect();
        if (signatureCanvas.width !== rect.width || signatureCanvas.height !== rect.height) {
            signatureCanvas.width = rect.width;
            signatureCanvas.height = rect.height;
            initCanvas();
        }
    }

    // --- NOUVELLE FONCTION POUR LA CLÔTURE ---
   async function handleSubmitFinal(e) {
    const opportuniteId = e.target.dataset.opportuniteId;
    if (!opportuniteId) return;

    // **LA DONNÉE MANQUANTE EST ICI** : on récupère l'ID de l'intervention depuis l'état de l'application
     const interventionId = e.target.dataset.interventionId;
    if (!interventionId) {
        alert("Erreur critique : l'ID de l'intervention est introuvable. Impossible de clôturer.");
        return;
    }

    if (!confirm("Cette action marquera l'intervention comme 'Réalisée' et clôturera le dossier si c'est une mission ponctuelle. Êtes-vous sûr ?")) {
        return;
    }

    const btn = e.target;
    btn.disabled = true;
    btn.textContent = 'Clôture en cours...';

    try {
        const response = await fetch(`https://portal.eecie.ca/wp-json/eecie-crm/v1/opportunite/${opportuniteId}/cloturer`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': state.authHeader
            },
            // **CORRECTION** : On envoie l'ID de l'intervention dans le corps de la requête
            body: JSON.stringify({
                intervention_id: interventionId 
            })
        });

        if (!response.ok) {
            const errData = await response.json();
            throw new Error(errData.message || 'Le serveur a refusé la clôture.');
        }

        alert('Intervention marquée comme réalisée avec succès !');
        await fetchJobs(); // Recharger la liste (l'intervention va disparaître)
        showScreen('jobs'); // Retourner à la liste

    } catch (error) {
        alert(`Erreur : ${error.message}`);
    } finally {
        btn.disabled = false;
        btn.textContent = '✅ Clôturer le Dossier';
    }
}

    // --- GESTION DE L'AUTHENTIFICATION ET DES DONNÉES ---
    async function handleLogin(e) {
        e.preventDefault();
        const username = e.target.username.value;
        const appPassword = e.target.password.value.replace(/\s/g, '');
        const loginError = document.getElementById('login-error');
        loginError.textContent = '';
        const basicAuth = 'Basic ' + btoa(username + ':' + appPassword);

        try {
            const response = await fetch('https://portal.eecie.ca/wp-json/eecie-crm/v1/fournisseur/mes-jobs', {
                headers: { 'Authorization': basicAuth }
            });
            const data = await response.json();
            if (!response.ok) {
                throw new Error(data.message || 'Identifiants ou mot de passe d\'application incorrects.');
            }
            state.authHeader = basicAuth;
            localStorage.setItem('auth_header', basicAuth);
            state.jobs = data;
            renderJobs();
            showScreen('jobs');
        } catch (error) {
            loginError.textContent = error.message;
        }
    }

async function fetchJobs() {
        // Vider les deux listes
        jobsListToday.innerHTML = '<div class="loader">Chargement...</div>';
        jobsListFuture.innerHTML = '<div class="loader">Chargement...</div>';
        try {
            const response = await fetch('https://portal.eecie.ca/wp-json/eecie-crm/v1/fournisseur/mes-jobs', {
                headers: { 'Authorization': state.authHeader }
            });
            const data = await response.json();
            if (!response.ok) {
                throw new Error(data.message || 'Impossible de charger les missions.');
            }
            state.jobs = data;
          
            renderJobs();
        } catch (error) {
            jobsListToday.innerHTML = `<p class="error-message">${error.message}</p>`;
            jobsListFuture.innerHTML = ''; // Vider l'autre panneau
        }
    }

// *** NOUVELLE FONCTION HELPER POUR ÉVITER LA DUPLICATION DE CODE ***
 function renderJobsInPanel(panel, jobs) {
    panel.innerHTML = '';
    if (jobs.length === 0) {
        panel.innerHTML = '<p>Aucune intervention ne correspond à ce critère.</p>';
        return;
    }
    jobs.forEach(job => {
        const item = document.createElement('div');
        item.className = 'job-item';
        item.dataset.jobId = job.id;

        // ======================================================================
        // ==                 DÉBUT DE LA CORRECTION DE LA DATE                ==
        // ======================================================================

        let dateHtml = '<span>Date non définie</span>';
        if (job.Date_Prev_Intervention) {
            try {
                // 1. Créer un objet Date à partir de la chaîne ISO (UTC)
                const date = new Date(job.Date_Prev_Intervention);

                // 2. Extraire chaque composante en utilisant les méthodes UTC pour ignorer le fuseau du navigateur
                const year = date.getUTCFullYear();
                const month = String(date.getUTCMonth() + 1).padStart(2, '0'); // +1 car les mois sont de 0 à 11
                const day = String(date.getUTCDate()).padStart(2, '0');
                const hours = String(date.getUTCHours()).padStart(2, '0');
                const minutes = String(date.getUTCMinutes()).padStart(2, '0');

                // 3. Formater manuellement la chaîne dans le format souhaité
                const formattedDate = `${day}/${month}/${year} ${hours}:${minutes}`;
                
                dateHtml = `<span><strong>Date :</strong> ${formattedDate}</span>`;

            } catch (e) {
                dateHtml = '<span>Date invalide</span>';
            }
        }

        // On insère le HTML de la date (le reste est inchangé)
        item.innerHTML = `
            <strong>Client : ${job.NomClient}</strong><br>
            ${dateHtml}<br>
            <span>Ville : ${job.Ville}</span>
        `;
        
        // ======================================================================
        // ==                   FIN DE LA CORRECTION DE LA DATE                ==
        // ======================================================================

        item.addEventListener('click', () => startReport(job.id));
        panel.appendChild(item);
    });
}

    // *** FONCTION renderJobs ENTIÈREMENT RÉÉCRITE ***
    function renderJobs() {
        if (!Array.isArray(state.jobs)) {
            jobsListToday.innerHTML = '<p class="error-message">Une erreur de format de données est survenue.</p>';
            jobsListFuture.innerHTML = '';
            return;
        }
        
        // Obtenir la date d'aujourd'hui au format YYYY-MM-DD (indépendant du fuseau horaire)
        const today = new Date();
        today.setMinutes(today.getMinutes() - today.getTimezoneOffset()); // Ajuster au fuseau horaire local
        const todayString = today.toISOString().split('T')[0];
        
        const todayJobs = [];
        const futureJobs = [];

        // Trier les jobs dans les bonnes listes
        state.jobs.forEach(job => {
            if (job.Date_Prev_Intervention) {
                const jobDateString = job.Date_Prev_Intervention.split('T')[0];
                if (jobDateString === todayString) {
                    todayJobs.push(job);
                } else if (jobDateString > todayString) {
                    futureJobs.push(job);
                }
            } else {
                // Si pas de date, on le met dans "À venir" par défaut
                futureJobs.push(job);
            }
        });

        // Rendre chaque liste dans son panneau respectif
        renderJobsInPanel(jobsListToday, todayJobs);
        renderJobsInPanel(jobsListFuture, futureJobs);
    }

    // --- LOGIQUE DU RAPPORT ---
    function startReport(jobId) {
        const job = state.jobs.find(j => j.id === jobId);
        if (!job) return;
// --- MISE À JOUR DE L'AFFICHAGE DES DÉTAILS ---
    const contactDetails = job.ContactDetails;
    const clientNameEl = document.getElementById('report-client-name');
    const clientAddressEl = document.getElementById('report-client-address');

    if (contactDetails) {
        // On utilise le nom du contact s'il existe, sinon on se rabat sur le nom de l'opportunité
        clientNameEl.textContent = contactDetails.Nom || job.NomClient;
        
        // On construit une chaîne d'adresse complète et propre
        let fullAddress = [
            contactDetails.Adresse,
            contactDetails.Ville,
            contactDetails.Code_postal
        ].filter(Boolean).join(', '); // .filter(Boolean) retire les parties vides (null, undefined, '')

        // On ajoute le téléphone et l'email s'ils existent, avec des liens cliquables
        if (contactDetails.Telephone) {
            fullAddress += `<br>📞 <a href="tel:${contactDetails.Telephone}">${contactDetails.Telephone}</a>`;
        }
        if (contactDetails.Email) {
            fullAddress += `<br>📧 <a href="mailto:${contactDetails.Email}">${contactDetails.Email}</a>`;
        }

        // On injecte le HTML dans l'élément 'Adresse'
        clientAddressEl.innerHTML = fullAddress || 'Adresse non spécifiée';
    } else {
        // Comportement de secours si ContactDetails n'est pas fourni par l'API
        clientNameEl.textContent = job.NomClient;
        clientAddressEl.textContent = job.Ville || 'Adresse non spécifiée';
    }
        reportTitle.textContent = `Rapport pour ${job.NomClient}`;
       
        let initialTotal = 0;
        const initialArticlesList = document.getElementById('initial-articles-list');
        initialArticlesList.innerHTML = `<div class="article-item articles-header"><span class="name">Description</span><span class="qty">Qté</span><span class="price">P.U.</span><span class="total">Total</span><span></span></div>`;
        if (job.ArticlesDevis && Array.isArray(job.ArticlesDevis) && job.ArticlesDevis.length > 0) {
            job.ArticlesDevis.forEach(article => {
                const totalLigne = parseFloat(article.Prix_hors_taxe || 0);
                initialTotal += totalLigne;
                const item = document.createElement('div');
                item.className = 'article-item readonly';
                item.innerHTML = `
                <span class="name" data-label="Description">${article.Nom}</span>
                <span class="qty" data-label="Qté">${parseFloat(article.Quantités || 0).toFixed(2)}</span>
                <span class="price" data-label="P.U.">${parseFloat(article.Prix_unitaire || 0).toFixed(2)}</span>
                <span class="total" data-label="Total">${totalLigne.toFixed(2)}</span>
                <span></span>`; 
                initialArticlesList.appendChild(item);
            });
        } else {
            initialArticlesList.innerHTML += '<div class="article-item">Aucun article spécifique au devis.</div>';
        }

        const hasExistingReport = job.RapportLivraison;
        const isSigned = hasExistingReport && job.RapportLivraison.Signature_Image_URL;

        if (hasExistingReport) {
            document.getElementById('report-notes').value = job.RapportLivraison.Notes_intervention || '';
            state.currentReport = {
                id: job.RapportLivraison.id, // *** CORRECTION 1 : On stocke l'ID du rapport existant
                opportunite_id: job.id,
                intervention_id: job.intervention_id,
                articles_supplementaires: job.RapportLivraison.Articles_Livraison || [],
                initialTotalHT: initialTotal,
            };
        } else {
            document.getElementById('report-notes').value = '';
            state.currentReport = {
                id: null, // Pas de rapport existant
                opportunite_id: job.id,
                intervention_id: job.intervention_id,
                notes: '',
                articles_supplementaires: [],
                initialTotalHT: initialTotal,
            };
        }
   

        if (isSigned) {
            document.getElementById('report-notes').disabled = true;
            document.getElementById('add-article-form').style.display = 'none';
            submitReportBtn.style.display = 'none';
            modifyReportBtn.style.display = 'block';
            cloturerDossierBtn.style.display = 'block';
            modifyReportBtn.dataset.reportId = job.RapportLivraison.id;
            cloturerDossierBtn.dataset.opportuniteId = job.id;
            cloturerDossierBtn.dataset.interventionId = job.intervention_id;
            const signaturePad = document.getElementById('signature-pad');
            signaturePad.innerHTML = `<p><strong>Signature du Client :</strong></p><img src="${job.RapportLivraison.Signature_Image_URL}" alt="Signature" style="border: 1px solid #ccc; max-width: 100%; border-radius: 5px;">`;
        } else {
            document.getElementById('report-notes').disabled = false;
            document.getElementById('add-article-form').style.display = 'block';
            submitReportBtn.style.display = 'block';
            modifyReportBtn.style.display = 'none';
            cloturerDossierBtn.style.display = 'none';
            document.getElementById('signature-pad').innerHTML = `<canvas id="signature-canvas"></canvas><button id="clear-signature-btn" class="btn-danger">Effacer</button>`;
            setupSignatureCanvas();
        }

        renderAddedArticles();
        showScreen('report');
    }

    function renderAddedArticles() {
        const addedArticlesList = document.getElementById('added-articles-list');
        const totalSpan = document.getElementById('added-total');
        const nouveauTotalSpan = document.getElementById('nouveau-total-ht');
        addedArticlesList.innerHTML = '';
        let totalAjouts = 0;
        const isEditing = !document.getElementById('report-notes').disabled;

        addedArticlesList.innerHTML = `<div class="article-item articles-header"><span class="name">Description</span><span class="qty">Qté</span><span class="price">P.U.</span><span class="total">Total</span><span class="actions"></span></div>`;

        if (state.currentReport.articles_supplementaires.length === 0) {
            addedArticlesList.innerHTML += '<div class="article-item" style="grid-column: 1 / -1; text-align: center; padding: 10px;">Aucun ajout pour le moment.</div>';
        } else {
            state.currentReport.articles_supplementaires.forEach((article, index) => {
                const item = document.createElement('div');
                item.className = 'article-item';
                const nom = article.nom || article.Nom || '';
                const quantite = parseFloat(article.quantite || article.Quantités || 0);
                const prixUnitaire = parseFloat(article.prix_unitaire || article.Prix_unitaire || 0);
                const totalLigne = parseFloat(article.Prix_hors_taxe) || (quantite * prixUnitaire);
                let deleteButtonHtml = '<span></span>';
                if (isEditing) {
                    if (article.id) {
                        deleteButtonHtml = `<span class="actions"><button class="delete-saved-article-btn" data-id="${article.id}" title="Supprimer cet article de la base de données">🗑️</button></span>`;
                    } else {
                        deleteButtonHtml = `<span class="actions"><button class="delete-unsaved-article-btn" data-index="${index}" title="Supprimer cet article ajouté">❌</button></span>`;
                    }
                }
                 item.innerHTML = `
        <span class="name" data-label="Description">${nom}</span>
        <span class="qty" data-label="Qté">${quantite.toFixed(2)}</span>
        <span class="price" data-label="P.U.">${prixUnitaire.toFixed(2)}</span>
        <span class="total" data-label="Total">${totalLigne.toFixed(2)}</span>
        ${deleteButtonHtml}
    `;
                addedArticlesList.appendChild(item);
                totalAjouts += totalLigne;
            });
        }

        totalSpan.textContent = totalAjouts.toFixed(2) + ' €';
        const nouveauTotalHT = state.currentReport.initialTotalHT + totalAjouts;
        nouveauTotalSpan.textContent = nouveauTotalHT.toFixed(2) + ' €';
    }

    function handleAddArticle(e) {
        e.preventDefault();
        const form = e.target;
        const newArticle = { nom: form['article-name'].value.trim(), quantite: form['article-qty'].value, prix_unitaire: form['article-price'].value };
        if (!newArticle.nom || !newArticle.quantite || !newArticle.prix_unitaire) {
            alert('Veuillez remplir tous les champs de l\'article.');
            return;
        }
        state.currentReport.articles_supplementaires.push(newArticle);
        renderAddedArticles();
        form.reset();
    }

    function handleDeleteUnsavedArticle(e) {
        const deleteBtn = e.target.closest('.delete-unsaved-article-btn');
        if (!deleteBtn) return;
        const index = parseInt(deleteBtn.dataset.index, 10);
        state.currentReport.articles_supplementaires.splice(index, 1);
        renderAddedArticles();
    }

    async function handleDeleteSavedArticle(e) {
        const deleteBtn = e.target.closest('.delete-saved-article-btn');
        if (!deleteBtn) return;
        const articleId = deleteBtn.dataset.id;
        if (!confirm("Êtes-vous sûr de vouloir supprimer cet article ? Cette action est irréversible.")) return;
        deleteBtn.disabled = true;
        try {
            const response = await fetch(`https://portal.eecie.ca/wp-json/eecie-crm/v1/articles-livraison/${articleId}`, {
                method: 'DELETE',
                headers: { 'Authorization': state.authHeader }
            });
            if (!response.ok) {
                const errData = await response.json();
                throw new Error(errData.message || 'La suppression a échoué.');
            }
            state.currentReport.articles_supplementaires = state.currentReport.articles_supplementaires.filter(a => a.id != articleId);
            renderAddedArticles();
            alert("Article supprimé avec succès.");
        } catch (error) {
            alert(`Erreur : ${error.message}`);
            deleteBtn.disabled = false;
        }
    }

    function setupSignatureCanvas() {
        signatureCanvas = document.getElementById('signature-canvas');
        if (!signatureCanvas) return;
        ctx = signatureCanvas.getContext('2d');
        drawing = false;
        const getMousePos = (e) => ({ x: e.clientX - signatureCanvas.getBoundingClientRect().left, y: e.clientY - signatureCanvas.getBoundingClientRect().top });
        const getTouchPos = (e) => ({ x: e.touches[0].clientX - signatureCanvas.getBoundingClientRect().left, y: e.touches[0].clientY - signatureCanvas.getBoundingClientRect().top });
        const draw = (e) => {
            e.preventDefault();
            if (!drawing) return;
            const pos = e.touches ? getTouchPos(e) : getMousePos(e);
            ctx.lineTo(pos.x, pos.y);
            ctx.stroke();
            ctx.beginPath();
            ctx.moveTo(pos.x, pos.y);
        };
        signatureCanvas.addEventListener('mousedown', (e) => { drawing = true; draw(e); });
        signatureCanvas.addEventListener('mouseup', () => { drawing = false; ctx.beginPath(); });
        signatureCanvas.addEventListener('mousemove', draw);
        signatureCanvas.addEventListener('touchstart', (e) => { drawing = true; draw(e); }, { passive: false });
        signatureCanvas.addEventListener('touchend', () => { drawing = false; ctx.beginPath(); });
        signatureCanvas.addEventListener('touchmove', draw, { passive: false });
        document.getElementById('clear-signature-btn').addEventListener('click', clearSignature);
    }

    function clearSignature() {
        if (signatureCanvas) {
            ctx.clearRect(0, 0, signatureCanvas.width, signatureCanvas.height);
            initCanvas();
        }
    }

    async function handleSubmitReport() {
        if (!signatureCanvas) { alert("Erreur: le canvas de signature n'a pas été trouvé."); return; }
        const isBlank = !ctx.getImageData(0, 0, signatureCanvas.width, signatureCanvas.height).data.some(channel => channel !== 0);
        if (isBlank) { alert("La signature du client est obligatoire."); return; }

        submitReportBtn.disabled = true;
        submitReportBtn.textContent = 'Envoi en cours...';

        const payload = {
            report_id: state.currentReport.id, // *** CORRECTION 2 : On envoie l'ID du rapport (sera null si nouveau)
            opportunite_id: state.currentReport.opportunite_id,
             intervention_id: state.currentReport.intervention_id,
            notes: document.getElementById('report-notes').value,
            signature_base64: signatureCanvas.toDataURL('image/png'),
            articles_supplementaires: state.currentReport.articles_supplementaires.filter(a => !a.id),
        };

        try {
            const response = await fetch('https://portal.eecie.ca/wp-json/eecie-crm/v1/livraison/submit-report', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': state.authHeader },
                body: JSON.stringify(payload)
            });
            if (!response.ok) throw new Error('Le serveur a refusé la soumission.');
            alert('Rapport soumis avec succès !');
            await fetchJobs();
            showScreen('jobs');
        } catch (error) {
            alert(`Erreur: ${error.message}`);
        } finally {
            submitReportBtn.disabled = false;
            submitReportBtn.textContent = 'Soumettre le Rapport Signé';
        }
    }

    function handleLogout() {
        state.authHeader = null;
        localStorage.removeItem('auth_header');
        showScreen('login');
    }

    function enableReportEditing() {
        document.getElementById('report-notes').disabled = false;
        document.getElementById('add-article-form').style.display = 'block';
        submitReportBtn.style.display = 'block';
        modifyReportBtn.style.display = 'none';
        document.getElementById('signature-pad').innerHTML = `<canvas id="signature-canvas"></canvas><button id="clear-signature-btn" class="btn-danger">Effacer</button>`;
        setupSignatureCanvas();
        renderAddedArticles();
        requestAnimationFrame(resizeCanvas);
    }

    async function handleModifyReport(e) {
        const reportId = e.target.dataset.reportId;
        if (!reportId) return;
        if (!confirm("Voulez-vous vraiment modifier ce rapport ? La signature actuelle sera supprimée et une nouvelle signature sera requise.")) return;
        modifyReportBtn.disabled = true;
        modifyReportBtn.textContent = "Invalidation...";
        try {
            const response = await fetch(`https://portal.eecie.ca/wp-json/eecie-crm/v1/livraison/invalidate-report/${reportId}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': state.authHeader },
                body: JSON.stringify({})
            });
            if (!response.ok) {
                const errData = await response.json();
                throw new Error(errData.message || 'Impossible d\'invalider la signature.');
            }
            alert("Le rapport est déverrouillé. Vous pouvez maintenant le modifier.");
            enableReportEditing();
        } catch (error) {
            alert(`Erreur : ${error.message}`);
        } finally {
            modifyReportBtn.disabled = false;
            modifyReportBtn.textContent = "Modifier le Rapport";
        }
    }

    function setupCollapsibleSections() {
        document.querySelectorAll('.collapsible-header').forEach(header => {
            header.addEventListener('click', function () {
                const content = this.nextElementSibling;
                const isOpening = content.style.display !== 'block';
                content.style.display = isOpening ? 'block' : 'none';
                if (isOpening) {
                    const canvas = content.querySelector('#signature-canvas');
                    if (canvas) {
                        requestAnimationFrame(resizeCanvas);
                    }
                }
            });
        });
    }

    // --- INITIALISATION ---
    function init() {
        loginForm.addEventListener('submit', handleLogin);
        logoutBtn.addEventListener('click', handleLogout);
        backToJobsBtn.addEventListener('click', () => showScreen('jobs'));
        submitReportBtn.addEventListener('click', handleSubmitReport);
        modifyReportBtn.addEventListener('click', handleModifyReport);
        cloturerDossierBtn.addEventListener('click', handleSubmitFinal); 
        document.getElementById('add-article-form').addEventListener('submit', handleAddArticle);
         // AJOUTER CES DEUX LIGNES
        tabToday.addEventListener('click', handleTabClick);
        tabFuture.addEventListener('click', handleTabClick);
        

        const addedArticlesList = document.getElementById('added-articles-list');
        addedArticlesList.addEventListener('click', (e) => {
            handleDeleteUnsavedArticle(e);
            handleDeleteSavedArticle(e);
        });

        setupCollapsibleSections();
        window.addEventListener('resize', () => {
            if (screens.report.classList.contains('active') && document.getElementById('signature-canvas')) {
                resizeCanvas();
            }
        });
        const savedAuth = localStorage.getItem('auth_header');
        if (savedAuth) {
            state.authHeader = savedAuth;
            fetchJobs();
            showScreen('jobs');
        } else {
            showScreen('login');
        }
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/service-worker.js')
                    .then(reg => console.log('Service worker registered.', reg))
                    .catch(err => console.log('Service worker registration failed: ', err));
            });
        }
    }

    init();
});