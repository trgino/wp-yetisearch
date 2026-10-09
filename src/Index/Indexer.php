<?php
declare(strict_types=1);

namespace WpYetiSearch\Index;

use Psr\Log\LoggerInterface;
use WpYetiSearch\Core\Config;
use YetiSearch\YetiSearch;

/**
 * Keeps the index in sync on save/delete (spec §6.3). Runs whenever health is
 * ready, independent of the master toggle. Never throws into WordPress.
 */
final class Indexer {

	public function __construct(
		private ?YetiSearch $yeti,
		private DocumentMapper $mapper,
		private LoggerInterface $logger
	) {
	}

	public function register(): void {
		if ( $this->yeti === null ) {
			return;
		}
		add_action( 'wp_after_insert_post', array( $this, 'onAfterInsertPost' ), 20, 4 );
		add_action( 'deleted_post', array( $this, 'onDeletedPost' ), 10, 1 );
		add_action( 'transition_post_status', array( $this, 'onTransitionStatus' ), 10, 3 );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $update/$before are fixed wp_after_insert_post signature slots.
	public function onAfterInsertPost( int $postId, \WP_Post $post, bool $update, ?\WP_Post $before ): void {
		if ( wp_is_post_revision( $postId ) !== false || wp_is_post_autosave( $postId ) !== false ) {
			return;
		}
		$this->sync( $post );
	}

	public function onDeletedPost( int $postId ): void {
		if ( $this->yeti === null ) {
			return;
		}
		// Post object is gone, language unknown: sweep every plugin index.
		$indices = array( Config::INDEX );
		try {
			foreach ( $this->yeti->listIndices() as $info ) {
				$name = is_array( $info ) ? ( $info['name'] ?? null ) : null;
				if ( is_string( $name ) && ! in_array( $name, $indices, true ) && LanguageResolver::isPluginIndex( $name ) ) {
					$indices[] = $name;
				}
			}
		} catch ( \Throwable $e ) {
			$this->logger->debug( 'Index list refresh failed; falling back to the base index.', array( 'exception' => $e ) );
		}
		foreach ( $indices as $index ) {
			$this->remove( $postId, $index );
		}
	}

	public function onTransitionStatus( string $newStatus, string $old, \WP_Post $post ): void {
		if ( $newStatus === $old ) {
			return; // Normal saves flow through wp_after_insert_post; avoid double work.
		}
		$this->sync( $post );
	}

	public function sync( \WP_Post $post ): void {
		$document = $this->mapper->map( $post );
		if ( $document === null ) {
			$this->remove( $post->ID, $this->mapper->indexFor( $post ) );
			return;
		}
		$this->upsert( $document, $this->mapper->indexFor( $post ) );
	}

	/** @param array<string, mixed> $document */
	public function upsert( array $document, string $index = Config::INDEX ): void {
		if ( $this->yeti === null ) {
			return;
		}
		$id = (string) $document['id'];
		try {
			// Old chunks must go first: a shorter post produces fewer chunks (spec §3.3).
			$this->yeti->deleteByIdPrefix( $index, $id . '#', false );
			$this->yeti->update( $index, $document );
			$this->clearCaches();
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Indexing failed',
				array(
					'post_id'   => $id,
					'exception' => $e,
				)
			);
		}
	}

	public function remove( int $postId, string $index = Config::INDEX ): void {
		if ( $this->yeti === null ) {
			return;
		}
		try {
			$this->yeti->delete( $index, (string) $postId );
			$this->yeti->deleteByIdPrefix( $index, $postId . '#', false );
			$this->clearCaches();
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Removing document failed',
				array(
					'post_id'   => $postId,
					'exception' => $e,
				)
			);
		}
	}

	/**
	 * Clears the persistent query cache. Since yetisearch 2.5.4 the engine
	 * drops its in-memory results on storage change-tokens as well, so a
	 * plain clearCache() is coherent for same-process reads.
	 */
	private function clearCaches(): void {
		if ( $this->yeti === null ) {
			return;
		}
		$this->yeti->clearCache();
	}
}
