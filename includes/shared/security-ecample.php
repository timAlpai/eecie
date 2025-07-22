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