// service-worker.js (Version 2 - Améliorée)

const CACHE_NAME = 'eecie-fournisseur-app-v3'; // On change le nom pour forcer la mise à jour
const urlsToCache = [
    '/',
    '/index.html',
    '/style.css',
    '/app.js',
    '/manifest.json',
    // Ajoutez ici les icônes si ce n'est pas fait
    '/icon-192.png',
    '/icon-512.png'
];

// Étape 1: Installation - Mettre en cache les ressources de l'application
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            console.log('Service Worker: Caching app shell');
            return cache.addAll(urlsToCache);
        })
    );
});

// Étape 2: Activation - Nettoyer les anciens caches
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    if (cacheName !== CACHE_NAME) {
                        console.log('Service Worker: Clearing old cache');
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
});


// Étape 3: Fetch - La logique de routage (la partie la plus importante)
self.addEventListener('fetch', event => {
    const requestUrl = new URL(event.request.url);

    // --- LA RÈGLE D'OR ---
    // Si la requête est pour une autre origine (notre API WordPress)...
    // ... ou si ce n'est pas une requête GET (c'est un POST, PATCH...)...
    // ... alors on ne fait RIEN et on laisse le réseau gérer.
    if (requestUrl.origin !== self.location.origin || event.request.method !== 'GET') {
        // En ne faisant rien (pas de event.respondWith), on laisse le navigateur
        // gérer la requête normalement, avec les bons en-têtes (CORS, Authorization, etc.)
        return;
    }

    // Pour toutes les autres requêtes (les fichiers de notre PWA), on utilise la stratégie "Cache-First"
    event.respondWith(
        caches.match(event.request).then(response => {
            // Si la ressource est dans le cache, on la retourne.
            // Sinon, on va la chercher sur le réseau.
            return response || fetch(event.request);
        })
    );
});