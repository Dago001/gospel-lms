#!/usr/bin/env bash
# Builds gospel-lms-cpanel.zip: Laravel (production vendor/, no dev packages) + the built React site inside backend/public.
# Usage: scripts/build-cpanel.sh [output-dir]      Needs: composer, node/npm, tar, zip.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT/dist-cpanel}"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

echo "==> Building the React site"
(cd "$ROOT/frontend" && npm ci --silent && npm run build --silent)

echo "==> Staging the Laravel app"
mkdir -p "$STAGE/backend"
tar -C "$ROOT/backend" -cf - \
  --exclude=./vendor --exclude=./node_modules --exclude=./tests --exclude=./.env --exclude='./.env.*' --exclude=./.phpunit.cache --exclude=./phpunit.xml \
  --exclude=./CLAUDE.md --exclude=./AGENTS.md --exclude='./storage/logs/*' --exclude='./storage/framework/cache/data/*' --exclude='./storage/framework/sessions/*' \
  --exclude='./storage/framework/views/*' --exclude='./storage/app/*' --exclude='./bootstrap/cache/*.php' . | tar -C "$STAGE/backend" -xf -
mkdir -p "$STAGE/backend/storage/logs" "$STAGE/backend/storage/framework/"{cache/data,sessions,views} "$STAGE/backend/storage/app/private" "$STAGE/backend/bootstrap/cache"
(cd "$STAGE/backend" && composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --prefer-dist --quiet)

echo "==> Pruning vendor (git history, tests, docs: not needed at runtime)"
find "$STAGE/backend/vendor" -name .git -type d -prune -exec rm -rf {} +
find "$STAGE/backend/vendor" -mindepth 3 -maxdepth 3 -type d \( -name tests -o -name Tests -o -name test -o -name docs -o -name examples -o -name .github \) -prune -exec rm -rf {} +
find "$STAGE/backend/vendor" -type f \( -name '*.md' -o -name 'phpunit*.xml*' -o -name '.gitattributes' \) -delete

echo "==> Merging the React build into backend/public"
cp -R "$ROOT/frontend/dist/." "$STAGE/backend/public/"
cp "$ROOT/scripts/cpanel/htaccess" "$STAGE/backend/public/.htaccess"
cp "$ROOT/scripts/cpanel/user.ini" "$STAGE/backend/public/.user.ini"
cp "$ROOT/scripts/cpanel/env.production.example" "$STAGE/backend/.env.production.example"
cp "$ROOT/docs/CPANEL_DEPLOYMENT.md" "$STAGE/README-DEPLOY.md"

mkdir -p "$OUT"
(cd "$STAGE" && zip -qr "$OUT/gospel-lms-cpanel.zip" .)
echo "==> Done: $OUT/gospel-lms-cpanel.zip ($(du -h "$OUT/gospel-lms-cpanel.zip" | cut -f1))"
