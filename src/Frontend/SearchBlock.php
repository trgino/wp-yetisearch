<?php
declare(strict_types=1);

namespace WpYetiSearch\Frontend;

/** Dynamic search-box block (block.json + render.php, no build step). */
final class SearchBlock {

	public const NAME = 'yetisearch/search-box';

	public static function register(): void {
		add_action( 'init', array( self::class, 'registerEditorAssets' ) );
	}

	public static function registerEditorAssets(): void {
		wp_register_script(
			'yetisearch-search-box-editor',
			plugins_url( 'blocks/search-box/edit.js', WPYETISEARCH_FILE ),
			array( 'wp-blocks', 'wp-element' ),
			WPYETISEARCH_VERSION,
			true
		);
		register_block_type( WPYETISEARCH_PATH . 'blocks/search-box' );
	}
}
