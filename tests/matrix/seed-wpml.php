<?php
// Matrix WPML seed (wp eval-file; no declare - eval context).
// Activates EN (default) + TR and creates one post per language.

$sitepress = $GLOBALS['sitepress'];
$sitepress->set_default_language('en');

global $wpdb;
$wpdb->query("UPDATE {$wpdb->prefix}icl_languages SET active = 1 WHERE code IN ('en', 'tr')");
$settings = get_option('icl_sitepress_settings');
if (is_array($settings)) {
    $settings['active_languages'] = ['en', 'tr'];
    update_option('icl_sitepress_settings', $settings);
}

$en = wp_insert_post([
    'post_title' => 'Brighton pier morning',
    'post_content' => 'Seagulls drift over Brighton pier at dawn.',
    'post_status' => 'publish',
]);
do_action('wpml_set_element_language_details', [
    'element_id' => $en,
    'element_type' => 'post_post',
    'trid' => false,
    'language_code' => 'en',
]);

$trid = apply_filters('wpml_element_trid', null, $en, 'post_post');
$tr = wp_insert_post([
    'post_title' => 'Bogazici sabah vapuru',
    'post_content' => 'Martilar Bogazici uzerinde ucar.',
    'post_status' => 'publish',
]);
do_action('wpml_set_element_language_details', [
    'element_id' => $tr,
    'element_type' => 'post_post',
    'trid' => $trid,
    'language_code' => 'tr',
    'source_language_code' => 'en',
]);

echo "MATRIX_WPML EN={$en} TR={$tr} TRID={$trid}\n";
