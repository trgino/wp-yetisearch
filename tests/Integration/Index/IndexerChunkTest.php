<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Integration\Index;

use Brain\Monkey\Functions;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Index\DocumentMapper;
use WpYetiSearch\Index\Indexer;
use WpYetiSearch\Tests\Integration\IntegrationTestCase;
use WpYetiSearch\Tests\Support\ArrayLogger;

/** Spec §10.4: a long post edited shorter leaves no ghost chunks. */
final class IndexerChunkTest extends IntegrationTestCase
{
    public function testShortenedPostLeavesNoGhostChunks(): void
    {
        Functions\when('get_permalink')->justReturn('https://example.test/?p=1');
        Functions\when('get_the_terms')->justReturn(false);
        Functions\when('get_post_meta')->justReturn('');
        // min_score=0: tiny single-doc corpus scores below default 0.1 cutoff (see Task 5).
        $config = new Config(['chunk_size' => 200, 'chunk_overlap' => 0, 'enable_fuzzy' => false, 'min_score' => 0.0]);
        $yeti = $this->makeYeti($config);
        $indexer = new Indexer($yeti, new DocumentMapper($config), new ArrayLogger());

        $long = 'Opening paragraph. ' . str_repeat('Lorem ipsum dolor sit amet consectetur. ', 40) . 'Closing ghostword sentence.';
        $indexer->sync(new \WP_Post(['ID' => 1, 'post_title' => 'Long post', 'post_content' => $long]));
        self::assertGreaterThan(0, $yeti->search(Config::INDEX, 'ghostword')['total']);

        $indexer->sync(new \WP_Post(['ID' => 1, 'post_title' => 'Long post', 'post_content' => 'Tiny body.']));

        self::assertSame(0, $yeti->search(Config::INDEX, 'ghostword')['total']);
        self::assertGreaterThan(0, $yeti->search(Config::INDEX, 'tiny')['total']);
    }
}
