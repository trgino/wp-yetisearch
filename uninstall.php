<?php

/**
 * Uninstall: delete all options, cron events, and storage files (spec §6.1).
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Release zips ship only vendor-prefixed/; guard both requires so uninstall
// never fatals on a missing autoloader.
if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}
if ( ! class_exists( \WpYetiSearch\Core\Config::class ) && is_readable( __DIR__ . '/vendor-prefixed/autoload.php' ) ) {
	require_once __DIR__ . '/vendor-prefixed/autoload.php';
}

use WpYetiSearch\Core\Config;
use WpYetiSearch\Core\HealthChecker;
use WpYetiSearch\Features\SemanticBridge;
use WpYetiSearch\Storage\StorageManager;

// 0. Read settings BEFORE deleting anything (custom dir lives inside the option).
$settings  = get_option( Config::OPTION_KEY, array() );
$customDir = is_array( $settings ) && isset( $settings['db_custom_dir'] ) && is_string( $settings['db_custom_dir'] )
	? $settings['db_custom_dir']
	: '';

// 1. Delete all plugin options.
foreach ( array( Config::OPTION_KEY, StorageManager::HASH_OPTION, HealthChecker::OPTION_KEY, Config::NEEDS_REINDEX_OPTION ) as $option ) {
	delete_option( $option );
}

// 2. Clear cron events (both; the health cron would otherwise fire with no callback).
wp_clear_scheduled_hook( SemanticBridge::CRON_HOOK );
wp_clear_scheduled_hook( HealthChecker::CRON_HOOK );

// 3. Delete storage directory.
if ( $customDir !== '' ) {
	// Custom directory: remove only our files, leave the directory and other files.
	$dir = rtrim( wp_normalize_path( $customDir ), '/' );
	if ( is_dir( $dir ) ) {
		$matches = glob( $dir . '/yetisearch_*.sqlite*' );
		if ( ! is_array( $matches ) ) {
			$matches = array();
		}
		foreach ( $matches as $file ) {
			wp_delete_file( $file );
		}
		foreach ( array( '.htaccess', 'web.config', 'index.php' ) as $name ) {
			$filePath = $dir . '/' . $name;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local marker check, not a URL fetch.
			if ( is_file( $filePath ) && str_contains( (string) file_get_contents( $filePath ), 'WP YetiSearch' ) ) {
				wp_delete_file( $filePath );
			}
		}
	}
} else {
	// Default directory: delete completely.
	$uploads = wp_upload_dir( null, false );
	$dir     = rtrim( wp_normalize_path( (string) $uploads['basedir'] ), '/' ) . '/' . StorageManager::DEFAULT_SUBDIR;
	if ( is_dir( $dir ) ) {
		$entries = scandir( $dir );
		if ( ! is_array( $entries ) ) {
			$entries = array();
		}
		foreach ( $entries as $name ) {
			if ( $name === '.' || $name === '..' ) {
				continue;
			}
			$filePath = $dir . '/' . $name;
			if ( is_dir( $filePath ) ) {
				wpyetisearch_remove_dir( $filePath );
			} else {
				wp_delete_file( $filePath );
			}
		}
		rmdir( $dir );
	}
}

/**
 * @param string $dir
 */
if ( ! function_exists( 'wpyetisearch_remove_dir' ) ) {
	function wpyetisearch_remove_dir( string $dir ): void {
		$entries = scandir( $dir );
		if ( ! is_array( $entries ) ) {
			return;
		}
		foreach ( $entries as $name ) {
			if ( $name === '.' || $name === '..' ) {
				continue;
			}
			$filePath = $dir . '/' . $name;
			if ( is_dir( $filePath ) ) {
				wpyetisearch_remove_dir( $filePath );
			} else {
				wp_delete_file( $filePath );
			}
		}
		rmdir( $dir );
	}
}
