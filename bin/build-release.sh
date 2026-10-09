#!/usr/bin/env bash
# Builds a shippable zip via Docker (Linux toolchain).
# For the legacy host-side flow, see git history; it is unreliable on Windows.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
exec "$ROOT/bin/release-in-docker.sh"
