<?php
// Matrix-style E2E helper (wp eval-file): renders the search widget in real
// WordPress and reports markup assertions. No declare - eval context.
ob_start();
the_widget('WpYetiSearch\Frontend\SearchWidget', ['title' => 'Find <things>']);
$html = (string) ob_get_clean();

$checks = [];
$checks[] = strpos($html, 'name="s"') !== false ? 'FORM-OK' : 'FORM-FAIL';
$checks[] = strpos($html, 'Find &lt;things&gt;') !== false ? 'ESC-OK' : 'ESC-FAIL';
$checks[] = strpos($html, '<things>') === false ? 'NOTAG-OK' : 'NOTAG-FAIL';
$checks[] = strpos($html, 'role="search"') !== false ? 'ROLE-OK' : 'ROLE-FAIL';

echo 'MATRIX_WIDGET ' . implode(',', $checks) . "\n";
