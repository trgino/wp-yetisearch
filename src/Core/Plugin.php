<?php
declare(strict_types=1);

namespace WpYetiSearch\Core;

use WpYetiSearch\Admin\AjaxHandler;
use WpYetiSearch\Admin\DashboardWidget;
use WpYetiSearch\Admin\SettingsPage;
use WpYetiSearch\Cli\YetiSearchCli;
use WpYetiSearch\Features\CacheBridge;
use WpYetiSearch\Features\DSLBridge;
use WpYetiSearch\Features\FacetBridge;
use WpYetiSearch\Features\GeoBridge;
use WpYetiSearch\Features\SemanticBridge;
use WpYetiSearch\Frontend\Assets;
use WpYetiSearch\Frontend\RestController;
use WpYetiSearch\Frontend\SearchBlock;
use WpYetiSearch\Frontend\SearchWidget;
use WpYetiSearch\Index\BulkIndexer;
use WpYetiSearch\Index\DocumentMapper;
use WpYetiSearch\Index\Indexer;
use WpYetiSearch\Index\LanguageResolver;
use WpYetiSearch\Search\QueryBridge;
use WpYetiSearch\Search\ResultNormalizer;
use WpYetiSearch\Search\SearchService;
use WpYetiSearch\Search\TurkishStemmer;
use WpYetiSearch\Storage\ServerSecurity;
use WpYetiSearch\Storage\StorageManager;
use YetiSearch\YetiSearch;

final class Plugin {

