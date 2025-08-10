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

use Enable\Cors\Helpers\Option;
use const Enable\Cors\VERSION;

class Upgrade {

	/**
	 * Handles plugin upgrade routines based on version comparisons.
	 *
	 * @since 1.2.4
	 * @return void
	 */
	public static function run() {
		$option          = new Option();
		$current_version = $option->get_version();
		if ( ! version_compare( $current_version, self::get_version(), '<' ) ) {
			return;
		}
		// Run upgrade routines based on version comparison.
		self::upgrade( $current_version );
		// Update current version.
		$option->update_version();
	}


	/**
	 * Upgrade routines for latest version.
	 *
	 * @since 1.2.4
	 *
	 * @param string $current_version The current version of the plugin.
	 */
	private static function upgrade( string $current_version ): void {
		if ( version_compare( $current_version, '1.2.4', '<' ) ) {
			$option = new Option();
			$data   = $option->get();
			// Rename keys.
			$data['allow_font'] = $data['allowFont'] ?? false;
			unset( $data['allowFont'] );
			$data['allow_image'] = $data['allowImage'] ?? false;
			unset( $data['allowImage'] );
			$data['allow_credentials'] = $data['allowCredentials'] ?? false;
			unset( $data['allowCredentials'] );
			$data['allowed_for'] = $data['allowedFor'] ?? array();
			unset( $data['allowedFor'] );
			$data['allowed_methods'] = $data['allowedMethods'] ?? array();
			unset( $data['allowedMethods'] );
			$data['allowed_header'] = $data['allowedHeader'] ?? array();
			unset( $data['allowedHeader'] );
			$option->save( $data );
		}
	}

	/**
	 * Retrieve the current plugin version.
	 *
	 * @return string The current plugin version.
	 * @since 1.2.4
	 */
	private static function get_version(): string {
		// Define or retrieve the plugin version dynamically.
		return defined( 'Enable\Cors\VERSION' ) ? VERSION : '1.2.3'; // Fallback to last version.
	}
}
