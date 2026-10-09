<?php
declare(strict_types=1);

namespace WpYetiSearch\Storage;

use WpYetiSearch\Core\Config;

/** Storage directory, database hash/path and public URL (spec §6.6). */
final class StorageManager {

	public const HASH_OPTION    = 'yetisearch_db_hash';
	public const DEFAULT_SUBDIR = 'yetisearch';

	public function __construct( private Config $config, private ServerSecurity $security ) {
	}

	public function defaultDir(): string {
		$uploads = wp_upload_dir( null, false );
		return rtrim( wp_normalize_path( (string) $uploads['basedir'] ), '/' ) . '/' . self::DEFAULT_SUBDIR;
	}

	public function storageDirFor( string $custom ): string {
		return $custom !== '' ? rtrim( wp_normalize_path( $custom ), '/' ) : $this->defaultDir();
	}

	public function storageDir(): string {
		return $this->storageDirFor( $this->config->string( 'db_custom_dir' ) );
	}

	public function dbHash(): string {
		$hash = get_option( self::HASH_OPTION, '' );
		if ( is_string( $hash ) && preg_match( '/^[a-f0-9]{12}$/', $hash ) === 1 ) {
			return $hash;
		}
		$hash = bin2hex( random_bytes( 6 ) );
		update_option( self::HASH_OPTION, $hash, false );

		return $hash;
	}

	public function dbPath(): string {
		return $this->storageDir() . '/yetisearch_' . $this->dbHash() . '.sqlite';
	}

	/** Public URL of the storage directory, or null when it is not under uploads/ABSPATH. */
	public function dirUrl(): ?string {
		$dir     = $this->storageDir();
		$uploads = wp_upload_dir( null, false );
		$base    = rtrim( wp_normalize_path( (string) $uploads['basedir'] ), '/' );
		if ( str_starts_with( $dir . '/', $base . '/' ) ) {
			return rtrim( (string) $uploads['baseurl'], '/' ) . substr( $dir, strlen( $base ) );
		}

		$root = defined( 'ABSPATH' ) ? rtrim( wp_normalize_path( ABSPATH ), '/' ) : '';
		if ( $root !== '' && str_starts_with( $dir . '/', $root . '/' ) ) {
			return rtrim( site_url(), '/' ) . substr( $dir, strlen( $root ) );
		}

		return null;
	}

	public function ensureProtected(): bool {
		$dir = $this->storageDir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		return $this->security->writeProtectionFiles( $dir );
	}

	/**
	 * Pre-save validation for a custom directory: absolute, creatable and writable.
	 * Empty means the default uploads directory, which health checks govern.
	 *
	 * @return array{ok: bool, error: string}
	 */
	public function checkDir( string $custom ): array {
		if ( $custom === '' ) {
			return array(
				'ok'    => true,
				'error' => '',
			);
		}
		$path     = rtrim( wp_normalize_path( $custom ), '/' );
		$absolute = str_starts_with( $path, '/' ) || preg_match( '#^[A-Za-z]:/#', $path ) === 1;
		if ( ! $absolute || str_contains( $path, '..' ) ) {
			return array(
				'ok'    => false,
				'error' => 'not_absolute',
			);
		}
		if ( is_file( $path ) ) {
			return array(
				'ok'    => false,
				'error' => 'not_a_directory',
			);
		}
		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			return array(
				'ok'    => false,
				'error' => 'not_creatable',
			);
		}
		$probe = $path . '/.yetisearch-write-test-' . bin2hex( random_bytes( 4 ) );
		if ( @file_put_contents( $probe, 'ok' ) === false ) {
			return array(
				'ok'    => false,
				'error' => 'not_writable',
			);
		}
		wp_delete_file( $probe );
		return array(
			'ok'    => true,
			'error' => '',
		);
	}

	/**
	 * Safe move of our own files: copy first, verify size + hash, only then
	 * delete sources. Foreign files are never touched; on any failure the
	 * remaining sources stay intact for a retry.
	 *
	 * @return array{ok: bool, moved: int, failed: list<string>}
	 */
	public function migrate( string $from, string $to ): array {
		$from   = rtrim( wp_normalize_path( $from ), '/' );
		$to     = rtrim( wp_normalize_path( $to ), '/' );
		$result = array(
			'ok'     => false,
			'moved'  => 0,
			'failed' => array(),
		);
		if ( $from === '' || $to === '' || $from === $to || ! is_dir( $from ) ) {
			$result['failed'][] = 'source';
			return $result;
		}
		if ( ! is_dir( $to ) && ! wp_mkdir_p( $to ) ) {
			$result['failed'][] = 'target';
			return $result;
		}
		foreach ( $this->ownFiles( $from ) as $name ) {
			$src = $from . '/' . $name;
			$dst = $to . '/' . $name;
			if ( ! @copy( $src, $dst ) ) {
				$result['failed'][] = $name;
				continue;
			}
			$same = filesize( $dst ) === filesize( $src )
				&& hash_file( 'sha256', $dst ) === hash_file( 'sha256', $src );
			if ( ! $same ) {
				wp_delete_file( $dst );
				$result['failed'][] = $name;
				continue;
			}
			wp_delete_file( $src );
			++$result['moved'];
		}
		$result['ok'] = $result['failed'] === array();
		return $result;
	}

	/** Our own files only: index databases plus marker-tagged protection files. */
	private function ownFiles( string $dir ): array {
		$names   = array();
		$matches = glob( $dir . '/yetisearch_*.sqlite*' );
		if ( ! is_array( $matches ) ) {
			$matches = array();
		}
		foreach ( $matches as $file ) {
			$names[] = basename( (string) $file );
		}
		foreach ( array( '.htaccess', 'web.config', 'index.php' ) as $name ) {
			$path = $dir . '/' . $name;
			if ( is_file( $path ) && str_contains( (string) file_get_contents( $path ), ServerSecurity::MARKER ) ) {
				$names[] = $name;
			}
		}
		return $names;
	}
}
