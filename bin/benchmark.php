#!/usr/bin/env php
<?php
/**
 * Standalone benchmark script for WP YetiSearch.
 *
 * WARNING: destructive on production — force-clears the live index.
 * Staging / throwaway environments only.
 *
 * Usage:
 *   php bin/benchmark.php [--posts=1000] [--iterations=5] [--format=table]
 */

declare(strict_types=1);

// Bootstrap WordPress if available, otherwise run in standalone mode
$wpLoad = findWpYetisearchLoad();
if ($wpLoad !== null) {
    require_once $wpLoad;
}

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
}

use WpYetiSearch\Cli\BenchmarkCommand;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Core\Logger;
use WpYetiSearch\Index\BulkIndexer;
use WpYetiSearch\Index\DocumentMapper;
use WpYetiSearch\Search\SearchService;
use WpYetiSearch\Search\ResultNormalizer;

// Parse arguments
$opts = getopt('', ['posts::', 'iterations::', 'format::']);
$postCount = (int) ($opts['posts'] ?? 1000);
$iterations = (int) ($opts['iterations'] ?? 5);
$format = (string) ($opts['format'] ?? 'table');

// Build services
$config = class_exists(Config::class) && function_exists('get_option') ? Config::load() : new Config();
$logger = new Logger();
$mapper = new DocumentMapper($config);
$indexer = new BulkIndexer(null, $mapper, $config, $logger);
$search = new SearchService(null, $config, new ResultNormalizer($config));

$command = new BenchmarkCommand($indexer, $search, $config, $logger);

echo "Running benchmark: {$postCount} posts, {$iterations} iterations...\n\n";

$results = $command->run($postCount, $iterations);

switch ($format) {
    case 'json':
        echo $command->formatJson($results) . "\n";
        break;
    case 'csv':
        echo $command->formatCsv($results) . "\n";
        break;
    case 'table':
    default:
        echo $command->formatTable($results) . "\n";
        break;
}

/**
 * Find wp-load.php by walking up from the current directory.
 */
function findWpYetisearchLoad(): ?string
{
    $dir = dirname(__DIR__);
    while (true) {
        $candidate = $dir . '/wp-load.php';
        if (file_exists($candidate)) {
            return $candidate;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            return null;
        }
        $dir = $parent;
    }
}
