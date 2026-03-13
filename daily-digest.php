<?php
/**
 * Plugin Name: Daily Digest
 * Description: Aggregates user activity from multiple external providers into a single digest.
 * Version: 0.1.0
 * Author: George Stephanis
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: keyring
 * Text Domain: daily-digest
 *
 * @package DailyDigest
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DAILY_DIGEST_PLUGIN_VERSION', '0.1.0' );
define( 'DAILY_DIGEST_PLUGIN_FILE', __FILE__ );
define( 'DAILY_DIGEST_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'DAILY_DIGEST_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$autoload_file = DAILY_DIGEST_PLUGIN_PATH . 'vendor/autoload.php';
if ( file_exists( $autoload_file ) ) {
	require_once $autoload_file;
}

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'DailyDigest\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $prefix ) );
		$class_parts    = explode( '\\', $relative_class );
		$class_leaf     = array_pop( $class_parts );
		$directory      = '';

		if ( ! empty( $class_parts ) ) {
			$directory = strtolower( implode( '/', $class_parts ) ) . '/';
		}

		$is_interface = 'Interface' === substr( $class_leaf, -9 );
		$filename     = $is_interface
			? strtolower( $class_leaf ) . '.php'
			: 'class-' . strtolower( $class_leaf ) . '.php';
		$file_path    = DAILY_DIGEST_PLUGIN_PATH . 'includes/' . $directory . $filename;

		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		DailyDigest\Plugin::instance()->boot();
	},
	20
);
