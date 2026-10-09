<?php
declare(strict_types=1);

namespace WpYetiSearch\Admin;

use Psr\Log\LoggerInterface;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Core\HealthChecker;
use WpYetiSearch\Core\SettingsSchema;
use WpYetiSearch\Features\SemanticBridge;
use WpYetiSearch\Search\SearchService;
use WpYetiSearch\Storage\ServerSecurity;
use WpYetiSearch\Storage\StorageManager;

/** Schema-driven settings page: 9 tabs, per-tab forms (spec §7). */
final class SettingsPage {

	public const MENU_SLUG = 'yetisearch';
	public const NONCE     = 'yetisearch-save';

	public function __construct(
		private Config $config,
		private HealthChecker $health,
		private StorageManager $storage,
		private ServerSecurity $security,
		private LoggerInterface $logger,
		private ?SearchService $search = null
	) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'handleSave' ) );
		add_action( 'admin_notices', array( $this, 'saveNotice' ) );
		add_action( 'admin_notices', array( $this, 'reindexNotice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function menu(): void {
		add_menu_page(
			__( 'WP YetiSearch', 'wp-yetisearch' ),
			__( 'WP YetiSearch', 'wp-yetisearch' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-search',
			99
		);
	}

	public function handleSave(): void {
		// Capability first: do not leak nonce validity to unauthorized users.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// wp_nonce_field() renders `_wpnonce`, not a field named after the action.
		if ( ! isset( $_POST['_wpnonce'] ) || ! check_admin_referer( self::NONCE ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'general';
		if ( ! in_array( $tab, SettingsSchema::TABS, true ) ) {
			$tab = 'general';
		}
		/** @var array<string, mixed> $input */
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- unslashed here; per-type sanitization happens in SettingsSchema::sanitize().
		$input  = isset( $_POST['yetisearch'] ) && is_array( $_POST['yetisearch'] ) ? wp_unslash( $_POST['yetisearch'] ) : array();
		$result = $this->save( $tab, $input );
		if ( ! $result['ok'] || isset( $result['warning'] ) ) {
			set_transient( 'yetisearch_save_notice', $result, MINUTE_IN_SECONDS );
		}
	}

	public function saveNotice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$notice = get_transient( 'yetisearch_save_notice' );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( 'yetisearch_save_notice' );
		$code    = (string) ( $notice['error'] ?? $notice['warning'] ?? '' );
		$message = match ( $code ) {
			'not_ready' => __( 'Search engine could not be enabled: health checks are not passing.', 'wp-yetisearch' ),
			'bad_storage_dir' => __( 'Custom directory is not usable; settings were not saved.', 'wp-yetisearch' ),
			'migration_failed' => __( 'Settings saved, but some index files could not be moved. Re-index to rebuild them.', 'wp-yetisearch' ),
			default => '',
		};
		if ( $message === '' ) {
			return;
		}
		$class = isset( $notice['warning'] ) ? 'notice-warning' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{ok: bool, error?: string, warning?: string}
	 */
	public function save( string $tab, array $input ): array {
		$before = $this->config->all();
		$after  = SettingsSchema::sanitize( $input, $before, $tab );

		if ( $tab === 'general' && ! empty( $after['master_enabled'] ) && ! $this->health->isReady() ) {
			$after['master_enabled'] = false;
			update_option( Config::OPTION_KEY, $after );
			return array(
				'ok'    => false,
				'error' => 'not_ready',
			);
		}

		$oldDir = (string) ( $before['db_custom_dir'] ?? '' );
		$newDir = (string) ( $after['db_custom_dir'] ?? '' );
		if ( $tab === 'general' && $oldDir !== $newDir ) {
			$check = ( new StorageManager( new Config( $after ), $this->security ) )->checkDir( $newDir );
			if ( ! $check['ok'] ) {
				return array(
					'ok'    => false,
					'error' => 'bad_storage_dir',
				);
			}
		}

		update_option( Config::OPTION_KEY, $after );

		if ( SettingsSchema::requiresReindex( $before, $after ) ) {
			update_option( Config::NEEDS_REINDEX_OPTION, true );
		}

		if ( $tab === 'general' && ( $before['db_custom_dir'] ?? '' ) !== ( $after['db_custom_dir'] ?? '' ) ) {
			$oldStorage = new StorageManager( new Config( $before ), $this->security );
			$storage    = new StorageManager( new Config( $after ), $this->security );
			if ( ! $storage->ensureProtected() ) {
				$this->logger->warning( 'Storage protection files could not be written', array( 'dir' => $storage->storageDir() ) );
			}
			$migration = $storage->migrate( $oldStorage->storageDir(), $storage->storageDir() );
			if ( ! $migration['ok'] ) {
				$this->logger->error( 'Storage migration failed', array( 'failed' => $migration['failed'] ) );
				$this->health->refresh( $storage->storageDir() );
				return array(
					'ok'      => true,
					'warning' => 'migration_failed',
				);
			}
			$this->health->refresh( $storage->storageDir() );
		}

		if ( $tab === 'semantic' ) {
			SemanticBridge::syncSchedule( new Config( $after ) );
		}

		return array( 'ok' => true );
	}

	public function reindexNotice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! (bool) get_option( Config::NEEDS_REINDEX_OPTION, false ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>'
			. esc_html__( 'WP YetiSearch settings changed the index schema. Please re-index on the Maintenance tab.', 'wp-yetisearch' )
			. '</p></div>';
	}

	public function assets( string $hook ): void {
		if ( $hook !== 'toplevel_page_' . self::MENU_SLUG ) {
			return;
		}
		wp_enqueue_script( 'wp-yetisearch-admin', WPYETISEARCH_URL . 'assets/js/admin.js', array(), WPYETISEARCH_VERSION, true );
		wp_add_inline_script(
			'wp-yetisearch-admin',
			'window.wpYetiSearchAdmin = ' . wp_json_encode( $this->scriptData() ) . ';',
			'before'
		);
		wp_enqueue_style( 'wp-yetisearch-admin', WPYETISEARCH_URL . 'assets/css/admin.css', array(), WPYETISEARCH_VERSION );
	}

	/** Server-rendered (translated) strings for assets/js/admin.js. */
	public function scriptData(): array {
		return array(
			'nonce'        => wp_create_nonce( AjaxHandler::NONCE ),
			'restEndpoint' => esc_url_raw( rest_url( 'yetisearch/v1/search' ) ),
			'i18n'         => array(
				'done'            => __( 'done', 'wp-yetisearch' ),
				'cleared'         => __( 'cleared', 'wp-yetisearch' ),
				'warmed'          => __( 'warmed', 'wp-yetisearch' ),
				'refreshed'       => __( 'refreshed', 'wp-yetisearch' ),
				'probed'          => __( 'probed', 'wp-yetisearch' ),
				'calibrated'      => __( 'calibrated', 'wp-yetisearch' ),
				'requestFailed'   => __( 'request failed', 'wp-yetisearch' ),
				'dirOk'           => __( 'Directory is usable.', 'wp-yetisearch' ),
				'dirInvalid'      => __( 'Enter an absolute path without "..".', 'wp-yetisearch' ),
				'dirNotDirectory' => __( 'Path exists but is not a directory.', 'wp-yetisearch' ),
				'dirNotCreatable' => __( 'Directory cannot be created here.', 'wp-yetisearch' ),
				'dirNotWritable'  => __( 'Directory is not writable.', 'wp-yetisearch' ),
				'previewEmpty'    => __( 'No results found.', 'wp-yetisearch' ),
			),
		);
	}

	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch, sanitized + allow-listed below; writes go through handleSave().
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'general';
		if ( ! in_array( $tab, SettingsSchema::TABS, true ) ) {
			$tab = 'general';
		}
		$config  = Config::load();
		$health  = $this->health->cached();
		$ready   = $this->health->isReady();
		$dbPath  = $this->storage->dbPath();
		$dirUrl  = $this->storage->dirUrl();
		$server  = $this->security->currentServer();
		$snippet = $dirUrl !== null ? $this->security->ruleSnippet( $server, $dirUrl ) : '';
		$stats   = $this->search !== null ? $this->search->indexStats() : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display value, no state change.
		$docCount        = isset( $stats['documents'] ) ? (int) $stats['documents'] : 0;
		$lastReindex     = (int) get_option( 'yetisearch_last_reindex', 0 );
		$checklistHidden = (bool) get_user_meta( get_current_user_id(), 'yetisearch_hide_checklist', true );
		require __DIR__ . '/Views/layout.php';
	}
}
