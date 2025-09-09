<?php

defined('ABSPATH') || exit;

// ROUTE DE DÉBOGAGE TEMPORAIRE
register_rest_route('eecie-crm/v1', '/debug/server-vars', [
    'methods' => 'GET',
    'callback' => function () {
        return new WP_REST_Response($_SERVER, 200);
    },
    'permission_callback' => '__return_true' // Ouvert à tous pour le test
]);