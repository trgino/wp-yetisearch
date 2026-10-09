<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Features;

use Brain\Monkey\Functions;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Features\SemanticBridge;
use WpYetiSearch\Tests\Support\ArrayLogger;
use WpYetiSearch\Tests\Unit\UnitTestCase;
use YetiSearch\Semantic\OpenAICompatibleEmbeddingProvider;
use YetiSearch\YetiSearch;

final class SemanticBridgeTest extends UnitTestCase
{
    public function testProviderIsOnlyAttachedWhenEnabled(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldNotReceive('setEmbeddingProvider');

        SemanticBridge::attachProvider($yeti, new Config(['semantic_enabled' => false]));
    }

    public function testProviderReceivesConfiguredOptions(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('setEmbeddingProvider')
            ->once()
            ->with(
                \Mockery::type(OpenAICompatibleEmbeddingProvider::class),
                \Mockery::on(static fn (array $o): bool => $o['weight'] === 0.7 && $o['calibration'] === 'off' && $o['min_margin'] === 0.15)
            )
            ->andReturnSelf();

        SemanticBridge::attachProvider($yeti, new Config(['semantic_enabled' => true, 'semantic_weight' => 0.7, 'semantic_calibration' => 'off']));
    }

    public function testEmbedErrorsAreReturnedNotThrown(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('isSemanticEnabled')->andReturn(true);
        $yeti->shouldReceive('listIndices')->once()->andReturn([]);
        $yeti->shouldReceive('embedPending')->once()->with(Config::INDEX, 50)->andThrow(new \RuntimeException('401 Unauthorized'));

        $result = (new SemanticBridge($yeti, new Config(['semantic_enabled' => true]), new ArrayLogger()))->embedBatch();

        self::assertSame('401 Unauthorized', $result['error']);
        self::assertSame(0, $result['embedded']);
    }

    public function testCalibrateReportsTooFewDocuments(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('isSemanticEnabled')->andReturn(true);
        $yeti->shouldReceive('listIndices')->once()->andReturn([]);
        $yeti->shouldReceive('calibrate')->once()->with(Config::INDEX)->andReturnNull();

        $result = (new SemanticBridge($yeti, new Config(['semantic_enabled' => true]), new ArrayLogger()))->calibrate();

        self::assertSame(['ok' => false, 'error' => 'not_enough_documents'], $result);
    }

    /** @return list<array{name: string}> */
    private static function twoLanguages(): array
    {
        return [['name' => Config::INDEX], ['name' => Config::INDEX . '_tr'], ['name' => 'foreign_index']];
    }

    public function testEmbedBatchSweepsAllLanguageIndexes(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('isSemanticEnabled')->andReturn(true);
        $yeti->shouldReceive('listIndices')->once()->andReturn(self::twoLanguages());
        $yeti->shouldReceive('embedPending')->once()->with(Config::INDEX, 50)->andReturn(['embedded' => 2, 'pending' => 1, 'total' => 3, 'error' => null]);
        $yeti->shouldReceive('embedPending')->once()->with(Config::INDEX . '_tr', 50)->andReturn(['embedded' => 4, 'pending' => 0, 'total' => 4, 'error' => null]);

        $result = (new SemanticBridge($yeti, new Config(['semantic_enabled' => true]), new ArrayLogger()))->embedBatch();

        self::assertSame(['embedded' => 6, 'pending' => 1, 'total' => 7, 'error' => null], $result);
    }

    public function testCalibrateRequiresEveryLanguageIndex(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('isSemanticEnabled')->andReturn(true);
        $yeti->shouldReceive('listIndices')->once()->andReturn(self::twoLanguages());
        $yeti->shouldReceive('calibrate')->once()->with(Config::INDEX)->andReturn(
            new \YetiSearch\Semantic\NoiseCalibration('test', 3, 10, 'probes', 'frame', [], [], [], 0.1, time())
        );
        $yeti->shouldReceive('calibrate')->once()->with(Config::INDEX . '_tr')->andReturnNull();

        $result = (new SemanticBridge($yeti, new Config(['semantic_enabled' => true]), new ArrayLogger()))->calibrate();

        self::assertSame(['ok' => false, 'error' => 'not_enough_documents'], $result);
    }

    public function testStatsAreKeyedPerLanguageIndex(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('isSemanticEnabled')->andReturn(true);
        $yeti->shouldReceive('listIndices')->once()->andReturn(self::twoLanguages());
        $yeti->shouldReceive('embeddingStats')->once()->with(Config::INDEX)->andReturn(['vectors' => 10]);
        $yeti->shouldReceive('embeddingStats')->once()->with(Config::INDEX . '_tr')->andReturn(['vectors' => 5]);

        $result = (new SemanticBridge($yeti, new Config(['semantic_enabled' => true]), new ArrayLogger()))->stats();

        self::assertSame([Config::INDEX => ['vectors' => 10], Config::INDEX . '_tr' => ['vectors' => 5]], $result);
    }

