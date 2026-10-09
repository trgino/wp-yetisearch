<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit;

use Brain\Monkey\Functions;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Core\HealthChecker;
use WpYetiSearch\Features\SemanticBridge;
use WpYetiSearch\Storage\StorageManager;

if (!defined('WP_UNINSTALL_PLUGIN')) {
    define('WP_UNINSTALL_PLUGIN', 'wp-yetisearch/wp-yetisearch.php');
}

final class UninstallTest extends UnitTestCase
{
    /** @var array<string, mixed> */
    private array $options = [];
    private string $uploads;
    /** @var list<string> */
    private array $clearedHooks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploads = $this->tempDir('yetisearch-uninstall-');
        $this->options = [
            Config::OPTION_KEY => ['master_enabled' => true],
            StorageManager::HASH_OPTION => 'abc123def456',
            HealthChecker::OPTION_KEY => ['ready' => true],
            Config::NEEDS_REINDEX_OPTION => false,
            'other_plugin_option' => 'keep me',
        ];
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
        Functions\when('wp_clear_scheduled_hook')->alias(function (string $hook): bool {
            $this->clearedHooks[] = $hook;
            return true;
        });
        Functions\when('plugin_basename')->justReturn('wp-yetisearch/wp-yetisearch.php');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->uploads);
        parent::tearDown();
    }

    public function testDeletesAllYetisearchOptions(): void
    {
        $dir = $this->uploads . '/yetisearch';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/.htaccess', '# test');
        file_put_contents($dir . '/yetisearch_abc123def456.sqlite', 'test');

        require dirname(__DIR__, 2) . '/uninstall.php';

        self::assertArrayNotHasKey(Config::OPTION_KEY, $this->options);
        self::assertArrayNotHasKey(StorageManager::HASH_OPTION, $this->options);
        self::assertArrayNotHasKey(HealthChecker::OPTION_KEY, $this->options);
        self::assertArrayNotHasKey(Config::NEEDS_REINDEX_OPTION, $this->options);
        self::assertArrayHasKey('other_plugin_option', $this->options, 'unrelated options must survive');
    }

    public function testClearsCron(): void
    {
        $dir = $this->uploads . '/yetisearch';
        mkdir($dir, 0777, true);

        require dirname(__DIR__, 2) . '/uninstall.php';

        // when() shadows expect(), so the hook call is recorded and asserted instead.
        self::assertContains(SemanticBridge::CRON_HOOK, $this->clearedHooks);
    }

    public function testDeletesDefaultStorageDirectoryCompletely(): void
    {
        $dir = $this->uploads . '/yetisearch';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/.htaccess', '# test');
        file_put_contents($dir . '/index.php', '<?php // test');
        file_put_contents($dir . '/yetisearch_abc123def456.sqlite', 'test');
        file_put_contents($dir . '/yetisearch_abc123def456.sqlite-wal', 'test');
        file_put_contents($dir . '/yetisearch_abc123def456.sqlite-shm', 'test');

        require dirname(__DIR__, 2) . '/uninstall.php';

        self::assertDirectoryDoesNotExist($dir, 'default storage dir must be deleted completely');
    }

    public function testCustomDirectoryRemovesOnlyYetisearchFiles(): void
    {
        $custom = $this->tempDir('yetisearch-custom-');
        file_put_contents($custom . '/.htaccess', '# test');
        file_put_contents($custom . '/index.php', '<?php // test');
        file_put_contents($custom . '/yetisearch_abc123def456.sqlite', 'test');
        file_put_contents($custom . '/yetisearch_abc123def456.sqlite-wal', 'test');
        file_put_contents($custom . '/important-data.txt', 'keep me');

        $this->options[Config::OPTION_KEY] = ['db_custom_dir' => $custom];

        require dirname(__DIR__, 2) . '/uninstall.php';

        self::assertDirectoryExists($custom, 'custom dir itself must survive');
        self::assertFileDoesNotExist($custom . '/yetisearch_abc123def456.sqlite');
        self::assertFileDoesNotExist($custom . '/yetisearch_abc123def456.sqlite-wal');
        self::assertFileExists($custom . '/important-data.txt', 'unrelated files must survive');

        $this->removeDir($custom);
    }
}
