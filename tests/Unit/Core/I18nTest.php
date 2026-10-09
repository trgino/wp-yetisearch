<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Core;

use Brain\Monkey\Functions;
use WpYetiSearch\Core\I18n;
use WpYetiSearch\Tests\Unit\UnitTestCase;

final class I18nTest extends UnitTestCase
{
    public function testLoadCallsLoadPluginTextdomain(): void
    {
        Functions\when('plugin_basename')->justReturn('wp-yetisearch/wp-yetisearch.php');
        Functions\expect('load_plugin_textdomain')
            ->once()
            ->with('wp-yetisearch', false, 'wp-yetisearch/languages');

        I18n::load();
    }
}
