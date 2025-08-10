<?php

namespace Enable\Cors\Helpers;

/*
|--------------------------------------------------------------------------
| If this file is called directly, abort.
|--------------------------------------------------------------------------
*/
if ( ! defined( 'Enable\Cors\SLUG' ) ) {
	exit;
}

/**
 * Class Headers
 *
 * @package Enable\Cors
 */
final class Headers {

	/**
	 * Adds headers for Cross-Origin Resource Sharing (CORS) based on the options set.
	 *
	 * @param Option $option from DB.
	 */
	public static function add( Option $option ): void {
		// Always set Vary: Origin for proper caching behavior.
		header( 'Vary: Origin' );

		// Check if the current origin is allowed.
		if ( $option->is_current_origin_allowed() ) {
			$origin = function_exists( 'get_http_origin' ) ? get_http_origin() : '*';
			header( 'Access-Control-Allow-Origin: ' . $origin );
		} elseif ( $option->has_wildcard() ) {
			header( 'Access-Control-Allow-Origin: *' );
		} else {
			return; // No CORS allowed, exit early.
		}

		// Set allowed methods.
		if ( $option->has_methods() ) {
			$allowed_methods = implode( ', ', $option->get_allowed_methods() );
			header( 'Access-Control-Allow-Methods: ' . $allowed_methods );
		} else {
			header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE, PATCH' );
		}

		// Set allowed headers.
		if ( $option->has_header() ) {
			$allowed_headers = implode( ', ', $option->get_allowed_header() );
			header( 'Access-Control-Allow-Headers: ' . $allowed_headers );
		} else {
			header( 'Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization' ); // Default.
		}

		// Set credentials policy.
		header( 'Access-Control-Allow-Credentials: ' . ( $option->should_allow_credentials() ? 'true' : 'false' ) );

		// Handle OPTIONS (preflight) requests properly.
		if ( 'OPTIONS' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			header( 'HTTP/1.1 204 No Content' ); // Indicate no body response.
			exit(); // Stop further execution.
		}
	}
}
