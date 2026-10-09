<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Cli;

use Brain\Monkey\Functions;
use WpYetiSearch\Cli\YetiSearchCli;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Features\CacheBridge;
use WpYetiSearch\Features\SemanticBridge;
use WpYetiSearch\Index\BulkIndexer;
use WpYetiSearch\Index\DocumentMapper;
use WpYetiSearch\Search\ResultNormalizer;
use WpYetiSearch\Search\SearchService;
use WpYetiSearch\Storage\ServerSecurity;
use WpYetiSearch\Storage\StorageManager;
use WpYetiSearch\Tests\Support\ArrayLogger;
use WpYetiSearch\Tests\Unit\UnitTestCase;
use YetiSearch\YetiSearch;

final class YetiSearchCliExtendedTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \WP_CLI::reset();
        Functions\when('get_option')->justReturn(10);
    }

    private function cli(?YetiSearch $yeti = null, array $settings = []): YetiSearchCli
    {
        $config = new Config($settings);
        $logger = new ArrayLogger();
        $yeti ??= \Mockery::mock(YetiSearch::class);
        $search = new SearchService($yeti, $config, new ResultNormalizer($config));
        return new YetiSearchCli(
            $config,
            $yeti,
            new StorageManager($config, new ServerSecurity()),
            $logger,
            new BulkIndexer($yeti, new DocumentMapper($config), $config, $logger),
            null,
            $search,
            new SemanticBridge($yeti, $config, $logger),
            new CacheBridge($yeti, $config, $search, $logger)
        );
    }

    public function testEmbedLoopsUntilNoPending(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('isSemanticEnabled')->andReturn(true);
        $yeti->shouldReceive('listIndices')->andReturn([]);
        $yeti->shouldReceive('embedPending')->once()->andReturn(['embedded' => 5, 'pending' => 3, 'total' => 8, 'error' => null]);
        $yeti->shouldReceive('embedPending')->once()->andReturn(['embedded' => 3, 'pending' => 0, 'total' => 8, 'error' => null]);

        $this->cli($yeti, ['semantic_enabled' => true])->embed([], []);

        self::assertContains('success', array_column(\WP_CLI::$log, 0));
    }

    public function testCalibrateWarnsWhenTooFewDocuments(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('isSemanticEnabled')->andReturn(true);
        $yeti->shouldReceive('listIndices')->andReturn([]);
        $yeti->shouldReceive('calibrate')->once()->andReturnNull();

        $this->cli($yeti, ['semantic_enabled' => true])->calibrate([], []);

        self::assertContains('warning', array_column(\WP_CLI::$log, 0));
    }

    public function testClearRefusesWithoutYes(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldNotReceive('clear');

        $this->cli($yeti)->clear([], []);

        self::assertContains('warning', array_column(\WP_CLI::$log, 0));
    }

    public function testCacheDispatcherRunsWarmup(): void
    {
        $this->cli()->cache(['warmup'], []);

        self::assertNotSame([], \WP_CLI::$log);
    }
}
