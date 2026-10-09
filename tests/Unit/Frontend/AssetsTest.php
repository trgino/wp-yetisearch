<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Frontend;

use Brain\Monkey\Functions;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Frontend\Assets;
use WpYetiSearch\Index\LanguageResolver;
use WpYetiSearch\Tests\Unit\UnitTestCase;

final class AssetsTest extends UnitTestCase
{
    public function testNothingIsEnqueuedWhenMasterIsOff(): void
    {
        Functions\expect('wp_enqueue_script')->never();

        (new Assets(new Config(['master_enabled' => false]), 'https://example.test/p/', '1.0.0'))->enqueue();
    }

    public function testTypeaheadIsDeferredWithInlineSettings(): void
    {
        Functions\when('rest_url')->justReturn('https://example.test/wp-json/yetisearch/v1/search');
        Functions\when('wp_json_encode')->alias(static fn (mixed $v): string => (string) json_encode($v));
        Functions\expect('wp_enqueue_style')->once();
        Functions\expect('wp_enqueue_script')
            ->once()
            ->with('wp-yetisearch-typeahead', 'https://example.test/p/assets/js/typeahead.js', [], '1.0.0', ['strategy' => 'defer', 'in_footer' => true]);
        Functions\expect('wp_add_inline_script')
            ->once()
            ->with('wp-yetisearch-typeahead', \Mockery::on(static fn (string $js): bool => str_starts_with($js, 'window.wpYetiSearch = ')
                && str_contains($js, '"minChars":2')), 'before');

        (new Assets(new Config(['master_enabled' => true]), 'https://example.test/p/', '1.0.0'))->enqueue();
    }

    public function testSettingsExposeCurrentLanguageForTypeahead(): void
    {
        Functions\when('rest_url')->justReturn('https://example.test/wp-json/yetisearch/v1/search');
        Functions\when('pll_current_language')->justReturn('tr');

        $settings = (new Assets(new Config(['master_enabled' => true]), 'https://example.test/p/', '1.0.0', new LanguageResolver()))->settings();

        self::assertSame('tr', $settings['lang']);
    }

    public function testSettingsLangDefaultsToEmpty(): void
    {
        Functions\when('rest_url')->justReturn('https://example.test/wp-json/yetisearch/v1/search');

        $settings = (new Assets(new Config(['master_enabled' => true]), 'https://example.test/p/', '1.0.0'))->settings();

        self::assertSame('', $settings['lang']);
    }
}
