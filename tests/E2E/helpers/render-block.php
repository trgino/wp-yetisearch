<?php
// E2E helper (wp eval-file): server-renders the search block. No declare.
$html = (string) do_blocks('<!-- wp:yetisearch/search-box {"title":"Find <things>"} /-->');

$checks = [];
$checks[] = strpos($html, 'name="s"') !== false ? 'FORM-OK' : 'FORM-FAIL';
$checks[] = strpos($html, 'Find &lt;things&gt;') !== false ? 'ESC-OK' : 'ESC-FAIL';
$checks[] = strpos($html, 'role="search"') !== false ? 'ROLE-OK' : 'ROLE-FAIL';

echo 'MATRIX_BLOCK ' . implode(',', $checks) . "\n";
