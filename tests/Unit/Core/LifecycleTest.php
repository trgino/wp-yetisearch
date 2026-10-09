<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Core;

use Brain\Monkey\Functions;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Core\Container;
use WpYetiSearch\Core\HealthChecker;
use WpYetiSearch\Core\Lifecycle;
use WpYetiSearch\Core\Logger;
use WpYetiSearch\Features\SemanticBridge;
use WpYetiSearch\Storage\ServerSecurity;
use WpYetiSearch\Storage\StorageManager;
use WpYetiSearch\Tests\Unit\UnitTestCase;

final class LifecycleTest extends UnitTestCase
{
    /** @var array<string, mixed> */
    private array $options = [];
    private string $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploads = $this->tempDir('yetisearch-lifecycle-');
        $this->options = [];
        Functions\when('wp_upload_dir')->justReturn(['basedir' => $this->uploads, 'baseurl' => 'https://example.test/wp-content/uploads']);
        Functions\when('site_url')->justReturn('https://example.test');
        Functions\when('wp_mkdir_p')->alias(static fn (string $d): bool => is_dir($d) || mkdir($d, 0777, true));
        Functions\when('get_option')->alias(fn (string $k, mixed $d = false): mixed => $this->options[$k] ?? $d);
        Functions\when('update_option')->alias(function (string $k, mixed $v): bool {
            $this->options[$k] = $v;
            return true;
        });
        Functions\when('delete_option')->alias(function (string $k): bool {
            unset($this->options[$k]);
            return true;
        });
        Functions\when('wp_next_scheduled')->justReturn(true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->uploads);
        parent::tearDown();
    }

    private function container(array $settings = []): Container
    {
        $container = new Container();
        $container->set('config', fn () => new Config($settings));
        $container->set('logger', fn () => new Logger());
        $container->set('health', fn () => new HealthChecker());
        $container->set('security', fn () => new ServerSecurity());
        $container->set('storage', fn (Container $c) => new StorageManager($c->get('config'), $c->get('security')));
        return $container;
    }

    public function testActivateCreatesStorageDirAndWritesProtectionFiles(): void
    {
        $container = $this->container();
        $storage = $container->get('storage');

        (new Lifecycle($container))->activate();

        self::assertDirectoryExists($storage->storageDir());
        self::assertFileExists($storage->storageDir() . '/.htaccess');
        self::assertFileExists($storage->storageDir() . '/index.php');
    }

    public function testActivateGeneratesDbHash(): void
    {
        $container = $this->container();

        (new Lifecycle($container))->activate();

        $hash = $container->get('storage')->dbHash();
        self::assertMatchesRegularExpression('/^[a-f0-9]{12}$/', $hash);
        self::assertSame($hash, $this->options[StorageManager::HASH_OPTION]);
    }

    public function testActivateRunsHealthCheckAndCachesIt(): void
    {
        $container = $this->container();

        (new Lifecycle($container))->activate();

        self::assertArrayHasKey(HealthChecker::OPTION_KEY, $this->options);
        $record = $this->options[HealthChecker::OPTION_KEY];
        self::assertArrayHasKey('ready', $record);
        self::assertArrayHasKey('checks', $record);
        self::assertArrayHasKey('php', $record);
    }

    public function testActivateSchedulesSemanticCronWhenEnabled(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\expect('wp_schedule_event')
            ->once()
            ->with(\Mockery::type('int'), SemanticBridge::CRON_SCHEDULE, SemanticBridge::CRON_HOOK);
        Functions\expect('wp_schedule_event')
            ->once()
            ->with(\Mockery::type('int'), 'hourly', \WpYetiSearch\Core\HealthChecker::CRON_HOOK);

        $container = $this->container(['semantic_enabled' => true, 'semantic_cron' => true]);
        (new Lifecycle($container))->activate();
    }

    public function testActivateSchedulesHourlyHealthCheck(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\expect('wp_schedule_event')
            ->once()
            ->with(\Mockery::type('int'), 'hourly', \WpYetiSearch\Core\HealthChecker::CRON_HOOK);

        $container = $this->container(['semantic_enabled' => false, 'semantic_cron' => false]);
        (new Lifecycle($container))->activate();
    }

    public function testActivateDoesNotScheduleCronWhenDisabled(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\expect('wp_schedule_event')
            ->once()
            ->with(\Mockery::type('int'), 'hourly', HealthChecker::CRON_HOOK);

        $container = $this->container(['semantic_enabled' => false, 'semantic_cron' => false]);
        (new Lifecycle($container))->activate();
    }

    public function testDeactivateClearsCron(): void
    {
        Functions\expect('wp_clear_scheduled_hook')
            ->once()
            ->with(SemanticBridge::CRON_HOOK);
        Functions\expect('wp_clear_scheduled_hook')
            ->once()
            ->with(HealthChecker::CRON_HOOK);

        $container = $this->container();
        (new Lifecycle($container))->deactivate();
    }

    public function testDeactivateDoesNotDeleteData(): void
    {
        Functions\when('wp_clear_scheduled_hook')->justReturn(true);

        $container = $this->container();
        $storage = $container->get('storage');
        (new Lifecycle($container))->activate();

        self::assertDirectoryExists($storage->storageDir());

        (new Lifecycle($container))->deactivate();

        self::assertDirectoryExists($storage->storageDir(), 'deactivation must not delete data');
        self::assertArrayHasKey(StorageManager::HASH_OPTION, $this->options, 'deactivation must not delete options');
    }
}
