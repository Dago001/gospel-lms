#!/usr/bin/env bash
# Builds gospel-lms-demo.zip: everything needed to run a local demo on Windows with only PHP 8.3+ and PostgreSQL installed.
# Includes Faker (the demo seeders use factories), the built React site in backend/public, and the helper scripts.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT/dist-cpanel}"
STAGE="$(mktemp -d)"; trap 'rm -rf "$STAGE"' EXIT
(cd "$ROOT/frontend" && npm ci --silent && npm run build --silent)
mkdir -p "$STAGE/backend"
tar -C "$ROOT/backend" -cf - --exclude=./vendor --exclude=./node_modules --exclude=./tests --exclude=./.env --exclude='./.env.*' --exclude=./.phpunit.cache --exclude=./phpunit.xml \
  --exclude=./CLAUDE.md --exclude=./AGENTS.md --exclude='./storage/logs/*' --exclude='./storage/framework/cache/data/*' --exclude='./storage/framework/sessions/*' \
  --exclude='./storage/framework/views/*' --exclude='./storage/app/*' --exclude='./bootstrap/cache/*.php' . | tar -C "$STAGE/backend" -xf -
mkdir -p "$STAGE/backend/storage/logs" "$STAGE/backend/storage/framework/"{cache/data,sessions,views} "$STAGE/backend/storage/app/private" "$STAGE/backend/bootstrap/cache"
# Demo seeders use Faker factories: promote fakerphp/faker from dev to runtime in the staged copy only, then install without the other dev packages.
python3 - "$STAGE/backend" <<'PY'
import json, sys
d = sys.argv[1]
c = json.load(open(d + '/composer.json')); v = c.get('require-dev', {}).pop('fakerphp/faker', '^1.23'); c['require']['fakerphp/faker'] = v; json.dump(c, open(d + '/composer.json', 'w'), indent=4)
l = json.load(open(d + '/composer.lock')); keep = []
for p in l['packages-dev']:
    (l['packages'] if p['name'] == 'fakerphp/faker' else keep).append(p)
l['packages-dev'] = keep; json.dump(l, open(d + '/composer.lock', 'w'), indent=4)
PY
(cd "$STAGE/backend" && composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --quiet 2>&1 | grep -v "lock file" || true)
find "$STAGE/backend/vendor" -name .git -type d -prune -exec rm -rf {} +
find "$STAGE/backend/vendor" -mindepth 3 -maxdepth 3 -type d \( -name tests -o -name Tests -o -name test -o -name docs -o -name examples -o -name .github \) -prune -exec rm -rf {} +
find "$STAGE/backend/vendor" -type f \( -name '*.md' -o -name 'phpunit*.xml*' -o -name '.gitattributes' \) -delete
cp -R "$ROOT/frontend/dist/." "$STAGE/backend/public/"
cp "$ROOT"/scripts/local-demo/{local-router.php,env.local.example,totp-code.php,setup-demo.ps1,start-demo.bat,fake-paystack.mjs,README-DEMO.txt} "$STAGE/"
cp "$ROOT/docs/samples/certificate-template-sample.png" "$STAGE/"
mkdir -p "$OUT"; rm -f "$OUT/gospel-lms-demo.zip"
(cd "$STAGE" && zip -qr "$OUT/gospel-lms-demo.zip" .)
echo "Done: $OUT/gospel-lms-demo.zip ($(du -h "$OUT/gospel-lms-demo.zip" | cut -f1))"
