<?php
declare(strict_types=1);

namespace WpYetiSearch\Search;

use Psr\Log\LoggerInterface;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Features\FacetBridge;
use WpYetiSearch\Index\LanguageResolver;

/** Takes over the main search query via posts_pre_query (spec §6.4). */
final class QueryBridge {

	/** @var array<int, array{title: string, excerpt: string}> */
	private array $highlights = array();

	public function __construct(
		private Config $config,
		private SearchService $search,
		private LoggerInterface $logger,
		private ?LanguageResolver $languages = null,
		private ?FacetBridge $facets = null
	) {
	}

	public function register(): void {
		add_filter( 'posts_pre_query', array( $this, 'onPostsPreQuery' ), 10, 2 );
		add_filter( 'the_title', array( $this, 'filterTitle' ), 10, 2 );
		add_filter( 'get_the_excerpt', array( $this, 'filterExcerpt' ), 20, 2 );
	}

	/**
	 * @param array<int, mixed>|null $posts
	 * @return array<int, mixed>|null
	 */
	public function onPostsPreQuery( ?array $posts, \WP_Query $query ): ?array {
		if ( $posts !== null || ! $this->shouldIntercept( $query ) ) {
			return $posts;
		}

		try {
			$types = $this->postTypes( $query );
			if ( $types === array() ) {
				return null;
			}
			$perPage = (int) $query->get( 'posts_per_page' );
			if ( $perPage <= 0 ) {
				$perPage = $this->config->int( 'max_results' );
			}
			$page  = max( 1, (int) $query->get( 'paged' ) );
			$term  = trim( (string) $query->get( 's' ) );
			$index = $this->languages?->indexForCurrent();

			$front = $this->search->frontQuery( $term, $types, $perPage, $page );
			if ( $this->facets !== null ) {
				$this->facets->apply( $front );
			}
			$result = $this->config->string( 'search_mode' ) === 'verified'
				? $this->search->runVerified( $front, array(), $perPage, $page, $index )
				: $this->search->run( $front, array(), $index );
			$ids    = array_map( static fn ( array $item ): int => $item['post_id'], $result['items'] );

			// Reload from MySQL: stale index rows can never surface private or draft posts.
			$loaded = $ids === array() ? array() : get_posts(
				array(
					'post__in'            => $ids,
					'post_type'           => $types,
					'post_status'         => 'publish',
					'has_password'        => false,
					'orderby'             => 'post__in',
					'posts_per_page'      => count( $ids ),
					'ignore_sticky_posts' => true,
					'no_found_rows'       => true,
					'suppress_filters'    => true,
				)
			);

			// WP does not call set_found_posts() after a fields=all short-circuit (verified in WP_Query).
			$query->found_posts   = $result['total'];
			$query->max_num_pages = (int) ceil( $result['total'] / $perPage );

			$this->highlights = array();
			foreach ( $result['items'] as $item ) {
				$this->highlights[ $item['post_id'] ] = array(
					'title'   => $item['title_html'],
					'excerpt' => $item['excerpt_html'],
				);
			}
			$query->set( 'yetisearch_suggestion', $result['suggestion'] ?? '' );
			$query->set( 'yetisearch_facets', $result['facets'] );
			$query->set( 'yetisearch_highlights', $this->highlights );

			return $loaded;
		} catch ( \Throwable $e ) {
			$this->logger->error( 'Search takeover failed; falling back to MySQL', array( 'exception' => $e ) );
			return null;
		}
	}

	public function filterTitle( string $title, int|string $postId = 0 ): string {
		$postId = (int) $postId;
		if ( ! $this->config->bool( 'highlight_enabled' ) || ! isset( $this->highlights[ $postId ] ) || ! in_the_loop() ) {
			return $title;
		}
		return $this->highlights[ $postId ]['title'];
	}

	public function filterExcerpt( string $excerpt, ?\WP_Post $post = null ): string {
		if ( $post === null || ! $this->config->bool( 'highlight_enabled' ) || ! isset( $this->highlights[ $post->ID ] ) || ! in_the_loop() ) {
			return $excerpt;
		}
		return $this->highlights[ $post->ID ]['excerpt'];
	}

	private function shouldIntercept( \WP_Query $query ): bool {
		if ( ! $this->config->bool( 'master_enabled' ) || ! $this->search->isAvailable() || is_admin() ) {
			return false;
		}
		if ( ! $query->is_main_query() || ! $query->is_search() ) {
			return false;
		}
		// fields=ids|id=>parent would make WP call set_found_posts() with a FOUND_ROWS() of nothing.
		if ( ! in_array( (string) $query->get( 'fields' ), array( '', 'all' ), true ) ) {
			return false;
		}
		return trim( (string) $query->get( 's' ) ) !== '';
	}

	/** @return list<string> */
	private function postTypes( \WP_Query $query ): array {
		$indexed   = $this->config->strings( 'indexed_post_types' );
		$requested = $query->get( 'post_type' );
		if ( $requested === '' || $requested === 'any' || $requested === array() || $requested === null ) {
			return $indexed;
		}
		$requested = array_map( 'strval', (array) $requested );

		return array_values( array_intersect( $indexed, $requested ) );
	}
}
