<?php

/**
 * Plugin Name:       WP YetiSearch
 * Plugin URI:        https://github.com/trgino/wp-yetisearch
 * Description:       Fast, typo-tolerant and semantic search for WordPress powered by SQLite FTS5 (YetiSearch).
 * Version:           1.1.1
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Author:            trgino
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-yetisearch
 * Domain Path:       /languages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( PHP_VERSION_ID < 80200 ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>'
			. esc_html__( 'WP YetiSearch requires PHP 8.2 or newer.', 'wp-yetisearch' )
			. '</p></div>';
		}
	);
	return;
}

if ( ! defined( 'WPYETISEARCH_VERSION' ) ) {
	define( 'WPYETISEARCH_VERSION', '1.1.1' );
	define( 'WPYETISEARCH_FILE', __FILE__ );
	define( 'WPYETISEARCH_PATH', plugin_dir_path( __FILE__ ) );
	define( 'WPYETISEARCH_URL', plugin_dir_url( __FILE__ ) );
}

// Release builds ship Strauss-prefixed dependencies in vendor-prefixed/.
foreach ( array( 'vendor-prefixed/autoload.php', 'vendor/autoload.php' ) as $wpyetisearch_autoload ) {
	if ( is_readable( WPYETISEARCH_PATH . $wpyetisearch_autoload ) ) {
		require_once WPYETISEARCH_PATH . $wpyetisearch_autoload;
	}
}
unset( $wpyetisearch_autoload );

if ( ! class_exists( \WpYetiSearch\Core\Plugin::class ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>'
			. esc_html__( 'WP YetiSearch is missing its dependencies. Please reinstall the plugin.', 'wp-yetisearch' )
			. '</p></div>';
		}
	);
	return;
}

require_once WPYETISEARCH_PATH . 'src/Search/template-tags.php';
require_once WPYETISEARCH_PATH . 'src/Core/I18n.php';
require_once WPYETISEARCH_PATH . 'src/Core/Lifecycle.php';

register_activation_hook(
	__FILE__,
	static function (): void {
		\WpYetiSearch\Core\Plugin::instance()->activate();
	}
);
register_deactivation_hook(
	__FILE__,
	static function (): void {
		\WpYetiSearch\Core\Plugin::instance()->deactivate();
	}
);
add_action(
	'plugins_loaded',
	static function (): void {
		\WpYetiSearch\Core\Plugin::instance()->boot();
	}
);
