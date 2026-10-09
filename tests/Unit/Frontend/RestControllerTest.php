<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Frontend;

use WpYetiSearch\Core\Config;
use WpYetiSearch\Features\DSLBridge;
use WpYetiSearch\Features\FacetBridge;
use WpYetiSearch\Features\GeoBridge;
use WpYetiSearch\Frontend\RestController;
use WpYetiSearch\Search\ResultNormalizer;
use WpYetiSearch\Search\SearchService;
use WpYetiSearch\Tests\Support\ArrayLogger;
use WpYetiSearch\Tests\Unit\UnitTestCase;
use YetiSearch\YetiSearch;

final class RestControllerTest extends UnitTestCase
{
    private static function controller(?YetiSearch $yeti, array $settings): RestController
    {
        $config = new Config($settings);
        $normalizer = new ResultNormalizer($config);
        return new RestController(
            $config,
            new SearchService($yeti, $config, $normalizer),
            $normalizer,
            new GeoBridge($config, true),
            new FacetBridge($config),
            new DSLBridge(),
            new ArrayLogger()
        );
    }

    public function testUnavailableWhenMasterIsOff(): void
    {
        $response = self::controller(\Mockery::mock(YetiSearch::class), ['master_enabled' => false])
            ->handle(new \WP_REST_Request(['q' => 'zebra']));

        self::assertInstanceOf(\WP_Error::class, $response);
        self::assertSame('yetisearch_unavailable', $response->get_error_code());
        self::assertSame(['status' => 503], $response->get_error_data());
    }

    public function testUnavailableWhenEngineIsMissing(): void
    {
        $response = self::controller(null, ['master_enabled' => true])->handle(new \WP_REST_Request(['q' => 'zebra']));

        self::assertInstanceOf(\WP_Error::class, $response);
        self::assertSame(['status' => 503], $response->get_error_data());
    }

    public function testRejectsUnindexedPostType(): void
    {
        $response = self::controller(\Mockery::mock(YetiSearch::class), ['master_enabled' => true])
            ->handle(new \WP_REST_Request(['q' => 'zebra', 'post_type' => 'attachment']));

        self::assertInstanceOf(\WP_Error::class, $response);
        self::assertSame('yetisearch_invalid_post_type', $response->get_error_code());
    }

    public function testArgsBoundQueryLengthAndLimit(): void
    {
        $args = self::controller(null, [])->args();

        self::assertSame(200, $args['q']['maxLength']);
        self::assertSame(DSLBridge::MAX_LIMIT, $args['limit']['maximum']);
        self::assertSame(['typeahead', 'search'], $args['context']['enum']);
    }

    public function testVerifiedModeRecountsPublicPosts(): void
    {        $engine = \Mockery::mock(\YetiSearch\Search\SearchEngine::class);
        $engine->shouldReceive('search')->once()->andReturn(new \YetiSearch\Models\SearchResults([
            ['id' => '21', 'score' => 5.0, 'document' => ['title' => 'Twenty-one', 'url' => 'https://example.test/?p=21'], 'highlights' => [], 'metadata' => ['post_id' => 21, 'post_type' => 'post']],
            ['id' => '22', 'score' => 4.0, 'document' => ['title' => 'Twenty-two', 'url' => 'https://example.test/?p=22'], 'highlights' => [], 'metadata' => ['post_id' => 22, 'post_type' => 'post']],
        ], 2));
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('getSearchEngine')->with(Config::INDEX)->andReturn($engine);
        \Brain\Monkey\Functions\when('get_posts')->justReturn([21]);

        $response = self::controller($yeti, ['master_enabled' => true, 'search_mode' => 'verified'])
            ->handle(new \WP_REST_Request(['q' => 'zebra']));

        self::assertInstanceOf(\WP_REST_Response::class, $response);
        $data = $response->get_data();
        self::assertSame(1, $data['total']);
        self::assertSame([21], array_column($data['items'], 'id'));
    }

    public function testSearchResponseCarriesConfigurableCacheControl(): void
    {
        $engine = \Mockery::mock(\YetiSearch\Search\SearchEngine::class);
        $engine->shouldReceive('search')->andReturn(new \YetiSearch\Models\SearchResults([
            ['id' => '21', 'score' => 5.0, 'document' => ['title' => 'Twenty-one', 'url' => 'https://example.test/?p=21'], 'highlights' => [], 'metadata' => ['post_id' => 21, 'post_type' => 'post']],
        ], 1));
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('getSearchEngine')->with(Config::INDEX)->andReturn($engine);
        \Brain\Monkey\Functions\when('get_posts')->justReturn([21]);

        $response = self::controller($yeti, ['master_enabled' => true, 'rest_cache_max_age' => 120])
            ->handle(new \WP_REST_Request(['q' => 'zebra']));

        self::assertInstanceOf(\WP_REST_Response::class, $response);
        self::assertSame('public, max-age=120', $response->get_headers()['Cache-Control'] ?? null);
    }

