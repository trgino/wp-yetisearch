<?php
declare(strict_types=1);

namespace WpYetiSearch\Frontend;

use WpYetiSearch\Core\Config;
use WpYetiSearch\Index\LanguageResolver;

/** Front-end typeahead assets (spec §6.8). */
final class Assets {

	public function __construct( private Config $config, private string $baseUrl, private string $version, private ?LanguageResolver $languages = null ) {
	}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue(): void {
		if ( ! $this->config->bool( 'master_enabled' ) || ! $this->config->bool( 'typeahead_enabled' ) ) {
			return;
		}
		wp_enqueue_style( 'wp-yetisearch', $this->baseUrl . 'assets/css/search.css', array(), $this->version );
		wp_enqueue_script(
			'wp-yetisearch-typeahead',
			$this->baseUrl . 'assets/js/typeahead.js',
			array(),
			$this->version,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		wp_add_inline_script( 'wp-yetisearch-typeahead', 'window.wpYetiSearch = ' . wp_json_encode( $this->settings() ) . ';', 'before' );
	}

	/** @return array<string, mixed> */
	public function settings(): array {
		return array(
			'endpoint'     => esc_url_raw( rest_url( RestController::NAMESPACE . '/search' ) ),
			'lang'         => $this->languages?->currentLanguage() ?? '',
			'selector'     => $this->config->string( 'typeahead_selector' ),
			'debounce'     => $this->config->int( 'typeahead_debounce_ms' ),
			'minChars'     => $this->config->int( 'typeahead_min_chars' ),
			'maxResults'   => $this->config->int( 'typeahead_max_results' ),
			'highlightTag' => $this->config->highlightTag(),
			'i18n'         => array(
				'label'        => __( 'Search suggestions', 'wp-yetisearch' ),
				'noResults'    => __( 'No results found', 'wp-yetisearch' ),
				/* translators: %d: number of suggestions */
				'resultsCount' => __( '%d suggestions available', 'wp-yetisearch' ),
			),
		);
	}
}
