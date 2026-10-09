<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Frontend;

use Brain\Monkey\Functions;
use WpYetiSearch\Frontend\SearchBlock;
use WpYetiSearch\Frontend\SearchBox;
use WpYetiSearch\Tests\Unit\UnitTestCase;

final class SearchBlockTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('home_url')->alias(static fn (string $p = ''): string => 'https://example.test/' . ltrim($p, '/'));
    }

    public function testFormEscapesTitleAndRendersSearchInput(): void
    {
        $html = SearchBox::form('Find <things>', 'box-1');

        self::assertStringContainsString('Find &lt;things&gt;', $html);
        self::assertStringNotContainsString('<things>', $html);
        self::assertStringContainsString('name="s"', $html);
        self::assertStringContainsString('role="search"', $html);
        self::assertStringContainsString('id="box-1"', $html);
    }

    public function testRegisterHooksInit(): void
    {
        SearchBlock::register();

        self::assertNotFalse(has_action('init'));
    }

    public function testRegisterEditorAssets(): void
    {
        Functions\when('plugins_url')->alias(static fn (string $p): string => 'https://example.test/wp-content/plugins/wp-yetisearch/' . $p);
        Functions\expect('wp_register_script')->once()->with(
            'yetisearch-search-box-editor',
            'https://example.test/wp-content/plugins/wp-yetisearch/blocks/search-box/edit.js',
            ['wp-blocks', 'wp-element'],
            WPYETISEARCH_VERSION,
            true
        );
        Functions\expect('register_block_type')->once()->with(WPYETISEARCH_PATH . 'blocks/search-box');

        SearchBlock::registerEditorAssets();
    }

    public function testRenderFileOutputsSharedMarkup(): void
    {
        $attributes = ['title' => 'Hi'];
        ob_start();
        require dirname(__DIR__, 3) . '/blocks/search-box/render.php';
        $html = (string) ob_get_clean();

        self::assertStringContainsString('name="s"', $html);
        self::assertStringContainsString('Hi', $html);
    }
}
