#!/usr/bin/env bash
# Builds the upload-ready release ZIP from src/ (no Composer, Node or build step needed on the host).
# Usage: tools/build-release.sh   ->  dist/motosupply-pos-<version>.zip
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(grep -oP "const MOTO_VERSION = '\K[^']+" "$HERE/src/app/bootstrap.php")"
STAGE="$(mktemp -d)"
OUT="$HERE/dist/motosupply-pos-$VERSION.zip"
mkdir -p "$HERE/dist"
cp -r "$HERE/src/." "$STAGE/"
# Never ship secrets or local state.
rm -f "$STAGE/config/config.php" "$STAGE/storage/installed.lock" "$STAGE/storage/install.lock.tmp"
rm -rf "$STAGE/storage/sessions"
find "$STAGE/storage/logs" -type f ! -name .gitkeep -delete
find "$STAGE/uploads/products" -type f ! -name index.html -delete
cp "$HERE/README.md" "$HERE/DEPLOYMENT_CHECKLIST.md" "$HERE/KNOWN_LIMITATIONS.md" "$STAGE/"
# Sanity checks.
for f in $(find "$STAGE" -name '*.php'); do php -l "$f" > /dev/null; done
if [ -e "$STAGE/config/config.php" ] || grep -rqE "motopass" "$STAGE"; then
  echo "Refusing to build: a real config file or local test credentials were found." >&2; exit 1
fi
rm -f "$OUT"
(cd "$STAGE" && zip -qr -X "$OUT" . -x '*.DS_Store')
rm -rf "$STAGE"
echo "Built $OUT ($(du -h "$OUT" | cut -f1))"
unzip -l "$OUT" | tail -1
