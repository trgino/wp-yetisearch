<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Index;

use Brain\Monkey\Functions;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Index\DocumentMapper;
use WpYetiSearch\Tests\Unit\UnitTestCase;

final class DocumentMapperTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_permalink')->justReturn('https://example.test/hello/');
        Functions\when('get_the_terms')->justReturn(false);
        Functions\when('get_post_meta')->justReturn('');
    }

    private static function post(array $props = []): \WP_Post
    {
        return new \WP_Post($props + ['ID' => 12, 'post_title' => 'Hello', 'post_content' => 'Body text', 'post_type' => 'post']);
    }

    public function testPasswordProtectedPostIsNotIndexable(): void
    {
        $mapper = new DocumentMapper(new Config());

        self::assertFalse($mapper->isIndexable(self::post(['post_password' => 'secret'])));
        self::assertNull($mapper->map(self::post(['post_password' => 'secret'])));
    }

    public function testDraftsAndUnindexedTypesAreNotIndexable(): void
    {
        $mapper = new DocumentMapper(new Config());

        self::assertFalse($mapper->isIndexable(self::post(['post_status' => 'draft'])));
        self::assertFalse($mapper->isIndexable(self::post(['post_status' => 'private'])));
        self::assertFalse($mapper->isIndexable(self::post(['post_type' => 'attachment'])));
        self::assertTrue($mapper->isIndexable(self::post()));
    }

    public function testMapsTaxonomiesMetaFacetsAndGeo(): void
    {
        Functions\when('get_the_terms')->alias(static function (int $id, string $taxonomy): array|false {
            return match ($taxonomy) {
                'category' => [(object) ['name' => 'Guides'], (object) ['name' => 'News']],
                'post_tag' => [(object) ['name' => 'zebra']],
                default => false,
            };
        });
        Functions\when('get_post_meta')->alias(static function (int $id, string $key, bool $single = false): mixed {
            return match ($key) {
                'specs' => $single ? 'ACME-42' : ['ACME-42', ['nested', 'values']],
                'latitude' => '41.0082',
                'longitude' => '28.9784',
                default => $single ? '' : [],
            };
        });
        $config = new Config([
            'indexed_meta_keys' => ['specs'],
            'facets_enabled' => true,
            'facet_taxonomies' => ['category'],
            'geo_enabled' => true,
        ]);

        $doc = (new DocumentMapper($config))->map(self::post());

        self::assertNotNull($doc);
        self::assertSame('12', $doc['id']);
        self::assertSame('Guides News zebra', $doc['content']['taxonomies']);
        self::assertSame('ACME-42 nested values', $doc['content']['meta']);
        self::assertSame('Guides', $doc['metadata']['facet_category'], 'facet holds the primary term (scalar)');
        self::assertSame(['lat' => 41.0082, 'lng' => 28.9784], $doc['geo']);
        self::assertSame('/?p=12', $doc['content']['route']);
        self::assertSame('https://example.test/hello/', $doc['content']['url']);
    }

    public function testInvalidCoordinatesAreDropped(): void
    {
        Functions\when('get_post_meta')->alias(static fn (int $id, string $key, bool $single = false): mixed => match ($key) {
            'latitude' => '123.4',
            'longitude' => '28.9',
            default => $single ? '' : [],
        });

        $doc = (new DocumentMapper(new Config(['geo_enabled' => true])))->map(self::post());

        self::assertNotNull($doc);
        self::assertArrayNotHasKey('geo', $doc);
    }

    public function testTextIsDecodedAndWhitespaceCollapsed(): void
    {
        $doc = (new DocumentMapper(new Config()))->map(self::post([
            'post_title' => "Tom &amp; Jerry\n\n  Show",
            'post_content' => "<p>First</p>\n\t<p>Second</p>",
        ]));

        self::assertNotNull($doc);
        self::assertSame('Tom & Jerry Show', $doc['content']['title']);
        self::assertSame('First Second', $doc['content']['content']);
    }

    public function testLanguageIsOnlySetWhenNotAuto(): void
    {
        $auto = (new DocumentMapper(new Config()))->map(self::post());
        $german = (new DocumentMapper(new Config(['stemmer_language' => 'german'])))->map(self::post());

        self::assertNotNull($auto);
        self::assertNotNull($german);
        self::assertArrayNotHasKey('language', $auto);
        self::assertSame('german', $german['language']);
    }

    public function testRouteHelpers(): void
    {
        self::assertSame('/?p=5', DocumentMapper::route(5));
        self::assertSame(12, DocumentMapper::postIdFromDocId('12#chunk3'));
        self::assertSame(12, DocumentMapper::postIdFromDocId('12'));
    }

    public function testNumericPriceLandsInMetadata(): void
    {
        Functions\when('get_post_meta')->alias(static fn (int $id, string $key, bool $single = false): mixed => match ($key) {
            '_price' => '29.99',
            default => $single ? '' : [],
        });

        $doc = (new DocumentMapper(new Config()))->map(self::post());

        self::assertNotNull($doc);
        self::assertSame(29.99, $doc['metadata']['price']);
    }

    public function testJunkPriceIsOmitted(): void
    {
        Functions\when('get_post_meta')->alias(static fn (int $id, string $key, bool $single = false): mixed => match ($key) {
            '_price' => 'contact us',
            default => $single ? '' : [],
        });

        $doc = (new DocumentMapper(new Config()))->map(self::post());

        self::assertNotNull($doc);
        self::assertArrayNotHasKey('price', $doc['metadata']);
    }

    public function testEmptyPriceKeyDisablesPrice(): void
    {
        Functions\when('get_post_meta')->alias(static fn (int $id, string $key, bool $single = false): mixed => match ($key) {
            '_price' => '29.99',
            default => $single ? '' : [],
        });

        $doc = (new DocumentMapper(new Config(['price_meta_key' => ''])))->map(self::post());

        self::assertNotNull($doc);
        self::assertArrayNotHasKey('price', $doc['metadata']);
    }

    public function testProductSkuJoinsMeta(): void
    {
        Functions\when('get_post_meta')->alias(static fn (int $id, string $key, bool $single = false): mixed => match ($key) {
            '_sku' => 'SKU-7',
            default => $single ? '' : [],
        });

        $doc = (new DocumentMapper(new Config(['indexed_post_types' => ['post', 'product']])))->map(self::post(['post_type' => 'product']));

        self::assertNotNull($doc);
        self::assertStringContainsString('SKU-7', $doc['content']['meta']);
    }

    public function testObjectMetaFlattensToEmpty(): void
    {
        Functions\when('get_post_meta')->alias(static fn (int $id, string $key, bool $single = false): mixed => match ($key) {
            'specs' => $single ? new \stdClass() : [new \stdClass()],
            default => $single ? '' : [],
        });

        $doc = (new DocumentMapper(new Config(['indexed_meta_keys' => ['specs']])))->map(self::post());

        self::assertNotNull($doc);
        self::assertSame('', $doc['content']['meta']);
    }

    public function testDocumentLanguageFollowsPostLanguage(): void
    {
        Functions\when('pll_get_post_language')->justReturn('tr');

        $doc = (new DocumentMapper(new Config()))->map(self::post());

        self::assertNotNull($doc);
        self::assertSame('tr', $doc['language']);
    }

    public function testDocumentLanguageFallsBackToSetting(): void
    {
        $doc = (new DocumentMapper(new Config(['stemmer_language' => 'german'])))->map(self::post());

        self::assertNotNull($doc);
        self::assertSame('german', $doc['language']);
    }

    public function testCreationSettingsStemInContentLanguage(): void
    {
        $mapper = new DocumentMapper(new Config());

        self::assertSame(['stemming' => true, 'language' => 'en'], $mapper->creationSettings('wp_posts'));
        self::assertSame(['stemming' => true, 'language' => 'tr'], $mapper->creationSettings('wp_posts_tr'));
    }

    /** @var array<string, mixed> */
    private array $storedOptions = [];

    /** @param array<string, mixed> $options */
    private function mapperWithOptions(array $options): DocumentMapper
    {
        $this->storedOptions = $options;
        Functions\when('get_option')->alias(fn (string $k, mixed $d = false): mixed => $this->storedOptions[$k] ?? $d);
        Functions\when('update_option')->alias(function (string $k, mixed $v): bool {
            $this->storedOptions[$k] = $v;
            return true;
        });
        return new DocumentMapper(new Config());
    }

    public function testEnsureIndexCreatesMissingIndex(): void
    {
        $yeti = \Mockery::mock(\YetiSearch\YetiSearch::class);
        $yeti->shouldReceive('listIndices')->once()->andReturn([]);
        $yeti->shouldReceive('createIndex')->once()->with('wp_posts', ['stemming' => true, 'language' => 'en']);
        $yeti->shouldNotReceive('rebuildFts');

        $this->mapperWithOptions([])->ensureIndex($yeti, 'wp_posts');

        self::assertSame('en', $this->storedOptions[Config::STEMMED_INDEXES_OPTION]['wp_posts'] ?? null);
    }

    public function testEnsureIndexHealsUnstemmedIndex(): void
    {
        $yeti = \Mockery::mock(\YetiSearch\YetiSearch::class);
        $yeti->shouldReceive('listIndices')->once()->andReturn([['name' => 'wp_posts']]);
        $yeti->shouldNotReceive('createIndex');
        $yeti->shouldReceive('rebuildFts')->once()->with('wp_posts', ['stemming' => true, 'language' => 'en']);

        $this->mapperWithOptions([])->ensureIndex($yeti, 'wp_posts');
    }

    public function testEnsureIndexSkipsRecordedIndex(): void
    {
        $yeti = \Mockery::mock(\YetiSearch\YetiSearch::class);
        $yeti->shouldReceive('listIndices')->once()->andReturn([['name' => 'wp_posts']]);
        $yeti->shouldNotReceive('createIndex', 'rebuildFts');

        $this->mapperWithOptions([Config::STEMMED_INDEXES_OPTION => ['wp_posts' => 'en']])
            ->ensureIndex($yeti, 'wp_posts');
    }

    public function testEnsureIndexRebuildsOnLanguageChange(): void
    {
        $yeti = \Mockery::mock(\YetiSearch\YetiSearch::class);
        $yeti->shouldReceive('listIndices')->once()->andReturn([['name' => 'wp_posts']]);
        $yeti->shouldReceive('rebuildFts')->once();

        $this->mapperWithOptions([Config::STEMMED_INDEXES_OPTION => ['wp_posts' => 'de']])
            ->ensureIndex($yeti, 'wp_posts');
    }

    public function testEnsureIndexIgnoresForeignIndexesAndNullEngine(): void
    {
        $mapper = new DocumentMapper(new Config());

        $mapper->ensureIndex(null, 'wp_posts');
        $mapper->ensureIndex(\Mockery::mock(\YetiSearch\YetiSearch::class), 'other_index');

        $this->expectNotToPerformAssertions();
    }
}
