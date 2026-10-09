<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Integration\Index;

use Brain\Monkey\Functions;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Index\DocumentMapper;
use WpYetiSearch\Search\ResultNormalizer;
use WpYetiSearch\Search\SearchService;
use WpYetiSearch\Tests\Integration\IntegrationTestCase;

final class LanguageIndexTest extends IntegrationTestCase
{
    public function testLanguagesLiveInSeparateIndices(): void
    {
        Functions\when('pll_get_post_language')->alias(static fn (int $id): string|false => $id === 1 ? 'tr' : 'en');
        Functions\when('pll_default_language')->justReturn('en');
        // min_score=0: tiny corpora score below the default 0.1 cutoff (see Task 5).
        $config = new Config(['enable_fuzzy' => false, 'min_score' => 0.0]);
        $yeti = $this->makeYeti($config);
        $mapper = new DocumentMapper($config);
        $yeti->indexBatch('wp_posts_tr', [
            $mapper->build(new \WP_Post(['ID' => 1, 'post_title' => 'Kedi maması', 'post_content' => 'Kedi.']), [], [], [], null, 'https://example.test/?p=1'),
        ]);
        $yeti->indexBatch('wp_posts', [
            $mapper->build(new \WP_Post(['ID' => 2, 'post_title' => 'Cat food', 'post_content' => 'Cat.']), [], [], [], null, 'https://example.test/?p=2'),
        ]);
        $service = new SearchService($yeti, $config, new ResultNormalizer($config));

        $tr = $service->run($service->frontQuery('kedi', ['post'], 10, 1), [], 'wp_posts_tr');
        $en = $service->run($service->frontQuery('kedi', ['post'], 10, 1));

        self::assertSame([1], array_column($tr['items'], 'post_id'));
        self::assertSame([], $en['items'], 'Turkish term must not leak into the default index');
    }
}
