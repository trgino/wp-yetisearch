<?php
declare(strict_types=1);

namespace WpYetiSearch\Core;

/**
 * Single source of truth for every user setting (spec §5).
 * Labels live in Admin\FieldLabels so this class never calls translation functions.
 */
final class SettingsSchema {

	public const TABS = array( 'general', 'content', 'relevance', 'language', 'display', 'facets_geo', 'semantic', 'performance', 'maintenance' );

	public const SEARCH_FIELDS = array( 'title', 'content', 'excerpt', 'taxonomies', 'meta' );

	/** @return array<string, array<string, mixed>> */
	public static function fields(): array {
		return array(
			// General
			'master_enabled'                  => self::field( 'general', 'bool', false ),
			'db_custom_dir'                   => self::field( 'general', 'path', '', array( 'reindex' => true ) ),

			// Content
			'indexed_post_types'              => self::field(
				'content',
				'multiselect',
				array( 'post', 'page' ),
				array(
					'source'  => 'post_types',
					'reindex' => true,
				)
			),
			'indexed_taxonomies'              => self::field(
				'content',
				'multiselect',
				array( 'category', 'post_tag' ),
				array(
					'source'  => 'taxonomies',
					'reindex' => true,
				)
			),
			'indexed_meta_keys'               => self::field( 'content', 'list', array(), array( 'reindex' => true ) ),
			'chunk_size'                      => self::field(
				'content',
				'int',
				1000,
				array(
					'min'     => 200,
					'max'     => 10000,
					'reindex' => true,
				)
			),
			'chunk_overlap'                   => self::field(
				'content',
				'int',
				100,
				array(
					'min'     => 0,
					'max'     => 5000,
					'reindex' => true,
				)
			),

			// Relevance & typo tolerance
			'weight_title'                    => self::field(
				'relevance',
				'float',
				3.0,
				array(
					'min' => 0.0,
					'max' => 10.0,
				)
			),
			'weight_content'                  => self::field(
				'relevance',
				'float',
				1.0,
				array(
					'min' => 0.0,
					'max' => 10.0,
				)
			),
			'weight_excerpt'                  => self::field(
				'relevance',
				'float',
				2.0,
				array(
					'min' => 0.0,
					'max' => 10.0,
				)
			),
			'weight_taxonomies'               => self::field(
				'relevance',
				'float',
				2.5,
				array(
					'min' => 0.0,
					'max' => 10.0,
				)
			),
			'weight_meta'                     => self::field(
				'relevance',
				'float',
				1.5,
				array(
					'min' => 0.0,
					'max' => 10.0,
				)
			),
			'min_score'                       => self::field(
				'relevance',
				'float',
				0.0,
				array(
					'min' => 0.0,
					'max' => 100.0,
				)
			),
			'two_pass_search'                 => self::field( 'relevance', 'bool', false ),
			'primary_fields'                  => self::field( 'relevance', 'multiselect', array( 'title' ), array( 'source' => 'search_fields' ) ),
			'primary_field_limit'             => self::field(
				'relevance',
				'int',
				100,
				array(
					'min' => 10,
					'max' => 1000,
				)
			),
			'exact_match_boost'               => self::field(
				'relevance',
				'float',
				2.0,
				array(
					'min' => 1.0,
					'max' => 10.0,
				)
			),
			'exact_terms_boost'               => self::field(
				'relevance',
				'float',
				1.5,
				array(
					'min' => 1.0,
					'max' => 10.0,
				)
			),
			'enable_fuzzy'                    => self::field( 'relevance', 'bool', true ),
			'fuzzy_algorithm'                 => self::field( 'relevance', 'select', 'trigram', array( 'options' => array( 'trigram', 'jaro_winkler', 'levenshtein' ) ) ),
			'fuzzy_correction_mode'           => self::field( 'relevance', 'bool', true ),
			'correction_threshold'            => self::field(
				'relevance',
				'float',
				0.6,
				array(
					'min' => 0.0,
					'max' => 1.0,
				)
			),
			'levenshtein_threshold'           => self::field(
				'relevance',
				'int',
				2,
				array(
					'min' => 1,
					'max' => 3,
				)
			),
			'trigram_threshold'               => self::field(
				'relevance',
				'float',
				0.35,
				array(
					'min' => 0.0,
					'max' => 1.0,
				)
			),
			'jaro_winkler_threshold'          => self::field(
				'relevance',
				'float',
				0.85,
				array(
					'min' => 0.0,
					'max' => 1.0,
				)
			),
			'min_term_frequency'              => self::field(
				'relevance',
				'int',
				1,
				array(
					'min' => 1,
					'max' => 100,
				)
			),
			'fuzzy_score_penalty'             => self::field(
				'relevance',
				'float',
				0.25,
				array(
					'min' => 0.0,
					'max' => 1.0,
				)
			),
			'fuzzy_last_token_only'           => self::field( 'relevance', 'bool', false ),
			'prefix_last_token'               => self::field( 'relevance', 'bool', true, array( 'reindex' => true ) ),
			'enable_suggestions'              => self::field( 'relevance', 'bool', true ),

			// Language analysis
			'stemmer_language'                => self::field(
				'language',
				'select',
				'auto',
				array(
					'options' => array( 'auto', 'english', 'french', 'german', 'spanish' ),
					'reindex' => true,
				)
			),
			'min_word_length'                 => self::field(
				'language',
				'int',
				2,
				array(
					'min'     => 1,
					'max'     => 10,
					'reindex' => true,
				)
			),
			'strip_punctuation'               => self::field( 'language', 'bool', true, array( 'reindex' => true ) ),
			'expand_contractions'             => self::field( 'language', 'bool', true, array( 'reindex' => true ) ),
			'custom_stop_words'               => self::field( 'language', 'list', array(), array( 'reindex' => true ) ),
			'disable_stop_words'              => self::field( 'language', 'bool', false, array( 'reindex' => true ) ),
			'enable_synonyms'                 => self::field( 'language', 'bool', false ),
			'synonyms'                        => self::field( 'language', 'map', array() ),

			// Display
			'highlight_enabled'               => self::field( 'display', 'bool', true ),
			'highlight_tag'                   => self::field( 'display', 'select', 'mark', array( 'options' => array( 'mark', 'strong', 'em' ) ) ),
			'snippet_length'                  => self::field(
				'display',
				'int',
				150,
				array(
					'min' => 50,
					'max' => 500,
				)
			),
			'typeahead_enabled'               => self::field( 'display', 'bool', true ),
			'typeahead_selector'              => self::field( 'display', 'string', 'input[name="s"]' ),
			'typeahead_debounce_ms'           => self::field(
				'display',
				'int',
				250,
				array(
					'min' => 100,
					'max' => 2000,
				)
			),
			'typeahead_min_chars'             => self::field(
				'display',
				'int',
				2,
				array(
					'min' => 2,
					'max' => 10,
				)
			),
			'typeahead_max_results'           => self::field(
				'display',
				'int',
				8,
				array(
					'min' => 1,
					'max' => 20,
				)
			),

			// Facets & geo
			'facets_enabled'                  => self::field( 'facets_geo', 'bool', false ),
			'facet_post_type'                 => self::field( 'facets_geo', 'bool', true ),
			'facet_taxonomies'                => self::field(
				'facets_geo',
				'multiselect',
				array( 'category' ),
				array(
					'source'  => 'taxonomies',
					'reindex' => true,
				)
			),
			'facet_limit'                     => self::field(
				'facets_geo',
				'int',
				10,
				array(
					'min' => 1,
					'max' => 50,
				)
			),
			'geo_enabled'                     => self::field( 'facets_geo', 'bool', false, array( 'reindex' => true ) ),
			'geo_lat_meta_key'                => self::field( 'facets_geo', 'string', 'latitude', array( 'reindex' => true ) ),
			'geo_lng_meta_key'                => self::field( 'facets_geo', 'string', 'longitude', array( 'reindex' => true ) ),
			'geo_default_radius'              => self::field(
				'facets_geo',
				'float',
				5.0,
				array(
					'min' => 0.1,
					'max' => 20000.0,
				)
			),
			'geo_unit'                        => self::field( 'facets_geo', 'select', 'km', array( 'options' => array( 'km', 'mi' ) ) ),
			'geo_distance_ranges'             => self::field( 'facets_geo', 'number_list', array( 1.0, 5.0, 10.0, 50.0 ) ),
			'distance_weight'                 => self::field(
				'facets_geo',
				'float',
				0.0,
				array(
					'min' => 0.0,
					'max' => 1.0,
				)
			),
			'price_facet_enabled'             => self::field( 'facets_geo', 'bool', false ),
			'price_meta_key'                  => self::field( 'facets_geo', 'string', '_price', array( 'reindex' => true ) ),
			'price_ranges'                    => self::field( 'facets_geo', 'number_list', array( 100.0, 500.0, 1000.0 ) ),

			// Semantic
			'semantic_enabled'                => self::field( 'semantic', 'bool', false ),
			'semantic_endpoint'               => self::field( 'semantic', 'url', 'https://api.openai.com/v1' ),
			'semantic_api_key'                => self::field( 'semantic', 'password', '' ),
			'semantic_model'                  => self::field( 'semantic', 'string', 'text-embedding-3-small' ),
			'semantic_dimensions'             => self::field(
				'semantic',
				'int',
				0,
				array(
					'min' => 0,
					'max' => 8192,
				)
			),
			'semantic_query_prefix'           => self::field( 'semantic', 'prefix', '' ),
			'semantic_document_prefix'        => self::field( 'semantic', 'prefix', '' ),
			'semantic_weight'                 => self::field(
				'semantic',
				'float',
				0.5,
				array(
					'min' => 0.0,
					'max' => 1.0,
				)
			),
			'semantic_min_similarity'         => self::field(
				'semantic',
				'float',
				0.25,
				array(
					'min' => 0.0,
					'max' => 1.0,
				)
			),
			'semantic_min_margin'             => self::field(
				'semantic',
				'float',
				0.15,
				array(
					'min' => 0.0,
					'max' => 1.0,
				)
			),
			'semantic_calibration'            => self::field( 'semantic', 'select', 'auto', array( 'options' => array( 'auto', 'off' ) ) ),
			'semantic_calibration_strictness' => self::field(
				'semantic',
				'float',
				2.0,
				array(
					'min' => 0.5,
					'max' => 5.0,
				)
			),
			'semantic_batch_size'             => self::field(
				'semantic',
				'int',
				50,
				array(
					'min' => 1,
					'max' => 500,
				)
			),
			'semantic_query_timeout'          => self::field(
				'semantic',
				'int',
				3,
				array(
					'min' => 1,
					'max' => 30,
				)
			),
			'semantic_in_typeahead'           => self::field( 'semantic', 'bool', false ),
			'semantic_cron'                   => self::field( 'semantic', 'bool', true ),

			// Performance
			'cache_enabled'                   => self::field( 'performance', 'bool', true ),
			'cache_ttl'                       => self::field(
				'performance',
				'int',
				300,
				array(
					'min' => 0,
					'max' => 86400,
				)
			),
			'cache_max_size'                  => self::field(
				'performance',
				'int',
				1000,
				array(
					'min' => 10,
					'max' => 100000,
				)
			),
			'warmup_queries'                  => self::field( 'performance', 'list', array() ),
			'synchronous'                     => self::field( 'performance', 'select', 'NORMAL', array( 'options' => array( 'NORMAL', 'FULL' ) ) ),
			'max_results'                     => self::field(
				'performance',
				'int',
				1000,
				array(
					'min' => 100,
					'max' => 10000,
				)
			),
			'search_mode'                     => self::field( 'performance', 'select', 'fast', array( 'options' => array( 'fast', 'verified' ) ) ),
			'rest_cache_max_age'              => self::field(
				'performance',
				'int',
				60,
				array(
					'min' => 0,
					'max' => 3600,
				)
			),
		);
	}

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return array_map( static fn ( array $field ): mixed => $field['default'], self::fields() );
	}

	/** @return array<string, array<string, mixed>> */
	public static function fieldsForTab( string $tab ): array {
		return array_filter( self::fields(), static fn ( array $field ): bool => $field['tab'] === $tab );
	}

	/**
	 * Sanitizes the fields of one tab; values of other tabs are copied from $current.
	 *
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $current
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input, array $current, string $tab ): array {
		$out = array_replace( self::defaults(), $current );
		foreach ( self::fieldsForTab( $tab ) as $key => $field ) {
			$out[ $key ] = self::sanitizeValue(
				$field,
				$input[ $key ] ?? null,
				$out[ $key ],
				! empty( $input[ $key . '_clear' ] )
			);
		}
		$out['chunk_overlap'] = min( (int) $out['chunk_overlap'], intdiv( (int) $out['chunk_size'], 2 ) );

		return $out;
	}

	/**
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 */
	public static function requiresReindex( array $before, array $after ): bool {
		foreach ( self::fields() as $key => $field ) {
			if ( ! empty( $field['reindex'] ) && ( $before[ $key ] ?? null ) !== ( $after[ $key ] ?? null ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	private static function field( string $tab, string $type, mixed $fallback, array $extra = array() ): array {
		return array(
			'tab'     => $tab,
			'type'    => $type,
			'default' => $fallback,
		) + $extra;
	}

	/** @param array<string, mixed> $field */
	private static function sanitizeValue( array $field, mixed $raw, mixed $current, bool $clear ): mixed {
		return match ( $field['type'] ) {
			'bool' => ! empty( $raw ),
			'int' => (int) self::clamp( is_numeric( $raw ) ? (int) $raw : (int) $field['default'], $field ),
			'float' => (float) self::clamp( is_numeric( $raw ) ? (float) $raw : (float) $field['default'], $field ),
			'select' => is_string( $raw ) && in_array( $raw, (array) $field['options'], true ) ? $raw : $field['default'],
			'multiselect' => self::keys( $raw, $field ),
			'list' => self::lines( $raw ),
			'number_list' => self::numbers( $raw ),
			'map' => self::synonymMap( $raw ),
			'url' => is_string( $raw ) && trim( $raw ) !== '' ? esc_url_raw( trim( $raw ), array( 'http', 'https' ) ) : $field['default'],
			'password' => $clear ? '' : ( is_string( $raw ) && trim( $raw ) !== '' ? sanitize_text_field( trim( $raw ) ) : $current ),
			'prefix' => is_string( $raw ) ? (string) preg_replace( '/[\r\n\t]+/', '', wp_strip_all_tags( $raw ) ) : $field['default'],
			'path' => self::path( $raw ),
			default => is_string( $raw ) ? sanitize_text_field( $raw ) : $field['default'],
		};
	}

	/** @param array<string, mixed> $field */
	private static function clamp( int|float $value, array $field ): int|float {
		if ( isset( $field['min'] ) ) {
			$value = max( $field['min'], $value );
		}
		if ( isset( $field['max'] ) ) {
			$value = min( $field['max'], $value );
		}
		return $value;
	}

	/**
	 * @param array<string, mixed> $field
	 * @return list<string>
	 */
	private static function keys( mixed $raw, array $field ): array {
		$values = is_array( $raw ) ? $raw : array();
		$keys   = array_values(
			array_unique(
				array_filter(
					array_map( static fn ( mixed $v ): string => sanitize_key( (string) $v ), $values ),
					static fn ( string $v ): bool => $v !== ''
				)
			)
		);
		if ( ( $field['source'] ?? '' ) === 'search_fields' ) {
			$keys = array_values( array_intersect( $keys, self::SEARCH_FIELDS ) );
		}
		return $keys;
	}

	/** @return list<string> */
	private static function lines( mixed $raw ): array {
		$split = preg_split( '/\R/', (string) ( $raw ?? '' ) );
		$items = is_array( $split ) ? $split : array();
		$clean = array_map( static fn ( mixed $v ): string => sanitize_text_field( trim( (string) $v ) ), $items );

		return array_values( array_unique( array_filter( $clean, static fn ( string $v ): bool => $v !== '' ) ) );
	}

	/** @return list<float> */
	private static function numbers( mixed $raw ): array {
		$split   = preg_split( '/[\s,]+/', (string) ( $raw ?? '' ) );
		$items   = is_array( $raw ) ? $raw : ( is_array( $split ) ? $split : array() );
		$numbers = array();
		foreach ( $items as $item ) {
			if ( is_numeric( $item ) && (float) $item > 0 ) {
				$numbers[] = (float) $item;
			}
		}
		$numbers = array_values( array_unique( $numbers ) );
		sort( $numbers );

		return $numbers;
	}

	/** @return array<string, list<string>> */
	private static function synonymMap( mixed $raw ): array {
		if ( is_array( $raw ) ) {
			$lines = array();
			foreach ( $raw as $term => $synonyms ) {
				$lines[] = $term . ':' . implode( ',', array_map( 'strval', (array) $synonyms ) );
			}
			$raw = implode( "\n", $lines );
		}

		$map   = array();
		$split = preg_split( '/\R/', (string) ( $raw ?? '' ) );
		$rows  = is_array( $split ) ? $split : array();
		foreach ( $rows as $line ) {
			[$term, $synonyms] = array_pad( explode( ':', $line, 2 ), 2, '' );
			$term              = mb_strtolower( sanitize_text_field( trim( $term ) ) );
			$list              = array_values(
				array_filter(
					array_map( static fn ( string $s ): string => mb_strtolower( sanitize_text_field( trim( $s ) ) ), explode( ',', $synonyms ) ),
					static fn ( string $s ): bool => $s !== ''
				)
			);
			if ( $term !== '' && $list !== array() ) {
				$map[ $term ] = $list;
			}
		}
		return $map;
	}

	private static function path( mixed $raw ): string {
		if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
			return '';
		}
		$path     = rtrim( wp_normalize_path( sanitize_text_field( trim( $raw ) ) ), '/' );
		$absolute = str_starts_with( $path, '/' ) || preg_match( '#^[A-Za-z]:/#', $path ) === 1;
		if ( ! $absolute || str_contains( $path, '..' ) ) {
			return '';
		}
		return $path;
	}
}
