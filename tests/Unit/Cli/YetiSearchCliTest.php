<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Cli;

use Brain\Monkey\Functions;
use WpYetiSearch\Cli\YetiSearchCli;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Core\HealthChecker;
use WpYetiSearch\Index\BulkIndexer;
use WpYetiSearch\Index\DocumentMapper;
use WpYetiSearch\Search\ResultNormalizer;
use WpYetiSearch\Search\SearchService;
use WpYetiSearch\Storage\ServerSecurity;
use WpYetiSearch\Storage\StorageManager;
use WpYetiSearch\Tests\Support\ArrayLogger;
use WpYetiSearch\Tests\Unit\UnitTestCase;
use YetiSearch\YetiSearch;

/**
 * NOTE: project services are final and never mocked — real services are built
 * around a mocked YetiSearch (not final), per the global test constraint.
 */
final class YetiSearchCliTest extends UnitTestCase
{
    /** @var array<string, mixed> */
    private array $options = [];
    private string $uploads;
    /** @var array<string, mixed> */
    private array $lastArgs = [];

    protected function setUp(): void
    {
        parent::setUp();
        \WP_CLI::reset();
        $this->uploads = $this->tempDir('yetisearch-cli-');
        $this->options = [];
        $this->lastArgs = [];
        Functions\when('wp_upload_dir')->justReturn(['basedir' => $this->uploads, 'baseurl' => 'https://example.test/wp-content/uploads']);
        Functions\when('site_url')->justReturn('https://example.test');
        Functions\when('wp_mkdir_p')->alias(static fn (string $d): bool => is_dir($d) || mkdir($d, 0777, true));
        Functions\when('get_option')->alias(fn (string $k, mixed $d = false): mixed => $this->options[$k] ?? $d);
        Functions\when('update_option')->alias(function (string $k, mixed $v): bool {
            $this->options[$k] = $v;
            return true;
        });
        Functions\when('get_permalink')->justReturn('https://example.test/');
        Functions\when('get_the_terms')->justReturn(false);
        Functions\when('get_post_meta')->justReturn('');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->uploads);
        parent::tearDown();
    }

    private function factory(int $maxPages, int $found = 2): \Closure
    {
        return function (array $args) use ($maxPages, $found): \WP_Query {
            $this->lastArgs = $args;
            $query = new \WP_Query($args);
            $query->posts = [
                new \WP_Post(['ID' => 1, 'post_title' => 'One']),
                new \WP_Post(['ID' => 2, 'post_title' => 'Two']),
            ];
            $query->found_posts = $found;
            $query->max_num_pages = $maxPages;
            return $query;
        };
    }

    private function cli(
        ?YetiSearch $yeti = null,
        ?BulkIndexer $indexer = null,
        ?HealthChecker $checker = null,
        ?SearchService $service = null,
        array $settings = []
    ): YetiSearchCli {
        $config = new Config($settings);
        $logger = new ArrayLogger();
        $yeti ??= \Mockery::mock(YetiSearch::class);
        $storage = new StorageManager($config, new ServerSecurity());
        $indexer ??= new BulkIndexer($yeti, new DocumentMapper($config), $config, $logger, $this->factory(1));
        $checker ??= new HealthChecker();
        $service ??= new SearchService($yeti, $config, new ResultNormalizer($config));
        return new YetiSearchCli($config, $yeti, $storage, $logger, $indexer, $checker, $service);
    }

    public function testRegisterAddsCommandWhenEngineAvailable(): void
    {
        $this->cli()->register();

        self::assertSame(['command', 'yetisearch'], \WP_CLI::$log[0]);
    }

    public function testRegisterIsNoOpWithoutEngine(): void
    {
        $config = new Config();
        $cli = new YetiSearchCli($config, null, new StorageManager($config, new ServerSecurity()), new ArrayLogger());
        $cli->register();

        self::assertSame([], \WP_CLI::$log);
    }

    public function testIndexRunsBulkIndexerWithProgress(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('createIndex')->andReturn(\Mockery::mock(\YetiSearch\Index\Indexer::class));
        $yeti->shouldReceive('stemmingFor')->andReturn('english');
        $yeti->shouldReceive('deleteByIdPrefix')->twice();
        $yeti->shouldReceive('indexBatch')->once();
        $yeti->shouldReceive('rebuildFts')->once();
        $yeti->shouldReceive('clearCache')->once();

        $config = new Config();
        $logger = new ArrayLogger();
        $cli = new YetiSearchCli(
            $config,
            $yeti,
            new StorageManager($config, new ServerSecurity()),
            $logger,
            new BulkIndexer($yeti, new DocumentMapper($config), $config, $logger, $this->factory(1))
        );
        $cli->index([], []);

        self::assertContains('success', array_column(\WP_CLI::$log, 0));
    }

    public function testIndexPassesOptionsToBulkIndexer(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('listIndices')->andReturn([['name' => Config::INDEX]]);
        $yeti->shouldReceive('createIndex')->andReturn(\Mockery::mock(\YetiSearch\Index\Indexer::class));
        $yeti->shouldReceive('stemmingFor')->andReturn('english');
        $yeti->shouldReceive('clear')->once()->with(Config::INDEX);
        $yeti->shouldReceive('indexBatch')->once();
        $yeti->shouldReceive('rebuildFts')->once();
        $yeti->shouldReceive('clearCache')->once();

        $config = new Config();
        $logger = new ArrayLogger();
        $cli = new YetiSearchCli(
            $config,
            $yeti,
            new StorageManager($config, new ServerSecurity()),
            $logger,
            new BulkIndexer($yeti, new DocumentMapper($config), $config, $logger, $this->factory(1))
        );
        $cli->index([], ['batch' => '50', 'post-type' => 'post', 'force' => true]);

        self::assertSame(50, $this->lastArgs['posts_per_page']);
        self::assertSame(['post'], $this->lastArgs['post_type']);
    }

    public function testIndexReportsErrors(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('deleteByIdPrefix')->andThrow(new \RuntimeException('disk full'));

        try {
            $this->cli($yeti)->index([], []);
            self::fail('WP_CLI::error must throw in the test stub.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('disk full', $e->getMessage());
        }
        self::assertContains('error', array_column(\WP_CLI::$log, 0));
    }

    public function testCheckRefreshesAndDisplaysResults(): void
    {
        $this->cli()->check([], []);

        $log = array_column(\WP_CLI::$log, 0);
        self::assertContains('row', $log);
        self::assertContains('success', $log);
    }

    public function testCheckWarnsWhenNotReady(): void
    {
        // Null PDO factory simulates a server without pdo_sqlite.
        $this->cli(null, null, new HealthChecker(static fn (): ?\PDO => null))->check([], []);

        self::assertContains('warning', array_column(\WP_CLI::$log, 0));
    }

    public function testHealthDisplaysCachedRecord(): void
    {
        $cli = $this->cli();
        $cli->check([], []);
        \WP_CLI::reset();

        $cli->health([], []);

        self::assertContains('success', array_column(\WP_CLI::$log, 0));
    }

    public function testHealthWarnsWhenNoCache(): void
    {
        $this->cli()->health([], []);

        self::assertContains('warning', array_column(\WP_CLI::$log, 0));
    }

    public function testStatsDisplaysIndexStatistics(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('countDocuments')->once()->andReturn(42);
        $yeti->shouldReceive('getStats')->once()->andReturn(['total_terms' => 1000]);
        $config = new Config();
        $service = new SearchService($yeti, $config, new ResultNormalizer($config));

        $this->cli($yeti, null, null, $service)->stats([], []);

        self::assertContains('row', array_column(\WP_CLI::$log, 0));
    }

    public function testStatsWarnsWhenUnavailable(): void
    {
        $config = new Config();
        $service = new SearchService(null, $config, new ResultNormalizer($config));

        $this->cli(null, null, null, $service)->stats([], []);

        self::assertContains('warning', array_column(\WP_CLI::$log, 0));
    }

    public function testHealthWarnsWhenCachedRecordIsNotReady(): void
    {
        $this->options[HealthChecker::OPTION_KEY] = [
            'ready' => false,
            'checks' => ['php_version' => ['ok' => true, 'value' => 'ok']],
            'checked_at' => time(),
            'php' => PHP_VERSION,
        ];

        $this->cli()->health([], []);

        self::assertContains('warning', array_column(\WP_CLI::$log, 0));
    }

    public function testEmbedWarnsWhenDisabled(): void
    {
        $this->cli(\Mockery::mock(YetiSearch::class))->embed([], []);

        self::assertContains('warning', array_column(\WP_CLI::$log, 0));
    }

    public function testEmbedReportsProviderErrors(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('isSemanticEnabled')->andReturn(true);
        $yeti->shouldReceive('listIndices')->andReturn([]);
        $yeti->shouldReceive('embedPending')->andThrow(new \RuntimeException('401 Unauthorized'));

        try {
            $this->cli($yeti, null, null, null, ['semantic_enabled' => true])->embed([], []);
            self::fail('WP_CLI::error must throw in the test stub.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('401 Unauthorized', $e->getMessage());
        }
        self::assertContains('error', array_column(\WP_CLI::$log, 0));
    }

    public function testCalibrateSuccess(): void
    {
        $noise = ['count' => 1, 'min' => 0.1, 'median' => 0.1, 'mean' => 0.1, 'sd' => 0.0, 'p95' => 0.1, 'max' => 0.1];
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('isSemanticEnabled')->andReturn(true);
        $yeti->shouldReceive('listIndices')->andReturn([]);
        $yeti->shouldReceive('calibrate')->once()->andReturn(
            new \YetiSearch\Semantic\NoiseCalibration('test', 3, 10, 'probes', 'frame', [], $noise, [], 0.1, time())
        );

        $this->cli($yeti, null, null, null, ['semantic_enabled' => true])->calibrate([], []);

        self::assertContains('success', array_column(\WP_CLI::$log, 0));
    }

    public function testClearRefusesWithoutYes(): void
    {
        $this->cli()->clear([], []);

        self::assertContains('warning', array_column(\WP_CLI::$log, 0));
    }

    public function testClearEmptiesIndex(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('clear')->once()->with(Config::INDEX);
        $yeti->shouldReceive('clearCache')->once();

        $this->cli($yeti)->clear([], ['yes' => true]);

        self::assertContains('success', array_column(\WP_CLI::$log, 0));
    }

    public function testClearReportsFailures(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('clear')->once()->andThrow(new \RuntimeException('locked'));

        try {
            $this->cli($yeti)->clear([], ['yes' => true]);
            self::fail('WP_CLI::error must throw in the test stub.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('locked', $e->getMessage());
        }
    }

    public function testClearWarnsWhenEngineMissing(): void
    {
        $config = new Config();
        $cli = new YetiSearchCli($config, null, new StorageManager($config, new ServerSecurity()), new ArrayLogger());
        $cli->clear([], ['yes' => true]);

        self::assertContains('warning', array_column(\WP_CLI::$log, 0));
    }

    public function testQueryNeedsATerm(): void
    {
        try {
            $this->cli()->query([], []);
            self::fail('WP_CLI::error must throw in the test stub.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Missing search term', $e->getMessage());
        }
    }

    private function searchingCli(?string $suggestion = null): YetiSearchCli
    {
        $results = new \YetiSearch\Models\SearchResults([
            ['id' => '21', 'score' => 5.0, 'document' => ['title' => 'Twenty-one', 'url' => 'https://example.test/?p=21'], 'highlights' => [], 'metadata' => ['post_id' => 21, 'post_type' => 'post']],
        ], 1);
        if ($suggestion !== null) {
            $results->setSuggestion($suggestion);
        }
        $engine = \Mockery::mock(\YetiSearch\Search\SearchEngine::class);
        $engine->shouldReceive('search')->andReturn($results);
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('getSearchEngine')->andReturn($engine);
        \Brain\Monkey\Functions\when('get_posts')->justReturn([21]);

        return $this->cli($yeti);
    }

    public function testQueryDisplaysHits(): void
    {
        $this->searchingCli()->query(['boots'], []);

        $log = array_column(\WP_CLI::$log, 0);
        self::assertContains('row', $log);
    }

    public function testQueryShowsSuggestionAndHonorsNoFuzzy(): void
    {
        $this->searchingCli('boot')->query(['boots'], ['no-fuzzy' => true]);

        $messages = array_column(\WP_CLI::$log, 1);
        self::assertContains('Did you mean: boot', $messages);
    }

    public function testQueryReportsEngineFailures(): void
    {
        $engine = \Mockery::mock(\YetiSearch\Search\SearchEngine::class);
        $engine->shouldReceive('search')->andThrow(new \RuntimeException('db gone'));
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('getSearchEngine')->andReturn($engine);

        try {
            $this->cli($yeti)->query(['boots'], []);
            self::fail('WP_CLI::error must throw in the test stub.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('db gone', $e->getMessage());
        }
    }

    public function testCacheSubcommands(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('clearCache')->once();

        $cli = $this->cli($yeti);
        $cli->cache(['clear'], []);
        $cli->cache(['warmup'], []);

        self::assertContains('success', array_column(\WP_CLI::$log, 0));

        try {
            $cli->cache(['nope'], []);
            self::fail('WP_CLI::error must throw in the test stub.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Usage:', $e->getMessage());
        }
    }
}
