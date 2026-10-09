<?php
declare(strict_types=1);

namespace WpYetiSearch\Admin;

use WpYetiSearch\Core\SettingsSchema;

/** Translatable tab/field labels. SettingsSchema never calls translation functions. */
final class FieldLabels {

	/** @return array<string, string> */
	public static function tabs(): array {
		return array(
			'general'     => __( 'General', 'wp-yetisearch' ),
			'content'     => __( 'Content', 'wp-yetisearch' ),
			'relevance'   => __( 'Relevance', 'wp-yetisearch' ),
			'language'    => __( 'Language', 'wp-yetisearch' ),
			'display'     => __( 'Display', 'wp-yetisearch' ),
			'facets_geo'  => __( 'Facets & Geo', 'wp-yetisearch' ),
			'semantic'    => __( 'Semantic', 'wp-yetisearch' ),
			'performance' => __( 'Performance', 'wp-yetisearch' ),
			'maintenance' => __( 'Maintenance', 'wp-yetisearch' ),
		);
	}

	/** @return array<string, array{label: string, help: string}> */
	public static function for( string $tab ): array {
		$out = array();
		foreach ( SettingsSchema::fieldsForTab( $tab ) as $key => $field ) {
			$out[ $key ] = self::label( $key );
		}
		return $out;
	}

	/** @return array{label: string, help: string} */
	public static function label( string $key ): array {
		return self::strings()[ $key ] ?? array(
			'label' => $key,
			'help'  => '',
		);
	}

