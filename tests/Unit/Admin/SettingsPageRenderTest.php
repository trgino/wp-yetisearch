<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use WpYetiSearch\Admin\SettingsPage;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Core\HealthChecker;
use WpYetiSearch\Core\SettingsSchema;
use WpYetiSearch\Storage\ServerSecurity;
use WpYetiSearch\Storage\StorageManager;
use WpYetiSearch\Tests\Support\ArrayLogger;
use WpYetiSearch\Tests\Unit\UnitTestCase;

final class SettingsPageRenderTest extends UnitTestCase
{
    /** @var array<string, mixed> */
    private array $options = [];
    private string $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploads = $this->tempDir('yetisearch-render-');
        $this->options = [Config::OPTION_KEY => []];
        Functions\when('wp_upload_dir')->justReturn(['basedir' => $this->uploads, 'baseurl' => 'https://example.test/wp-content/uploads']);
        Functions\when('site_url')->justReturn('https://example.test');
        Functions\when('wp_mkdir_p')->alias(static fn (string $d): bool => is_dir($d) || mkdir($d, 0777, true));
        Functions\when('get_option')->alias(fn (string $k, mixed $d = false): mixed => $this->options[$k] ?? $d);
        Functions\when('update_option')->alias(function (string $k, mixed $v): bool {
            $this->options[$k] = $v;
            return true;
        });
        Functions\when('admin_url')->alias(static fn (string $p = ''): string => 'https://example.test/wp-admin/' . $p);
        Functions\when('wp_nonce_field')->alias(static function (): void {
            echo '<input type="hidden" name="_wpnonce" value="nonce" />';
        });
        Functions\when('submit_button')->alias(static function (): void {
            echo '<input type="submit" />';
        });
        Functions\when('checked')->alias(static function (mixed $c): void {
            if ($c) {
                echo " checked='checked'";
            }
        });
        Functions\when('selected')->alias(static function (mixed $c): void {
            if ($c) {
                echo " selected='selected'";
            }
        });
        Functions\when('disabled')->alias(static function (mixed $d): void {
            if ($d) {
                echo " disabled='disabled'";
            }
        });
        Functions\when('human_time_diff')->justReturn('2 hours');
        Functions\when('get_post_types')->justReturn(['post' => 'post', 'page' => 'page']);
        Functions\when('get_taxonomies')->justReturn(['category' => 'category']);
        Functions\when('get_user_meta')->justReturn('');
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('wp_next_scheduled')->justReturn(false);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->uploads);
        unset($_GET['tab']);
        parent::tearDown();
    }

    private function page(): SettingsPage
    {
        $config = new Config($this->options[Config::OPTION_KEY]);
        return new SettingsPage($config, new HealthChecker(), new StorageManager($config, new ServerSecurity()), new ServerSecurity(), new ArrayLogger());
    }

    private function render(string $tab): string
    {
        $_GET['tab'] = $tab;
        ob_start();
        try {
            $this->page()->render();
        } finally {
            unset($_GET['tab']);
        }
        return (string) ob_get_clean();
    }

    public function testGeneralTabShowsChecklistHealthAndLockedToggle(): void
    {
        $html = $this->render('general');

        self::assertStringContainsString('Get started', $html);
        self::assertStringContainsString('System health', $html);
        self::assertStringContainsString('yetisearch-master_enabled', $html);
        self::assertStringContainsString('Locked: health checks are not passing.', $html);
        self::assertStringContainsString('Never reindexed', $html);
        foreach (SettingsSchema::TABS as $slug) {
            self::assertStringContainsString('tab=' . $slug, $html, $slug);
        }
    }

    public function testEveryTabRendersItsFields(): void
    {
        $this->options[Config::OPTION_KEY] = [
            'custom_stop_words' => ['foo', 'bar'],
            'synonyms' => ['car' => ['auto', 'vehicle']],
            'warmup_queries' => ['alpha'],
        ];
        foreach (SettingsSchema::TABS as $tab) {
            if ($tab === 'general' || $tab === 'maintenance') {
                continue;
            }
            $html = $this->render($tab);
            foreach (array_keys(SettingsSchema::fieldsForTab($tab)) as $key) {
                self::assertStringContainsString('yetisearch-' . $key, $html, "{$tab}.{$key}");
            }
        }
        $language = $this->render('language');
        self::assertStringContainsString('car: auto, vehicle', $language);
        self::assertStringContainsString("foo\nbar", $language);
    }

    public function testMaintenanceTabHasActionsAndNoSubmit(): void
    {
        $html = $this->render('maintenance');

        self::assertStringContainsString('Re-index all posts', $html);
        self::assertStringContainsString('yetisearch-log-output', $html);
        self::assertStringNotContainsString('type="submit"', $html);
    }

    public function testUnknownTabFallsBackToGeneral(): void
    {
        self::assertStringContainsString('System health', $this->render('nope'));
    }

    public function testReadyEngineShowsStatusAndReindexNotice(): void
    {
        $this->options[HealthChecker::OPTION_KEY] = ['ready' => true, 'checks' => [], 'checked_at' => time(), 'php' => PHP_VERSION];
        $this->options[Config::NEEDS_REINDEX_OPTION] = true;

        $html = $this->render('general');

        self::assertStringContainsString('Engine ready', $html);
        self::assertStringContainsString('Index schema changed', $html);
    }

    public function testGeneralTabShowsHealthRowsNoticeAndSnippet(): void
    {
        $this->options[HealthChecker::OPTION_KEY] = [
            'ready' => false,
            'checks' => [
                'pdo_sqlite' => ['ok' => true, 'value' => 'ok'],
                'fts5_support' => ['ok' => false, 'value' => 'missing'],
                'sqlite_recency' => ['ok' => false, 'value' => 'old'],
            ],
            'checked_at' => time() - 60,
            'php' => PHP_VERSION,
        ];
        $this->options['yetisearch_last_reindex'] = time() - 100;
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';
        try {
            $html = $this->render('general');
        } finally {
            unset($_SERVER['SERVER_SOFTWARE']);
        }

        self::assertStringContainsString('pdo_sqlite', $html);
        self::assertStringContainsString('FAIL', $html);
        self::assertStringContainsString('OK', $html);
        self::assertStringContainsString('Last checked 2 hours ago', $html);
        self::assertStringContainsString('SQLite 3.35 or newer is recommended', $html);
        self::assertStringContainsString('Reindexed 2 hours ago', $html);
        self::assertStringContainsString('deny all;', $html, 'nginx snippet is shown');
    }

    public function testMenuRegistersPage(): void
    {
        Functions\expect('add_menu_page')->once()->with(
            'WP YetiSearch',
            'WP YetiSearch',
            'manage_options',
            SettingsPage::MENU_SLUG,
            [$this->page(), 'render'],
            'dashicons-search',
            99
        );

        $this->page()->menu();
    }

    public function testRegisterHooksActions(): void
    {
        $this->page()->register();

        foreach (['admin_menu', 'admin_init', 'admin_notices', 'admin_enqueue_scripts'] as $hook) {
            self::assertNotFalse(has_action($hook), $hook);
        }
    }

    public function testAssetsEnqueueOnlyOnOwnScreen(): void
    {
        Functions\when('wp_create_nonce')->justReturn('n');
        Functions\when('rest_url')->justReturn('https://example.test/wp-json/');
        Functions\expect('wp_enqueue_script')->once();
        Functions\expect('wp_add_inline_script')->once();
        Functions\expect('wp_enqueue_style')->once();

        $this->page()->assets('toplevel_page_yetisearch');
        $this->page()->assets('edit.php');
    }
}
