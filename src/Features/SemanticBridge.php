<?php
declare(strict_types=1);

namespace WpYetiSearch\Features;

use Psr\Log\LoggerInterface;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Index\LanguageResolver;
use YetiSearch\Semantic\OpenAICompatibleEmbeddingProvider;
use YetiSearch\YetiSearch;

/** Embedding provider wiring, pending-embedding cron and calibration (spec §3.7, §6.5). */
final class SemanticBridge {

	public const CRON_HOOK     = 'yetisearch_embed_pending';
	public const CRON_SCHEDULE = 'yetisearch_five_minutes';

	public function __construct(
		private ?YetiSearch $yeti,
		private Config $config,
		private LoggerInterface $logger
	) {
	}

	/** Called once when the engine is built. Saving a post never calls the provider. */
	public static function attachProvider( YetiSearch $yeti, Config $config ): void {
		if ( ! $config->bool( 'semantic_enabled' ) ) {
			return;
		}
		$apiKey     = $config->semanticApiKey();
		$dimensions = $config->int( 'semantic_dimensions' );

		$provider = new OpenAICompatibleEmbeddingProvider(
			array(
				'base_url'        => $config->string( 'semantic_endpoint' ),
				'api_key'         => $apiKey !== '' ? $apiKey : null,
				'model'           => $config->string( 'semantic_model' ),
				'dimensions'      => $dimensions > 0 ? $dimensions : null,
				'query_timeout'   => $config->int( 'semantic_query_timeout' ),
				'batch_size'      => $config->int( 'semantic_batch_size' ),
				'query_prefix'    => $config->string( 'semantic_query_prefix' ),
				'document_prefix' => $config->string( 'semantic_document_prefix' ),
			)
		);

		$yeti->setEmbeddingProvider(
			$provider,
			array(
				'weight'                 => $config->float( 'semantic_weight' ),
				'min_similarity'         => $config->float( 'semantic_min_similarity' ),
				'min_margin'             => $config->float( 'semantic_min_margin' ),
				'calibration'            => $config->string( 'semantic_calibration' ),
				'calibration_strictness' => $config->float( 'semantic_calibration_strictness' ),
			)
		);
	}

	public function register(): void {
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- embedding queue needs prompt (5 min) processing; each run is time-boxed.
		add_filter( 'cron_schedules', array( self::class, 'addSchedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'runCron' ) );
	}

	public function isEnabled(): bool {
		return $this->yeti !== null && $this->config->bool( 'semantic_enabled' ) && $this->yeti->isSemanticEnabled();
	}

	/** @return array{embedded: int, pending: int, total: int, error: ?string} */
	public function embedBatch( ?int $limit = null ): array {
		if ( $this->yeti === null || ! $this->isEnabled() ) {
			return array(
				'embedded' => 0,
				'pending'  => 0,
				'total'    => 0,
				'error'    => 'disabled',
			);
		}
		$batch  = $limit ?? $this->config->int( 'semantic_batch_size' );
		$result = array(
			'embedded' => 0,
			'pending'  => 0,
			'total'    => 0,
			'error'    => null,
		);
		try {
			foreach ( $this->indices() as $index ) {
				$row                 = $this->yeti->embedPending( $index, $batch );
				$result['embedded'] += (int) $row['embedded'];
				$result['pending']  += (int) $row['pending'];
				$result['total']    += (int) $row['total'];
				if ( $result['error'] === null && $row['error'] !== null ) {
					$result['error'] = (string) $row['error'];
				}
			}
			return $result;
		} catch ( \Throwable $e ) {
			$this->logger->error( 'Embedding failed', array( 'exception' => $e ) );
			return array(
				'embedded' => 0,
				'pending'  => 0,
				'total'    => 0,
				'error'    => $e->getMessage(),
			);
		}
	}

	/** @return array{ok: bool, error?: string, calibration?: array} */
	public function calibrate(): array {
		if ( $this->yeti === null || ! $this->isEnabled() ) {
			return array(
				'ok'    => false,
				'error' => 'disabled',
			);
		}
		try {
			$calibration = null;
			foreach ( $this->indices() as $index ) {
				$row = $this->yeti->calibrate( $index );
				if ( $row === null ) {
					return array(
						'ok'    => false,
						'error' => 'not_enough_documents',
					);
				}
				$calibration = $row;
			}
			return $calibration === null
				? array(
					'ok'    => false,
					'error' => 'not_enough_documents',
				)
				: array(
					'ok'          => true,
					'calibration' => $calibration->toArray(),
				);
		} catch ( \Throwable $e ) {
			$this->logger->error( 'Calibration failed', array( 'exception' => $e ) );
			return array(
				'ok'    => false,
				'error' => $e->getMessage(),
			);
		}
	}

	public function stats(): array {
		if ( $this->yeti === null || ! $this->isEnabled() ) {
			return array();
		}
		try {
			$stats = array();
			foreach ( $this->indices() as $index ) {
				$stats[ $index ] = $this->yeti->embeddingStats( $index );
			}
			return $stats;
		} catch ( \Throwable ) {
			return array();
		}
	}

	/** Every plugin index (default plus per-language ones), never foreign tables. */
	private function indices(): array {
		$indices = array( Config::INDEX );
		if ( $this->yeti === null ) {
			return $indices;
		}
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
		return $indices;
	}

	public function runCron(): void {
		$this->embedBatch();
	}

	public static function syncSchedule( Config $config ): void {
		$wanted = $config->bool( 'semantic_enabled' ) && $config->bool( 'semantic_cron' );
		$next   = wp_next_scheduled( self::CRON_HOOK );
		if ( $wanted && $next === false ) {
			wp_schedule_event( time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK );
		} elseif ( ! $wanted && $next !== false ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * @param array<string, array{interval: int, display: string}> $schedules
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function addSchedule( array $schedules ): array {
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => 300,
			'display'  => __( 'Every five minutes (WP YetiSearch)', 'wp-yetisearch' ),
		);
		return $schedules;
	}
}
