<?php

namespace Enable\Cors\Helpers;

/*
|--------------------------------------------------------------------------
| If this file is called directly, abort.
|--------------------------------------------------------------------------
*/

use const Enable\Cors\SLUG;

if ( ! defined( 'Enable\Cors\SLUG' ) ) {
	exit;
}


final class Htaccess {


	/**
	 * The filesystem object.
	 *
	 * @var mixed $filesystem
	 */
	private $filesystem;

	/**
	 * A constructor to initialize the filesystem if not already set.
	 */
	public function __construct() {
		if ( null === $this->filesystem ) {
			global $wp_filesystem;

			if ( empty( $wp_filesystem ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}

			$this->filesystem = $wp_filesystem;
		}
	}

	/**
	 * It modifies the .htaccess file to add headers for allowing fonts and css
	 */
	public function modify(): void {
		$option = new Option();

		if ( ! $option->is_enable() ) {
			$this->restore();
			return;
		}

		$origin = false;

		if ( $option->has_wildcard() ) {
			// Allow all origins.
			$origin      = 'Header set Access-Control-Allow-Origin "*"';
			$credentials = 'Header set Access-Control-Allow-Credentials ' . ( $option->should_allow_credentials() ? 'true' : 'false' );
		} else {
			$domains = $option->get_domains();
			if ( array() !== $domains ) {
				// Construct regex pattern for allowed domains.
				$pattern = '^https?://(.+\.)?(' . implode( '|', array_map( 'preg_quote', $domains ) ) . ')$';

				// Environment variable for matching the origin.
				$origin      = 'SetEnvIf Origin "' . $pattern . '" ORIGIN=$0' . PHP_EOL;
				$origin     .= 'Header set Access-Control-Allow-Origin %{ORIGIN}e env=ORIGIN' . PHP_EOL;
				$credentials = 'Header set Access-Control-Allow-Credentials ' . ( $option->should_allow_credentials() ? 'true' : 'false' ) . ' env=ORIGIN' . PHP_EOL;
				$origin     .= 'Header merge Vary Origin' . PHP_EOL;
			}
		}

		if ( false === $origin ) {
			return;
		}

		// Start building the .htaccess rules.
		$lines   = array( '<IfModule mod_headers.c>' );
		$lines[] = $origin;
		$lines[] = $credentials ?? '';

		// CORS Headers for preflight requests.
		if ( $option->has_methods() ) {
			$methods = 'Header set Access-Control-Allow-Methods "' . implode( ', ', $option->get_allowed_methods() ) . '"';
			$lines[] = $methods;
		}

		if ( $option->has_header() ) {
			$headers = 'Header set Access-Control-Allow-Headers "' . implode( ', ', $option->get_allowed_header() ) . '"';
			$lines[] = $headers;
		}

		// Allow CORS for Fonts.
		if ( $option->should_allow_font() ) {
			$lines[] = '<FilesMatch "\.(ttf|ttc|otf|eot|woff|woff2|font.css|css)$">';
			$lines[] = $origin;
			$lines[] = $credentials ?? '';
			$lines[] = $methods ?? '';
			$lines[] = $headers ?? '';
			$lines[] = '</FilesMatch>';
		}

		// Allow CORS for Images.
		if ( $option->should_allow_image() ) {
			$lines[] = '<FilesMatch "\.(avifs?|bmp|cur|gif|ico|jpe?g|jxl|a?png|svgz?|webp)$">';
			$lines[] = $origin;
			$lines[] = $credentials ?? '';
			$lines[] = $methods ?? '';
			$lines[] = $headers ?? '';
			$lines[] = '</FilesMatch>';
		}

		$lines[] = '</IfModule>';

		// Write the rules to .htaccess.
		$this->write( $lines );
	}

	/**
	 * Inserts an array of strings into a file (.htaccess), placing it between
	 * BEGIN and END markers.
	 *
	 * @param array $lines need to write.
	 */
	private function write( array $lines ): void {
		// Ensure get_home_path() is declared.
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! function_exists( 'insert_with_markers' ) || ! function_exists( 'got_mod_rewrite' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$htaccess_file = get_home_path() . '.htaccess';

		if ( got_mod_rewrite() ) {
			insert_with_markers( $htaccess_file, SLUG, $lines );
		}
	}

	/**
	 * It writes an empty array to the .htaccess file.
	 */
	public function restore(): void {
		$lines = array( '' );
		$this->write( $lines );
	}
}