    public function testZeroCacheAgeDisablesClientCaching(): void
    {
        $engine = \Mockery::mock(\YetiSearch\Search\SearchEngine::class);
        $engine->shouldReceive('search')->andReturn(new \YetiSearch\Models\SearchResults([
            ['id' => '21', 'score' => 5.0, 'document' => ['title' => 'Twenty-one', 'url' => 'https://example.test/?p=21'], 'highlights' => [], 'metadata' => ['post_id' => 21, 'post_type' => 'post']],
        ], 1));
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('getSearchEngine')->with(Config::INDEX)->andReturn($engine);
        \Brain\Monkey\Functions\when('get_posts')->justReturn([21]);

        $response = self::controller($yeti, ['master_enabled' => true, 'rest_cache_max_age' => 0])
            ->handle(new \WP_REST_Request(['q' => 'zebra']));

        self::assertInstanceOf(\WP_REST_Response::class, $response);
        self::assertSame('no-store', $response->get_headers()['Cache-Control'] ?? null);
    }

    public function testRegisterRoutesRegistersSearchEndpoint(): void
    {
        \Brain\Monkey\Functions\expect('register_rest_route')->once()->with(
            RestController::NAMESPACE,
            '/search',
            \Mockery::on(static fn (array $a): bool => $a['methods'] === 'GET' && $a['permission_callback'] === '__return_true')
        );

        self::controller(null, [])->registerRoutes();
        self::controller(null, [])->register();

        self::assertNotFalse(has_action('rest_api_init'));
    }

    public function testRejectsEmptyAndOversizedQueries(): void
    {
        $controller = self::controller(\Mockery::mock(YetiSearch::class), ['master_enabled' => true]);

        foreach (['   ', str_repeat('x', 201)] as $q) {
            $response = $controller->handle(new \WP_REST_Request(['q' => $q]));

            self::assertInstanceOf(\WP_Error::class, $response);
            self::assertSame('yetisearch_invalid_query', $response->get_error_code());
            self::assertSame(['status' => 400], $response->get_error_data());
        }
    }

    private static function searchingController(array $settings): RestController
    {
        $engine = \Mockery::mock(\YetiSearch\Search\SearchEngine::class);
        $engine->shouldReceive('search')->andReturn(new \YetiSearch\Models\SearchResults([], 0));
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('getSearchEngine')->andReturn($engine);
        \Brain\Monkey\Functions\when('get_posts')->justReturn([]);

        return self::controller($yeti, ['master_enabled' => true] + $settings);
    }

    public function testFacetsFlagAppliesFacetBridges(): void
    {
        $response = self::searchingController([])->handle(new \WP_REST_Request(['q' => 'zebra', 'facets' => true]));

        self::assertInstanceOf(\WP_REST_Response::class, $response);
        self::assertSame([], $response->get_data()['items']);
    }

    public function testGeoCoordinatesFilterTheQuery(): void
    {
        $response = self::searchingController(['geo_enabled' => true])
            ->handle(new \WP_REST_Request(['q' => 'zebra', 'lat' => 41.0, 'lng' => 29.0, 'radius' => 10.0]));

        self::assertInstanceOf(\WP_REST_Response::class, $response);
    }

    public function testOutOfRangeCoordinatesAreBadRequest(): void
    {
        $response = self::searchingController(['geo_enabled' => true])
            ->handle(new \WP_REST_Request(['q' => 'zebra', 'lat' => 999.0, 'lng' => 29.0]));

        self::assertInstanceOf(\WP_Error::class, $response);
        self::assertSame('yetisearch_invalid_query', $response->get_error_code());
        self::assertStringContainsString('Invalid coordinates', (string) $response->get_error_message());
    }

    public function testEngineFailureIsServiceUnavailable(): void
    {
        $engine = \Mockery::mock(\YetiSearch\Search\SearchEngine::class);
        $engine->shouldReceive('search')->andThrow(new \RuntimeException('db gone'));
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('getSearchEngine')->andReturn($engine);

        $response = self::controller($yeti, ['master_enabled' => true])->handle(new \WP_REST_Request(['q' => 'zebra']));

        self::assertInstanceOf(\WP_Error::class, $response);
        self::assertSame('yetisearch_unavailable', $response->get_error_code());
        self::assertSame(['status' => 503], $response->get_error_data());
    }
}
