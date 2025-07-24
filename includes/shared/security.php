<?php
defined('ABSPATH') || exit;

// Utilise les clés de sécurité de WordPress pour plus de robustesse
define('GCE_ENCRYPTION_KEY', defined('AUTH_KEY') ? AUTH_KEY : 'a-fallback-key-if-not-set');
define('GCE_ENCRYPTION_IV', defined('AUTH_SALT') ? substr(hash('sha256', AUTH_SALT), 0, 16) : 'a-fallback-iv-1234');

function gce_encrypt_password($password) {
    if (empty($password)) {
        return '';
    }
    return base64_encode(openssl_encrypt($password, 'aes-256-cbc', GCE_ENCRYPTION_KEY, 0, GCE_ENCRYPTION_IV));
}

function gce_decrypt_password() {
    $encrypted_password = get_option('gce_baserow_service_password_encrypted');
    if (empty($encrypted_password)) {
        return '';
    }
    return openssl_decrypt(base64_decode($encrypted_password), 'aes-256-cbc', GCE_ENCRYPTION_KEY, 0, GCE_ENCRYPTION_IV);
}

// --- NOUVELLES FONCTIONS (pour les données partagées avec N8N) ---

/**
 * Chiffre une donnée avec la clé partagée.
 * @param string $data La donnée à chiffrer.
 * @return string|false La donnée chiffrée et encodée en base64, ou false en cas d'erreur.
 */
/**
 * Chiffre une donnée avec la clé partagée.
 * @param string $data La donnée à chiffrer.
 * @return string|false La donnée chiffrée et encodée en base64, ou false en cas d'erreur.
 */
function gce_encrypt_shared_data($data) {
    if (empty($data) || !defined('GCE_SHARED_SECRET_KEY') || !defined('GCE_SHARED_IV')) {
        return false;
    }
    // LA CORRECTION EST ICI : On utilise OPENSSL_RAW_DATA
    return base64_encode(openssl_encrypt($data, 'aes-256-cbc', GCE_SHARED_SECRET_KEY, OPENSSL_RAW_DATA, GCE_SHARED_IV));
}
/**
 * Déchiffre une donnée avec la clé partagée.
 * @param string $encrypted_data La donnée chiffrée (encodée en base64).
 * @return string|false La donnée déchiffrée, ou false en cas d'erreur.
 */
function gce_decrypt_shared_data($encrypted_data) {
    if (empty($encrypted_data) || !defined('GCE_SHARED_SECRET_KEY') || !defined('GCE_SHARED_IV')) {
        return false;
    }
    return openssl_decrypt(base64_decode($encrypted_data), 'aes-256-cbc', GCE_SHARED_SECRET_KEY, 0, GCE_SHARED_IV);
}