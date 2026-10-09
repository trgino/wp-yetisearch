<?php
// Matrix i18n seed: EN (default) + TR languages, one post each (wp eval-file; no declare — eval context).

$model = PLL()->model;
$defs = [
    ['English', 'en', 'en_US', 0, 1],
    ['Türkçe', 'tr', 'tr_TR', 0, 2],
];
foreach ($defs as [$name, $slug, $locale, $rtl, $group]) {
    if (!$model->get_language($slug)) {
        $model->add_language([
            'name' => $name,
            'slug' => $slug,
            'locale' => $locale,
            'rtl' => $rtl,
            'term_group' => $group,
        ]);
    }
}

$en = wp_insert_post([
    'post_title' => 'Brighton pier morning',
    'post_content' => 'Seagulls drift over Brighton pier at dawn.',
    'post_status' => 'publish',
]);
pll_set_post_language($en, 'en');

$tr = wp_insert_post([
    'post_title' => 'Bogazici sabah vapuru',
    'post_content' => 'Martilar Bogazici uzerinde ucar.',
    'post_status' => 'publish',
]);
pll_set_post_language($tr, 'tr');

echo "MATRIX_I18N EN={$en} TR={$tr}\n";
