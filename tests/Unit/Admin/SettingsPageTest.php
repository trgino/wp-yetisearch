<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use WpYetiSearch\Admin\SettingsPage;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Core\HealthChecker;
use WpYetiSearch\Features\SemanticBridge;
use WpYetiSearch\Storage\ServerSecurity;
use WpYetiSearch\Storage\StorageManager;
use WpYetiSearch\Tests\Support\ArrayLogger;
use WpYetiSearch\Tests\Unit\UnitTestCase;

final class SettingsPageTest extends UnitTestCase
{
    /** @var array<string, mixed> */
    private array $options = [];
    private string $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploads = $this->tempDir('yetisearch-admin-');
        $this->options = [Config::OPTION_KEY => []];
        Functions\when('wp_upload_dir')->justReturn(['basedir' => $this->uploads, 'baseurl' => 'https://example.test/wp-content/uploads']);
        Functions\when('site_url')->justReturn('https://example.test');
        Functions\when('wp_mkdir_p')->alias(static fn (string $d): bool => is_dir($d) || mkdir($d, 0777, true));
        Functions\when('get_option')->alias(fn (string $k, mixed $d = false): mixed => $this->options[$k] ?? $d);
        Functions\when('update_option')->alias(function (string $k, mixed $v): bool {
            $this->options[$k] = $v;
            return true;
        });
        Functions\when('wp_next_scheduled')->justReturn(false);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->uploads);
        parent::tearDown();
    }

    private function page(array $settings = []): SettingsPage
    {
        $this->options[Config::OPTION_KEY] = $settings;
        $config = new Config($settings);
        return new SettingsPage($config, new HealthChecker(), new StorageManager($config, new ServerSecurity()), new ServerSecurity(), new ArrayLogger());
    }

    public function testMasterToggleBlockedWhenNotReady(): void
    {
        // No health record cached → not ready → master stays off with an error.
        $result = $this->page()->save('general', ['master_enabled' => '1']);

        self::assertFalse($result['ok']);
        self::assertFalse((bool) ($this->options[Config::OPTION_KEY]['master_enabled'] ?? false));
    }

    public function testSanitizeOnlyTouchesSubmittedTab(): void
    {
        $result = $this->page(['enable_fuzzy' => true])->save('general', ['master_enabled' => '0']);

        self::assertTrue($result['ok']);
        self::assertTrue($this->options[Config::OPTION_KEY]['enable_fuzzy'], 'relevance tab must survive a general-tab save');
    }

    public function testSchemaChangeSetsReindexFlag(): void
    {
        $this->page(['chunk_size' => 1000])->save('content', ['chunk_size' => '2000', 'chunk_overlap' => '100', 'indexed_post_types' => ['post']]);

        self::assertTrue((bool) ($this->options[Config::NEEDS_REINDEX_OPTION] ?? false));
    }

    public function testWeightChangeDoesNotSetReindexFlag(): void
    {
        // Real forms submit every field of the tab; unchecked boxes arrive as '0'.
        $input = [];
        foreach (\WpYetiSearch\Core\SettingsSchema::fieldsForTab('relevance') as $key => $field) {
            $default = \WpYetiSearch\Core\SettingsSchema::defaults()[$key];
            $input[$key] = match ($field['type']) {
                'bool' => $default ? '1' : '0',
                'multiselect' => $default,
                'list', 'number_list', 'map' => '',
                default => is_scalar($default) ? (string) $default : $default,
            };
        }
        $input['weight_title'] = '5';

        $this->page()->save('relevance', $input);

        self::assertFalse((bool) ($this->options[Config::NEEDS_REINDEX_OPTION] ?? false));
    }

    public function testApiKeyBlankKeepsExisting(): void
    {
        $this->page(['semantic_api_key' => 'sk-live'])->save('semantic', ['semantic_api_key' => '']);

        self::assertSame('sk-live', $this->options[Config::OPTION_KEY]['semantic_api_key']);
    }

    public function testHandleSavePersistsGeneralTabThroughPost(): void
    {        // Regression: handleSave must read `_wpnonce` (what wp_nonce_field renders),
        // not a field named after the action — otherwise saves silently never persist.
        $this->options[HealthChecker::OPTION_KEY] = [
            'ready' => true, 'checks' => [], 'checked_at' => time(), 'php' => PHP_VERSION,
        ];
        \Brain\Monkey\Functions\when('check_admin_referer')->justReturn(1);
        \Brain\Monkey\Functions\when('current_user_can')->justReturn(true);
        $_POST['_wpnonce'] = 'valid-nonce';
        $_POST['yetisearch'] = ['master_enabled' => '1'];
        $_GET['tab'] = 'general';

        try {
            $this->page()->handleSave();
        } finally {
            unset($_POST['_wpnonce'], $_POST['yetisearch'], $_GET['tab']);
        }

        self::assertTrue((bool) ($this->options[Config::OPTION_KEY]['master_enabled'] ?? false));
    }
    public function testBadCustomDirBlocksSave(): void
    {
        $file = $this->uploads . '/afile';
        file_put_contents($file, 'x');

        $result = $this->page()->save('general', ['db_custom_dir' => $file]);

        self::assertFalse($result['ok']);
        self::assertSame('bad_storage_dir', $result['error']);
        self::assertSame([], $this->options[Config::OPTION_KEY] ?? [], 'nothing is persisted');
    }

    public function testDirChangeMigratesFiles(): void
    {
        $old = $this->uploads . '/old';
        $new = $this->uploads . '/new';
        mkdir($old, 0777, true);
        file_put_contents($old . '/yetisearch_abc123.sqlite', 'data');

        $result = $this->page(['db_custom_dir' => $old])->save('general', ['db_custom_dir' => $new]);

        self::assertTrue($result['ok']);
        self::assertSame($new, $this->options[Config::OPTION_KEY]['db_custom_dir']);
        self::assertFileExists($new . '/yetisearch_abc123.sqlite');
        self::assertFileDoesNotExist($old . '/yetisearch_abc123.sqlite');
    }

    public function testFailedMigrationWarnsButKeepsSettings(): void
    {
        $old = $this->uploads . '/old2';
        mkdir($old, 0777, true);
        file_put_contents($old . '/yetisearch_abc123.sqlite', 'data');
        $blocked = $this->uploads . '/afile';
        file_put_contents($blocked, 'x');

        $result = $this->page(['db_custom_dir' => $old])->save('general', ['db_custom_dir' => $blocked]);

        self::assertFalse($result['ok']);
        self::assertSame('bad_storage_dir', $result['error']);
        self::assertFileExists($old . '/yetisearch_abc123.sqlite');
    }

    public function testHandleSaveStoresNoticeOnBlockedSave(): void
    {
        $stored = [];
        \Brain\Monkey\Functions\when('check_admin_referer')->justReturn(1);
        \Brain\Monkey\Functions\when('current_user_can')->justReturn(true);
        \Brain\Monkey\Functions\when('set_transient')->alias(function (string $k, mixed $v) use (&$stored): bool {
            $stored[$k] = $v;
            return true;
        });
        $file = $this->uploads . '/afile';
        file_put_contents($file, 'x');
        $_POST['_wpnonce'] = 'valid-nonce';
        $_POST['yetisearch'] = ['db_custom_dir' => $file];
        $_GET['tab'] = 'general';

        try {
            $this->page()->handleSave();
        } finally {
            unset($_POST['_wpnonce'], $_POST['yetisearch'], $_GET['tab']);
        }

        self::assertSame('bad_storage_dir', $stored['yetisearch_save_notice']['error'] ?? null);
    }

    public function testSaveNoticeRendersAndClears(): void
    {
        \Brain\Monkey\Functions\when('current_user_can')->justReturn(true);
        \Brain\Monkey\Functions\when('get_transient')->justReturn(['ok' => false, 'error' => 'bad_storage_dir']);
        \Brain\Monkey\Functions\expect('delete_transient')->once()->with('yetisearch_save_notice');

        $this->expectOutputRegex('/not usable/');
        $this->page()->saveNotice();
    }

    public function testScriptDataExposesTranslatedStatusStrings(): void
    {
        \Brain\Monkey\Functions\when('wp_create_nonce')->justReturn('test-nonce');
        \Brain\Monkey\Functions\when('rest_url')->justReturn('https://example.test/wp-json/yetisearch/v1/search');

        $data = $this->page()->scriptData();

        self::assertSame('test-nonce', $data['nonce']);
        foreach (['done', 'cleared', 'warmed', 'refreshed', 'probed', 'calibrated', 'requestFailed', 'dirOk', 'dirInvalid', 'dirNotDirectory', 'dirNotCreatable', 'dirNotWritable'] as $key) {
            self::assertArrayHasKey($key, $data['i18n'], $key);
            self::assertNotSame('', $data['i18n'][$key]);
        }
    }

    public function testHandleSaveIgnoresUnauthorizedOrNoncedRequests(): void
    {
        \Brain\Monkey\Functions\when('current_user_can')->justReturn(false);
        \Brain\Monkey\Functions\expect('check_admin_referer')->never();
        \Brain\Monkey\Functions\expect('set_transient')->never();

        $this->page()->handleSave();

        \Brain\Monkey\Functions\when('current_user_can')->justReturn(true);
        $_POST['yetisearch'] = ['master_enabled' => '1'];
        try {
            $this->page()->handleSave();
        } finally {
            unset($_POST['yetisearch']);
        }

        self::assertSame([], $this->options[Config::OPTION_KEY], 'no nonce means no save');
    }

    public function testHandleSaveFallsBackToGeneralForUnknownTab(): void
    {
        \Brain\Monkey\Functions\when('check_admin_referer')->justReturn(1);
        \Brain\Monkey\Functions\when('current_user_can')->justReturn(true);
        $_POST['_wpnonce'] = 'valid-nonce';
        $_POST['yetisearch'] = ['master_enabled' => '0'];
        $_GET['tab'] = 'nope';
        try {
            $this->page()->handleSave();
        } finally {
            unset($_POST['_wpnonce'], $_POST['yetisearch'], $_GET['tab']);
        }

        self::assertFalse((bool) ($this->options[Config::OPTION_KEY]['master_enabled'] ?? true));
    }

    public function testSaveNoticeMessages(): void
    {
        \Brain\Monkey\Functions\when('current_user_can')->justReturn(true);
        \Brain\Monkey\Functions\expect('delete_transient')->twice()->with('yetisearch_save_notice');
        $page = $this->page();

        \Brain\Monkey\Functions\when('get_transient')->justReturn(['ok' => false, 'error' => 'not_ready']);
        ob_start();
        $page->saveNotice();
        $error = (string) ob_get_clean();
        self::assertStringContainsString('could not be enabled', $error);
        self::assertStringContainsString('notice-error', $error);

        \Brain\Monkey\Functions\when('get_transient')->justReturn(['ok' => true, 'warning' => 'migration_failed']);
        ob_start();
        $page->saveNotice();
        $warning = (string) ob_get_clean();
        self::assertStringContainsString('notice-warning', $warning);
        self::assertStringContainsString('could not be moved', $warning);
    }

    public function testSaveNoticeStaysSilentWithoutNotice(): void
    {
        \Brain\Monkey\Functions\when('current_user_can')->justReturn(false);

        $this->expectOutputString('');
        $this->page()->saveNotice();

        \Brain\Monkey\Functions\when('current_user_can')->justReturn(true);
        \Brain\Monkey\Functions\when('get_transient')->justReturn(false);
        \Brain\Monkey\Functions\expect('delete_transient')->never();

        $this->expectOutputString('');
        $this->page()->saveNotice();

        \Brain\Monkey\Functions\when('get_transient')->justReturn(['ok' => true]);
        \Brain\Monkey\Functions\expect('delete_transient')->once()->with('yetisearch_save_notice');

        $this->expectOutputString('');
        $this->page()->saveNotice();
    }

    public function testReindexNotice(): void
    {
        \Brain\Monkey\Functions\when('current_user_can')->justReturn(false);

        $this->expectOutputString('');
        $this->page()->reindexNotice();

        \Brain\Monkey\Functions\when('current_user_can')->justReturn(true);
        $this->expectOutputString('');
        $this->page()->reindexNotice();

        $this->options[Config::NEEDS_REINDEX_OPTION] = true;
        $this->expectOutputRegex('/re-index/');
        $this->page()->reindexNotice();
    }

    public function testSemanticSaveSyncsCronSchedule(): void
    {
        \Brain\Monkey\Functions\when('wp_next_scheduled')->justReturn(false);
        \Brain\Monkey\Functions\expect('wp_schedule_event')->once();
        \Brain\Monkey\Functions\expect('wp_clear_scheduled_hook')->never();

        $result = $this->page()->save('semantic', ['semantic_enabled' => '1', 'semantic_cron' => '1']);

        self::assertTrue($result['ok']);

        \Brain\Monkey\Functions\when('wp_next_scheduled')->justReturn(123);
        \Brain\Monkey\Functions\expect('wp_clear_scheduled_hook')->once()->with(SemanticBridge::CRON_HOOK);

        $this->page()->save('semantic', ['semantic_enabled' => '0', 'semantic_cron' => '0']);
    }

    public function testDirChangeWarnsWhenProtectionOrMoveFails(): void
    {
        $new = $this->uploads . '/newdir';
        mkdir($new, 0777, true);
        file_put_contents($new . '/.htaccess', "Options -Indexes\n");
        $logger = new ArrayLogger();
        $config = new Config([]);
        $page = new SettingsPage($config, new HealthChecker(), new StorageManager($config, new ServerSecurity()), new ServerSecurity(), $logger);

        $result = $page->save('general', ['db_custom_dir' => $new]);

        self::assertTrue($result['ok']);
        self::assertSame('migration_failed', $result['warning']);
        self::assertTrue($logger->hasLevel('warning'), 'unwritable protection files warn first');
        self::assertTrue($logger->hasLevel('error'), 'failed move is logged');
    }
}
