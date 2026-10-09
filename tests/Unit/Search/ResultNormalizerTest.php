<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Search;

use Brain\Monkey\Functions;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Search\ResultNormalizer;
use WpYetiSearch\Tests\Unit\UnitTestCase;

final class ResultNormalizerTest extends UnitTestCase
{
    public function testNormalizeSkipsNonArrayRows(): void
    {
        $items = (new ResultNormalizer(new Config()))->normalize(['junk', 42]);

        self::assertSame([], $items);
    }

    public function testOnlyPublicShortCircuitsOnEmpty(): void
    {
        Functions\expect('get_posts')->never();

        self::assertSame([], (new ResultNormalizer(new Config()))->onlyPublic([]));
    }

    public function testChunksOfTheSamePostCollapseToOneItem(): void
    {
        $items = (new ResultNormalizer(new Config()))->normalize([
            ['id' => '4#chunk1', 'score' => 9.0, 'document' => ['title' => 'Four', 'url' => 'https://example.test/4'], 'highlights' => [], 'metadata' => ['post_id' => 4, 'post_type' => 'post']],
            ['id' => '4#chunk2', 'score' => 5.0, 'document' => ['title' => 'Four'], 'highlights' => [], 'metadata' => ['post_id' => 4, 'post_type' => 'post']],
            ['id' => '5', 'score' => 3.0, 'document' => ['title' => 'Five'], 'highlights' => [], 'metadata' => []],
        ]);

        self::assertSame([4, 5], array_column($items, 'post_id'), 'post id falls back to the document id');
        self::assertSame(9.0, $items[0]['score']);
    }

    public function testSafeHtmlOnlyAllowsTheConfiguredTag(): void
    {
        $normalizer = new ResultNormalizer(new Config(['highlight_tag' => 'mark']));

        $html = $normalizer->safeHtml('<mark>zebra</mark> <img src=x onerror=alert(1)> <strong>x</strong>');

        self::assertSame('<mark>zebra</mark> &lt;img src=x onerror=alert(1)&gt; &lt;strong&gt;x&lt;/strong&gt;', $html);
    }

    public function testExcerptFallsBackToTrimmedContent(): void
    {
        $items = (new ResultNormalizer(new Config(['snippet_length' => 50])))->normalize([
            ['id' => '1', 'score' => 1.0, 'document' => ['title' => 'T', 'content' => str_repeat('a', 80)], 'highlights' => [], 'metadata' => ['post_id' => 1]],
        ]);

        self::assertSame(str_repeat('a', 50) . '…', $items[0]['excerpt_html']);
    }

    public function testOnlyPublicDropsPostsThatAreNoLongerPublic(): void
    {
        Functions\expect('get_posts')->once()->with(\Mockery::on(static fn (array $a): bool => $a['fields'] === 'ids'
            && $a['post_status'] === 'publish' && $a['has_password'] === false && $a['post__in'] === [1, 2]))
            ->andReturn([2]);
        $normalizer = new ResultNormalizer(new Config());

        $items = $normalizer->onlyPublic([['post_id' => 1], ['post_id' => 2]]);

        self::assertSame([['post_id' => 2]], $items);
    }
}
