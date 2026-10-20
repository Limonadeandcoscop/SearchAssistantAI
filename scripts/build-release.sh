#!/usr/bin/env bash
# Build the release zip registered on omeka.org: one top-level directory
# named after the module, production dependencies in vendor/, development
# files left out (see .gitattributes export-ignore).
#
#   scripts/build-release.sh [git-ref]     (default: HEAD)
#
# The zip lands in build/<Module>-<version>.zip. Needs git and zip; uses a
# local composer when available, the composer Docker image otherwise.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
ref="${1:-HEAD}"
# Module id = directory name = namespace of Module.php (module.ini's name is the display name).
module="$(sed -n 's/^namespace \([A-Za-z0-9_]*\);/\1/p' "$root/Module.php" | head -1)"
version="$(git -C "$root" show "$ref:config/module.ini" | sed -n 's/^version *= *"\(.*\)"/\1/p')"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

git -C "$root" archive --format=tar --prefix="$module/" "$ref" | tar -x -C "$work"

if command -v composer >/dev/null 2>&1; then
    (cd "$work/$module" && composer install --no-dev --optimize-autoloader --no-interaction --quiet)
else
    docker run --rm -v "$work/$module":/app -w /app composer:2 \
        install --no-dev --optimize-autoloader --no-interaction --quiet --ignore-platform-req=ext-*
fi
rm -f "$work/$module/composer.lock"

mkdir -p "$root/build"
zip_path="$root/build/$module-$version.zip"
rm -f "$zip_path"
(cd "$work" && zip -qr "$zip_path" "$module")
echo "$zip_path"
