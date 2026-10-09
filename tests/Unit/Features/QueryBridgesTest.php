<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Features;

use WpYetiSearch\Core\Config;
use WpYetiSearch\Features\DSLBridge;
use WpYetiSearch\Features\FacetBridge;
use WpYetiSearch\Features\GeoBridge;
use WpYetiSearch\Tests\Unit\UnitTestCase;
use YetiSearch\Models\SearchQuery;

final class QueryBridgesTest extends UnitTestCase
{
    public function testGeoAddsRadiusInMetersDistanceSortAndFacet(): void
    {
        $geo = new GeoBridge(new Config(['geo_enabled' => true, 'facets_enabled' => true, 'geo_unit' => 'mi']), true);

        $query = $geo->apply(new SearchQuery('cafe'), 41.0, 29.0, 2.0);

        $filters = $query->getGeoFilters();
        self::assertEqualsWithDelta(3218.688, $filters['near']['radius'], 0.001);
        self::assertSame('asc', $filters['distance_sort']['direction']);
        self::assertSame([1.0, 5.0, 10.0, 50.0], $query->getFacets()['distance']['ranges']);
        self::assertSame('mi', $query->getFacets()['distance']['units']);
    }

    public function testGeoRejectsInvalidCoordinatesAndNeedsRtree(): void
    {
        // Since yetisearch 2.5.4 geo works without R-Tree (exact, slower).
        self::assertTrue((new GeoBridge(new Config(['geo_enabled' => true]), false))->isEnabled());
        self::assertTrue((new GeoBridge(new Config(['geo_enabled' => true]), false))->isUnindexed());
        self::assertFalse((new GeoBridge(new Config(['geo_enabled' => true]), true))->isUnindexed());
        self::assertFalse((new GeoBridge(new Config(['geo_enabled' => false]), true))->isEnabled());

        $this->expectException(\InvalidArgumentException::class);
        (new GeoBridge(new Config(['geo_enabled' => true]), true))->apply(new SearchQuery('x'), 95.0, 0.0);
    }

    public function testFacetsUsePostTypeAndPrimaryTermFields(): void
    {
        $query = (new FacetBridge(new Config(['facets_enabled' => true, 'facet_taxonomies' => ['category', 'genre'], 'facet_limit' => 5])))
            ->apply(new SearchQuery('x'));

        self::assertSame(['post_type', 'facet_category', 'facet_genre'], array_keys($query->getFacets()));
        self::assertSame(['limit' => 5], $query->getFacets()['facet_genre']);
    }

    public function testDslCopiesOnlyMetadataFiltersAndSorts(): void
    {
        $query = (new DSLBridge())->applyFilters(
            [
                'filter' => ['author' => ['eq' => '3'], 'content' => ['eq' => 'x'], 'metadata.facet_category' => 'News'],
                'sort' => '-date,title',
            ],
            new SearchQuery('x')
        );

        self::assertSame(['metadata.author_id', 'metadata.facet_category'], array_column($query->getFilters(), 'field'));
        self::assertSame(['metadata.post_date' => 'desc'], $query->getSort());
    }

    public function testDslMapsPriceAliasToMetadata(): void
    {
        $query = (new DSLBridge())->applyFilters(
            ['filter' => ['price' => ['gte' => '100', 'lte' => '500']]],
            new SearchQuery('x')
        );

        $filters = $query->getFilters();
        self::assertSame(['metadata.price', 'metadata.price'], array_column($filters, 'field'));
    }

    public function testDslSkipsPostTypeFilterOwnedByFrontQuery(): void
    {
        // frontQuery() owns metadata.post_type; a second AND-ed filter would empty all results.
        $query = (new DSLBridge())->applyFilters(
            ['filter' => ['post_type' => 'page'], 'sort' => '-date'],
            (new SearchQuery('x'))->filter('metadata.post_type', ['post'], 'in')
        );

        $fields = array_column($query->getFilters(), 'field');
        self::assertSame(['metadata.post_type'], $fields);
    }

    public function testRangeFacetForwardsFieldAndRanges(): void
    {
        $query = (new FacetBridge(new Config()))->applyRange(
            new SearchQuery('x'),
            'price_range',
            'price',
            [['to' => 100], ['from' => 100, 'to' => 200], ['from' => 200]]
        );

        $facets = $query->getFacets();
        self::assertSame('price', $facets['price_range']['field']);
        self::assertCount(3, $facets['price_range']['ranges']);
    }

    public function testEmptyRangesAddNoFacet(): void
    {
        $query = (new FacetBridge(new Config()))->applyRange(new SearchQuery('x'), 'price_range', 'price', []);

        self::assertSame([], $query->getFacets());
    }

    public function testPriceFacetBuildsPairsFromThresholds(): void
    {
        $config = new Config(['facets_enabled' => true, 'price_facet_enabled' => true, 'price_ranges' => [100.0, 500.0, 1000.0]]);
        $query = (new FacetBridge($config))->apply(new SearchQuery('x'));

        $ranges = $query->getFacets()['price_range']['ranges'];
        self::assertSame([['to' => 100.0], ['from' => 100.0, 'to' => 500.0], ['from' => 500.0, 'to' => 1000.0], ['from' => 1000.0]], $ranges);
    }

    public function testPriceFacetAbsentWhenDisabled(): void
    {
        $query = (new FacetBridge(new Config()))->apply(new SearchQuery('x'));

        self::assertArrayNotHasKey('price_range', $query->getFacets());
    }

    public function testEnrichFacetsAddsFilterSpecsToRangeBuckets(): void
    {
        $config = new Config(['facets_enabled' => true, 'price_facet_enabled' => true, 'price_ranges' => [100.0, 500.0]]);
        $bridge = new FacetBridge($config);
        $bridge->apply(new SearchQuery('x'));

        $facets = $bridge->enrichFacets([
            'price_range' => [
                ['value' => '0-100', 'count' => 1, 'to' => 100.0],
                ['value' => '100-500', 'count' => 2, 'from' => 100.0, 'to' => 500.0],
                ['value' => '500+', 'count' => 0, 'from' => 500.0],
            ],
            'post_type' => [['value' => 'post', 'count' => 3]],
        ]);

        self::assertSame(['price' => ['lte' => 100.0]], $facets['price_range'][0]['filter']);
        self::assertSame(['price' => ['gte' => 100.0, 'lte' => 500.0]], $facets['price_range'][1]['filter']);
        self::assertSame(['price' => ['gte' => 500.0]], $facets['price_range'][2]['filter']);
        self::assertArrayNotHasKey('filter', $facets['post_type'][0]);
    }

    public function testEnrichFacetsSkipsMissingAndMalformedBuckets(): void
    {
        $config = new Config(['facets_enabled' => true, 'price_facet_enabled' => true, 'price_ranges' => [100.0]]);
        $bridge = new FacetBridge($config);
        $bridge->apply(new SearchQuery('x'));

        $facets = $bridge->enrichFacets([
            'price_range' => ['junk', ['value' => '0-100', 'count' => 1, 'to' => 100.0]],
            'post_type' => 'junk',
        ]);

        self::assertSame('junk', $facets['price_range'][0]);
        self::assertSame(['price' => ['lte' => 100.0]], $facets['price_range'][1]['filter']);
        self::assertSame('junk', $facets['post_type']);
        self::assertSame(['other' => []], $bridge->enrichFacets(['other' => []]));
    }
}
