<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Integration\Index;

use WpYetiSearch\Core\Config;
use WpYetiSearch\Index\DocumentMapper;
use WpYetiSearch\Tests\Integration\IntegrationTestCase;

/** Spec §10.2: meta and taxonomy terms are searchable because indexer.fields declares them. */
final class DocumentMapperSearchTest extends IntegrationTestCase
{
    public function testMetaAndTaxonomyTermsAreSearchable(): void
    {
        // min_score=0: single-doc BM25 raw score is <0.1 default threshold,
        // test verifies field declaration (§3.1), not scoring cutoff.
        $config = new Config(['enable_fuzzy' => false, 'min_score' => 0.0]);
        $yeti = $this->makeYeti($config);
        $mapper = new DocumentMapper($config);
        $post = new \WP_Post(['ID' => 3, 'post_title' => 'Plain title', 'post_content' => 'Plain body']);

        $yeti->indexBatch(Config::INDEX, [
            $mapper->build($post, ['zebracategory'], ['acmewidget'], [], null, 'https://example.test/?p=3'),
        ]);

        foreach (['acmewidget', 'zebracategory'] as $term) {
            $result = $yeti->search(Config::INDEX, $term);
            self::assertGreaterThan(0, $result['total'], $term);
            self::assertSame(3, (int) $result['results'][0]['metadata']['post_id'], $term);
        }
    }
}
