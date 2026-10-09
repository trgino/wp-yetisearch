#!/usr/bin/env bash
# Runs INSIDE the release container (WORKDIR /src = clean archive copy).
# Steps: full deps (Strauss itself is a dev dep) -> Strauss prefix ->
# pot file -> smoke test -> zip to /out.
set -euo pipefail

VERSION="$(grep -m1 'Version:' wp-yetisearch.php | sed 's/[^0-9.]//g')"
echo "Building wp-yetisearch ${VERSION} ..."

composer install --optimize-autoloader --no-progress 2>/dev/null || composer install --optimize-autoloader
vendor/bin/strauss

if wp --allow-root --version >/dev/null 2>&1; then
    wp i18n make-pot . languages/wp-yetisearch.pot --domain=wp-yetisearch --exclude=vendor,vendor-prefixed,tests --allow-root
else
    echo "wp-cli unavailable; keeping committed pot file." >&2
fi

# Smoke test: prefixed classes load, unprefixed originals must NOT leak.
php -r '
require "vendor-prefixed/autoload.php";
foreach (["WpYetiSearch\\Vendor\\YetiSearch\\YetiSearch", "WpYetiSearch\\Vendor\\Psr\\Log\\NullLogger"] as $c) {
    if (!class_exists($c)) { fwrite(STDERR, "missing $c\n"); exit(1); }
}
if (class_exists("YetiSearch\\YetiSearch") || interface_exists("Psr\\Log\\LoggerInterface")) {
    fwrite(STDERR, "unprefixed dependency leaked\n"); exit(1);
}
echo "prefix-ok\n";'

mkdir -p /out
php bin/pack-release.php . "/out/wp-yetisearch-${VERSION}.zip"
