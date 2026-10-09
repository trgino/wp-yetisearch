<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Core;

use Brain\Monkey\Functions;
use WpYetiSearch\Core\Plugin;
use WpYetiSearch\Tests\Unit\UnitTestCase;

final class PluginWiringTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_upload_dir')->justReturn(['basedir' => $this->tempDir('yetisearch-wiring-'), 'baseurl' => 'https://example.test/wp-content/uploads']);
        Functions\when('site_url')->justReturn('https://example.test');
        Functions\when('get_option')->justReturn(false);
        Functions\when('update_option')->justReturn(true);
        Functions\when('is_admin')->justReturn(true);
    }

    public function testContainerResolvesAdminServices(): void
    {
        $plugin = Plugin::instance();
        $plugin->boot();

        self::assertInstanceOf(\WpYetiSearch\Admin\SettingsPage::class, $plugin->container()->get('settingsPage'));
        self::assertInstanceOf(\WpYetiSearch\Admin\AjaxHandler::class, $plugin->container()->get('ajaxHandler'));
    }

    public function testBootRegistersAdminHooks(): void
    {
        Plugin::instance()->boot();

        self::assertNotFalse(has_action('admin_menu'));
    }
}