	/** @return array<string, array{label: string, help: string}> */
	private static function strings(): array {
		return array(
			'master_enabled'                  => array(
				'label' => __( 'Enable WP YetiSearch', 'wp-yetisearch' ),
				'help'  => __( 'Take over front-end search. Locked until health checks pass.', 'wp-yetisearch' ),
			),
			'db_custom_dir'                   => array(
				'label' => __( 'Custom database directory', 'wp-yetisearch' ),
				'help'  => __( 'Absolute path; outside the web root is safest. Empty means uploads/yetisearch/.', 'wp-yetisearch' ),
			),
			'indexed_post_types'              => array(
				'label' => __( 'Post types to index', 'wp-yetisearch' ),
				'help'  => __( 'Only published, password-less posts are indexed. Changing this requires a re-index.', 'wp-yetisearch' ),
			),
			'indexed_taxonomies'              => array(
				'label' => __( 'Taxonomies to index', 'wp-yetisearch' ),
				'help'  => __( 'Term names become searchable text. Requires a re-index.', 'wp-yetisearch' ),
			),
			'indexed_meta_keys'               => array(
				'label' => __( 'Meta keys to index', 'wp-yetisearch' ),
				'help'  => __( 'One key per line. Requires a re-index.', 'wp-yetisearch' ),
			),
			'chunk_size'                      => array(
				'label' => __( 'Chunk size', 'wp-yetisearch' ),
				'help'  => __( 'Long posts are split into chunks of this many characters. Requires a re-index.', 'wp-yetisearch' ),
			),
			'chunk_overlap'                   => array(
				'label' => __( 'Chunk overlap', 'wp-yetisearch' ),
				'help'  => __( 'Overlap between neighboring chunks, at most half the chunk size. Requires a re-index.', 'wp-yetisearch' ),
			),
			'weight_title'                    => array(
				'label' => __( 'Title weight', 'wp-yetisearch' ),
				'help'  => __( 'Applied at query time; no re-index needed.', 'wp-yetisearch' ),
			),
			'weight_content'                  => array(
				'label' => __( 'Content weight', 'wp-yetisearch' ),
				'help'  => __( 'Applied at query time; no re-index needed.', 'wp-yetisearch' ),
			),
			'weight_excerpt'                  => array(
				'label' => __( 'Excerpt weight', 'wp-yetisearch' ),
				'help'  => __( 'Applied at query time; no re-index needed.', 'wp-yetisearch' ),
			),
			'weight_taxonomies'               => array(
				'label' => __( 'Taxonomies weight', 'wp-yetisearch' ),
				'help'  => __( 'Applied at query time; no re-index needed.', 'wp-yetisearch' ),
			),
			'weight_meta'                     => array(
				'label' => __( 'Meta weight', 'wp-yetisearch' ),
				'help'  => __( 'Applied at query time; no re-index needed.', 'wp-yetisearch' ),
			),
			'min_score'                       => array(
				'label' => __( 'Minimum score', 'wp-yetisearch' ),
				'help'  => __( 'Results below this BM25 score are dropped. Keep 0 unless noisy results appear on large sites.', 'wp-yetisearch' ),
			),
			'two_pass_search'                 => array(
				'label' => __( 'Two-pass search', 'wp-yetisearch' ),
				'help'  => __( 'Search primary fields first, then the full index.', 'wp-yetisearch' ),
			),
			'primary_fields'                  => array(
				'label' => __( 'Primary fields', 'wp-yetisearch' ),
				'help'  => __( 'Fields searched in the first pass.', 'wp-yetisearch' ),
			),
			'primary_field_limit'             => array(
				'label' => __( 'Primary field limit', 'wp-yetisearch' ),
				'help'  => __( 'Maximum candidates taken from the first pass.', 'wp-yetisearch' ),
			),
			'exact_match_boost'               => array(
				'label' => __( 'Exact match boost', 'wp-yetisearch' ),
				'help'  => __( 'Multiplier for exact phrase matches.', 'wp-yetisearch' ),
			),
			'exact_terms_boost'               => array(
				'label' => __( 'Exact terms boost', 'wp-yetisearch' ),
				'help'  => __( 'Multiplier when all exact terms are present.', 'wp-yetisearch' ),
			),
			'enable_fuzzy'                    => array(
				'label' => __( 'Fuzzy search', 'wp-yetisearch' ),
				'help'  => __( 'Tolerate typos via trigram / Jaro-Winkler / Levenshtein consensus.', 'wp-yetisearch' ),
			),
			'fuzzy_algorithm'                 => array(
				'label' => __( 'Fuzzy algorithm', 'wp-yetisearch' ),
				'help'  => __( 'Which similarity measure drives typo correction.', 'wp-yetisearch' ),
			),
			'fuzzy_correction_mode'           => array(
				'label' => __( 'Fuzzy correction mode', 'wp-yetisearch' ),
				'help'  => __( 'Rewrite the query to the corrected terms before searching.', 'wp-yetisearch' ),
			),
			'correction_threshold'            => array(
				'label' => __( 'Correction threshold', 'wp-yetisearch' ),
				'help'  => __( 'Minimum confidence for applying a typo correction.', 'wp-yetisearch' ),
			),
			'levenshtein_threshold'           => array(
				'label' => __( 'Levenshtein threshold', 'wp-yetisearch' ),
				'help'  => __( 'Maximum edit distance for Levenshtein matches.', 'wp-yetisearch' ),
			),
			'trigram_threshold'               => array(
				'label' => __( 'Trigram threshold', 'wp-yetisearch' ),
				'help'  => __( 'Minimum trigram similarity for fuzzy matches.', 'wp-yetisearch' ),
			),
			'jaro_winkler_threshold'          => array(
				'label' => __( 'Jaro-Winkler threshold', 'wp-yetisearch' ),
				'help'  => __( 'Minimum Jaro-Winkler similarity for fuzzy matches.', 'wp-yetisearch' ),
			),
			'min_term_frequency'              => array(
				'label' => __( 'Minimum term frequency', 'wp-yetisearch' ),
				'help'  => __( 'A term must occur this often before it can be a correction target.', 'wp-yetisearch' ),
			),
			'fuzzy_score_penalty'             => array(
				'label' => __( 'Fuzzy score penalty', 'wp-yetisearch' ),
				'help'  => __( 'Score discount applied to fuzzy-corrected matches.', 'wp-yetisearch' ),
			),
			'fuzzy_last_token_only'           => array(
				'label' => __( 'Fuzzy last token only', 'wp-yetisearch' ),
				'help'  => __( 'Apply typo correction to the last query token only.', 'wp-yetisearch' ),
			),
			'prefix_last_token'               => array(
				'label' => __( 'Prefix last token', 'wp-yetisearch' ),
				'help'  => __( 'Match the last token as a prefix. Requires a re-index (FTS prefix index).', 'wp-yetisearch' ),
			),
			'enable_suggestions'              => array(
				'label' => __( 'Search suggestions', 'wp-yetisearch' ),
				'help'  => __( 'Offer "did you mean" suggestions for typo queries.', 'wp-yetisearch' ),
			),
			'stemmer_language'                => array(
				'label' => __( 'Stemmer language', 'wp-yetisearch' ),
				'help'  => __( 'Auto uses the site language. Indexes stem in their content language. Requires a re-index.', 'wp-yetisearch' ),
			),
			'min_word_length'                 => array(
				'label' => __( 'Minimum word length', 'wp-yetisearch' ),
				'help'  => __( 'Shorter tokens are dropped at index time. Requires a re-index.', 'wp-yetisearch' ),
			),
			'strip_punctuation'               => array(
				'label' => __( 'Strip punctuation', 'wp-yetisearch' ),
				'help'  => __( 'Remove punctuation during analysis. Requires a re-index.', 'wp-yetisearch' ),
			),
			'expand_contractions'             => array(
				'label' => __( 'Expand contractions', 'wp-yetisearch' ),
				'help'  => __( 'Expand don\'t → do not etc. Requires a re-index.', 'wp-yetisearch' ),
			),
			'custom_stop_words'               => array(
				'label' => __( 'Custom stop words', 'wp-yetisearch' ),
				'help'  => __( 'One word per line. Requires a re-index.', 'wp-yetisearch' ),
			),
			'disable_stop_words'              => array(
				'label' => __( 'Disable stop words', 'wp-yetisearch' ),
				'help'  => __( 'Index even the most common words. Requires a re-index.', 'wp-yetisearch' ),
			),
			'enable_synonyms'                 => array(
				'label' => __( 'Enable synonyms', 'wp-yetisearch' ),
				'help'  => __( 'Expand queries with the synonym map below.', 'wp-yetisearch' ),
			),
			'synonyms'                        => array(
				'label' => __( 'Synonyms', 'wp-yetisearch' ),
				'help'  => __( 'One per line: term: synonym1, synonym2', 'wp-yetisearch' ),
			),
			'highlight_enabled'               => array(
				'label' => __( 'Highlight matches', 'wp-yetisearch' ),
				'help'  => __( 'Wrap matching terms in the highlight tag.', 'wp-yetisearch' ),
			),
			'highlight_tag'                   => array(
				'label' => __( 'Highlight tag', 'wp-yetisearch' ),
				'help'  => __( 'Only this tag is allowed in highlight HTML; everything else is escaped.', 'wp-yetisearch' ),
			),
			'snippet_length'                  => array(
				'label' => __( 'Snippet length', 'wp-yetisearch' ),
				'help'  => __( 'Characters shown around each match.', 'wp-yetisearch' ),
			),
			'typeahead_enabled'               => array(
				'label' => __( 'Typeahead suggestions', 'wp-yetisearch' ),
				'help'  => __( 'Suggest results while typing in search fields.', 'wp-yetisearch' ),
			),
			'typeahead_selector'              => array(
				'label' => __( 'Typeahead selector', 'wp-yetisearch' ),
				'help'  => __( 'CSS selector for search inputs to enhance.', 'wp-yetisearch' ),
			),
			'typeahead_debounce_ms'           => array(
				'label' => __( 'Typeahead debounce (ms)', 'wp-yetisearch' ),
				'help'  => __( 'Wait this long after typing stops before searching.', 'wp-yetisearch' ),
			),
			'typeahead_min_chars'             => array(
				'label' => __( 'Typeahead minimum characters', 'wp-yetisearch' ),
				'help'  => __( 'Start suggesting after this many characters.', 'wp-yetisearch' ),
			),
			'typeahead_max_results'           => array(
				'label' => __( 'Typeahead maximum results', 'wp-yetisearch' ),
				'help'  => __( 'Suggestions shown per keystroke.', 'wp-yetisearch' ),
			),
			'facets_enabled'                  => array(
				'label' => __( 'Enable facets', 'wp-yetisearch' ),
				'help'  => __( 'Count results per post type and taxonomy.', 'wp-yetisearch' ),
			),
			'facet_post_type'                 => array(
				'label' => __( 'Post type facet', 'wp-yetisearch' ),
				'help'  => __( 'Include a post-type facet in results.', 'wp-yetisearch' ),
			),
			'facet_taxonomies'                => array(
				'label' => __( 'Facet taxonomies', 'wp-yetisearch' ),
				'help'  => __( 'Only the primary term per post is counted. Requires a re-index.', 'wp-yetisearch' ),
			),
			'facet_limit'                     => array(
				'label' => __( 'Facet limit', 'wp-yetisearch' ),
				'help'  => __( 'Values returned per facet.', 'wp-yetisearch' ),
			),
			'geo_enabled'                     => array(
				'label' => __( 'Enable geo search', 'wp-yetisearch' ),
				'help'  => __( 'Without SQLite R-Tree, results stay exact but queries are slower. Requires a re-index.', 'wp-yetisearch' ),
			),
			'geo_lat_meta_key'                => array(
				'label' => __( 'Latitude meta key', 'wp-yetisearch' ),
				'help'  => __( 'Post meta key holding the latitude. Requires a re-index.', 'wp-yetisearch' ),
			),
			'geo_lng_meta_key'                => array(
				'label' => __( 'Longitude meta key', 'wp-yetisearch' ),
				'help'  => __( 'Post meta key holding the longitude. Requires a re-index.', 'wp-yetisearch' ),
			),
			'geo_default_radius'              => array(
				'label' => __( 'Default radius', 'wp-yetisearch' ),
				'help'  => __( 'In the unit selected below.', 'wp-yetisearch' ),
			),
			'geo_unit'                        => array(
				'label' => __( 'Distance unit', 'wp-yetisearch' ),
				'help'  => __( 'Kilometers or miles.', 'wp-yetisearch' ),
			),
			'geo_distance_ranges'             => array(
				'label' => __( 'Distance ranges', 'wp-yetisearch' ),
				'help'  => __( 'Comma-separated ranges for the distance facet.', 'wp-yetisearch' ),
			),
			'distance_weight'                 => array(
				'label' => __( 'Distance weight', 'wp-yetisearch' ),
				'help'  => __( 'How strongly distance influences ranking (0–1).', 'wp-yetisearch' ),
			),
			'price_facet_enabled'             => array(
				'label' => __( 'Price facet', 'wp-yetisearch' ),
				'help'  => __( 'Bucket results by price ranges below. Prices index automatically; no re-index needed to toggle.', 'wp-yetisearch' ),
			),
			'price_meta_key'                  => array(
				'label' => __( 'Price meta key', 'wp-yetisearch' ),
				'help'  => __( 'Post meta key holding the numeric price (WooCommerce: _price). Requires a re-index.', 'wp-yetisearch' ),
			),
			'price_ranges'                    => array(
				'label' => __( 'Price ranges', 'wp-yetisearch' ),
				'help'  => __( 'Comma-separated upper bounds, e.g. 100, 500, 1000. Applied at query time.', 'wp-yetisearch' ),
			),
			'semantic_enabled'                => array(
				'label' => __( 'Enable semantic search', 'wp-yetisearch' ),
				'help'  => __( 'Hybrid keyword + embedding search via an OpenAI-compatible API.', 'wp-yetisearch' ),
			),
			'semantic_endpoint'               => array(
				'label' => __( 'Embedding endpoint', 'wp-yetisearch' ),
				'help'  => __( 'Base URL, e.g. https://api.openai.com/v1 or http://localhost:11434/v1', 'wp-yetisearch' ),
			),
			'semantic_api_key'                => array(
				'label' => __( 'API key', 'wp-yetisearch' ),
				'help'  => __( 'Write-only: blank keeps the stored key. YETISEARCH_SEMANTIC_API_KEY wins.', 'wp-yetisearch' ),
			),
			'semantic_model'                  => array(
				'label' => __( 'Embedding model', 'wp-yetisearch' ),
				'help'  => __( 'Model id sent to the embedding endpoint.', 'wp-yetisearch' ),
			),
			'semantic_dimensions'             => array(
				'label' => __( 'Dimensions', 'wp-yetisearch' ),
				'help'  => __( '0 means auto-detect from the first response.', 'wp-yetisearch' ),
			),
			'semantic_query_prefix'           => array(
				'label' => __( 'Query prefix', 'wp-yetisearch' ),
				'help'  => __( 'Prepended to queries, e.g. for nomic-embed-text.', 'wp-yetisearch' ),
			),
			'semantic_document_prefix'        => array(
				'label' => __( 'Document prefix', 'wp-yetisearch' ),
				'help'  => __( 'Prepended to indexed documents.', 'wp-yetisearch' ),
			),
			'semantic_weight'                 => array(
				'label' => __( 'Semantic weight', 'wp-yetisearch' ),
				'help'  => __( 'Hybrid balance between keyword and vector scores.', 'wp-yetisearch' ),
			),
			'semantic_min_similarity'         => array(
				'label' => __( 'Minimum similarity', 'wp-yetisearch' ),
				'help'  => __( 'Vector hits below this are discarded.', 'wp-yetisearch' ),
			),
			'semantic_min_margin'             => array(
				'label' => __( 'Minimum margin', 'wp-yetisearch' ),
				'help'  => __( 'Noise gate: semantic must beat keyword by this margin.', 'wp-yetisearch' ),
			),
			'semantic_calibration'            => array(
				'label' => __( 'Calibration', 'wp-yetisearch' ),
				'help'  => __( 'Auto measures the noise floor; off disables the gate.', 'wp-yetisearch' ),
			),
			'semantic_calibration_strictness' => array(
				'label' => __( 'Calibration strictness', 'wp-yetisearch' ),
				'help'  => __( 'Higher rejects more semantic noise.', 'wp-yetisearch' ),
			),
			'semantic_batch_size'             => array(
				'label' => __( 'Embedding batch size', 'wp-yetisearch' ),
				'help'  => __( 'Documents embedded per cron/AJAX batch.', 'wp-yetisearch' ),
			),
			'semantic_query_timeout'          => array(
				'label' => __( 'Query timeout (s)', 'wp-yetisearch' ),
				'help'  => __( 'Embedding calls slower than this fall back to keyword search.', 'wp-yetisearch' ),
			),
			'semantic_in_typeahead'           => array(
				'label' => __( 'Semantic in typeahead', 'wp-yetisearch' ),
				'help'  => __( 'Off by default to keep keystroke latency low.', 'wp-yetisearch' ),
			),
			'semantic_cron'                   => array(
				'label' => __( 'Embed via cron', 'wp-yetisearch' ),
				'help'  => __( 'Embed pending documents every five minutes.', 'wp-yetisearch' ),
			),
			'cache_enabled'                   => array(
				'label' => __( 'Query cache', 'wp-yetisearch' ),
				'help'  => __( 'Cache search results for repeated queries.', 'wp-yetisearch' ),
			),
			'cache_ttl'                       => array(
				'label' => __( 'Cache TTL (s)', 'wp-yetisearch' ),
				'help'  => __( 'How long cached results stay valid.', 'wp-yetisearch' ),
			),
			'cache_max_size'                  => array(
				'label' => __( 'Cache max size', 'wp-yetisearch' ),
				'help'  => __( 'Maximum cached queries held.', 'wp-yetisearch' ),
			),
			'warmup_queries'                  => array(
				'label' => __( 'Warm-up queries', 'wp-yetisearch' ),
				'help'  => __( 'One per line; replayed after clearing the cache.', 'wp-yetisearch' ),
			),
			'synchronous'                     => array(
				'label' => __( 'SQLite synchronous', 'wp-yetisearch' ),
				'help'  => __( 'FULL is safer, NORMAL is faster.', 'wp-yetisearch' ),
			),
			'max_results'                     => array(
				'label' => __( 'Maximum results', 'wp-yetisearch' ),
				'help'  => __( 'De-duplication window pulled per search.', 'wp-yetisearch' ),
			),
			'search_mode'                     => array(
				'label' => __( 'Search mode', 'wp-yetisearch' ),
				'help'  => __( 'Fast uses the engine total (may include removed posts); verified recounts public posts within the result window (slower transfer). Items are always verified.', 'wp-yetisearch' ),
			),
			'rest_cache_max_age'              => array(
				'label' => __( 'REST cache (s)', 'wp-yetisearch' ),
				'help'  => __( 'Browser/CDN cache lifetime for public search responses. 0 disables client caching.', 'wp-yetisearch' ),
			),
		);
	}
}
