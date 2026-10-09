<?php
// Matrix woo+polylang seed (wp eval-file; no declare - eval context).
// Creates EN + TR simple products with prices, linked as translations
// through the PLLWC product language store (falls back to core APIs).

$defs = [
    // title, content, price, lang, translation group key
    ['Woo EN boots', 'Buy English boots.', 80, 'en', 'boots'],
    ['Woo EN coat', 'Buy English coat.', 1500, 'en', 'coat'],
    ['Woo TR bot', 'Turkce bot satin al.', 250, 'tr', 'boots'],
    ['Woo TR mont', 'Turkce mont satin al.', 300, 'tr', 'coat'],
];

// Languages first (free core model API, same as the i18n leg).
$model = PLL()->model;
foreach ([['English', 'en', 'en_US', 0, 1], ['Türkçe', 'tr', 'tr_TR', 0, 2]] as [$name, $slug, $locale, $rtl, $group]) {
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

$ids = [];
$groups = [];
foreach ($defs as [$title, $content, $price, $lang, $group]) {
    $id = wp_insert_post([
        'post_title' => $title,
        'post_content' => $content,
        'post_status' => 'publish',
        'post_type' => 'product',
    ]);
    update_post_meta($id, '_price', $price);
    update_post_meta($id, '_visibility', 'visible');
    update_post_meta($id, '_stock_status', 'instock');
    if (class_exists('PLLWC_Data_Store') && function_exists('pll_current_language')) {
        $store = PLLWC_Data_Store::load('product_language');
        $store->set_language($id, $lang);
    } else {
        pll_set_post_language($id, $lang);
    }
    $ids[] = $id;
    $groups[$group][$lang] = $id;
}

if (class_exists('PLLWC_Data_Store') && function_exists('pll_current_language')) {
    foreach ($groups as $translations) {
        if (count($translations) === 2) {
            PLLWC_Data_Store::load('product_language')->save_translations($translations);
        }
    }
} else {
    foreach ($groups as $translations) {
        if (count($translations) === 2) {
            pll_save_post_translations($translations);
        }
    }
}

echo 'MATRIX_WOOPLL ' . implode(',', $ids) . "\n";
