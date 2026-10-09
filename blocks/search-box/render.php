<?php
/** Server render for the yetisearch/search-box block. No build step. */
declare(strict_types=1);

$title = isset( $attributes['title'] ) && is_string( $attributes['title'] ) ? $attributes['title'] : '';

echo \WpYetiSearch\Frontend\SearchBox::form( $title, 'yetisearch-block-search' );
