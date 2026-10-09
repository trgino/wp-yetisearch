<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Integration\Search;

use Brain\Monkey\Functions;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Index\DocumentMapper;
use WpYetiSearch\Search\QueryBridge;
use WpYetiSearch\Search\ResultNormalizer;
use WpYetiSearch\Search\SearchService;
use WpYetiSearch\Tests\Integration\IntegrationTestCase;
use WpYetiSearch\Tests\Support\ArrayLogger;

final class QueryBridgeTest extends IntegrationTestCase
{
    public function testMainSearchQueryIsServedFromTheIndex(): void
    {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('in_the_loop')->justReturn(true);
        Functions\when('get_posts')->alias(static fn (array $args): array => array_map(
            static fn (int $id): \WP_Post => new \WP_Post(['ID' => $id, 'post_title' => 'Post ' . $id]),
            $args['post__in']
        ));
        // min_score=0: tiny 3-doc corpus scores below default 0.1 cutoff (see Task 5).
        $config = new Config(['master_enabled' => true, 'enable_fuzzy' => false, 'min_score' => 0.0]);
        $yeti = $this->makeYeti($config);
        $mapper = new DocumentMapper($config);
        $yeti->indexBatch(Config::INDEX, [
            $mapper->build(new \WP_Post(['ID' => 11, 'post_title' => 'Zebra facts', 'post_content' => 'Zebra stripes.']), [], [], [], null, 'https://example.test/?p=11'),
            $mapper->build(new \WP_Post(['ID' => 12, 'post_title' => 'Lion facts', 'post_content' => 'A zebra runs.']), [], [], [], null, 'https://example.test/?p=12'),
            $mapper->build(new \WP_Post(['ID' => 13, 'post_title' => 'Unrelated', 'post_content' => 'Nothing here.']), [], [], [], null, 'https://example.test/?p=13'),
        ]);
        $bridge = new QueryBridge($config, new SearchService($yeti, $config, new ResultNormalizer($config)), new ArrayLogger());
        $query = new \WP_Query(['s' => 'zebra', 'posts_per_page' => 1, 'paged' => 1, 'fields' => '', 'post_type' => 'any']);
        $query->is_search = true;

        $posts = $bridge->onPostsPreQuery(null, $query);

        self::assertIsArray($posts);
        self::assertCount(1, $posts);
        self::assertSame(2, $query->found_posts);
        self::assertSame(2, $query->max_num_pages);
        self::assertSame('', $query->get('yetisearch_suggestion'));
        $first = $posts[0];
        self::assertInstanceOf(\WP_Post::class, $first);
        self::assertStringContainsString('<mark>', $bridge->filterTitle('raw', $first->ID));
    }

    public function testVerifiedModeExcludesPrivatizedPostFromTotal(): void
    {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('in_the_loop')->justReturn(true);
        Functions\when('get_posts')->alias(static function (array $args): array {
            // Post 12 went private after indexing: only 11 stays public.
            $ids = array_values(array_intersect($args['post__in'], [11]));
            if (($args['fields'] ?? '') === 'ids') {
                return $ids;
            }
            return array_map(
                static fn (int $id): \WP_Post => new \WP_Post(['ID' => $id, 'post_title' => 'Post ' . $id]),
                $ids
            );
        });
        $config = new Config(['master_enabled' => true, 'enable_fuzzy' => false, 'min_score' => 0.0, 'search_mode' => 'verified']);
        $yeti = $this->makeYeti($config);
        $mapper = new DocumentMapper($config);
        $yeti->indexBatch(Config::INDEX, [
            $mapper->build(new \WP_Post(['ID' => 11, 'post_title' => 'Zebra facts', 'post_content' => 'Zebra stripes.']), [], [], [], null, 'https://example.test/?p=11'),
            $mapper->build(new \WP_Post(['ID' => 12, 'post_title' => 'Zebra tales', 'post_content' => 'More zebra.']), [], [], [], null, 'https://example.test/?p=12'),
        ]);
        $bridge = new QueryBridge($config, new SearchService($yeti, $config, new ResultNormalizer($config)), new ArrayLogger());
        $query = new \WP_Query(['s' => 'zebra', 'posts_per_page' => 10, 'paged' => 1, 'fields' => '', 'post_type' => 'any']);
        $query->is_search = true;

        $posts = $bridge->onPostsPreQuery(null, $query);

        self::assertIsArray($posts);
        self::assertCount(1, $posts);
        self::assertSame(1, $query->found_posts);
    }
}
