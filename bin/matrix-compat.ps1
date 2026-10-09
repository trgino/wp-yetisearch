param(
  [Parameter(Mandatory = $true)][string]$Wp,
  [Parameter(Mandatory = $true)][string]$Php
)
$ErrorActionPreference = 'Stop'

$override = "{`"core`": `"WordPress/WordPress#$Wp`", `"phpVersion`": `"$Php`", `"plugins`": [`".`"]}"
Set-Content .wp-env.override.json $override -Encoding utf8
$env:MATRIX_WP = $Wp
$env:MATRIX_PHP = $Php
try {
  # destroy (not stop): wp-env only refreshes mounts/config on recreate.
  echo 'y' | npx wp-env destroy
  npx wp-env start
  npx wp-env clean tests
  npx playwright test --config tests/matrix/playwright.matrix.ts "matrix.compat.spec.ts"
  $code = $LASTEXITCODE
}
finally {
  npx wp-env stop
  Remove-Item .wp-env.override.json -Force -ErrorAction Continue
}
exit $code
