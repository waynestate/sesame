# Site accessibility gate (axe-core in Playwright), run in Docker so no host
# browser is needed. Windows PowerShell 5.1 and PowerShell 7; on Linux and
# macOS use scripts/a11y.sh.
#
# The repo is mounted read-only; only tools/a11y/node_modules lives in a named
# volume, so npm installs once and later runs reuse it.
#
# Checks site/index.html and every HTML page under site/docs/ (and
# site/handbook/, if that folder exists): a glob that matches nothing is
# skipped, so the same script works for repos without a handbook.
$ErrorActionPreference = "Stop"

Set-Location -LiteralPath (Split-Path -Parent $PSScriptRoot)

# The read-only repo mount cannot create the named volume's mount point.
New-Item -ItemType Directory -Force -Path 'tools/a11y/node_modules' | Out-Null

$image = "mcr.microsoft.com/playwright:v1.49.1-noble"

$inner = 'if [ ! -d tools/a11y/node_modules/playwright ]; then if [ -f tools/a11y/package-lock.json ]; then (cd tools/a11y && npm ci --no-audit --no-fund); else (cd tools/a11y && npm install --no-audit --no-fund); fi; fi && node tools/a11y/check.mjs --root=site --page=index.html --page="docs/**/*.html" --page="handbook/**/*.html"'

docker run --rm `
  -v "${PWD}:/work:ro" `
  -v sesame-a11y-node-modules:/work/tools/a11y/node_modules `
  -w /work `
  $image `
  sh -c $inner

exit $LASTEXITCODE
