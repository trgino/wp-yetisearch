<?php
/**
 * Version bumper: keeps the four version markers in sync.
 *
 * Usage: composer bump -- 1.1.0   (or omit the version for a prompt)
 *
 * Updates wp-yetisearch.php (header + constant), readme.txt (Stable tag)
 * and CHANGELOG.md (new section). Commit and tag separately:
 * git commit -am "release 1.1.0" && git tag v1.1.0 && git push --follow-tags
 */
declare(strict_types=1);

$version = $argv[1] ?? '';
if ($version === '' && function_exists('readline')) {
    $version = trim((string) readline('New version (x.y.z): '));
}
if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    fwrite(STDERR, "Usage: composer bump -- <x.y.z>\n");
    exit(1);
}

$root = dirname(__DIR__);
$date = date('Y-m-d');
$edits = 0;

$replace = static function (string $file, string $pattern, string $replacement) use ($root, &$edits): void {
    $path = $root . '/' . $file;
    $content = file_get_contents($path);
    if ($content === false) {
        fwrite(STDERR, "Cannot read $file\n");
        exit(1);
    }
    $updated = preg_replace($pattern, $replacement, $content, 1, $count);
    if ($count !== 1 || $updated === null) {
        fwrite(STDERR, "Pattern not found once in $file\n");
        exit(1);
    }
    file_put_contents($path, $updated);
    $edits++;
};

$replace('wp-yetisearch.php', '/^ \* Version:\s*\S+/m', " * Version:           $version");
$replace('wp-yetisearch.php', "/define\( 'WPYETISEARCH_VERSION', '[^']*' \)/", "define( 'WPYETISEARCH_VERSION', '$version' )");
$replace('readme.txt', '/^Stable tag:\s*\S+/m', "Stable tag: $version");

$changelog = $root . '/CHANGELOG.md';
$log = file_get_contents($changelog);
if ($log === false) {
    fwrite(STDERR, "Cannot read CHANGELOG.md\n");
    exit(1);
}
if (str_contains($log, "## [$version]")) {
    fwrite(STDERR, "CHANGELOG.md already has [$version]\n");
    exit(1);
}
$eol = str_contains($log, "\r\n") ? "\r\n" : "\n";
$anchor = "Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).";
$section = "{$eol}## [$version] - $date{$eol}{$eol}### Changed{$eol}{$eol}- {$eol}";
if (!str_contains($log, $anchor)) {
    fwrite(STDERR, "CHANGELOG.md anchor not found\n");
    exit(1);
}
file_put_contents($changelog, str_replace($anchor, $anchor . $section, $log));
$edits++;

echo "Bumped to $version ($edits files). Fill in CHANGELOG.md, then commit and tag v$version.\n";
