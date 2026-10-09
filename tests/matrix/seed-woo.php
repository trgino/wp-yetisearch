<?php
// Matrix woo seed: 3 products with distinct prices (wp eval-file; no declare — eval context).

$ids = [];
foreach ([['Woo cheap boots', 80], ['Woo mid boots', 250], ['Woo dear boots', 1500]] as [$title, $price]) {
    $id = wp_insert_post([
        'post_title' => $title,
        'post_content' => 'Buy this test product.',
        'post_status' => 'publish',
        'post_type' => 'product',
    ]);
    update_post_meta($id, '_price', $price);
    update_post_meta($id, '_visibility', 'visible');
    update_post_meta($id, '_stock_status', 'instock');
    $ids[] = $id;
}

echo 'MATRIX_WOO ' . implode(',', $ids) . "\n";
