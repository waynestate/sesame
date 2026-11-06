#!/bin/sh
# Site accessibility gate (axe-core in Playwright), run in Docker so no host
# browser is needed. Linux and macOS; on Windows use scripts/a11y.ps1, or this
# script from Git Bash (the MSYSTEM block below handles its path conversion).
#
# The repo is mounted read-only; only tools/a11y/node_modules lives in a named
# volume, so npm installs once and later runs reuse it.
#
# Checks site/index.html and every HTML page under site/docs/ (and
# site/handbook/, if that folder exists): a glob that matches nothing is
# skipped, so the same script works for repos without a handbook.
set -eu

cd "$(dirname "$0")/.."

# The read-only repo mount cannot create the named volume's mount point.
mkdir -p tools/a11y/node_modules

# Git Bash on Windows rewrites absolute-looking arguments for native programs
# (docker would turn -w /work into a Windows path). Turn that off and pass the
# Windows form of the repo path instead.
if [ -n "${MSYSTEM:-}" ]; then
  export MSYS_NO_PATHCONV=1
  REPO="$(pwd -W)"
else
  REPO="$PWD"
fi

IMAGE="mcr.microsoft.com/playwright:v1.49.1-noble"

docker run --rm \
  -v "${REPO}:/work:ro" \
  -v sesame-a11y-node-modules:/work/tools/a11y/node_modules \
  -w /work \
  "$IMAGE" \
  sh -c 'if [ ! -d tools/a11y/node_modules/playwright ]; then if [ -f tools/a11y/package-lock.json ]; then (cd tools/a11y && npm ci --no-audit --no-fund); else (cd tools/a11y && npm install --no-audit --no-fund); fi; fi && node tools/a11y/check.mjs --root=site --page=index.html --page="docs/**/*.html" --page="handbook/**/*.html"'
