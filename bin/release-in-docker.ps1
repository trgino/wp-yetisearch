# Host-side release driver for Windows PowerShell: builds a clean archive copy
# of HEAD and runs the release pipeline inside Docker (Linux).
# Output: <repo>/build/wp-yetisearch-<version>.zip
$ErrorActionPreference = 'Stop'
$ROOT = Split-Path -Parent $PSScriptRoot
$BUILD = Join-Path $ROOT 'build/release-src'
$OUT = Join-Path $ROOT 'build'

if (Test-Path $BUILD) { Remove-Item -Recurse -Force $BUILD }
New-Item -ItemType Directory $BUILD, $OUT -Force | Out-Null
# NOTE: do NOT pipe git archive into tar here — PowerShell re-encodes piped
# bytes as text and corrupts the stream. Write to a file first.
$archive = Join-Path ([System.IO.Path]::GetTempPath()) 'wp-yetisearch-head.tar'
git -C $ROOT archive HEAD -o $archive
tar -xf $archive -C $BUILD
Remove-Item $archive -Force

docker build -f (Join-Path $ROOT 'Dockerfile.release') -t wp-yetisearch-release $ROOT
docker run --rm -v "${BUILD}:/src" -v "${OUT}:/out" wp-yetisearch-release
Get-ChildItem $OUT
