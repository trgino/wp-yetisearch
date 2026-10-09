<?php
declare(strict_types=1);

namespace WpYetiSearch\Core;

/**
 * System checks (spec §6.2). Probing happens only on activation, settings save,
 * "Re-check" and `wp yetisearch check`; requests read the cached record.
 */
final class HealthChecker {

	public const OPTION_KEY         = 'yetisearch_health';
	public const MIN_SQLITE         = '3.24.0';
	public const RECOMMENDED_SQLITE = '3.35.0';
	public const CRON_HOOK          = 'yetisearch_health_check';

	/** @param (\Closure(): ?\PDO)|null $pdoFactory */
	public function __construct( private ?\Closure $pdoFactory = null ) {
	}

	/** @return array<string, array{ok: bool, value: string}> */
	public function checkAll( string $dir ): array {
		$pdo     = $this->connect();
		$version = '';
		if ( $pdo !== null ) {
			try {
				$statement = $pdo->query( 'select sqlite_version()' );
				$version   = $statement !== false ? (string) $statement->fetchColumn() : '';
			} catch ( \PDOException ) {
				$version = '';
			}
		}

		$fts5     = $pdo !== null && $this->probe( $pdo, 'CREATE VIRTUAL TABLE temp.yetisearch_fts_probe USING fts5(body)' );
		$rtree    = $pdo !== null && $this->probe( $pdo, 'CREATE VIRTUAL TABLE temp.yetisearch_rtree_probe USING rtree(id, min_x, max_x)' );
		$writable = $this->directoryWritable( $dir );

		return array(
			'php_version'        => array(
				'ok'    => PHP_VERSION_ID >= 80200,
				'value' => PHP_VERSION,
			),
			'pdo_sqlite'         => array(
				'ok'    => $pdo !== null,
				'value' => $pdo !== null ? 'loaded' : 'missing',
			),
			'sqlite_version'     => array(
				'ok'    => $version !== '' && version_compare( $version, self::MIN_SQLITE, '>=' ),
				'value' => $version !== '' ? $version : 'n/a',
			),
			'sqlite_recency'     => array(
				'ok'    => $version !== '' && version_compare( $version, self::RECOMMENDED_SQLITE, '>=' ),
				'value' => $version !== '' ? $version : 'n/a',
			),
			'fts5_support'       => array(
				'ok'    => $fts5,
				'value' => $fts5 ? 'yes' : 'no',
			),
			'rtree_support'      => array(
				'ok'    => $rtree,
				'value' => $rtree ? 'yes' : 'no',
			),
			'directory_writable' => array(
				'ok'    => $writable,
				'value' => $dir,
			),
		);
	}

	/** @return array{ready: bool, checks: array<string, array{ok: bool, value: string}>, checked_at: int, php: string} */
	public function refresh( string $dir ): array {
		$checks = $this->checkAll( $dir );
		$record = array(
			'ready'      => self::computeReady( $checks ),
			'checks'     => $checks,
			'checked_at' => time(),
			'php'        => PHP_VERSION,
		);
		update_option( self::OPTION_KEY, $record, false );

		return $record;
	}

	/** @return array{ready: bool, checks: array<string, array{ok: bool, value: string}>, checked_at: int, php: string}|null */
	public function cached(): ?array {
		$record = get_option( self::OPTION_KEY, null );
		if ( ! is_array( $record ) || ( $record['php'] ?? '' ) !== PHP_VERSION || ! isset( $record['ready'], $record['checks'] ) ) {
			return null;
		}
		/** @var array{ready: bool, checks: array<string, array{ok: bool, value: string}>, checked_at: int, php: string} $record */
		return $record;
	}

	public function isReady(): bool {
		return (bool) ( $this->cached()['ready'] ?? false );
	}

	public function isGeoReady(): bool {
		$record = $this->cached();
		return $record !== null && $record['ready'] && (bool) ( $record['checks']['rtree_support']['ok'] ?? false );
	}

	public static function syncSchedule(): void {
		if ( wp_next_scheduled( self::CRON_HOOK ) === false ) {
			wp_schedule_event( time() + 3600, 'hourly', self::CRON_HOOK );
		}
	}

	/** @param array<string, array{ok: bool, value: string}> $checks */
	public static function computeReady( array $checks ): bool {
		// rtree_support (geo only) and sqlite_recency (advisory notice) never block.
		$advisory = array( 'rtree_support', 'sqlite_recency' );
		foreach ( $checks as $key => $check ) {
			if ( ! in_array( $key, $advisory, true ) && ! $check['ok'] ) {
				return false;
			}
		}
		return $checks !== array();
	}

	private function connect(): ?\PDO {
		if ( $this->pdoFactory !== null ) {
			return ( $this->pdoFactory )();
		}
		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			return null;
		}
		try {
			// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- SQLite health probe; $wpdb cannot open SQLite.
			return new \PDO( 'sqlite::memory:', null, null, array( \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ) );
		} catch ( \PDOException ) {
			return null;
		}
	}

	private function probe( \PDO $pdo, string $sql ): bool {
		try {
			$pdo->exec( $sql );
			return true;
		} catch ( \PDOException ) {
			return false;
		}
	}

	private function directoryWritable( string $dir ): bool {
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			return false;
		}
		$file = $dir . '/.yetisearch-write-test-' . bin2hex( random_bytes( 4 ) );
		if ( @file_put_contents( $file, 'ok' ) === false ) {
			return false;
		}
		wp_delete_file( $file );

		return ! file_exists( $file );
	}
}
