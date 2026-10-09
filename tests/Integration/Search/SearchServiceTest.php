<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Integration\Search;

use WpYetiSearch\Core\Config;
use WpYetiSearch\Index\DocumentMapper;
use WpYetiSearch\Search\ResultNormalizer;
use WpYetiSearch\Search\SearchService;
use WpYetiSearch\Tests\Integration\IntegrationTestCase;

final class SearchServiceTest extends IntegrationTestCase
{
    /** Spec §10.3: a long post appears once and the total counts posts, not chunks. */
    public function testChunkedPostAppearsOnceWithCorrectTotal(): void
    {
        // min_score=0: tiny 2-doc corpus scores below default 0.1 cutoff (see Task 5).
        $config = new Config(['chunk_size' => 200, 'chunk_overlap' => 0, 'enable_fuzzy' => false, 'min_score' => 0.0]);
        $yeti = $this->makeYeti($config);
        $mapper = new DocumentMapper($config);
        $long = str_repeat('Zebra stripes appear in every paragraph of this long article. ', 30);
        $yeti->indexBatch(Config::INDEX, [
            $mapper->build(new \WP_Post(['ID' => 1, 'post_title' => 'Long zebra', 'post_content' => $long]), [], [], [], null, 'https://example.test/?p=1'),
            $mapper->build(new \WP_Post(['ID' => 2, 'post_title' => 'Short zebra', 'post_content' => 'A zebra.']), [], [], [], null, 'https://example.test/?p=2'),
        ]);
        $service = new SearchService($yeti, $config, new ResultNormalizer($config));

        $result = $service->run($service->frontQuery('zebra', ['post'], 10, 1));

        self::assertSame(2, $result['total']);
        self::assertCount(2, $result['items']);
        self::assertEqualsCanonicalizing([1, 2], array_column($result['items'], 'post_id'));
    }

    /** Spec §10.8: stored text with markup is escaped; only the highlight tag survives. */
    public function testHighlightHtmlIsEscapedExceptHighlightTag(): void
    {
        // min_score=0: single-doc BM25 raw score is below default 0.1 cutoff (see Task 5).
        $config = new Config(['enable_fuzzy' => false, 'highlight_tag' => 'mark', 'min_score' => 0.0]);
        $yeti = $this->makeYeti($config);
        $mapper = new DocumentMapper($config);
        $yeti->indexBatch(Config::INDEX, [
            $mapper->build(new \WP_Post([
                'ID' => 9,
                'post_title' => 'Zebra &lt;img src=x onerror=alert(1)&gt; title',
                'post_content' => 'Tom &lt;script&gt;alert(1)&lt;/script&gt; loves zebra stripes.',
            ]), [], [], [], null, 'https://example.test/?p=9'),
        ]);
        $service = new SearchService($yeti, $config, new ResultNormalizer($config));

        $item = $service->run($service->frontQuery('zebra', ['post'], 10, 1))['items'][0];

        foreach (['title_html', 'excerpt_html'] as $field) {
            self::assertStringContainsString('<mark>', $item[$field], $field);
            self::assertStringNotContainsString('<img', $item[$field], $field);
            self::assertStringNotContainsString('<script', $item[$field], $field);
        }
        self::assertStringContainsString('&lt;img', $item['title_html']);
        self::assertStringContainsString('&lt;script&gt;', $item['excerpt_html']);
    }
}
