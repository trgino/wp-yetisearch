#!/usr/bin/env bash
# Host-side release driver: builds a clean archive copy of HEAD and runs the
# release pipeline inside Docker (Linux), where the PHP toolchain is reliable.
# Output: <repo>/build/wp-yetisearch-<version>.zip
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="$ROOT/build/release-src"
OUT="$ROOT/build"

rm -rf "$BUILD"
mkdir -p "$BUILD" "$OUT"
git -C "$ROOT" archive HEAD | tar -x -C "$BUILD"

docker build -f "$ROOT/Dockerfile.release" -t wp-yetisearch-release "$ROOT"
docker run --rm -v "$BUILD:/src" -v "$OUT:/out" wp-yetisearch-release
ls -la "$OUT"
