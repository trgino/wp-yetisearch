<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Core;

use WpYetiSearch\Core\SettingsSchema;
use WpYetiSearch\Tests\Unit\UnitTestCase;

final class SettingsSchemaTest extends UnitTestCase
{
    public function testDefaultsCoverEveryFieldWithSpecValues(): void
    {
        $defaults = SettingsSchema::defaults();

        self::assertSame(array_keys(SettingsSchema::fields()), array_keys($defaults));
        self::assertFalse($defaults['master_enabled']);
        self::assertSame(1, $defaults['min_term_frequency']);
        self::assertSame(0.0, $defaults['min_score']);
        self::assertSame('auto', $defaults['stemmer_language']);
        self::assertSame(['post', 'page'], $defaults['indexed_post_types']);
    }

    public function testEveryFieldBelongsToAKnownTab(): void
    {
        foreach (SettingsSchema::fields() as $key => $field) {
            self::assertContains($field['tab'], SettingsSchema::TABS, $key);
            self::assertNotSame('maintenance', $field['tab'], $key);
        }
    }

    public function testSanitizeOnlyTouchesSubmittedTab(): void
    {
        $current = SettingsSchema::defaults();
        $current['enable_fuzzy'] = true;

        $out = SettingsSchema::sanitize(['master_enabled' => '1'], $current, 'general');

        self::assertTrue($out['master_enabled']);
        self::assertTrue($out['enable_fuzzy'], 'relevance tab must be untouched');
    }

    public function testUncheckedBoolBecomesFalse(): void
    {
        $current = SettingsSchema::defaults();
        $current['master_enabled'] = true;

        $out = SettingsSchema::sanitize(['master_enabled' => '0'], $current, 'general');

        self::assertFalse($out['master_enabled']);
    }

    public function testNumbersAreClampedToBounds(): void
    {
        $out = SettingsSchema::sanitize(
            ['min_term_frequency' => '500', 'correction_threshold' => '-3', 'weight_title' => 'abc'],
            SettingsSchema::defaults(),
            'relevance'
        );

        self::assertSame(100, $out['min_term_frequency']);
        self::assertSame(0.0, $out['correction_threshold']);
        self::assertSame(3.0, $out['weight_title'], 'non-numeric falls back to default');
    }

    public function testTypeaheadFloorsAreEnforced(): void
    {
        $out = SettingsSchema::sanitize(
            ['typeahead_debounce_ms' => '20', 'typeahead_min_chars' => '1'],
            SettingsSchema::defaults(),
            'display'
        );

        self::assertSame(100, $out['typeahead_debounce_ms']);
        self::assertSame(2, $out['typeahead_min_chars']);
    }

    public function testChunkOverlapIsCappedAtHalfTheChunkSize(): void
    {
        $out = SettingsSchema::sanitize(
            ['chunk_size' => '400', 'chunk_overlap' => '300', 'indexed_post_types' => ['post']],
            SettingsSchema::defaults(),
            'content'
        );

        self::assertSame(400, $out['chunk_size']);
        self::assertSame(200, $out['chunk_overlap']);
    }

    public function testSelectRejectsUnknownValues(): void
    {
        $out = SettingsSchema::sanitize(['fuzzy_algorithm' => 'evil'], SettingsSchema::defaults(), 'relevance');

        self::assertSame('trigram', $out['fuzzy_algorithm']);
    }

    public function testPasswordIsKeptWhenBlankAndClearedOnRequest(): void
    {
        $current = SettingsSchema::defaults();
        $current['semantic_api_key'] = 'sk-existing';

        $kept = SettingsSchema::sanitize(['semantic_api_key' => ''], $current, 'semantic');
        $replaced = SettingsSchema::sanitize(['semantic_api_key' => ' sk-new '], $current, 'semantic');
        $cleared = SettingsSchema::sanitize(['semantic_api_key' => '', 'semantic_api_key_clear' => '1'], $current, 'semantic');

        self::assertSame('sk-existing', $kept['semantic_api_key']);
        self::assertSame('sk-new', $replaced['semantic_api_key']);
        self::assertSame('', $cleared['semantic_api_key']);
    }

    public function testPrefixFieldsKeepTrailingSpaces(): void
    {
        $out = SettingsSchema::sanitize(
            ['semantic_query_prefix' => "search_query: \n"],
            SettingsSchema::defaults(),
            'semantic'
        );

        self::assertSame('search_query: ', $out['semantic_query_prefix']);
    }

    public function testListsAndSynonymsAreParsed(): void
    {
        $out = SettingsSchema::sanitize(
            [
                'custom_stop_words' => "foo\n bar \n\nfoo",
                'synonyms' => "Car: auto, vehicle\nbroken line\n: orphan",
            ],
            SettingsSchema::defaults(),
            'language'
        );

        self::assertSame(['foo', 'bar'], $out['custom_stop_words']);
        self::assertSame(['car' => ['auto', 'vehicle']], $out['synonyms']);
    }

    public function testNumberListIsParsedSortedAndPositive(): void
    {
        $out = SettingsSchema::sanitize(['geo_distance_ranges' => '10, 1, -5, abc, 5'], SettingsSchema::defaults(), 'facets_geo');

        self::assertSame([1.0, 5.0, 10.0], $out['geo_distance_ranges']);
    }

    public function testCustomDirMustBeAbsoluteWithoutTraversal(): void
    {
        $defaults = SettingsSchema::defaults();

        self::assertSame('', SettingsSchema::sanitize(['db_custom_dir' => 'relative/dir'], $defaults, 'general')['db_custom_dir']);
        self::assertSame('', SettingsSchema::sanitize(['db_custom_dir' => '/var/data/../etc'], $defaults, 'general')['db_custom_dir']);
        self::assertSame('/var/data/yeti', SettingsSchema::sanitize(['db_custom_dir' => '/var/data/yeti/'], $defaults, 'general')['db_custom_dir']);
        self::assertSame('C:/data/yeti', SettingsSchema::sanitize(['db_custom_dir' => 'C:\\data\\yeti'], $defaults, 'general')['db_custom_dir']);
    }

    public function testRequiresReindexOnlyForSchemaAffectingFields(): void
    {
        $before = SettingsSchema::defaults();

        self::assertTrue(SettingsSchema::requiresReindex($before, ['chunk_size' => 2000] + $before));
        self::assertTrue(SettingsSchema::requiresReindex($before, ['prefix_last_token' => false] + $before));
        self::assertFalse(SettingsSchema::requiresReindex($before, ['weight_title' => 5.0] + $before));
    }

    public function testSynonymMapAcceptsArrayAndText(): void
    {
        $before = SettingsSchema::defaults();

        $fromArray = SettingsSchema::sanitize(['synonyms' => ['car' => ['auto', 'vehicle']]], $before, 'language');
        self::assertSame(['car' => ['auto', 'vehicle']], $fromArray['synonyms']);

        $fromText = SettingsSchema::sanitize(['synonyms' => "car: auto, vehicle\nboat: ship"], $before, 'language');
        self::assertSame(['car' => ['auto', 'vehicle'], 'boat' => ['ship']], $fromText['synonyms']);
    }
}
