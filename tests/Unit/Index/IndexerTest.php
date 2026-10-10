<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Index;

use Brain\Monkey\Functions;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Index\DocumentMapper;
use WpYetiSearch\Index\Indexer;
use WpYetiSearch\Tests\Support\ArrayLogger;
use WpYetiSearch\Tests\Unit\UnitTestCase;
use YetiSearch\Search\SearchEngine;
use YetiSearch\YetiSearch;

final class IndexerTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_permalink')->justReturn('https://example.test/?p=7');
        Functions\when('get_the_terms')->justReturn(false);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
    }

    private static function post(array $props = []): \WP_Post
    {
        return new \WP_Post($props + ['ID' => 7, 'post_title' => 'Seven', 'post_content' => 'Body']);
    }

    public function testIndexingFailureIsLoggedNotThrown(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('createIndex')->once();
        $yeti->shouldReceive('deleteByIdPrefix')->andThrow(new \RuntimeException('database is locked'));
        $logger = new ArrayLogger();

        (new Indexer($yeti, new DocumentMapper(new Config()), $logger))->sync(self::post());

        self::assertTrue($logger->hasLevel('error'));
        self::assertStringContainsString('database is locked', (string) $logger->records[0]['context']['exception']->getMessage());
    }

    public function testPublishedPostReplacesItsChunks(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('createIndex')->once();
        $yeti->shouldReceive('deleteByIdPrefix')->once()->with(Config::INDEX, '7#', false)->ordered();
        $yeti->shouldReceive('update')->once()->with(Config::INDEX, \Mockery::on(static fn (array $d): bool => $d['id'] === '7'))->ordered();
        $yeti->shouldReceive('clearCache')->once()->ordered();

        (new Indexer($yeti, new DocumentMapper(new Config()), new ArrayLogger()))->sync(self::post());
    }

    public function testUnpublishedPostIsRemovedWithItsChunks(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('delete')->once()->with(Config::INDEX, '7');
        $yeti->shouldReceive('deleteByIdPrefix')->once()->with(Config::INDEX, '7#', false);
        $yeti->shouldReceive('clearCache')->once();
        $yeti->shouldNotReceive('update');

        (new Indexer($yeti, new DocumentMapper(new Config()), new ArrayLogger()))->sync(self::post(['post_status' => 'private']));
    }

    public function testRevisionsAreSkipped(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(3);
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldNotReceive('update', 'delete', 'deleteByIdPrefix');

        (new Indexer($yeti, new DocumentMapper(new Config()), new ArrayLogger()))
            ->onAfterInsertPost(7, self::post(), true, null);
    }

    public function testRegisterIsNoOpWithoutEngine(): void
    {
        Functions\expect('add_action')->never();

        (new Indexer(null, new DocumentMapper(new Config()), new ArrayLogger()))->register();
    }

    public function testRegisterHooksAfterInsert(): void
    {
        $indexer = new Indexer(\Mockery::mock(YetiSearch::class), new DocumentMapper(new Config()), new ArrayLogger());

        $indexer->register();

        self::assertSame(20, has_action('wp_after_insert_post', [$indexer, 'onAfterInsertPost']));
        self::assertNotFalse(has_action('deleted_post', [$indexer, 'onDeletedPost']));
        self::assertNotFalse(has_action('transition_post_status', [$indexer, 'onTransitionStatus']));
    }

    public function testTransitionFiresOnlyOnRealStatusChange(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldNotReceive('update', 'delete', 'deleteByIdPrefix');

        (new Indexer($yeti, new DocumentMapper(new Config()), new ArrayLogger()))
            ->onTransitionStatus('publish', 'publish', self::post());
    }

    public function testTransitionToPublishSyncs(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('createIndex')->once();
        $yeti->shouldReceive('deleteByIdPrefix')->once()->with(Config::INDEX, '7#', false);
        $yeti->shouldReceive('update')->once();
        $yeti->shouldReceive('clearCache')->once();

        (new Indexer($yeti, new DocumentMapper(new Config()), new ArrayLogger()))
            ->onTransitionStatus('publish', 'future', self::post());
    }

    public function testInsertSyncsPublishedPost(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('createIndex')->once();
        $yeti->shouldReceive('deleteByIdPrefix')->once()->with(Config::INDEX, '7#', false);
        $yeti->shouldReceive('update')->once();
        $yeti->shouldReceive('clearCache')->once();

        (new Indexer($yeti, new DocumentMapper(new Config()), new ArrayLogger()))
            ->onAfterInsertPost(7, self::post(), false, null);
    }

    public function testDeleteSweepsEveryPluginIndex(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('listIndices')->once()->andReturn([
            ['name' => 'wp_posts'],
            ['name' => 'wp_posts_tr'],
            ['name' => 'foreign_index'],
            'junk',
            ['name' => 'wp_posts'],
        ]);
        $yeti->shouldReceive('delete')->twice()->with(\Mockery::on(static fn (string $i): bool => in_array($i, [Config::INDEX, 'wp_posts_tr'], true)), '7');
        $yeti->shouldReceive('deleteByIdPrefix')->twice();
        $yeti->shouldReceive('clearCache')->twice();

        (new Indexer($yeti, new DocumentMapper(new Config()), new ArrayLogger()))->onDeletedPost(7);
    }

    public function testDeleteFallsBackToBaseIndexWhenListingFails(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('listIndices')->once()->andThrow(new \RuntimeException('gone'));
        $yeti->shouldReceive('delete')->once()->with(Config::INDEX, '7');
        $yeti->shouldReceive('deleteByIdPrefix')->once();
        $yeti->shouldReceive('clearCache')->once();
        $logger = new ArrayLogger();

        (new Indexer($yeti, new DocumentMapper(new Config()), $logger))->onDeletedPost(7);

        self::assertTrue($logger->hasLevel('debug'));
    }

    public function testNullEngineIsAlwaysNoOp(): void
    {
        $indexer = new Indexer(null, new DocumentMapper(new Config()), new ArrayLogger());

        $indexer->onDeletedPost(7);
        $indexer->upsert(['id' => '7'], Config::INDEX);
        $indexer->remove(7, Config::INDEX);

        $this->expectNotToPerformAssertions();
    }

    public function testRemoveFailureIsLoggedNotThrown(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('delete')->once()->andThrow(new \RuntimeException('locked'));
        $logger = new ArrayLogger();

        (new Indexer($yeti, new DocumentMapper(new Config()), $logger))->remove(7, Config::INDEX);

        self::assertTrue($logger->hasLevel('error'));
    }
}
