<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Core;

use Brain\Monkey\Functions;
use WpYetiSearch\Core\Container;
use WpYetiSearch\Core\Plugin;
use WpYetiSearch\Tests\Unit\UnitTestCase;

final class PluginTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_upload_dir')->justReturn(['basedir' => $this->tempDir('yetisearch-plugin-'), 'baseurl' => 'https://example.test/wp-content/uploads']);
        Functions\when('site_url')->justReturn('https://example.test');
        Functions\when('wp_mkdir_p')->alias(static fn (string $d): bool => is_dir($d) || mkdir($d, 0777, true));
        Functions\when('get_option')->justReturn(false);
        Functions\when('update_option')->justReturn(true);
        Functions\when('load_plugin_textdomain')->justReturn(true);
        Functions\when('plugin_basename')->justReturn('wp-yetisearch/wp-yetisearch.php');
        Functions\when('is_admin')->justReturn(false);
    }

    public function testBootSetsUpContainerAndRegistersHooks(): void
    {
        $plugin = Plugin::instance();
        $plugin->boot();

        self::assertInstanceOf(Container::class, $plugin->container());
        self::assertNotFalse(has_action('init'));
    }

    public function testContainerSharesSingleInstance(): void
    {
        $plugin = Plugin::instance();
        $plugin->boot();

        self::assertSame($plugin->container(), $plugin->container());
    }

    public function testContainerResolvesConfig(): void
    {
        $plugin = Plugin::instance();
        $plugin->boot();

        self::assertInstanceOf(\WpYetiSearch\Core\Config::class, $plugin->container()->get('config'));
    }

    public function testContainerResolvesLogger(): void
    {
        $plugin = Plugin::instance();
        $plugin->boot();

        self::assertInstanceOf(\WpYetiSearch\Core\Logger::class, $plugin->container()->get('logger'));
    }

    public function testContainerResolvesStorage(): void
    {
        $plugin = Plugin::instance();
        $plugin->boot();

        self::assertInstanceOf(\WpYetiSearch\Storage\StorageManager::class, $plugin->container()->get('storage'));
    }

    public function testContainerResolvesHealthChecker(): void
    {
        $plugin = Plugin::instance();
        $plugin->boot();

        self::assertInstanceOf(\WpYetiSearch\Core\HealthChecker::class, $plugin->container()->get('health'));
    }

    public function testContainerResolvesSearchService(): void
    {
        $plugin = Plugin::instance();
        $plugin->boot();

        self::assertInstanceOf(\WpYetiSearch\Search\SearchService::class, $plugin->container()->get('search'));
    }

    public function testContainerResolvesQueryBridge(): void
    {
        $plugin = Plugin::instance();
        $plugin->boot();

        self::assertInstanceOf(\WpYetiSearch\Search\QueryBridge::class, $plugin->container()->get('queryBridge'));
    }

    public function testContainerResolvesIndexer(): void
    {
        $plugin = Plugin::instance();
        $plugin->boot();

        self::assertInstanceOf(\WpYetiSearch\Index\Indexer::class, $plugin->container()->get('indexer'));
    }

    public function testContainerResolvesBulkIndexer(): void
    {
        $plugin = Plugin::instance();
        $plugin->boot();

        self::assertInstanceOf(\WpYetiSearch\Index\BulkIndexer::class, $plugin->container()->get('bulkIndexer'));
    }

    public function testContainerResolvesResultNormalizer(): void
    {
        $plugin = Plugin::instance();
        $plugin->boot();

        self::assertInstanceOf(\WpYetiSearch\Search\ResultNormalizer::class, $plugin->container()->get('normalizer'));
    }

    public function testContainerResolvesDocumentMapper(): void
    {
        $plugin = Plugin::instance();
        $plugin->boot();

        self::assertInstanceOf(\WpYetiSearch\Index\DocumentMapper::class, $plugin->container()->get('mapper'));
    }

    public function testContainerResolvesSemanticBridge(): void
    {
        $plugin = Plugin::instance();
        $plugin->boot();

        self::assertInstanceOf(\WpYetiSearch\Features\SemanticBridge::class, $plugin->container()->get('semanticBridge'));
    }

    public function testContainerResolvesRestController(): void
    {
        $plugin = Plugin::instance();
        $plugin->boot();

        self::assertInstanceOf(\WpYetiSearch\Frontend\RestController::class, $plugin->container()->get('restController'));
    }

    public function testContainerResolvesAssets(): void
    {
        $plugin = Plugin::instance();
        $plugin->boot();

        self::assertInstanceOf(\WpYetiSearch\Frontend\Assets::class, $plugin->container()->get('assets'));
    }

    public function testActivateDelegatesToLifecycle(): void
    {
        Functions\when('wp_upload_dir')->justReturn(['basedir' => $this->tempDir('yetisearch-act-'), 'baseurl' => 'https://example.test/wp-content/uploads']);
        Functions\when('wp_mkdir_p')->alias(static fn (string $d): bool => is_dir($d) || mkdir($d, 0777, true));
        Functions\when('get_option')->justReturn(false);
        Functions\when('update_option')->justReturn(true);
        Functions\when('wp_schedule_event')->justReturn(true);
        Functions\when('wp_next_scheduled')->justReturn(false);

        $plugin = Plugin::instance();
        $plugin->activate();

        // If we got here without exception, the delegation worked.
        self::assertTrue(true);
    }

    public function testDeactivateDelegatesToLifecycle(): void
    {
        Functions\when('wp_clear_scheduled_hook')->justReturn(true);

        $plugin = Plugin::instance();
        $plugin->deactivate();

        self::assertTrue(true);
    }

    /** @return array{ready: bool, checks: array<string, array{ok: bool, value: string}>, checked_at: int, php: string} */
    private static function readyRecord(): array
    {
        $checks = [];
        foreach (['php_version', 'pdo_sqlite', 'sqlite_version', 'sqlite_recency', 'fts5_support', 'rtree_support', 'directory_writable'] as $key) {
            $checks[$key] = ['ok' => true, 'value' => 'ok'];
        }
        return ['ready' => true, 'checks' => $checks, 'checked_at' => time(), 'php' => PHP_VERSION];
    }

    public function testContainerBuildsEngineWhenHealthIsReady(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }
        Functions\when('get_option')->alias(static fn (string $k, mixed $d = false): mixed => match ($k) {
            \WpYetiSearch\Core\Config::OPTION_KEY => [],
            \WpYetiSearch\Core\HealthChecker::OPTION_KEY => self::readyRecord(),
            default => $d,
        });

        $plugin = Plugin::instance();
        $plugin->boot();

        self::assertInstanceOf(\YetiSearch\YetiSearch::class, $plugin->container()->get('yeti'));
    }

    public function testHealthDegradationIsLogged(): void
    {
        $lines = [];
        $options = [
            \WpYetiSearch\Core\Config::OPTION_KEY => [],
            \WpYetiSearch\Core\HealthChecker::OPTION_KEY => self::readyRecord(),
        ];
        Functions\when('get_option')->alias(static fn (string $k, mixed $d = false): mixed => $options[$k] ?? $d);

        $plugin = Plugin::instance();
        $plugin->boot();
        // NOTE: the writer closure must be created in this scope: a closure
        // built inside the factory would bind the factory's copy of $lines.
        $writer = static function (string $line) use (&$lines): void {
            $lines[] = $line;
        };
        $plugin->container()->set('logger', static fn (): \WpYetiSearch\Core\Logger => new \WpYetiSearch\Core\Logger(
            \Psr\Log\LogLevel::DEBUG,
            $writer
        ));
        $file = (string) tempnam(sys_get_temp_dir(), 'ys');
        $plugin->container()->set('storage', static fn (): \WpYetiSearch\Storage\StorageManager => new \WpYetiSearch\Storage\StorageManager(
            new \WpYetiSearch\Core\Config(['db_custom_dir' => $file]),
            new \WpYetiSearch\Storage\ServerSecurity()
        ));

        $plugin->runHealthCheck();
        unlink($file);

        self::assertNotSame([], $lines);
        self::assertStringContainsString('health degraded', $lines[0]);
    }
}
