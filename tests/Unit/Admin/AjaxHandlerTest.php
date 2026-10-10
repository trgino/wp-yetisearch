<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use WpYetiSearch\Admin\AjaxHandler;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Core\FileLog;
use WpYetiSearch\Core\HealthChecker;
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

final class AjaxHandlerTest extends UnitTestCase
{
    private string $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploads = $this->tempDir('yetisearch-ajax-');
        Functions\when('wp_upload_dir')->justReturn(['basedir' => $this->uploads, 'baseurl' => 'https://example.test/wp-content/uploads']);
        Functions\when('site_url')->justReturn('https://example.test');
        Functions\when('wp_mkdir_p')->alias(static fn (string $d): bool => is_dir($d) || mkdir($d, 0777, true));
        Functions\when('get_option')->justReturn(false);
        Functions\when('get_permalink')->justReturn('https://example.test/');
        Functions\when('get_the_terms')->justReturn(false);
        Functions\when('get_post_meta')->justReturn('');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->uploads);
        parent::tearDown();
    }

    /** @param array<string, mixed> $settings */
    private function handler(?YetiSearch $yeti = null, ?\Closure $factory = null, array $settings = []): AjaxHandler
    {
        $config = new Config($settings);
        $yeti ??= \Mockery::mock(YetiSearch::class);
        $logger = new ArrayLogger();
        $mapper = new DocumentMapper($config);
        $factory ??= static function (array $args): \WP_Query {
            $query = new \WP_Query($args);
            $query->posts = [];
            $query->found_posts = 0;
            $query->max_num_pages = 0;
            return $query;
        };
        $search = new SearchService($yeti, $config, new ResultNormalizer($config));
        return new AjaxHandler(
            $config,
            new BulkIndexer($yeti, $mapper, $config, $logger, $factory),
            new SemanticBridge($yeti, $config, $logger),
            new CacheBridge($yeti, $config, $search, $logger),
            new HealthChecker(),
            new StorageManager($config, new ServerSecurity()),
            new ServerSecurity(),
            $search,
            $logger
        );
    }

    public function testReindexRunsOneBulkPage(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('createIndex')->andReturn(\Mockery::mock(\YetiSearch\Index\Indexer::class));
        $yeti->shouldReceive('stemmingFor')->andReturn('english');
        $yeti->shouldReceive('indexBatch')->once();
        $yeti->shouldReceive('deleteByIdPrefix')->once()->with(Config::INDEX, '1#', false);
        $factory = function (array $args): \WP_Query {
            $query = new \WP_Query($args);
            $query->posts = [new \WP_Post(['ID' => 1, 'post_title' => 'One'])];
            $query->found_posts = 1;
            $query->max_num_pages = 1;
            return $query;
        };
        $yeti->shouldReceive('rebuildFts')->once()->with(Config::INDEX);
        $yeti->shouldReceive('clearCache')->once();
        Functions\expect('update_option')->once()->with(Config::NEEDS_REINDEX_OPTION, false);

        $result = $this->handler($yeti, $factory)->reindex(['page' => 1, 'per_page' => 100]);

        self::assertTrue($result['finished']);
        self::assertSame(1, $result['processed']);
    }

    public function testRegisterAddsAjaxActions(): void
    {
        $this->handler()->register();

        foreach (['reindex', 'embed', 'calibrate', 'cache_clear', 'cache_warmup', 'probe', 'health', 'logs', 'logs_clear', 'dircheck', 'checklist_dismiss'] as $action) {
            self::assertNotFalse(has_action('wp_ajax_yetisearch_' . $action), $action);
        }
    }

    public function testLogsReturnsTailAndClears(): void
    {
        $handler = $this->handler();
        $lines = $handler->logs(['lines' => 50]);

        self::assertArrayHasKey('lines', $lines);
        self::assertArrayHasKey('truncated', $lines);

        $cleared = $handler->logsClear();

        self::assertTrue($cleared['cleared']);
    }

    public function testDircheckReportsInvalidPath(): void
    {
        $result = $this->handler()->dispatch('dircheck', ['path' => 'relative/path']);

        self::assertFalse($result['ok']);
        self::assertSame('not_absolute', $result['error']);
    }

    public function testDircheckAcceptsWritableDirectory(): void
    {
        $result = $this->handler()->dispatch('dircheck', ['path' => $this->uploads . '/custom']);

        self::assertTrue($result['ok'], $result['error'] ?? '');
    }

    public function testChecklistDismissSetsUserFlag(): void
    {
        Functions\when('get_current_user_id')->justReturn(7);
        Functions\expect('update_user_meta')->once()->with(7, 'yetisearch_hide_checklist', 1);

        $this->handler()->dispatch('checklist_dismiss', []);
    }

    public function testLogsSanitizesLevelFilter(): void
    {        // Stored levels are lowercase (PSR); an uppercase filter must still match.
        $config = new Config();
        $dir = (new StorageManager($config, new ServerSecurity()))->storageDir();
        (new FileLog($dir))->append('error', 'boom');

        $rows = $this->handler()->logs(['level' => 'ERROR']);

        self::assertCount(1, $rows['lines']);
    }

    public function testDispatchRoutesEveryAction(): void
    {
        Functions\when('update_option')->justReturn(true);
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('isSemanticEnabled')->andReturn(false);
        $yeti->shouldReceive('clearCache')->twice();
        $yeti->shouldReceive('listIndices')->andReturn([]);
        $engine = \Mockery::mock(\YetiSearch\Search\SearchEngine::class);
        $engine->shouldReceive('search')->andReturn(new \YetiSearch\Models\SearchResults([], 0));
        $yeti->shouldReceive('getSearchEngine')->andReturn($engine);
        $yeti->shouldReceive('countDocuments')->with(Config::INDEX)->andReturn(7);
        $yeti->shouldReceive('getStats')->with(Config::INDEX)->andReturn(['tables' => 1]);
        $yeti->shouldReceive('getCacheStats')->andReturn(['hits' => 2]);
        $handler = $this->handler($yeti, null, ['warmup_queries' => ['alpha']]);

        self::assertSame('disabled', $handler->dispatch('calibrate', [])['error']);
        self::assertTrue($handler->dispatch('cache_clear', [])['cleared']);
        $warmup = $handler->dispatch('cache_warmup', []);
        self::assertSame(0, $warmup['failed']);
        self::assertGreaterThan(0, $warmup['warmed']);
        self::assertArrayHasKey('ready', $handler->dispatch('health', []));
        self::assertSame('disabled', $handler->dispatch('embed', [])['error']);
        self::assertArrayHasKey('lines', $handler->dispatch('logs', ['lines' => 5]));
        self::assertTrue($handler->dispatch('reindex', [])['finished']);
        self::assertTrue($handler->dispatch('logs_clear', [])['cleared']);
        self::assertTrue($handler->dispatch('dircheck', ['path' => ''])['ok']);
        $stats = $handler->stats();
        self::assertSame(7, $stats['index']['documents']);
        self::assertSame([], $stats['embeddings'], 'semantic disabled');
        self::assertSame(['hits' => 2], $stats['cache']);
        $this->expectException(\InvalidArgumentException::class);
        $handler->dispatch('nope', []);
    }

    public function testDispatchProbeReturnsResultAndSnippet(): void
    {
        Functions\when('wp_remote_get')->justReturn(['code' => 403, 'body' => 'Forbidden']);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->alias(static fn (array $r): int => $r['code']);
        Functions\when('wp_remote_retrieve_body')->alias(static fn (array $r): string => $r['body']);
        mkdir($this->uploads . '/yetisearch', 0777, true);

        $result = $this->handler()->dispatch('probe', []);

        self::assertSame('safe', $result['result']);
        self::assertSame('', $result['snippet'], 'apache needs no snippet');
    }

    public function testReindexParsesPostTypeList(): void
    {
        Functions\when('update_option')->justReturn(true);
        $seen = [];
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('createIndex')->andReturn(\Mockery::mock(\YetiSearch\Index\Indexer::class));
        $yeti->shouldReceive('stemmingFor')->andReturn('english');
        $yeti->shouldReceive('indexBatch')->once();
        $yeti->shouldReceive('deleteByIdPrefix')->once();
        $yeti->shouldReceive('rebuildFts')->once();
        $yeti->shouldReceive('clearCache')->once();
        $factory = function (array $args) use (&$seen): \WP_Query {
            $seen = $args;
            $query = new \WP_Query($args);
            $query->posts = [new \WP_Post(['ID' => 1, 'post_title' => 'One'])];
            $query->found_posts = 1;
            $query->max_num_pages = 1;
            return $query;
        };

        $result = $this->handler($yeti, $factory, ['indexed_post_types' => ['post', 'page']])
            ->reindex(['page' => 1, 'per_page' => 10, 'post_type' => 'post,page,nope']);

        self::assertTrue($result['finished']);
        self::assertSame(['post', 'page'], $seen['post_type'], 'unknown types are filtered against settings');
    }

    public function testEmbedClampsBatchToBounds(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('isSemanticEnabled')->andReturn(true);
        $yeti->shouldReceive('listIndices')->andReturn([]);
        $yeti->shouldReceive('embedPending')->once()->ordered()->with(Config::INDEX, 1)
            ->andReturn(['embedded' => 0, 'pending' => 0, 'total' => 0, 'error' => null]);
        $yeti->shouldReceive('embedPending')->once()->ordered()->with(Config::INDEX, 500)
            ->andReturn(['embedded' => 0, 'pending' => 0, 'total' => 0, 'error' => null]);
        $handler = $this->handler($yeti, null, ['semantic_enabled' => true]);

        self::assertSame(0, $handler->embed(['batch' => 0])['pending']);
        self::assertSame(0, $handler->embed(['batch' => 9999])['pending']);
    }

    public function testHandleRejectsWithoutCapability(): void
    {
        $sent = [];
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('wp_send_json_error')->alias(function (mixed $data, mixed $code = null) use (&$sent): void {
            $sent['error'] = [$data, $code];
        });
        Functions\when('wp_send_json_success')->alias(function () use (&$sent): void {
            $sent['success'] = true;
        });

        $this->handler()->handle('health', []);

        self::assertSame(['error' => 'forbidden'], $sent['error'][0] ?? null);
        self::assertSame(403, $sent['error'][1] ?? null);
        self::assertArrayNotHasKey('success', $sent, 'must stop after the capability failure');
    }

    public function testHandleDispatchesAndReturnsJson(): void
    {
        Functions\when('update_option')->justReturn(true);
        $sent = [];
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_send_json_error')->alias(function (mixed $data, mixed $code = null) use (&$sent): void {
            $sent['error'] = [$data, $code];
        });
        Functions\when('wp_send_json_success')->alias(function (mixed $data) use (&$sent): void {
            $sent['success'] = $data;
        });

        $this->handler()->handle('health', []);

        self::assertArrayHasKey('ready', $sent['success'] ?? []);
        self::assertArrayNotHasKey('error', $sent);
    }

    public function testHandleReturnsDispatchErrorsAsJson(): void
    {
        $sent = [];
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_send_json_error')->alias(function (mixed $data, mixed $code = null) use (&$sent): void {
            $sent['error'] = [$data, $code];
        });
        Functions\when('wp_send_json_success')->alias(function (mixed $data) use (&$sent): void {
            $sent['success'] = $data;
        });

        $this->handler()->handle('nope', []);

        self::assertSame('Unknown action.', $sent['error'][0]['error'] ?? null);
        self::assertSame(500, $sent['error'][1] ?? null);
    }

    public function testHandlePassesBulkErrorsThroughAsSuccessPayload(): void
    {
        Functions\when('update_option')->justReturn(true);
        $sent = [];
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_send_json_success')->alias(function (mixed $data) use (&$sent): void {
            $sent['success'] = $data;
        });
        $factory = static function (): \WP_Query {
            throw new \RuntimeException('db gone');
        };

        $this->handler(\Mockery::mock(YetiSearch::class), $factory)->handle('reindex', ['page' => 1]);

        self::assertSame('db gone', $sent['success']['error'] ?? null);
        self::assertFalse($sent['success']['finished'] ?? true);
    }
}
