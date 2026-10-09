<?php
declare(strict_types=1);

namespace WpYetiSearch\Search;

use WpYetiSearch\Core\Config;
use WpYetiSearch\Features\FacetBridge;
use WpYetiSearch\Index\LanguageResolver;
use YetiSearch\Models\SearchQuery;
use YetiSearch\YetiSearch;

/**
 * The single search path (spec §3.5): SearchQuery → SearchEngine::search() with
 * unique_by_route, because YetiSearch::search()/execute() drop facets and options.
 */
final class SearchService {

	public function __construct(
		private ?YetiSearch $yeti,
		private Config $config,
		private ResultNormalizer $normalizer,
		private ?LanguageResolver $languages = null,
		private ?FacetBridge $facets = null
	) {
	}

	public function isAvailable(): bool {
		return $this->yeti !== null;
	}

	public function newQuery( string $term ): SearchQuery {
		$query = new SearchQuery( $term );
		$query->fuzzy( $this->config->bool( 'enable_fuzzy' ) );
		$query->highlight( $this->config->bool( 'highlight_enabled' ), $this->config->int( 'snippet_length' ) );
		$language = $this->config->stemmerLanguage();
		if ( $language !== null ) {
			$query->language( $language );
		}
		return $query;
	}

	/** @param list<string> $postTypes */
	public function frontQuery( string $term, array $postTypes, int $perPage, int $page ): SearchQuery {
		$perPage = max( 1, $perPage );
		$query   = $this->newQuery( $term )
			->limit( $perPage )
			->offset( ( max( 1, $page ) - 1 ) * $perPage );
		$query->filter( 'metadata.post_type', $postTypes, 'in' );
		$current = $this->languages !== null ? $this->languages->currentLanguage() : null;
		if ( is_string( $current ) && $current !== '' ) {
			$query->language( $current );
		}

		return $query;
	}

	/**
	 * @param array{semantic?: bool} $options
	 * @return array{items: list<array{post_id: int, score: float, title: string, title_html: string, url: string, excerpt_html: string, post_type: string}>, total: int, suggestion: ?string, facets: array, search_time: float, semantic: bool}
	 */
	public function run( SearchQuery $query, array $options = array(), ?string $index = null ): array {
		if ( $this->yeti === null ) {
			throw new \RuntimeException( 'YetiSearch is not available.' );
		}
		$index       ??= $this->languages?->indexForCurrent() ?? Config::INDEX;
		$engineOptions = array( 'unique_by_route' => true );
		if ( array_key_exists( 'semantic', $options ) ) {
			$engineOptions['semantic'] = (bool) $options['semantic'];
		}

		$raw    = $this->yeti->getSearchEngine( $index )->search( $query, $engineOptions )->toArray();
		$facets = is_array( $raw['facets'] ?? null ) ? $raw['facets'] : array();

		return array(
			'items'       => $this->normalizer->normalize( is_array( $raw['results'] ?? null ) ? $raw['results'] : array() ),
			'total'       => (int) ( $raw['total'] ?? 0 ),
			'suggestion'  => is_string( $raw['suggestion'] ?? null ) && $raw['suggestion'] !== '' ? $raw['suggestion'] : null,
			'facets'      => $this->facets !== null ? $this->facets->enrichFacets( $facets ) : $facets,
			'search_time' => (float) ( $raw['search_time'] ?? 0.0 ),
			'semantic'    => (bool) ( $raw['metadata']['semantic'] ?? false ),
		);
	}

	/**
	 * Verified mode: recounts public posts over the whole candidate window so
	 * the total excludes stale/private entries (spec: admin transparency).
	 * Exact for totals within `max_results`; the engine window bounds both modes.
	 * DSL/facet/geo/semantic options on the query are preserved.
	 *
	 * @param array{semantic?: bool} $options
	 * @return array{items: list<array{post_id: int, score: float, title: string, title_html: string, url: string, excerpt_html: string, post_type: string}>, total: int, suggestion: ?string, facets: array, search_time: float, semantic: bool}
	 */
	public function runVerified( SearchQuery $query, array $options, int $perPage, int $page, ?string $index = null ): array {
		$perPage = max( 1, $perPage );
		$window  = max( 1, $this->config->int( 'max_results' ) );
		$wide    = clone $query;
		$wide->limit( $window )->offset( 0 );
		$result          = $this->run( $wide, $options, $index );
		$public          = $this->normalizer->onlyPublic( $result['items'] );
		$result['items'] = array_slice( $public, ( max( 1, $page ) - 1 ) * $perPage, $perPage );
		$result['total'] = count( $public );
		return $result;
	}

	/** @return array{documents?: int, stats?: array} */
	public function indexStats(): array {
		if ( $this->yeti === null ) {
			return array();
		}
		try {
			return array(
				'documents' => $this->yeti->countDocuments( Config::INDEX ),
				'stats'     => $this->yeti->getStats( Config::INDEX ),
			);
		} catch ( \Throwable ) {
			return array();
		}
	}
}
