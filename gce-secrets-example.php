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