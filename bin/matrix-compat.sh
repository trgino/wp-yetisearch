#!/usr/bin/env bash
# Matrix compat runner: ./bin/matrix-compat.sh 6.6 8.2
set -euo pipefail
WP="${1:?usage: matrix-compat.sh <wp> <php>}"
PHP="${2:?usage: matrix-compat.sh <wp> <php>}"

printf '{"core": "WordPress/WordPress#%s", "phpVersion": "%s", "plugins": ["."]}' "$WP" "$PHP" > .wp-env.override.json
export MATRIX_WP="$WP" MATRIX_PHP="$PHP"
cleanup() {
  npx wp-env stop || true
  rm -f .wp-env.override.json
}
trap cleanup EXIT

echo 'y' | npx wp-env destroy || true
npx wp-env start
npx wp-env clean tests
npx playwright test --config tests/matrix/playwright.matrix.ts "matrix.compat.spec.ts"
