<?php
declare(strict_types=1);

namespace WpYetiSearch\Frontend;

use Psr\Log\LoggerInterface;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Features\DSLBridge;
use WpYetiSearch\Features\FacetBridge;
use WpYetiSearch\Features\GeoBridge;
use WpYetiSearch\Index\LanguageResolver;
use WpYetiSearch\Search\ResultNormalizer;
use WpYetiSearch\Search\SearchService;

/** GET /wp-json/yetisearch/v1/search (spec §6.7). */
final class RestController {

	public const NAMESPACE = 'yetisearch/v1';

	public function __construct(
		private Config $config,
		private SearchService $search,
		private ResultNormalizer $normalizer,
		private GeoBridge $geo,
		private FacetBridge $facets,
		private DSLBridge $dsl,
		private LoggerInterface $logger,
		private ?LanguageResolver $languages = null
	) {
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
				'args'                => $this->args(),
			)
		);
	}

	/** @return array<string, array<string, mixed>> */
	public function args(): array {
		return array(
			'q'         => array(
				'type'              => 'string',
				'required'          => true,
				'minLength'         => 1,
				'maxLength'         => 200,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'limit'     => array(
				'type'    => 'integer',
				'default' => 10,
				'minimum' => 1,
				'maximum' => DSLBridge::MAX_LIMIT,
			),
			'page'      => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
				'maximum' => 100,
			),
			'post_type' => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
			),
			'facets'    => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'lat'       => array(
				'type'    => 'number',
				'minimum' => -90,
				'maximum' => 90,
			),
			'lng'       => array(
				'type'    => 'number',
				'minimum' => -180,
				'maximum' => 180,
			),
			'radius'    => array(
				'type'    => 'number',
				'minimum' => 0.1,
				'maximum' => 20000,
			),
			'context'   => array(
				'type'    => 'string',
				'default' => 'search',
				'enum'    => array( 'typeahead', 'search' ),
			),
			'lang'      => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}

	public function handle( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->config->bool( 'master_enabled' ) || ! $this->search->isAvailable() ) {
			return $this->unavailable();
		}

		$term = trim( (string) $request->get_param( 'q' ) );
		if ( $term === '' || mb_strlen( $term ) > 200 ) {
			return new \WP_Error( 'yetisearch_invalid_query', __( 'The search term is invalid.', 'wp-yetisearch' ), array( 'status' => 400 ) );
		}
		$indexed  = $this->config->strings( 'indexed_post_types' );
		$postType = (string) ( $request->get_param( 'post_type' ) ?? '' );
		if ( $postType !== '' && ! in_array( $postType, $indexed, true ) ) {
			return new \WP_Error( 'yetisearch_invalid_post_type', __( 'This post type is not searchable.', 'wp-yetisearch' ), array( 'status' => 400 ) );
		}
		$limit     = max( 1, min( DSLBridge::MAX_LIMIT, (int) ( $request->get_param( 'limit' ) ?? 10 ) ) );
		$page      = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );
		$typeahead = $request->get_param( 'context' ) === 'typeahead';
		$lang      = (string) ( $request->get_param( 'lang' ) ?? '' );
		$index     = $this->languages?->indexForCode( $lang !== '' ? $lang : null );

		try {
			$query = $this->search->frontQuery( $term, $postType !== '' ? array( $postType ) : $indexed, $limit, $page );
			$this->dsl->applyFilters( $request->get_params(), $query );
			if ( ! $typeahead && (bool) $request->get_param( 'facets' ) ) {
				$this->facets->apply( $query );
			}
			$lat = $request->get_param( 'lat' );
			$lng = $request->get_param( 'lng' );
			if ( $this->geo->isEnabled() && is_numeric( $lat ) && is_numeric( $lng ) ) {
				$radius = $request->get_param( 'radius' );
				$this->geo->apply( $query, (float) $lat, (float) $lng, is_numeric( $radius ) ? (float) $radius : null );
			}
			$options = $typeahead && ! $this->config->bool( 'semantic_in_typeahead' ) ? array( 'semantic' => false ) : array();
			$result  = $this->config->string( 'search_mode' ) === 'verified'
				? $this->search->runVerified( $query, $options, $limit, $page, $index )
				: $this->search->run( $query, $options, $index );
		} catch ( \InvalidArgumentException | \YetiSearch\Exceptions\InvalidArgumentException $e ) {
			return new \WP_Error( 'yetisearch_invalid_query', esc_html( $e->getMessage() ), array( 'status' => 400 ) );
		} catch ( \Throwable $e ) {
			$this->logger->error( 'REST search failed', array( 'exception' => $e ) );
			return $this->unavailable();
		}

		$items = array_map(
			static fn ( array $item ): array => array(
				'id'           => $item['post_id'],
				'title'        => $item['title'],
				'title_html'   => $item['title_html'],
				'url'          => $item['url'],
				'excerpt_html' => $item['excerpt_html'],
				'post_type'    => $item['post_type'],
			),
			$this->normalizer->onlyPublic( $result['items'] )
		);

		return new \WP_REST_Response(
			array(
				'items'       => $items,
				'total'       => $result['total'],
				'page'        => $page,
				'suggestion'  => $result['suggestion'],
				'facets'      => $result['facets'],
				'search_time' => $result['search_time'],
			),
			200,
			$this->cacheHeaders()
		);
	}

	/** @return array<string, string> */
	private function cacheHeaders(): array {
		// All response fields are public posts; safe to cache briefly. Zero disables it.
		$maxAge = $this->config->int( 'rest_cache_max_age' );
		if ( $maxAge <= 0 ) {
			return array( 'Cache-Control' => 'no-store' );
		}
		return array( 'Cache-Control' => 'public, max-age=' . $maxAge );
	}

	private function unavailable(): \WP_Error {
		return new \WP_Error( 'yetisearch_unavailable', __( 'Search is temporarily unavailable.', 'wp-yetisearch' ), array( 'status' => 503 ) );
	}
}
