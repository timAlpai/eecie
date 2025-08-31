// app.js

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
    const logoutBtn = document.getElementById('logout-btn');
    const backToJobsBtn = document.getElementById('back-to-jobs-btn');
    const signatureCanvas = document.getElementById('signature-canvas');
    const clearSignatureBtn = document.getElementById('clear-signature-btn');

    let ctx = signatureCanvas.getContext('2d'); // 'let' pour pouvoir le réassigner
    let drawing = false;

    // --- ÉTAT DE L'APPLICATION ---
    const state = {
        authHeader: null,
        jobs: [],
        currentReport: {
            opportunite_id: null,
            notes: '',
            articles_supplementaires: [], // Renommé pour plus de clarté
        },
    };

    // --- GESTION DES ÉCRANS ---
    function showScreen(screenName) {
        Object.values(screens).forEach(screen => screen.classList.remove('active'));
        screens[screenName].classList.add('active');
    }

    // --- GESTION DU CANVAS ---
    function initCanvas() {
        ctx.fillStyle = "white";
        ctx.fillRect(0, 0, signatureCanvas.width, signatureCanvas.height);
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.strokeStyle = '#000';
        ctx.beginPath();
    }

    function resizeCanvas() {
        const rect = signatureCanvas.getBoundingClientRect();
        if (rect.width > 0 && rect.height > 0) {
            signatureCanvas.width = rect.width;
            signatureCanvas.height = rect.height;
        }
        initCanvas();
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
        jobsList.innerHTML = '<div class="loader">Chargement...</div>';
        try {
            const response = await fetch('https://portal.eecie.ca/wp-json/eecie-crm/v1/fournisseur/mes-jobs', {
                headers: { 'Authorization': state.authHeader }
            });
            const data = await response.json();
            if (response.status === 401) {
                handleLogout();
                return;
            }
            if (!response.ok) {
                throw new Error(data.message || 'Impossible de charger les missions.');
            }
            state.jobs = data;
            renderJobs();
        } catch (error) {
            jobsList.innerHTML = `<p class="error-message">${error.message}</p>`;
        }
    }



    function renderJobs() {
        jobsList.innerHTML = '';
        if (!Array.isArray(state.jobs)) {
            jobsList.innerHTML = '<p class="error-message">Une erreur de format de données est survenue.</p>';
            return;
        }
        if (state.jobs.length === 0) {
            jobsList.innerHTML = '<p>Aucune intervention de type "Realisation" ne vous est assignée pour le moment.</p>';
            return;
        }
        state.jobs.forEach(job => {
            const item = document.createElement('div');
            item.className = 'job-item';
            item.dataset.jobId = job.id;
            item.innerHTML = `<strong>Client : ${job.NomClient}</strong><br><span>Ville : ${job.Ville}</span>`;
            item.addEventListener('click', () => startReport(job.id));
            jobsList.appendChild(item);
        });
    }

    // --- LOGIQUE DU RAPPORT ---
    function startReport(jobId) {
        const job = state.jobs.find(j => j.id === jobId);
        if (!job) return;

        // Réinitialisation de l'affichage
        document.getElementById('add-article-form').style.display = 'block';
        document.getElementById('report-notes').disabled = false;

        // Remplissage des détails de base
        reportTitle.textContent = `Rapport pour ${job.NomClient}`;
        document.getElementById('report-client-name').textContent = job.NomClient;
        document.getElementById('report-client-address').textContent = job.Ville;
        document.getElementById('report-general-scope').innerHTML = job.Travaux;

        // Affichage des articles du devis initial
        const initialArticlesList = document.getElementById('initial-articles-list');
        initialArticlesList.innerHTML = `<div class="article-item articles-header"><span class="name">Description</span><span class="qty">Qté</span><span class="price">P.U.</span><span class="total">Total</span></div>`;
        if (job.ArticlesDevis && Array.isArray(job.ArticlesDevis) && job.ArticlesDevis.length > 0) {
            job.ArticlesDevis.forEach(article => {
                const item = document.createElement('div');
                item.className = 'article-item readonly';
                item.innerHTML = `<span class="name">${article.Nom}</span><span class="qty">${parseFloat(article.Quantités || 0).toFixed(2)}</span><span class="price">${parseFloat(article.Prix_unitaire || 0).toFixed(2)}</span><span class="total">${parseFloat(article.Prix_hors_taxe || 0).toFixed(2)}</span>`;
                initialArticlesList.appendChild(item);
            });
        } else {
            initialArticlesList.innerHTML += '<div class="article-item">Aucun article spécifique au devis.</div>';
        }
        
        if (job.RapportLivraison) {
            // ---- MODE LECTURE SEULE ----
            test=job.RapportLivraison;

            // 1. Remplir les champs avec les données du rapport existant
            document.getElementById('report-notes').value = job.RapportLivraison.Notes_intervention || '';

            // 2. Mettre à jour l'état de l'application avec les articles déjà soumis
            //    C'est la ligne qui manquait et qui est cruciale.
            state.currentReport.articles_supplementaires = job.RapportLivraison.Articles_Livraison || [];

            // 3. Désactiver les interactions de l'utilisateur
            document.getElementById('report-notes').disabled = true;
            document.getElementById('add-article-form').style.display = 'none'; // Cache le formulaire d'ajout
            document.getElementById('submit-report-btn').style.display = 'none'; // Cache le bouton de soumission

            // 4. Afficher l'image de la signature enregistrée
            const signaturePad = document.getElementById('signature-pad');
            if (job.RapportLivraison.Signature_Image_URL) {
                signaturePad.innerHTML = `<p><strong>Signature du Client :</strong></p>
                                  <img src="${job.RapportLivraison.Signature_Image_URL}" alt="Signature" style="border: 1px solid #ccc; max-width: 100%; border-radius: 5px;">`;
            } else {
                signaturePad.innerHTML = '<p><strong>Signature du Client :</strong><br>Signature enregistrée (image non disponible).</p>';
            }

        
        state.currentReport.articles_supplementaires = job.RapportLivraison.Articles_Livraison || [];
        // Note: Il faudra ajouter la logique pour afficher les articles du rapport existant ici.
       // state.currentReport.articles_supplementaires = []; // Placeholder

    } else {
        // Mode Création
        document.getElementById('report-notes').value = '';
        document.getElementById('submit-report-btn').style.display = 'block';

        document.getElementById('signature-pad').innerHTML = `<canvas id="signature-canvas"></canvas><button id="clear-signature-btn" class="btn-danger">Effacer</button>`;
        setupSignatureCanvas(); // On ré-attache les listeners au nouveau canvas

        state.currentReport = {
            opportunite_id: job.id,
            notes: '',
            articles_supplementaires: [],
        };
    }

    renderAddedArticles();
    showScreen('report');
    if (!job.RapportLivraison) {
        setTimeout(resizeCanvas, 50);
    }
} //  <-- L'ACCOLADE ÉTAIT MAL PLACÉE ICI

