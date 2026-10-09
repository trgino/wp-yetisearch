#!/usr/bin/env bash
# Matrix leg runner: ./bin/matrix-leg.sh woo|i18n|cpt|load|wpml|woopll
set -euo pipefail
LEG="${1:?usage: matrix-leg.sh woo|i18n|cpt|load|wpml|woopll}"
case "$LEG" in woo|i18n|cpt|load|wpml|woopll) ;; *) echo "unknown leg: $LEG" >&2; exit 1;; esac

cp "tests/matrix/overrides/${LEG}.json" .wp-env.override.json
cleanup() {
  npx wp-env stop || true
  rm -f .wp-env.override.json
}
trap cleanup EXIT

# destroy (not stop): wp-env only refreshes volume mounts on recreate.
npx wp-env stop || true
echo 'y' | npx wp-env destroy || true
npx wp-env start
npx wp-env clean tests
npx playwright test --config tests/matrix/playwright.matrix.ts "matrix.${LEG}.spec.ts"
