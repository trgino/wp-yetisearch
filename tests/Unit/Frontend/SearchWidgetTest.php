<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Frontend;

use Brain\Monkey\Functions;
use WpYetiSearch\Frontend\SearchWidget;
use WpYetiSearch\Tests\Unit\UnitTestCase;

final class SearchWidgetTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('home_url')->alias(static fn (string $p = ''): string => 'https://example.test/' . ltrim($p, '/'));
    }

    public function testUpdateSanitizesTitle(): void
    {
        $widget = new SearchWidget();

        self::assertSame(['title' => 'alert'], $widget->update(['title' => '<b>alert</b>'], []));
    }

    public function testWidgetRendersSearchForm(): void
    {
        $widget = new SearchWidget();
        $args = ['before_widget' => '<aside>', 'after_widget' => '</aside>', 'before_title' => '<h2>', 'after_title' => '</h2>'];

        ob_start();
        $widget->widget($args, ['title' => 'Find <things>']);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('<aside>', $html);
        self::assertStringContainsString('Find &lt;things&gt;', $html);
        self::assertStringContainsString('name="s"', $html);
        self::assertStringContainsString('https://example.test/', $html);
    }

    public function testRegisterHooksWidgetInit(): void
    {
        SearchWidget::register();

        self::assertNotFalse(has_action('widgets_init'));
    }

    public function testRegisterWidgetRegistersClass(): void
    {
        Functions\expect('register_widget')->once()->with(SearchWidget::class);

        SearchWidget::registerWidget();
    }

    public function testFormRendersTitleField(): void
    {
        $widget = new SearchWidget();

        ob_start();
        $widget->form(['title' => 'Hello']);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Title:', $html);
        self::assertStringContainsString('value="Hello"', $html);
    }
}
