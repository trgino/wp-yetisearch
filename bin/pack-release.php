<?php
/**
 * Packs the release zip honoring .distignore (one pattern per line).
 * Usage: php bin/pack-release.php <source-dir> <out-zip>
 */

declare(strict_types=1);

[$script, $source, $outZip] = $argv + [null, null, null];
if (!is_string($source) || !is_string($outZip) || !is_dir($source)) {
    fwrite(STDERR, "Usage: php bin/pack-release.php <source-dir> <out-zip>\n");
    exit(1);
}

$patterns = [];
$ignoreFile = rtrim($source, '/\\') . '/.distignore';
if (is_readable($ignoreFile)) {
    foreach (file($ignoreFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $patterns[] = $line;
    }
}

$excluded = static function (string $relative) use ($patterns): bool {
    $relative = str_replace('\\', '/', $relative);
    foreach ($patterns as $pattern) {
        $pattern = str_replace('\\', '/', $pattern);
        if (str_ends_with($pattern, '/')) {
            // Directory rule (rooted or not): excludes the dir and all below.
            $rule = trim($pattern, '/');
            if ($relative === $rule || str_starts_with($relative, $rule . '/')) {
                return true;
            }
            continue;
        }
        if (str_starts_with($pattern, '/')) {
            // Rooted: match from the start.
            $rule = ltrim($pattern, '/');
            if ($relative === rtrim($rule, '/') || str_starts_with($relative, rtrim($rule, '/') . '/')) {
                return true;
            }
            if (fnmatch($rule, $relative)) {
                return true;
            }
        } elseif (fnmatch($pattern, $relative) || fnmatch($pattern, basename($relative))) {
            return true;
        }
    }
    return false;
};

$zip = new ZipArchive();
if ($zip->open($outZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot open {$outZip} for writing.\n");
    exit(1);
}

$base = rtrim($source, '/\\');
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
$count = 0;
foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    $relative = substr($file->getPathname(), strlen($base) + 1);
    if (str_starts_with(str_replace('\\', '/', (string) $relative), '.git/') || $relative === '.git') {
        continue;
    }
    if ($excluded((string) $relative)) {
        continue;
    }
    if ($file->isDir()) {
        $zip->addEmptyDir(str_replace('\\', '/', (string) $relative));
        continue;
    }
    $zip->addFile($file->getPathname(), str_replace('\\', '/', (string) $relative));
    $count++;
}
$zip->close();

echo "Packed {$count} files into {$outZip}\n";