    public function testScheduleFollowsSettings(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\expect('wp_schedule_event')->once()->with(\Mockery::type('int'), SemanticBridge::CRON_SCHEDULE, SemanticBridge::CRON_HOOK);
        SemanticBridge::syncSchedule(new Config(['semantic_enabled' => true, 'semantic_cron' => true]));

        Functions\when('wp_next_scheduled')->justReturn(12345);
        Functions\expect('wp_clear_scheduled_hook')->once()->with(SemanticBridge::CRON_HOOK);
        SemanticBridge::syncSchedule(new Config(['semantic_enabled' => false]));
    }

    public function testFiveMinuteScheduleIsAdded(): void
    {
        $schedules = SemanticBridge::addSchedule([]);

        self::assertSame(300, $schedules[SemanticBridge::CRON_SCHEDULE]['interval']);
    }

    public function testDisabledBridgeShortCircuits(): void
    {
        $off = new SemanticBridge(null, new Config(['semantic_enabled' => true]), new ArrayLogger());

        self::assertSame('disabled', $off->embedBatch()['error']);
        self::assertSame(['ok' => false, 'error' => 'disabled'], $off->calibrate());
        self::assertSame([], $off->stats());
        $off->runCron();
    }

    public function testEmbedRowErrorsPropagate(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('isSemanticEnabled')->andReturn(true);
        $yeti->shouldReceive('listIndices')->once()->andReturn([]);
        $yeti->shouldReceive('embedPending')->once()->andReturn(['embedded' => 1, 'pending' => 2, 'total' => 3, 'error' => 'provider down']);

        $result = (new SemanticBridge($yeti, new Config(['semantic_enabled' => true]), new ArrayLogger()))->embedBatch();

        self::assertSame(['embedded' => 1, 'pending' => 2, 'total' => 3, 'error' => 'provider down'], $result);
    }

    public function testCalibrateSuccessReturnsCalibration(): void
    {
        $noise = ['count' => 1, 'min' => 0.1, 'median' => 0.1, 'mean' => 0.1, 'sd' => 0.0, 'p95' => 0.1, 'max' => 0.1];
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('isSemanticEnabled')->andReturn(true);
        $yeti->shouldReceive('listIndices')->once()->andReturn([]);
        $yeti->shouldReceive('calibrate')->once()->with(Config::INDEX)->andReturn(
            new \YetiSearch\Semantic\NoiseCalibration('test', 3, 10, 'probes', 'frame', [], $noise, [], 0.1, time())
        );

        $result = (new SemanticBridge($yeti, new Config(['semantic_enabled' => true]), new ArrayLogger()))->calibrate();

        self::assertTrue($result['ok']);
        self::assertSame('test', $result['calibration']['model']);
    }

    public function testCalibrateFailureIsReturnedNotThrown(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('isSemanticEnabled')->andReturn(true);
        $yeti->shouldReceive('listIndices')->once()->andReturn([]);
        $yeti->shouldReceive('calibrate')->once()->andThrow(new \RuntimeException('no vectors'));
        $logger = new ArrayLogger();

        $result = (new SemanticBridge($yeti, new Config(['semantic_enabled' => true]), $logger))->calibrate();

        self::assertSame(['ok' => false, 'error' => 'no vectors'], $result);
        self::assertTrue($logger->hasLevel('error'));
    }

    public function testStatsFailureReturnsEmpty(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('isSemanticEnabled')->andReturn(true);
        $yeti->shouldReceive('listIndices')->once()->andReturn([]);
        $yeti->shouldReceive('embeddingStats')->once()->andThrow(new \RuntimeException('gone'));

        self::assertSame([], (new SemanticBridge($yeti, new Config(['semantic_enabled' => true]), new ArrayLogger()))->stats());
    }

    public function testIndexListingFailureFallsBackToBaseIndex(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('isSemanticEnabled')->andReturn(true);
        $yeti->shouldReceive('listIndices')->once()->andThrow(new \RuntimeException('gone'));
        $yeti->shouldReceive('embedPending')->once()->with(Config::INDEX, 50)
            ->andReturn(['embedded' => 1, 'pending' => 0, 'total' => 1, 'error' => null]);
        $logger = new ArrayLogger();

        $result = (new SemanticBridge($yeti, new Config(['semantic_enabled' => true]), $logger))->embedBatch();

        self::assertSame(1, $result['embedded']);
        self::assertTrue($logger->hasLevel('debug'));
    }
}
