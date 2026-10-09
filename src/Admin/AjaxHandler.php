<?php
declare(strict_types=1);

namespace WpYetiSearch\Admin;

use Psr\Log\LoggerInterface;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Core\FileLog;
use WpYetiSearch\Core\HealthChecker;
use WpYetiSearch\Features\CacheBridge;
use WpYetiSearch\Features\SemanticBridge;
use WpYetiSearch\Index\BulkIndexer;
use WpYetiSearch\Search\SearchService;
use WpYetiSearch\Storage\ServerSecurity;
use WpYetiSearch\Storage\StorageManager;

/** Privileged maintenance actions (spec §6.1, §6.5). All routes require manage_options + nonce. */
final class AjaxHandler {

	public const NONCE = 'yetisearch-admin';

	private FileLog $fileLog;

	public function __construct(
		private Config $config,
		private BulkIndexer $indexer,
		private SemanticBridge $semantic,
		private CacheBridge $cache,
		private HealthChecker $health,
		private StorageManager $storage,
		private ServerSecurity $security,
		private SearchService $search,
		private LoggerInterface $logger,
		?FileLog $fileLog = null
	) {
		$this->fileLog = $fileLog ?? new FileLog( $storage->storageDir() );
	}

	public function register(): void {
		foreach ( array( 'reindex', 'embed', 'calibrate', 'cache_clear', 'cache_warmup', 'probe', 'health', 'logs', 'logs_clear', 'dircheck', 'checklist_dismiss' ) as $action ) {
			add_action(
				'wp_ajax_yetisearch_' . $action,
				function () use ( $action ): void {
					$this->handle( $action, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce is verified in handle(); per-action sanitization happens in dispatch().
				}
			);
		}
	}

	/** @param array<string, mixed> $post */
	public function handle( string $action, array $post ): void {
		check_ajax_referer( self::NONCE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
		} else {
			try {
				$params = array();
				foreach ( $post as $k => $v ) {
					$params[ (string) $k ] = $v;
				}
				wp_send_json_success( $this->dispatch( $action, $params ) );
			} catch ( \Throwable $e ) {
				$this->logger->error(
					'Admin AJAX failed',
					array(
						'action'    => $action,
						'exception' => $e,
					)
				);
				wp_send_json_error( array( 'error' => $e->getMessage() ), 500 );
			}
		}
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	public function dispatch( string $action, array $params ): array {
		return match ( $action ) {
			'reindex' => $this->reindex( $params ),
			'embed' => $this->embed( $params ),
			'calibrate' => $this->calibrate(),
			'cache_clear' => $this->cacheClear(),
			'cache_warmup' => $this->cacheWarmup(),
			'probe' => $this->probe(),
			'health' => $this->health(),
			'logs'              => $this->logs( $params ),
			'logs_clear'        => $this->logsClear(),
			'dircheck'          => $this->dircheck( $params ),
			'checklist_dismiss' => $this->checklistDismiss(),
			default             => throw new \InvalidArgumentException( 'Unknown action.' ),
		};
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	public function reindex( array $params ): array {
		$page    = max( 1, (int) ( $params['page'] ?? 1 ) );
		$perPage = max( 1, min( 500, (int) ( $params['per_page'] ?? 100 ) ) );
		$types   = isset( $params['post_type'] ) && is_string( $params['post_type'] ) && $params['post_type'] !== ''
			? array_map( 'sanitize_key', explode( ',', $params['post_type'] ) )
			: array();
		$force   = ! empty( $params['force'] );
		return $this->indexer->run( $page, $perPage, $types, $force );
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	public function embed( array $params ): array {
		$limit = isset( $params['batch'] )
			? max( 1, min( 500, (int) $params['batch'] ) )
			: $this->config->int( 'semantic_batch_size' );
		return $this->semantic->embedBatch( $limit ); // JS polls until pending is 0.
	}

	/** @return array<string, mixed> */
	public function calibrate(): array {
		return $this->semantic->calibrate();
	}

	/** @return array<string, mixed> */
	public function cacheClear(): array {
		return array( 'cleared' => $this->cache->clear() );
	}

	/** @return array<string, mixed> */
	public function cacheWarmup(): array {
		return $this->cache->warmup();
	}

	/** @return array<string, mixed> */
	public function probe(): array {
		$url    = $this->storage->dirUrl();
		$result = $this->security->probe( $this->storage->storageDir(), $url );
		return array(
			'result'  => $result,
			'snippet' => $url !== null ? $this->security->ruleSnippet( $this->security->currentServer(), $url ) : '',
		);
	}

	/** @return array<string, mixed> */
	public function health(): array {
		return $this->health->refresh( $this->storage->storageDir() );
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array{lines: list<array{time: string, level: string, message: string}>, truncated: bool}
	 */
	public function logs( array $params ): array {
		$lines = max( 1, min( 1000, (int) ( $params['lines'] ?? 200 ) ) );
		$level = isset( $params['level'] ) && is_string( $params['level'] ) && $params['level'] !== '' ? sanitize_key( $params['level'] ) : null;
		$rows  = $this->fileLog->tail( $lines, $level );
		return array(
			'lines'     => $rows,
			'truncated' => count( $rows ) >= $lines,
		);
	}

	/** @return array{cleared: bool} */
	public function logsClear(): array {
		$this->fileLog->clear();
		return array( 'cleared' => true );
	}

	/**
	 * Pre-save directory check for the settings form.
	 *
	 * @param array<string, mixed> $params
	 * @return array{ok: bool, error: string}
	 */
	public function dircheck( array $params ): array {
		$raw = isset( $params['path'] ) && is_string( $params['path'] ) ? trim( $params['path'] ) : '';
		return ( new StorageManager( $this->config, $this->security ) )->checkDir( $raw );
	}

	/** @return array{dismissed: bool} */
	public function checklistDismiss(): array {
		update_user_meta( get_current_user_id(), 'yetisearch_hide_checklist', 1 );
		return array( 'dismissed' => true );
	}

	/** @return array<string, mixed> */
	public function stats(): array {
		return array(
			'index'      => $this->search->indexStats(),
			'embeddings' => $this->semantic->stats(),
			'cache'      => $this->cache->stats(),
		);
	}
}
