<?php
// E2E helper (wp eval-file): renders facet links for a hand-built query
// carrying stashed facets, like QueryBridge sets them. No declare.
$query = new WP_Query();
$query->set('s', 'boots');
$query->set('yetisearch_facets', [
    'price_range' => [
        ['value' => '0-100', 'count' => 1, 'to' => 100.0, 'filter' => ['price' => ['lte' => 100.0]]],
        ['value' => '100+', 'count' => 2, 'from' => 100.0, 'filter' => ['price' => ['gte' => 100.0]]],
    ],
]);

$html = yetisearch_get_facet_links('price_range', $query);

$checks = [];
$checks[] = strpos($html, 'yetisearch-facet-links') !== false ? 'LIST-OK' : 'LIST-FAIL';
$checks[] = strpos($html, 'filter%5Bprice%5D%5Blte%5D=100') !== false ? 'URL-OK' : 'URL-FAIL';
$checks[] = strpos($html, '(2)') !== false ? 'COUNT-OK' : 'COUNT-FAIL';
$checks[] = yetisearch_get_facet_links('nope', $query) === '' ? 'EMPTY-OK' : 'EMPTY-FAIL';

echo 'MATRIX_FACETLINKS ' . implode(',', $checks) . "\n";
