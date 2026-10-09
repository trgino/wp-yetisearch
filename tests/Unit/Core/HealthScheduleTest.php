<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Core;

use Brain\Monkey\Functions;
use WpYetiSearch\Core\HealthChecker;
use WpYetiSearch\Core\Plugin;
use WpYetiSearch\Tests\Unit\UnitTestCase;

final class HealthScheduleTest extends UnitTestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->options = [];
        Functions\when('wp_upload_dir')->justReturn(['basedir' => $this->tempDir('yetisearch-healthcron-'), 'baseurl' => 'https://example.test/wp-content/uploads']);
        Functions\when('site_url')->justReturn('https://example.test');
        Functions\when('get_option')->alias(fn (string $k, mixed $d = false): mixed => $this->options[$k] ?? $d);
        Functions\when('update_option')->alias(function (string $k, mixed $v): bool {
            $this->options[$k] = $v;
            return true;
        });
        Functions\when('is_admin')->justReturn(false);
    }

    public function testBootRegistersHealthHook(): void
    {
        Plugin::instance()->boot();

        self::assertNotFalse(has_action(HealthChecker::CRON_HOOK));
    }

    public function testHealthHookRefreshesCachedRecord(): void
    {
        $plugin = Plugin::instance();
        $plugin->boot();
        $plugin->runHealthCheck();

        self::assertArrayHasKey(HealthChecker::OPTION_KEY, $this->options);
        self::assertArrayHasKey('ready', $this->options[HealthChecker::OPTION_KEY]);
    }
}
