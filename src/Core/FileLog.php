<?php
declare(strict_types=1);

namespace WpYetiSearch\Core;

/**
 * Daily JSON-lines log files with backward streaming reads and age pruning.
 * Keeps admin-visible history without loading whole files into memory.
 */
final class FileLog {

	public const RETENTION_DAYS = 14;
	public const PREFIX         = 'yetisearch-';
	public const SUFFIX         = '.log';

	public function __construct( private string $dir ) {
	}

	/** @param array<string, mixed> $context */
	public function append( string $level, string $message, array $context = array() ): void {
		if ( ! is_dir( $this->dir ) && ! @mkdir( $this->dir, 0755, true ) && ! is_dir( $this->dir ) ) {
			return;
		}
		$line = wp_json_encode(
			array(
				'time'    => gmdate( 'c' ),
				'level'   => $level,
				'message' => $message,
				'context' => $this->scrub( $context ),
			)
		);
		if ( ! is_string( $line ) ) {
			return;
		}
		$handle = @fopen( $this->file(), 'ab' );
		if ( $handle === false ) {
			return;
		}
		flock( $handle, LOCK_EX );
		fwrite( $handle, $line . "\n" );
		flock( $handle, LOCK_UN );
		fclose( $handle );
	}

	/**
	 * Last N lines, oldest first, read backward from the end of today's file.
	 *
	 * @return list<array{time: string, level: string, message: string}>
	 */
	public function tail( int $lines = 200, ?string $level = null ): array {
		$path = $this->file();
		if ( ! is_file( $path ) ) {
			return array();
		}
		$lines = max( 1, min( 1000, $lines ) );
		$found = array();
		try {
			$file = new \SplFileObject( $path, 'r' );
			$file->seek( PHP_INT_MAX );
			$last = $file->key();
			for ( $i = $last; $i >= 0; $i-- ) {
				$file->seek( $i );
				$raw = $file->current();
				if ( ! is_string( $raw ) ) {
					continue;
				}
				$row = json_decode( $raw, true );
				if ( ! is_array( $row ) || ! isset( $row['message'] ) ) {
					continue;
				}
				if ( $level !== null && ( $row['level'] ?? '' ) !== $level ) {
					continue;
				}
				$found[] = array(
					'time'    => (string) ( $row['time'] ?? '' ),
					'level'   => (string) ( $row['level'] ?? '' ),
					'message' => (string) $row['message'],
				);
				if ( count( $found ) >= $lines ) {
					break;
				}
			}
		} catch ( \Throwable ) {
			return array();
		}
		return array_reverse( $found );
	}

	/** Deletes expired log files. Returns the number removed. */
	public function prune(): int {
		$removed = 0;
		$cutoff  = time() - self::RETENTION_DAYS * 86400;
		$matches = glob( $this->dir . '/' . self::PREFIX . '*' . self::SUFFIX );
		if ( ! is_array( $matches ) ) {
			return 0;
		}
		foreach ( $matches as $file ) {
			if ( is_file( $file ) && filemtime( $file ) !== false && filemtime( $file ) < $cutoff ) {
				wp_delete_file( $file );
				if ( ! file_exists( $file ) ) {
					++$removed;
				}
			}
		}
		return $removed;
	}

	public function clear(): void {
		$matches = glob( $this->dir . '/' . self::PREFIX . '*' . self::SUFFIX );
		if ( ! is_array( $matches ) ) {
			return;
		}
		foreach ( $matches as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	private function file(): string {
		return $this->dir . '/' . self::PREFIX . gmdate( 'Y-m-d' ) . self::SUFFIX;
	}

	/** @param array<string, mixed> $context @return array<string, mixed> */
	private function scrub( array $context ): array {
		unset( $context['exception'] );
		array_walk_recursive(
			$context,
			static function ( &$v ): void {
				if ( is_object( $v ) || is_resource( $v ) ) {
					$v = '(' . get_debug_type( $v ) . ')';
				}
			}
		);
		/** @var array<string, mixed> $context */
		return $context;
	}
}
