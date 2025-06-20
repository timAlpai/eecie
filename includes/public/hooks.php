<?php
defined('ABSPATH') || exit;


// change design login :
function gce_login_custom_style() {
    wp_enqueue_style('gce-login-css', plugin_dir_url(__FILE__) . 'assets/css/login.css');
}
add_action('login_enqueue_scripts', 'gce_login_custom_style');



// Redirection après login pour les non-admins
add_filter('login_redirect', 'gce_redirect_user_after_login', 10, 3);
function gce_redirect_user_after_login($redirect_to, $request, $user) {
    if (!is_wp_error($user)) {
        if (!in_array('administrator', (array) $user->roles)) {
            return home_url('/mon-espace');
        }
    }
    return $redirect_to;
}

// Enregistrement du shortcode [gce_user_dashboard]
add_shortcode('gce_user_dashboard', 'gce_render_user_dashboard');

function gce_render_user_dashboard() {
    if (!is_user_logged_in()) {
        return '<p>Vous devez être connecté pour accéder à cet espace.</p>';
    }

    wp_enqueue_style('gce-dashboard-css', plugin_dir_url(__FILE__) . 'assets/css/dashboard.css', [], '1.0');
    wp_enqueue_script('gce-dashboard-js', plugin_dir_url(__FILE__) . 'assets/js/dashboard.js', [], '1.0', true);

    $current_user = wp_get_current_user();
    $current_page = isset($_GET['gce-page']) ? sanitize_text_field($_GET['gce-page']) : 'dashboard';

    ob_start();
    ?>
    <div class="gce-dashboard">

        <!-- Barre mobile -->
        <header class="gce-header">
            <span class="gce-header-title">Mon espace</span>
            <button class="gce-burger-button" onclick="gceToggleSidebar()">☰</button>
        </header>

        <!-- Sidebar -->
        <aside id="gce-sidebar" class="gce-sidebar">
            <div class="gce-sidebar-header">👤 <?php echo esc_html($current_user->display_name); ?></div>
            <ul class="gce-nav">
                <li><a href="?gce-page=dashboard">🏠 Dashboard</a></li>
                <li><a href="?gce-page=taches">📋 Mes Tâches</a></li>
                <li><a href="?gce-page=opportunites">💼 Opportunités</a></li>
            </ul>
        </aside>

        <!-- Contenu dynamique -->
        <main class="gce-main-content">
            <?php gce_render_dashboard_content($current_page); ?>
        </main>

    </div>
    <?php
    return ob_get_clean();
}


function gce_render_dashboard_content($page) {
    $base = plugin_dir_path(__FILE__) . 'pages/';
    switch ($page) {
        case 'dashboard':
            include $base . 'dashboard.php';
            break;
        case 'taches':
            include $base . 'taches.php';
            break;
        case 'opportunites':
            include $base . 'opportunites.php';
            break;
        default:
            echo '<h2>Page introuvable</h2>';
    }
}
