<?php

namespace Enable\Cors;

/*
|--------------------------------------------------------------------------
| If this file is called directly, abort.
|--------------------------------------------------------------------------
*/
if ( ! defined( 'Enable\Cors\SLUG' ) ) {
	exit;
}


final class AdminPage {

	/**
	 * Initialize admin page
	 *
	 * @return void
	 */
	public function __construct() {
		add_action(
			'admin_menu',
			function (): void {
				add_menu_page(
					__( 'Enable CORS', 'enable-cors' ),
					__( 'Enable CORS', 'enable-cors' ),
					'manage_options',
					SLUG,
					function (): void {
						wp_enqueue_style( SLUG );
						wp_enqueue_script( SLUG );
						add_filter( 'admin_footer_text', array( $this, 'credit' ) );
						add_filter( 'update_footer', array( $this, 'version' ), 11 );
						echo wp_kses_post( '<div id="' . SLUG . '">Loading scripts. If you are still here, something went wrong.</div>' );
					},
					'dashicons-admin-generic'
				);
			}
		);
		add_filter( 'script_loader_tag', array( $this, 'add_module' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'scripts' ) );
	}

	/**
	 * Register scripts
	 */
	public function scripts(): void {
		if ( ! $this->register_script() ) {
			return;
		}

		wp_add_inline_style( SLUG, '#wpcontent .notice, #wpcontent #message{display: none} input[type=checkbox]:checked::before{content:unset}' );
		wp_localize_script(
			SLUG,
			'enableCors',
			$this->js_object()
		);
	}

	/**
	 * Registers the necessary styles and scripts for the admin page.
	 *
	 * @return bool True if scripts and styles are registered successfully, false otherwise.
	 */
	public function register_script(): bool {
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( $wp_filesystem->exists( plugin_dir_path( FILE ) . 'assets/dist/main.js' ) ) {
			wp_register_style( SLUG, plugins_url( 'assets/dist/main.css', FILE ), array(), VERSION );
			wp_register_script( SLUG, plugins_url( 'assets/dist/main.js', FILE ), array(), VERSION, true );
			return true;
		}

		wp_register_style( SLUG, 'http://localhost:3000/src/main.css', array(), VERSION );
		wp_register_script( SLUG, 'http://localhost:3000/src/main.js', array(), VERSION, true );
		return true;
	}

	/**
	 * Get object for js
	 *
	 * @return array of js
	 */
	private function js_object(): array {
		return array(
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'endpoint' => sprintf( '%senable-cors/v%s/settings', get_rest_url(), VERSION ),
			'strings'  => include plugin_dir_path( FILE ) . '/strings.php',
		);
	}

	/**
	 * Add type="module" to script tags
	 *
	 * @param string $tag    The <code>&lt;script&gt;</code> tag for the enqueued script.
	 * @param string $handle The script's registered handle.
	 * @return string with type="module"
	 */
	public function add_module( $tag, $handle ): string {
		if ( SLUG === $handle ) {
			return str_replace( '<script ', '<script type="module" ', $tag );
		}

		return $tag;
	}

	/**
	 * Version string for plugin footer
	 */
	public function version(): string {
		return sprintf( 'You are using <strong>%s</strong> version', VERSION );
	}

	/**
	 * Credit string for plugin footer
	 */
	public function credit(): string {
		return sprintf(
			"<strong>%s <span style='color: mediumvioletred'>❤</span> %s <a style='text-decoration: none' href='%s' target='_blank'><strong>%s</strong></a><strong/>",
			__( 'Created with', 'enable-cors' ),
			__( 'by', 'enable-cors' ),
			esc_url_raw( 'https://devkabir.github.io/?utm_source=wordpress&utm_medium=plugin&utm_campaign=enable-cors' ),
			__( 'Dev Kabir', 'enable-cors' )
		);
	}
}
