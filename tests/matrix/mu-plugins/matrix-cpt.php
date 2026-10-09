<?php
/**
 * Matrix CPT fixture: registers a public `book` type so the content tab
 * lists it and the indexer can include it. Test-only mu-plugin.
 */
declare(strict_types=1);

add_action('init', static function (): void {
    register_post_type('book', [
        'public' => true,
        'label' => 'Books',
        'supports' => ['title', 'editor'],
        'show_in_rest' => true,
    ]);
});
