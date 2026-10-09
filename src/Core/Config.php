<?php
declare(strict_types=1);

namespace WpYetiSearch\Core;

/** Read-only, typed view of the stored settings. Saving happens in Admin\SettingsPage. */
final class Config {

	public const OPTION_KEY           = 'yetisearch_settings';
	public const NEEDS_REINDEX_OPTION = 'yetisearch_needs_reindex';
	public const INDEX                = 'wp_posts';

	/** @var array<string, mixed> */
	private array $settings;

	/** @param array<string, mixed> $stored */
	public function __construct( array $stored = array() ) {
		$defaults       = SettingsSchema::defaults();
		$this->settings = array_replace( $defaults, array_intersect_key( $stored, $defaults ) );
	}

	public static function load(): self {
		$stored = get_option( self::OPTION_KEY, array() );
		return new self( is_array( $stored ) ? $stored : array() );
	}

	public function get( string $key ): mixed {
		if ( ! array_key_exists( $key, $this->settings ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, not output.
			throw new \InvalidArgumentException( sprintf( "Unknown setting '%s'.", $key ) );
		}
		return $this->settings[ $key ];
	}

	public function bool( string $key ): bool {
		return (bool) $this->get( $key );
	}

	public function int( string $key ): int {
		$value = $this->get( $key );
		return is_numeric( $value ) ? (int) $value : 0;
	}

	public function float( string $key ): float {
		$value = $this->get( $key );
		return is_numeric( $value ) ? (float) $value : 0.0;
	}

	public function string( string $key ): string {
		$value = $this->get( $key );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/** @return list<string> */
	public function strings( string $key ): array {
		$value = $this->get( $key );
		return is_array( $value ) ? array_values( array_map( 'strval', $value ) ) : array();
	}

	/** @return array<string, mixed> */
	public function all(): array {
		return $this->settings;
	}

	public function semanticApiKey(): string {
		if ( defined( 'YETISEARCH_SEMANTIC_API_KEY' ) ) {
			$constant = constant( 'YETISEARCH_SEMANTIC_API_KEY' );
			return is_string( $constant ) ? $constant : '';
		}
		return $this->string( 'semantic_api_key' );
	}

	/** Null means "let the library auto-detect" (spec §11). */
	public function stemmerLanguage(): ?string {
		$language = $this->string( 'stemmer_language' );
		return $language === 'auto' ? null : $language;
	}

	public function highlightTag(): string {
		return $this->string( 'highlight_tag' );
	}

	/** @return array<string, float> */
	public function fieldWeights(): array {
		$weights = array();
		foreach ( SettingsSchema::SEARCH_FIELDS as $field ) {
			$weights[ $field ] = $this->float( 'weight_' . $field );
		}
		return $weights;
	}

	/** @return array<string, list<string>> */
	public function synonyms(): array {
		$map = array();
		foreach ( (array) $this->get( 'synonyms' ) as $term => $list ) {
			$map[ (string) $term ] = array_values( array_map( 'strval', (array) $list ) );
		}
		return $map;
	}

	/** Library configuration (spec §3.1). */
	public function toYetiConfig( string $dbPath ): array {
		$weights = $this->fieldWeights();
		$tag     = $this->highlightTag();

		$fields = array();
		foreach ( SettingsSchema::SEARCH_FIELDS as $field ) {
			$fields[ $field ] = array(
				'boost' => $weights[ $field ],
				'store' => true,
				'index' => true,
			);
		}
		$fields['url']   = array(
			'boost' => 1.0,
			'store' => true,
			'index' => false,
		);
		$fields['route'] = array(
			'boost' => 1.0,
			'store' => true,
			'index' => false,
		);

		$fts = array( 'multi_column' => true );
		if ( $this->bool( 'prefix_last_token' ) ) {
			$fts['prefix'] = array( 2, 3 );
		}

		return array(
			'storage'  => array(
				'path'             => $dbPath,
				'external_content' => true,
				'synchronous'      => $this->string( 'synchronous' ),
				'search'           => array(
					'enable_fuzzy'    => $this->bool( 'enable_fuzzy' ),
					'fuzzy_algorithm' => $this->string( 'fuzzy_algorithm' ),
				),
			),
			'analyzer' => array(
				'min_word_length'     => $this->int( 'min_word_length' ),
				'max_word_length'     => 50,
				'remove_numbers'      => false,
				'lowercase'           => true,
				'strip_html'          => true,
				'strip_punctuation'   => $this->bool( 'strip_punctuation' ),
				'expand_contractions' => $this->bool( 'expand_contractions' ),
				'custom_stop_words'   => $this->strings( 'custom_stop_words' ),
				'disable_stop_words'  => $this->bool( 'disable_stop_words' ),
			),
			'indexer'  => array(
				'batch_size'    => 100,
				'auto_flush'    => true,
				'chunk_size'    => $this->int( 'chunk_size' ),
				'chunk_overlap' => $this->int( 'chunk_overlap' ),
				'fields'        => $fields,
				'fts'           => $fts,
			),
			'search'   => array(
				'min_score'              => $this->float( 'min_score' ),
				'highlight_tag'          => '<' . $tag . '>',
				'highlight_tag_close'    => '</' . $tag . '>',
				'snippet_length'         => $this->int( 'snippet_length' ),
				'max_results'            => $this->int( 'max_results' ),
				'result_fields'          => array( 'title', 'content', 'excerpt', 'url', 'route' ),
				'field_weights'          => $weights,
				'multi_column_fts'       => true,
				'two_pass_search'        => $this->bool( 'two_pass_search' ),
				'primary_fields'         => $this->strings( 'primary_fields' ),
				'primary_field_limit'    => $this->int( 'primary_field_limit' ),
				'exact_match_boost'      => $this->float( 'exact_match_boost' ),
				'exact_terms_boost'      => $this->float( 'exact_terms_boost' ),
				'enable_fuzzy'           => $this->bool( 'enable_fuzzy' ),
				'fuzzy_algorithm'        => $this->string( 'fuzzy_algorithm' ),
				'fuzzy_correction_mode'  => $this->bool( 'fuzzy_correction_mode' ),
				'correction_threshold'   => $this->float( 'correction_threshold' ),
				'levenshtein_threshold'  => $this->int( 'levenshtein_threshold' ),
				'trigram_threshold'      => $this->float( 'trigram_threshold' ),
				'jaro_winkler_threshold' => $this->float( 'jaro_winkler_threshold' ),
				'min_term_frequency'     => $this->int( 'min_term_frequency' ),
				'fuzzy_score_penalty'    => $this->float( 'fuzzy_score_penalty' ),
				'fuzzy_last_token_only'  => $this->bool( 'fuzzy_last_token_only' ),
				'prefix_last_token'      => $this->bool( 'prefix_last_token' ),
				'enable_suggestions'     => $this->bool( 'enable_suggestions' ),
				'enable_synonyms'        => $this->bool( 'enable_synonyms' ),
				'synonyms'               => $this->synonyms(),
				'distance_weight'        => $this->float( 'distance_weight' ),
				'geo_units'              => $this->string( 'geo_unit' ),
				'cache_ttl'              => $this->int( 'cache_ttl' ),
			),
			'cache'    => array(
				'enabled'  => $this->bool( 'cache_enabled' ),
				'ttl'      => $this->int( 'cache_ttl' ),
				'max_size' => $this->int( 'cache_max_size' ),
			),
		);
	}
}
