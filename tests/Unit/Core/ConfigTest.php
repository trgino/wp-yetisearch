<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use WpYetiSearch\Core\Config;

final class ConfigTest extends TestCase
{
    public function testStoredValuesOverrideDefaultsAndUnknownKeysAreDropped(): void
    {
        $config = new Config(['chunk_size' => 500, 'bogus' => 1]);

        self::assertSame(500, $config->int('chunk_size'));
        self::assertArrayNotHasKey('bogus', $config->all());
    }

    public function testUnknownKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Config())->get('does_not_exist');
    }

    public function testEveryIndexedFieldIsDeclaredForTheLibrary(): void
    {
        $fields = (new Config())->toYetiConfig('/tmp/x.sqlite')['indexer']['fields'];

        foreach (['title', 'content', 'excerpt', 'taxonomies', 'meta', 'url', 'route'] as $name) {
            self::assertArrayHasKey($name, $fields, $name);
        }
        self::assertFalse($fields['route']['index']);
        self::assertFalse($fields['url']['index']);
        self::assertTrue($fields['meta']['store']);
    }

    public function testFieldWeightsAreAppliedAtQueryTime(): void
    {
        $yeti = (new Config(['weight_title' => 4.5, 'weight_meta' => 0.5]))->toYetiConfig('/tmp/x.sqlite');

        self::assertSame(4.5, $yeti['search']['field_weights']['title']);
        self::assertSame(0.5, $yeti['search']['field_weights']['meta']);
    }

    public function testPrefixIndexIsOnlyDeclaredWhenPrefixLastTokenIsOn(): void
    {
        $on = (new Config(['prefix_last_token' => true]))->toYetiConfig('/tmp/x.sqlite');
        $off = (new Config(['prefix_last_token' => false]))->toYetiConfig('/tmp/x.sqlite');

        self::assertSame([2, 3], $on['indexer']['fts']['prefix']);
        self::assertArrayNotHasKey('prefix', $off['indexer']['fts']);
        self::assertFalse($off['search']['prefix_last_token']);
    }

    public function testStorageHighlightAndLanguageSettings(): void
    {
        $config = new Config(['highlight_tag' => 'strong', 'fuzzy_algorithm' => 'levenshtein', 'stemmer_language' => 'german']);
        $yeti = $config->toYetiConfig('/data/db.sqlite');

        self::assertSame('/data/db.sqlite', $yeti['storage']['path']);
        self::assertTrue($yeti['storage']['external_content']);
        self::assertSame('levenshtein', $yeti['storage']['search']['fuzzy_algorithm']);
        self::assertSame('<strong>', $yeti['search']['highlight_tag']);
        self::assertSame('</strong>', $yeti['search']['highlight_tag_close']);
        self::assertSame('german', $config->stemmerLanguage());
        self::assertNull((new Config())->stemmerLanguage(), "'auto' maps to null (library auto-detect)");
    }

    public function testSynonymsAreOnlyPassedAsAMap(): void
    {
        $yeti = (new Config(['enable_synonyms' => true, 'synonyms' => ['car' => ['auto']]]))->toYetiConfig('/tmp/x.sqlite');

        self::assertTrue($yeti['search']['enable_synonyms']);
        self::assertSame(['car' => ['auto']], $yeti['search']['synonyms']);
    }
}
