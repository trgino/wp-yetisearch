<?php
declare(strict_types=1);

namespace WpYetiSearch\Index;

use Psr\Log\LoggerInterface;
use WpYetiSearch\Core\Config;
use YetiSearch\YetiSearch;

/** Paged bulk indexing for admin AJAX and WP-CLI (spec §6.3). */
final class BulkIndexer {

	/** @var \Closure(array<string, mixed>): \WP_Query */
	private \Closure $queryFactory;

	public function __construct(
		private ?YetiSearch $yeti,
		private DocumentMapper $mapper,
		private Config $config,
		private LoggerInterface $logger,
		?\Closure $queryFactory = null
	) {
		$this->queryFactory = $queryFactory ?? static fn ( array $args ): \WP_Query => new \WP_Query( $args );
	}

	/**
	 * @param list<string> $postTypes Empty means every indexed post type.
	 * @return array{processed: int, indexed: int, total: int, finished: bool, error: ?string}
	 */
	public function run( int $page, int $perPage, array $postTypes = array(), bool $force = false ): array {
		$result = array(
			'processed' => 0,
			'indexed'   => 0,
			'total'     => 0,
			'finished'  => false,
			'error'     => null,
		);
		$yeti   = $this->yeti;
		if ( $yeti === null ) {
			$result['error'] = 'unavailable';
			return $result;
		}

		$page    = max( 1, $page );
		$perPage = max( 1, min( 500, $perPage ) );
		$allowed = $this->config->strings( 'indexed_post_types' );
		$types   = $postTypes === array() ? $allowed : array_values( array_intersect( $postTypes, $allowed ) );
		if ( $types === array() ) {
			$result['finished'] = true;
			return $result;
		}

		try {
			/** @var array<string, true> $touched */
			$touched = array();
			if ( $force && $page === 1 ) {
				foreach ( $this->reset( $yeti ) as $resetIndex ) {
					$touched[ $resetIndex ] = true;
				}
			}

			$query = ( $this->queryFactory )(
				array(
					'post_type'           => $types,
					'post_status'         => 'publish',
					'has_password'        => false,
					'orderby'             => 'ID',
					'order'               => 'ASC',
					'posts_per_page'      => $perPage,
					'paged'               => $page,
					'ignore_sticky_posts' => true,
					'suppress_filters'    => true,
				)
			);

			/** @var array<string, list<array<string, mixed>>> $batches */
			$batches = array();
			foreach ( $query->posts as $post ) {
				if ( ! $post instanceof \WP_Post ) {
					continue;
				}
				++$result['processed'];
				$document = $this->mapper->map( $post );
				if ( $document === null ) {
					continue;
				}
				$index = $this->mapper->indexFor( $post );
				if ( ! $force ) {
					$yeti->deleteByIdPrefix( $index, $document['id'] . '#', false );
				}
				$batches[ $index ][] = $document;
				$touched[ $index ]   = true;
			}
			foreach ( $batches as $index => $documents ) {
				$yeti->indexBatch( $index, $documents );
				$result['indexed'] += count( $documents );
			}

			$result['total']    = (int) $query->found_posts;
			$result['finished'] = $page >= max( 1, (int) $query->max_num_pages );

			if ( $result['finished'] ) {
				foreach ( array_keys( $touched ) as $index ) {
					$yeti->rebuildFts( $index );
				}
				$yeti->clearCache();
				update_option( Config::NEEDS_REINDEX_OPTION, false );
				update_option( 'yetisearch_last_reindex', time() );
			}
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Bulk indexing failed',
				array(
					'page'      => $page,
					'exception' => $e,
				)
			);
			$result['error']    = $e->getMessage();
			$result['finished'] = false;
		}

		return $result;
	}

	/** @return list<string> touched plugin indices (force-reset ones first). */
	private function reset( YetiSearch $yeti ): array {
		$indices = array( Config::INDEX );
		try {
			foreach ( $yeti->listIndices() as $info ) {
				$name = is_array( $info ) ? ( $info['name'] ?? null ) : null;
				if ( is_string( $name ) && ! in_array( $name, $indices, true ) && LanguageResolver::isPluginIndex( $name ) ) {
					$indices[] = $name;
				}
			}
		} catch ( \Throwable $e ) {
			$this->logger->debug( 'Index list refresh failed; falling back to the base index.', array( 'exception' => $e ) );
		}
		if ( (bool) get_option( Config::NEEDS_REINDEX_OPTION, false ) ) {
			foreach ( $indices as $index ) {
				$yeti->dropIndex( $index ); // FTS prefix and columns are fixed at table creation.
			}
			return $indices;
		}
		foreach ( $indices as $index ) {
			try {
				$yeti->clear( $index );
			} catch ( \Throwable ) {
				$yeti->dropIndex( $index ); // clear() throws when the table does not exist yet.
			}
		}
		return $indices;
	}
}
