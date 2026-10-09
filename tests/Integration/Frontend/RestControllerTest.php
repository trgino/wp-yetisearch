<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Integration\Frontend;

use Brain\Monkey\Functions;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Features\DSLBridge;
use WpYetiSearch\Features\FacetBridge;
use WpYetiSearch\Features\GeoBridge;
use WpYetiSearch\Frontend\RestController;
use WpYetiSearch\Index\DocumentMapper;
use WpYetiSearch\Search\ResultNormalizer;
use WpYetiSearch\Search\SearchService;
use WpYetiSearch\Tests\Integration\IntegrationTestCase;
use WpYetiSearch\Tests\Support\ArrayLogger;

/** Spec §10.7: REST only returns normalized fields of posts that are still public. */
final class RestControllerTest extends IntegrationTestCase
{
    public function testRestNeverReturnsRawDocuments(): void
    {
        // min_score=0: tiny 2-doc corpus scores below default 0.1 cutoff (see Task 5).
        $config = new Config(['master_enabled' => true, 'enable_fuzzy' => false, 'min_score' => 0.0]);
        $yeti = $this->makeYeti($config);
        $mapper = new DocumentMapper($config);
        $yeti->indexBatch(Config::INDEX, [
            $mapper->build(new \WP_Post(['ID' => 21, 'post_title' => 'Public zebra', 'post_content' => 'Zebra.']), [], ['secret-meta-value'], [], null, 'https://example.test/?p=21'),
            $mapper->build(new \WP_Post(['ID' => 22, 'post_title' => 'Now private zebra', 'post_content' => 'Zebra.']), [], [], [], null, 'https://example.test/?p=22'),
        ]);
        // Post 22 became private after indexing: the database no longer returns it.
        Functions\when('get_posts')->justReturn([21]);
        $normalizer = new ResultNormalizer($config);
        $controller = new RestController(
            $config,
            new SearchService($yeti, $config, $normalizer),
            $normalizer,
            new GeoBridge($config, true),
            new FacetBridge($config),
            new DSLBridge(),
            new ArrayLogger()
        );

        $response = $controller->handle(new \WP_REST_Request(['q' => 'zebra', 'limit' => 10, 'page' => 1, 'context' => 'search']));

        self::assertInstanceOf(\WP_REST_Response::class, $response);
        $data = $response->get_data();
        self::assertSame([21], array_column($data['items'], 'id'));
        self::assertSame(['id', 'title', 'title_html', 'url', 'excerpt_html', 'post_type'], array_keys($data['items'][0]));
        self::assertStringNotContainsString('secret-meta-value', (string) json_encode($data));
    }
}
