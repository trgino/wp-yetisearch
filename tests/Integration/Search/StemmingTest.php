<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Integration\Search;

use WpYetiSearch\Core\Config;
use WpYetiSearch\Index\DocumentMapper;
use WpYetiSearch\Search\ItalianStemmer;
use WpYetiSearch\Search\ResultNormalizer;
use WpYetiSearch\Search\SearchService;
use WpYetiSearch\Search\TurkishStemmer;
use WpYetiSearch\Tests\Integration\IntegrationTestCase;
use YetiSearch\Stemmer\StemmerFactory;

/** 2.6 stemming wired end to end: custom TR/IT stemmers match inflected forms. */
final class StemmingTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        StemmerFactory::reset();
        parent::tearDown();
    }

    /** @return array{SearchService, DocumentMapper} */
    private function service(Config $config): array
    {
        $yeti = $this->makeYeti($config);
        return [$yeti, new SearchService($yeti, $config, new ResultNormalizer($config)), new DocumentMapper($config)];
    }

    private static function doc(DocumentMapper $mapper, int $id, string $title, string $body, string $lang): array
    {
        $doc = $mapper->build(
            new \WP_Post(['ID' => $id, 'post_title' => $title, 'post_content' => $body]),
            [],
            [],
            [],
            null,
            'https://example.test/?p=' . $id
        );
        $doc['language'] = $lang;
        return $doc;
    }

    public function testTurkishInflectionMatchesStem(): void
    {
        TurkishStemmer::register();
        $config = new Config(['enable_fuzzy' => false, 'min_score' => 0.0]);
        [$yeti, $service, $mapper] = $this->service($config);
        $yeti->createIndex('wp_posts_tr', ['stemming' => true, 'language' => 'tr']);
        $yeti->indexBatch('wp_posts_tr', [
            self::doc($mapper, 1, 'Buyuk indirim', 'Kalin kitaplar ve ince dergiler burada.', 'tr'),
        ]);

        $stemmed = $service->run($service->frontQuery('kitap', ['post'], 10, 1), [], 'wp_posts_tr');
        $exact = $service->run($service->frontQuery('kitaplar', ['post'], 10, 1), [], 'wp_posts_tr');

        self::assertSame([1], array_column($stemmed['items'], 'post_id'), 'inflected form matches by stem');
        self::assertSame([1], array_column($exact['items'], 'post_id'), 'exact form still matches');
    }

    public function testItalianInflectionMatchesStem(): void
    {
        ItalianStemmer::register();
        $config = new Config(['enable_fuzzy' => false, 'min_score' => 0.0]);
        [$yeti, $service, $mapper] = $this->service($config);
        $yeti->createIndex('wp_posts_it', ['stemming' => true, 'language' => 'it']);
        $yeti->indexBatch('wp_posts_it', [
            self::doc($mapper, 2, 'Comunicato', 'La comunicazione ufficiale e lunga.', 'it'),
        ]);

        $stemmed = $service->run($service->frontQuery('comuni', ['post'], 10, 1), [], 'wp_posts_it');

        self::assertSame([2], array_column($stemmed['items'], 'post_id'));
    }

    public function testEnglishStemmingStillWorks(): void
    {
        $config = new Config(['enable_fuzzy' => false, 'min_score' => 0.0]);
        [$yeti, $service, $mapper] = $this->service($config);
        $yeti->createIndex(Config::INDEX, ['stemming' => true, 'language' => 'en']);
        $yeti->indexBatch(Config::INDEX, [
            self::doc($mapper, 3, 'Morning jog', 'Running shoes for runners.', 'en'),
        ]);

        $stemmed = $service->run($service->frontQuery('run', ['post'], 10, 1));

        self::assertSame([3], array_column($stemmed['items'], 'post_id'));
    }
}
