<?php
// Fichier : timalpai-eecie/gce-secrets.php

// Sécurité : Empêche l'accès direct au fichier via le web.
defined('ABSPATH') || exit;

/**
 * Clés secrètes partagées pour le chiffrement des données entre WP et N8N.
 * NE COMMETTEZ JAMAIS CE FICHIER SUR GIT.
 * Ces valeurs doivent correspondre EXACTEMENT à celles définies dans l'environnement N8N.
 */
define('GCE_SHARED_SECRET_KEY', 'votre_super_cle_secrete_de_32_caracteres_ici'); // Doit faire exactement 32 caractères de long
define('GCE_SHARED_IV', 'votre_iv_de_16_caracteres_ici'); // Doit faire exactement 16 caractères de long

// Env pour les pwa et le orchestrator/websocket tempsréel
// Doit être EXACTEMENT la même que dans le .env du serveur Python
define('GCE_SHARED_SECRET_KEY_JWT', 'api_key_longue_et_secrete_ici');

// Doit être EXACTEMENT la même que dans le .env du serveur Python
define('GCE_ORCHESTRATOR_INTERNAL_API_KEY', 'api_key_longue_et_secrete_ici');

// URL locale de l'orchestrateur. Utiliser localhost est plus rapide et sécurisé.
define('GCE_ORCHESTRATOR_URL', 'http://127.0.0.1:8888');

// Identifiants pour l'API de Virtualmin/Webmin
define('VIRTUALMIN_API_URL', 'https://votre-serveur.com:10000'); // URL de votre instance Virtualmin
define('VIRTUALMIN_API_USER', 'user_privilege'); // ou un autre utilisateur avec les droits
define('VIRTUALMIN_API_PASSWORD', 'votre_mot_de_passe_virtualmin');

// Identifiants pour l'API de Nextcloud (un compte admin)
define('NEXTCLOUD_API_URL', 'https://cloud.votre-domaine.com'); // URL de votre instance Nextcloud
define('NEXTCLOUD_API_USER', 'admin_nextcloud');
define('NEXTCLOUD_API_PASSWORD', 'mot_de_passe_admin_nextcloud');