<?php
defined('ABSPATH') || exit;

// Style de login personnalisé
function gce_login_custom_style()
{
    wp_enqueue_style('gce-login-css', plugin_dir_url(__FILE__) . 'assets/css/login.css');
}
add_action('login_enqueue_scripts', 'gce_login_custom_style');

// Redirection après login pour les non-admins
add_filter('login_redirect', 'gce_redirect_user_after_login', 10, 3);
function gce_redirect_user_after_login($redirect_to, $request, $user)
{
    if (!is_wp_error($user)) {
        if (!in_array('administrator', (array) $user->roles)) {
            return home_url('/mon-espace');
        }
    }
    return $redirect_to;
}

// Enqueue conditionnel des scripts pour la partie publique
add_action('wp_enqueue_scripts', 'gce_enqueue_front_scripts');
function gce_enqueue_front_scripts()
{
    if (!is_user_logged_in()) return;

    // Condition 1: La page est une page interne du dashboard via query param
    $is_gce_page = isset($_GET['gce-page']) && !empty($_GET['gce-page']);

    // Condition 2: La page contient le shortcode du dashboard
    global $post;
    $has_dashboard_shortcode = is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'gce_user_dashboard');

    // Si aucune des conditions n'est remplie, on ne charge rien
    if (!$is_gce_page && !$has_dashboard_shortcode) {
        return;
    }

    // === MODIFICATION : Déplacement des scripts du dashboard ici ===
    wp_enqueue_style('gce-dashboard-css', plugin_dir_url(__FILE__) . 'assets/css/dashboard.css', [], GCE_VERSION);
    wp_enqueue_script('gce-popup-handler', plugin_dir_url(__FILE__) . '../shared/js/popup-handler.js', ['eecie-crm-rest'], GCE_VERSION, true);
    wp_enqueue_style('gce-popup-css', plugin_dir_url(__FILE__) . '../shared/css/popup.css', [], GCE_VERSION);
    wp_enqueue_script('gce-dashboard-js', plugin_dir_url(__FILE__) . 'assets/js/dashboard.js', ['gce-popup-handler'], '1.0', true);
    wp_enqueue_editor();
    
    // === FIN DE LA MODIFICATION ===

    // Le code existant pour les pages spécifiques (opportunites, taches) reste valide
    $page_slug = isset($_GET['gce-page']) ? sanitize_text_field($_GET['gce-page']) : '';
    if (in_array($page_slug, ['opportunites', 'taches'])) {
        // Tabulator + dépendances
        wp_enqueue_style('tabulator-css', 'https://unpkg.com/tabulator-tables@6.3/dist/css/tabulator.min.css', [], '6.3');
        wp_enqueue_script('tabulator-js', 'https://unpkg.com/tabulator-tables@6.3/dist/js/tabulator.min.js', [], '6.3', true);
        wp_enqueue_script('luxon-js', 'https://cdn.jsdelivr.net/npm/luxon@3.4.3/build/global/luxon.min.js', [], '3.4.3', true);

        wp_enqueue_script('gce-tabulator-editors', plugin_dir_url(__FILE__) . '../shared/js/tabulator-editors.js', ['tabulator-js'], GCE_VERSION, true);
        wp_enqueue_script('gce-tabulator-columns', plugin_dir_url(__FILE__) . '../shared/js/tabulator-columns.js', ['tabulator-js', 'gce-tabulator-editors'], GCE_VERSION, true);

        // Script opportunités
        wp_enqueue_script(
            'gce-opportunites-js',
            plugin_dir_url(__FILE__) . 'assets/js/opportunites.js',
            ['eecie-crm-rest', 'tabulator-js', 'gce-tabulator-editors', 'gce-tabulator-columns'],
            GCE_VERSION,
            true
        );

        $current_user = wp_get_current_user();
        wp_localize_script('gce-opportunites-js', 'GCE_CURRENT_USER', [
            'email' => $current_user->user_email,
        ]);
    }
}


// Shortcode (si jamais tu l’utilises ailleurs)
add_shortcode('gce_user_dashboard', 'gce_render_user_dashboard');
function gce_render_user_dashboard()
{
    if (!is_user_logged_in()) {
        return '<p>Vous devez être connecté pour accéder à cet espace.</p>';
    }

    // SUPPRESSION : Ces lignes sont maintenant dans gce_enqueue_front_scripts
    // wp_enqueue_style('gce-dashboard-css', ...);
    // wp_enqueue_script('gce-popup-handler', ...);
    // wp_enqueue_style('gce-popup-css', ...);
    // wp_enqueue_script('gce-dashboard-js', ...);
    
    $current_page = isset($_GET['gce-page']) ? sanitize_text_field($_GET['gce-page']) : 'dashboard';

    ob_start();
?>
    <div class="gce-dashboard">
        <header class="gce-header">
            <span class="gce-header-title">Mon espace</span>
            <button class="gce-burger-button" onclick="gceToggleSidebar()">☰</button>
        </header>
        <aside id="gce-sidebar" class="gce-sidebar">
            <div class="gce-sidebar-header">👤 <?php echo esc_html(wp_get_current_user()->display_name); ?></div>
            <ul class="gce-nav">
                <li><a href="?gce-page=dashboard">🏠 Dashboard</a></li>
                <li><a href="?gce-page=taches">📋 Mes Tâches</a></li>
                <li><a href="?gce-page=opportunites">💼 Opportunités</a></li>
                <li><a href="?gce-page=appels">📞 Flux de travail</a></li>
                <li><a href="?gce-page=devis">📄 Devis</a></li>
                <li><a href="?gce-page=contacts">📇 Mes contacts</a></li>
            </ul>
        </aside>
        <main class="gce-main-content">
            <?php gce_render_dashboard_content($current_page); ?>
        </main>
    </div>
<?php
    return ob_get_clean();
}

// Rend les pages internes du dashboard public
function gce_render_dashboard_content($page)
{
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
        case 'appels':
            include $base . 'appels.php';
            break;
        case 'devis':
            include $base . 'devis.php';
            break;
        case 'contacts':
            include $base . 'contacts.php';
            break;

        default:
            echo '<h2>Page introuvable</h2>';
    }
}