function renderAddedArticles() {
        const addedArticlesList = document.getElementById('added-articles-list');
        const totalSpan = document.getElementById('added-total');
        addedArticlesList.innerHTML = '';
        let total = 0;

        // On ajoute un en-tête pour le tableau des ajouts
        addedArticlesList.innerHTML = `<div class="article-item articles-header">
        <span class="name">Description</span><span class="qty">Qté</span>
        <span class="price">P.U.</span><span class="total">Total</span>
    </div>`;

        if (state.currentReport.articles_supplementaires.length === 0) {
            addedArticlesList.innerHTML += '<div class="article-item" style="grid-column: 1 / -1; text-align: center; padding: 10px;">Aucun ajout pour le moment.</div>';

        } else {
            state.currentReport.articles_supplementaires.forEach((article, index) => {
                const item = document.createElement('div');
                item.className = 'article-item';
                
                // On calcule le total de la ligne
                const lineTotal = (parseFloat(article.Quantités || 0) * parseFloat(article.Prix_unitaire || 0)).toFixed(2);

                // On affiche les données. On utilise les noms de champs de Baserow.
                
                item.innerHTML = `
            <span class="name">${article.Nom}</span>
            <span class="qty">${parseFloat(article['Quantités'] || 0).toFixed(2)}</span>
            <span class="price">${parseFloat(article['Prix_unitaire'] || 0).toFixed(2)}</span>
            <span class="total">${parseFloat(article['Prix_hors_taxe'] || 0).toFixed(2)}</span>
        `;
                addedArticlesList.appendChild(item);
                total += parseFloat(lineTotal);
            });
        }
        totalSpan.textContent = total.toFixed(2) + ' €';
    }

    function handleAddArticle(e) {
        // ... (Le code de cette fonction est inchangé)
        e.preventDefault();
        const form = e.target;
        const newArticle = { nom: form['article-name'].value.trim(), quantite: form['article-qty'].value, prix_unitaire: form['article-price'].value, };
        if (!newArticle.nom || !newArticle.quantite || !newArticle.prix_unitaire) {
            alert('Veuillez remplir tous les champs de l\'article.');
            return;
        }
        state.currentReport.articles_supplementaires.push(newArticle);
        renderAddedArticles();
        form.reset();
    }

    function handleDeleteArticle(e) {
        // ... (Le code de cette fonction est inchangé)
        if (!e.target.classList.contains('delete-article-btn')) return;
        const index = parseInt(e.target.dataset.index, 10);
        state.currentReport.articles_supplementaires.splice(index, 1);
        renderAddedArticles();
    }

    // ... (Le reste des fonctions de signature et de soumission sont inchangées) ...
    // ... setupSignatureCanvas(), draw(), clearSignature(), handleSubmitReport() ...
    
    function setupSignatureCanvas() {
        const canvas = document.getElementById('signature-canvas');
        if (!canvas) return;

        ctx = canvas.getContext('2d');
        drawing = false;

        const getMousePos = (e) => ({ x: e.clientX - canvas.getBoundingClientRect().left, y: e.clientY - canvas.getBoundingClientRect().top });
        const getTouchPos = (e) => ({ x: e.touches[0].clientX - canvas.getBoundingClientRect().left, y: e.touches[0].clientY - canvas.getBoundingClientRect().top });

        const draw = (e) => {
            e.preventDefault();
            if (!drawing) return;
            const pos = e.touches ? getTouchPos(e) : getMousePos(e);
            ctx.lineTo(pos.x, pos.y);
            ctx.stroke();
            ctx.beginPath();
            ctx.moveTo(pos.x, pos.y);
        };

        canvas.addEventListener('mousedown', (e) => { drawing = true; draw(e); });
        canvas.addEventListener('mouseup', () => { drawing = false; ctx.beginPath(); });
        canvas.addEventListener('mousemove', draw);
        canvas.addEventListener('touchstart', (e) => { drawing = true; draw(e); }, { passive: false });
        canvas.addEventListener('touchend', () => { drawing = false; ctx.beginPath(); });
        canvas.addEventListener('touchmove', draw, { passive: false });

        document.getElementById('clear-signature-btn').addEventListener('click', clearSignature);

        resizeCanvas();
    }
    
    function clearSignature() {
        const canvas = document.getElementById('signature-canvas');
        if (canvas) {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            initCanvas();
        }
    }

    async function handleSubmitReport() {
        const canvas = document.getElementById('signature-canvas');
        if (!canvas) { alert("Erreur: le canvas de signature n'a pas été trouvé."); return; }
        const isBlank = !ctx.getImageData(0, 0, canvas.width, canvas.height).data.some(channel => channel !== 0);
        if (isBlank) { alert("La signature du client est obligatoire."); return; }

        submitReportBtn.disabled = true;
        submitReportBtn.textContent = 'Envoi en cours...';

        const payload = {
            opportunite_id: state.currentReport.opportunite_id,
            notes: document.getElementById('report-notes').value,
            signature_base64: canvas.toDataURL('image/png'),
            articles_supplementaires: state.currentReport.articles_supplementaires,
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

    function setupCollapsibleSections() {
        document.querySelectorAll('.collapsible-header').forEach(header => {
            header.addEventListener('click', function () {
                const content = this.nextElementSibling;
                content.style.display = content.style.display === 'block' ? 'none' : 'block';
            });
        });
    }

    // --- INITIALISATION ---
    function init() {
        loginForm.addEventListener('submit', handleLogin);
        logoutBtn.addEventListener('click', handleLogout);
        backToJobsBtn.addEventListener('click', () => showScreen('jobs'));
        submitReportBtn.addEventListener('click', handleSubmitReport);
        document.getElementById('add-article-form').addEventListener('submit', handleAddArticle);
        document.getElementById('added-articles-list').addEventListener('click', handleDeleteArticle);

        setupCollapsibleSections();

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

}); // <-- L'accolade de fin de DOMContentLoaded