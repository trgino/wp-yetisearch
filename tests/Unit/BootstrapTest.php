<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit;

use Brain\Monkey\Functions;

final class BootstrapTest extends UnitTestCase
{
    public function testMainFileDefinesConstantsAndRegistersHooks(): void
    {
        $root = dirname(__DIR__, 2);
        Functions\when('plugin_dir_path')->justReturn($root . '/');
        Functions\when('plugin_dir_url')->justReturn('https://example.test/wp-content/plugins/wp-yetisearch/');
        Functions\expect('register_activation_hook')->once();
        Functions\expect('register_deactivation_hook')->once();

        require $root . '/wp-yetisearch.php';

        self::assertSame('1.0.0', WPYETISEARCH_VERSION);
        self::assertStringEndsWith('/', WPYETISEARCH_PATH);
        self::assertNotFalse(has_action('plugins_loaded'));
    }
}
