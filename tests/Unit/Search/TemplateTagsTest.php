<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Search;

use Brain\Monkey\Functions;
use WpYetiSearch\Tests\Unit\UnitTestCase;

require_once dirname(__DIR__, 3) . '/src/Search/template-tags.php';

final class TemplateTagsTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('home_url')->alias(static fn (string $p = ''): string => 'https://example.test/' . ltrim($p, '/'));
        Functions\when('add_query_arg')->alias(
            static function (array $args, string $url): string {
                return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
            }
        );
    }

    /** @return array<string, mixed> */
    private static function facets(): array
    {
        return [
            'price_range' => [
                ['value' => '0-100', 'count' => 1, 'to' => 100.0, 'filter' => ['price' => ['lte' => 100.0]]],
                ['value' => '100-500', 'count' => 2, 'from' => 100.0, 'to' => 500.0, 'filter' => ['price' => ['gte' => 100.0, 'lte' => 500.0]]],
                ['value' => '500+', 'count' => 0, 'from' => 500.0, 'filter' => ['price' => ['gte' => 500.0]]],
            ],
        ];
    }

    public function testFacetLinksUseFilterSpecs(): void
    {
        $query = new \WP_Query(['s' => 'boots', 'yetisearch_facets' => self::facets()]);

        $html = yetisearch_get_facet_links('price_range', $query);

        self::assertStringContainsString('filter%5Bprice%5D%5Blte%5D=100', $html);
        self::assertStringContainsString('filter%5Bprice%5D%5Bgte%5D=500', $html);
        self::assertStringContainsString('(2)', $html);
        self::assertStringContainsString('s=boots', $html);
    }

    public function testFacetLinksEmptyForUnknownFacet(): void
    {
        $query = new \WP_Query(['s' => 'boots', 'yetisearch_facets' => self::facets()]);

        self::assertSame('', yetisearch_get_facet_links('nope', $query));
        self::assertSame('', yetisearch_get_facet_links('price_range'));
    }

    public function testSuggestionReadsQueryVar(): void
    {
        self::assertSame('boots', yetisearch_get_suggestion(new \WP_Query(['yetisearch_suggestion' => 'boots'])));
        self::assertSame('', yetisearch_get_suggestion(new \WP_Query(['yetisearch_suggestion' => 42])));
        self::assertSame('', yetisearch_get_suggestion(new \WP_Query()));
        self::assertSame('', yetisearch_get_suggestion());
    }

    public function testTheSuggestionPrintsLinkOnlyWhenPresent(): void
    {
        Functions\when('get_search_link')->alias(static fn (string $s): string => 'https://example.test/?s=' . $s);
        $GLOBALS['wp_query'] = new \WP_Query(['yetisearch_suggestion' => 'boots']);
        try {
            ob_start();
            yetisearch_the_suggestion();
            $html = (string) ob_get_clean();
        } finally {
            unset($GLOBALS['wp_query']);
        }

        self::assertStringContainsString('Did you mean:', $html);
        self::assertStringContainsString('boots', $html);

        ob_start();
        yetisearch_the_suggestion();
        self::assertSame('', (string) ob_get_clean());
    }

    public function testFacetLinksSkipBucketsWithoutFilters(): void
    {
        $facets = ['price_range' => [['value' => 'all', 'count' => 3]]];
        $query = new \WP_Query(['s' => 'boots', 'yetisearch_facets' => $facets]);

        self::assertSame('', yetisearch_get_facet_links('price_range', $query));
        self::assertSame('', yetisearch_get_facet_links('price_range', new \WP_Query(['yetisearch_facets' => 'junk'])));
    }

    public function testTheFacetLinksEcho(): void
    {
        $query = new \WP_Query(['s' => 'boots', 'yetisearch_facets' => self::facets()]);
        $GLOBALS['wp_query'] = $query;
        try {
            ob_start();
            yetisearch_the_facet_links('price_range');
            $html = (string) ob_get_clean();
        } finally {
            unset($GLOBALS['wp_query']);
        }

        self::assertStringContainsString('<ul', $html);

        ob_start();
        yetisearch_the_facet_links('nope');
        self::assertSame('', (string) ob_get_clean());
    }

    public function testBucketLabels(): void
    {
        self::assertSame('Under 100', yetisearch_facet_bucket_label(['to' => 100.0]));
        self::assertSame('100 – 500', yetisearch_facet_bucket_label(['from' => 100.0, 'to' => 500.0]));
        self::assertSame('500+', yetisearch_facet_bucket_label(['from' => 500.0]));
        self::assertSame('sale', yetisearch_facet_bucket_label(['value' => 'sale']));
        self::assertSame('', yetisearch_facet_bucket_label([]));
    }

    public function testFormatNumberTrimsIntegersAndTrailingZeros(): void
    {
        self::assertSame('100', yetisearch_facet_format_number(100.0));
        self::assertSame('100.5', yetisearch_facet_format_number(100.50));
        self::assertSame('99.95', yetisearch_facet_format_number(99.95));
    }
}
