<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use WpYetiSearch\Admin\DashboardWidget;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Core\HealthChecker;
use WpYetiSearch\Search\ResultNormalizer;
use WpYetiSearch\Search\SearchService;
use WpYetiSearch\Tests\Support\ArrayLogger;
use WpYetiSearch\Tests\Unit\UnitTestCase;
use YetiSearch\YetiSearch;

final class DashboardWidgetTest extends UnitTestCase
{
    private function widget(?YetiSearch $yeti = null): DashboardWidget
    {
        $config = new Config([]);
        $search = new SearchService($yeti, $config, new ResultNormalizer($config));
        return new DashboardWidget(new HealthChecker(), $search);
    }

    public function testRenderSkipsWithoutCapability(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $this->expectOutputString('');
        $this->widget()->render();
    }

    public function testRenderShowsStatusAndCounts(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_option')->justReturn(time() - 3600);
        Functions\when('human_time_diff')->justReturn('1 hour');
        Functions\when('admin_url')->alias(static fn (string $p = ''): string => 'https://example.test/wp-admin/' . $p);

        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('countDocuments')->once()->andReturn(42);
        $yeti->shouldReceive('getStats')->once()->andReturn([]);

        $this->expectOutputRegex('/WP YetiSearch/');
        $this->expectOutputRegex('/42/');
        $this->widget($yeti)->render();
    }

    public function testAddWidgetRegistersForManagersOnly(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\expect('wp_add_dashboard_widget')->never();

        $this->widget()->addWidget();
        $this->widget()->register();

        self::assertNotFalse(has_action('wp_dashboard_setup'));
    }

    public function testAddWidgetRegistersDashboardWidget(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\expect('wp_add_dashboard_widget')->once()->with(
            DashboardWidget::WIDGET_ID,
            'WP YetiSearch',
            \Mockery::on(static fn (mixed $cb): bool => is_array($cb) && ($cb[0] ?? null) instanceof DashboardWidget && ($cb[1] ?? null) === 'render')
        );

        $this->widget()->addWidget();
    }
}