	private static ?self $instance = null;
	private ?Container $container  = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
	}

	public function boot(): void {
		$this->container = $this->createContainer();
		$this->registerHooks( $this->container );
	}

	public function activate(): void {
		$container = $this->createContainer();
		( new Lifecycle( $container ) )->activate();
	}

	public function deactivate(): void {
		$container = $this->createContainer();
		( new Lifecycle( $container ) )->deactivate();
	}

	public function container(): Container {
		return $this->container ??= $this->createContainer();
	}

	public function runHealthCheck(): void {
		$container = $this->container();
		$before    = $container->get( 'health' )->cached();
		$record    = $container->get( 'health' )->refresh( $container->get( 'storage' )->storageDir() );
		if ( ( $before['ready'] ?? true ) && ! $record['ready'] ) {
			$container->get( 'logger' )->warning( 'YetiSearch health degraded', array( 'checks' => $record['checks'] ) );
		}
	}

	private function createContainer(): Container {
		$container = new Container();

		$container->set( 'config', static fn (): Config => Config::load() );
		$container->set(
			'logger',
			static function ( Container $c ): Logger {
				$base = Logger::fromEnvironment();
				try {
					return $base->withFileLog( new FileLog( $c->get( 'storage' )->storageDir() ) );
				} catch ( \Throwable ) {
					return $base;
				}
			}
		);
		$container->set( 'health', static fn (): HealthChecker => new HealthChecker() );
		$container->set( 'security', static fn (): ServerSecurity => new ServerSecurity() );
		$container->set( 'storage', static fn ( Container $c ): StorageManager => new StorageManager( $c->get( 'config' ), $c->get( 'security' ) ) );

		$container->set(
			'yeti',
			static function ( Container $c ): ?YetiSearch {
				$health = $c->get( 'health' );
				if ( ! $health->isReady() ) {
					return null;
				}
				$config  = $c->get( 'config' );
				$storage = $c->get( 'storage' );
				$yeti    = new YetiSearch( $config->toYetiConfig( $storage->dbPath() ) );
				SemanticBridge::attachProvider( $yeti, $config ); // No HTTP on save: provider only embeds via cron/AJAX/CLI.
				TurkishStemmer::registerIfSupported(); // No-op until the library ships StemmerFactory::register().
				return $yeti;
			}
		);

		$container->set( 'mapper', static fn ( Container $c ): DocumentMapper => new DocumentMapper( $c->get( 'config' ), $c->get( 'languageResolver' ) ) );
		$container->set( 'languageResolver', static fn (): LanguageResolver => new LanguageResolver() );
		$container->set( 'indexer', static fn ( Container $c ): Indexer => new Indexer( $c->get( 'yeti' ), $c->get( 'mapper' ), $c->get( 'logger' ) ) );
		$container->set( 'bulkIndexer', static fn ( Container $c ): BulkIndexer => new BulkIndexer( $c->get( 'yeti' ), $c->get( 'mapper' ), $c->get( 'config' ), $c->get( 'logger' ) ) );
		$container->set( 'normalizer', static fn ( Container $c ): ResultNormalizer => new ResultNormalizer( $c->get( 'config' ) ) );
		$container->set( 'search', static fn ( Container $c ): SearchService => new SearchService( $c->get( 'yeti' ), $c->get( 'config' ), $c->get( 'normalizer' ), $c->get( 'languageResolver' ), $c->get( 'facetBridge' ) ) );
		$container->set( 'queryBridge', static fn ( Container $c ): QueryBridge => new QueryBridge( $c->get( 'config' ), $c->get( 'search' ), $c->get( 'logger' ), $c->get( 'languageResolver' ), $c->get( 'facetBridge' ) ) );

		$container->set( 'semanticBridge', static fn ( Container $c ): SemanticBridge => new SemanticBridge( $c->get( 'yeti' ), $c->get( 'config' ), $c->get( 'logger' ) ) );
		$container->set( 'geoBridge', static fn ( Container $c ): GeoBridge => new GeoBridge( $c->get( 'config' ), $c->get( 'health' )->isGeoReady() ) );
		$container->set( 'facetBridge', static fn ( Container $c ): FacetBridge => new FacetBridge( $c->get( 'config' ) ) );
		$container->set( 'dslBridge', static fn (): DSLBridge => new DSLBridge() );
		$container->set( 'cacheBridge', static fn ( Container $c ): CacheBridge => new CacheBridge( $c->get( 'yeti' ), $c->get( 'config' ), $c->get( 'search' ), $c->get( 'logger' ) ) );

		$container->set(
			'restController',
			static fn ( Container $c ): RestController => new RestController(
				$c->get( 'config' ),
				$c->get( 'search' ),
				$c->get( 'normalizer' ),
				$c->get( 'geoBridge' ),
				$c->get( 'facetBridge' ),
				$c->get( 'dslBridge' ),
				$c->get( 'logger' ),
				$c->get( 'languageResolver' )
			)
		);

		$container->set(
			'assets',
			static fn ( Container $c ): Assets => new Assets(
				$c->get( 'config' ),
				WPYETISEARCH_URL,
				WPYETISEARCH_VERSION,
				$c->get( 'languageResolver' )
			)
		);

		$container->set(
			'settingsPage',
			static fn ( Container $c ): SettingsPage => new SettingsPage(
				$c->get( 'config' ),
				$c->get( 'health' ),
				$c->get( 'storage' ),
				$c->get( 'security' ),
				$c->get( 'logger' ),
				$c->get( 'search' )
			)
		);

		$container->set(
			'ajaxHandler',
			static fn ( Container $c ): AjaxHandler => new AjaxHandler(
				$c->get( 'config' ),
				$c->get( 'bulkIndexer' ),
				$c->get( 'semanticBridge' ),
				$c->get( 'cacheBridge' ),
				$c->get( 'health' ),
				$c->get( 'storage' ),
				$c->get( 'security' ),
				$c->get( 'search' ),
				$c->get( 'logger' )
			)
		);

		$container->set(
			'dashboardWidget',
			static fn ( Container $c ): DashboardWidget => new DashboardWidget(
				$c->get( 'health' ),
				$c->get( 'search' )
			)
		);

		$container->set(
			'cli',
			static fn ( Container $c ): YetiSearchCli => new YetiSearchCli(
				$c->get( 'config' ),
				$c->get( 'yeti' ),
				$c->get( 'storage' ),
				$c->get( 'logger' ),
				$c->get( 'bulkIndexer' ),
				$c->get( 'health' ),
				$c->get( 'search' ),
				$c->get( 'semanticBridge' ),
				$c->get( 'cacheBridge' )
			)
		);

		return $container;
	}

	private function registerHooks( Container $container ): void {
		add_action( 'init', array( I18n::class, 'load' ) );
		add_action( HealthChecker::CRON_HOOK, array( $this, 'runHealthCheck' ) );

		$indexer = $container->get( 'indexer' );
		$indexer->register();

		$semantic = $container->get( 'semanticBridge' );
		$semantic->register(); // cron_schedules + yetisearch_embed_pending (no-op without engine).

		$queryBridge = $container->get( 'queryBridge' );
		$queryBridge->register();

		$restController = $container->get( 'restController' );
		$restController->register();

		$assets = $container->get( 'assets' );
		$assets->register();
		SearchWidget::register();
		SearchBlock::register();

		if ( is_admin() ) {
			$container->get( 'settingsPage' )->register();
			$container->get( 'ajaxHandler' )->register();
			$container->get( 'dashboardWidget' )->register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$container->get( 'cli' )->register();
		}
	}
}
