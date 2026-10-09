param(
  [Parameter(Mandatory = $true)][ValidateSet('woo', 'i18n', 'cpt', 'load', 'wpml', 'woopll')][string]$Leg
)
$ErrorActionPreference = 'Stop'

$override = "tests/matrix/overrides/$Leg.json"
Copy-Item $override .wp-env.override.json -Force
try {
  # destroy (not stop): wp-env only refreshes volume mounts on recreate.
  echo 'y' | npx wp-env destroy
  npx wp-env start
  npx wp-env clean tests
  npx playwright test --config tests/matrix/playwright.matrix.ts "matrix.$Leg.spec.ts"
  $code = $LASTEXITCODE
}
finally {
  npx wp-env stop
  Remove-Item .wp-env.override.json -Force -ErrorAction Continue
}
exit $code
