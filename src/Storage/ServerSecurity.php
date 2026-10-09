<?php
declare(strict_types=1);

namespace WpYetiSearch\Storage;

/** Protects the database directory (spec §6.6). */
final class ServerSecurity {

	public const MARKER = 'WP YetiSearch';

	public function detectServer( string $software ): string {
		$software = strtolower( $software );

		return match ( true ) {
			str_contains( $software, 'nginx' ) => 'nginx',
			str_contains( $software, 'litespeed' ) => 'litespeed',
			str_contains( $software, 'caddy' ) => 'caddy',
			str_contains( $software, 'iis' ) => 'iis',
			default => 'apache',
		};
	}

	public function currentServer(): string {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) && is_string( $_SERVER['SERVER_SOFTWARE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) )
			: '';

		return $this->detectServer( $software );
	}

	/** Writes deny-all files; files without our marker are left untouched. */
	public function writeProtectionFiles( string $dir ): bool {
		$ok = true;
		foreach ( array(
			'.htaccess'  => self::htaccess(),
			'web.config' => self::webConfig(),
			'index.php'  => self::indexPhp(),
		) as $name => $body ) {
			$path = $dir . '/' . $name;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local marker check, not a URL fetch.
			if ( is_file( $path ) && ! str_contains( (string) file_get_contents( $path ), self::MARKER ) ) {
				$ok = false;
				continue;
			}
			if ( @file_put_contents( $path, $body ) === false ) {
				$ok = false;
			}
		}
		return $ok;
	}

	public function ruleSnippet( string $server, string $dirUrl ): string {
		$path = wp_parse_url( $dirUrl, PHP_URL_PATH );
		$path = '/' . trim( is_string( $path ) ? $path : '', '/' );

		return match ( $server ) {
			'nginx' => "location ^~ {$path}/ {\n    deny all;\n    return 403;\n}",
			'caddy' => "@yetisearch path {$path}/*\nrespond @yetisearch 403",
			default => '',
		};
	}

	/** Requests a token file over HTTP: 200 + token means the directory leaks. */
	public function probe( string $dir, ?string $dirUrl ): string {
		if ( $dirUrl === null ) {
			return 'safe';
		}
		$token = bin2hex( random_bytes( 16 ) );
		$name  = 'yetisearch-probe-' . bin2hex( random_bytes( 6 ) ) . '.sqlite';
		$file  = $dir . '/' . $name;
		if ( @file_put_contents( $file, $token ) === false ) {
			return 'unknown';
		}

		try {
			$response = wp_remote_get(
				rtrim( $dirUrl, '/' ) . '/' . $name,
				array(
					'timeout'     => 5,
					'redirection' => 0,
					'sslverify'   => false,
				)
			);
			if ( is_wp_error( $response ) ) {
				return 'unknown';
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );

			return $code === 200 && str_contains( $body, $token ) ? 'leak' : 'safe';
		} finally {
			wp_delete_file( $file );
		}
	}

	private static function htaccess(): string {
		return <<<'HTACCESS'
# BEGIN WP YetiSearch
# Deny every request to the search database directory (.sqlite, -wal, -shm).
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
# END WP YetiSearch

HTACCESS;
	}

	private static function webConfig(): string {
		return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!-- WP YetiSearch: deny every request to this directory. -->
<configuration>
  <system.webServer>
    <security>
      <requestFiltering>
        <fileExtensions allowUnlisted="false" />
      </requestFiltering>
      <authorization>
        <remove users="*" roles="" verbs="" />
        <add accessType="Deny" users="*" />
      </authorization>
    </security>
  </system.webServer>
</configuration>

XML;
	}

	private static function indexPhp(): string {
		return "<?php\n// WP YetiSearch: no directory listing.\nhttp_response_code(403);\nexit;\n";
	}
}
