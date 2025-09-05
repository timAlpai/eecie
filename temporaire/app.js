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

        if (!confirm("Cette action clôturera définitivement le dossier et changera son statut à 'Finaliser'. Êtes-vous sûr de vouloir continuer ?")) {
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
                body: JSON.stringify({}) // Le corps peut être vide
            });

            if (!response.ok) {
                const errData = await response.json();
                throw new Error(errData.message || 'Le serveur a refusé la clôture.');
            }

            alert('Dossier clôturé avec succès !');
            await fetchJobs(); // Recharger la liste des missions
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

        reportTitle.textContent = `Rapport pour ${job.NomClient}`;
        document.getElementById('report-client-name').textContent = job.NomClient;
        document.getElementById('report-client-address').textContent = job.Ville;
        document.getElementById('report-general-scope').innerHTML = job.Travaux;

        let initialTotal = 0;
        const initialArticlesList = document.getElementById('initial-articles-list');
        initialArticlesList.innerHTML = `<div class="article-item articles-header"><span class="name">Description</span><span class="qty">Qté</span><span class="price">P.U.</span><span class="total">Total</span><span></span></div>`;
        if (job.ArticlesDevis && Array.isArray(job.ArticlesDevis) && job.ArticlesDevis.length > 0) {
            job.ArticlesDevis.forEach(article => {
                const totalLigne = parseFloat(article.Prix_hors_taxe || 0);
                initialTotal += totalLigne;
                const item = document.createElement('div');
                item.className = 'article-item readonly';
                item.innerHTML = `<span class="name">${article.Nom}</span><span class="qty">${parseFloat(article.Quantités || 0).toFixed(2)}</span><span class="price">${parseFloat(article.Prix_unitaire || 0).toFixed(2)}</span><span class="total">${totalLigne.toFixed(2)}</span><span></span>`;
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
                articles_supplementaires: job.RapportLivraison.Articles_Livraison || [],
                initialTotalHT: initialTotal,
            };
        } else {
            document.getElementById('report-notes').value = '';
            state.currentReport = {
                id: null, // Pas de rapport existant
                opportunite_id: job.id,
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
                    <span class="name">${nom}</span>
                    <span class="qty">${quantite.toFixed(2)}</span>
                    <span class="price">${prixUnitaire.toFixed(2)}</span>
                    <span class="total">${totalLigne.toFixed(2)}</span>
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