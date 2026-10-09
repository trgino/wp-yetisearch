<?php
declare(strict_types=1);

namespace WpYetiSearch\Core;

/** Textdomain loading (spec §6.9). */
final class I18n {

	public static function load(): void {
		load_plugin_textdomain(
			'wp-yetisearch',
			false,
			dirname( plugin_basename( WPYETISEARCH_FILE ) ) . '/languages'
		);
	}
}
