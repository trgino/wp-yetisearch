<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Integration\Search;

use WpYetiSearch\Core\Config;
use WpYetiSearch\Features\FacetBridge;
use WpYetiSearch\Index\DocumentMapper;
use WpYetiSearch\Search\ResultNormalizer;
use WpYetiSearch\Search\SearchService;
use WpYetiSearch\Tests\Integration\IntegrationTestCase;
use YetiSearch\Models\SearchQuery;

final class PriceFacetTest extends IntegrationTestCase
{
    public function testBucketsCountIncludingEmptyOnes(): void
    {
        // min_score=0: tiny corpora score below the default cutoff (see Task 5).
        $config = new Config([
            'enable_fuzzy' => false,
            'min_score' => 0.0,
            'facets_enabled' => true,
            'price_facet_enabled' => true,
            'price_ranges' => [100.0, 500.0, 1000.0],
        ]);
        $yeti = $this->makeYeti($config);
        $mapper = new DocumentMapper($config);
        $docs = [];
        foreach ([[1, 'Cheap widget', 50.0], [2, 'Mid widget', 150.0], [3, 'Dear widget', 2000.0]] as [$id, $title, $price]) {
            $doc = $mapper->build(new \WP_Post(['ID' => $id, 'post_title' => $title, 'post_content' => 'Widget for sale.']), [], [], [], null, 'https://example.test/?p=' . $id);
            $doc['metadata']['price'] = $price;
            $docs[] = $doc;
        }
        $yeti->indexBatch(Config::INDEX, $docs);
        $service = new SearchService($yeti, $config, new ResultNormalizer($config));

        $query = (new FacetBridge($config))->apply(new SearchQuery('widget'));
        $result = $service->run($query);

        $buckets = $result['facets']['price_range'] ?? [];
        self::assertCount(4, $buckets, 'empty buckets are included');
        self::assertSame([1, 1, 0, 1], array_column($buckets, 'count'));
    }
}
