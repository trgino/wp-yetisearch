<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Stubs/wp-classes.php';
require __DIR__ . '/Stubs/wp-cli.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/wp/');
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('WPYETISEARCH_VERSION')) {
    define('WPYETISEARCH_VERSION', '1.0.0');
    define('WPYETISEARCH_FILE', dirname(__DIR__) . '/wp-yetisearch.php');
    define('WPYETISEARCH_PATH', dirname(__DIR__) . '/');
    define('WPYETISEARCH_URL', 'https://example.test/wp-content/plugins/wp-yetisearch/');
}
