<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Index;

use Brain\Monkey\Functions;
use WpYetiSearch\Index\LanguageResolver;
use WpYetiSearch\Tests\Unit\UnitTestCase;

final class LanguageResolverTest extends UnitTestCase
{
    public function testFallsBackToBaseIndexWithoutMultilingualPlugins(): void
    {
        $resolver = new LanguageResolver();

        self::assertNull($resolver->postLanguage(new \WP_Post(['ID' => 1])));
        self::assertSame('wp_posts', $resolver->indexForPost(new \WP_Post(['ID' => 1])));
        self::assertTrue(LanguageResolver::isPluginIndex('wp_posts'));
        self::assertTrue(LanguageResolver::isPluginIndex('wp_posts_tr'));
        self::assertFalse(LanguageResolver::isPluginIndex('other_index'));
    }

    public function testUsesPolylangWhenAvailable(): void
    {
        Functions\when('pll_get_post_language')->justReturn('tr');
        Functions\when('pll_default_language')->justReturn('en');

        $resolver = new LanguageResolver();

        self::assertSame('tr', $resolver->postLanguage(new \WP_Post(['ID' => 1])));
        self::assertSame('wp_posts_tr', $resolver->indexForPost(new \WP_Post(['ID' => 1])));
    }

    public function testDefaultLanguageStaysOnBaseIndex(): void
    {
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_default_language')->justReturn('en');

        self::assertSame('wp_posts', (new LanguageResolver())->indexForPost(new \WP_Post(['ID' => 1])));
    }

    public function testCurrentLanguageSelectsIndex(): void
    {
        Functions\when('pll_current_language')->justReturn('tr');
        Functions\when('pll_default_language')->justReturn('en');

        self::assertSame('wp_posts_tr', (new LanguageResolver())->indexForCurrent());
    }

    public function testDefaultLanguageFallsBackToLocaleThenEnglish(): void
    {
        Functions\when('pll_default_language')->justReturn('');
        Functions\when('get_locale')->justReturn('de_DE');

        self::assertSame('de', (new LanguageResolver())->defaultLanguage());

        Functions\when('get_locale')->justReturn('');

        self::assertSame('en', (new LanguageResolver())->defaultLanguage());
    }
}
