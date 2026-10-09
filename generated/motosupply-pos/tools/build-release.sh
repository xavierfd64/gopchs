#!/usr/bin/env bash
# Builds the upload-ready release ZIP from src/ (no Composer, Node or build step needed on the host).
# Usage: tools/build-release.sh   ->  dist/MotoSupply-POS-Installer.zip
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(grep -oP "const MOTO_VERSION = '\K[^']+" "$HERE/src/app/bootstrap.php")"
STAGE="$(mktemp -d)"
OUT="$HERE/dist/MotoSupply-POS-Installer.zip"
mkdir -p "$HERE/dist"
cp -r "$HERE/src/." "$STAGE/"
# Never ship secrets or local state.
rm -f "$STAGE/config/config.php" "$STAGE/storage/installed.lock" "$STAGE/storage/install.lock.tmp" "$STAGE/storage/migrate.lock" "$STAGE/storage/maintenance.flag"
rm -rf "$STAGE/storage/sessions" "$STAGE/storage/backups" "$STAGE/storage/updates" "$STAGE/storage/imports" "$STAGE/uploads/branding"
find "$STAGE" \( -name "*.tmp" -o -name "*.log" -o -name ".DS_Store" -o -name "Thumbs.db" \) -delete
find "$STAGE/storage/logs" -type f ! -name .gitkeep -delete
find "$STAGE/uploads/products" -type f ! -name index.html -delete
cp "$HERE/README.md" "$HERE/INSTALLATION-CHECKLIST.md" "$HERE/CHANGELOG.md" "$STAGE/"
# Sanity checks.
for f in $(find "$STAGE" -name '*.php'); do php -l "$f" > /dev/null; done
# Every file the requirement check expects must be in the package.
for f in $(php -r 'define("MOTO_ROOT", "x"); define("MOTO_MIN_PHP", "8.1.0"); require $argv[1]; echo implode(" ", App\Services\Requirements::REQUIRED_FILES);' "$HERE/src/app/Services/Requirements.php"); do
  [ -f "$STAGE/$f" ] || { echo "Missing required file: $f" >&2; exit 1; }
done
if [ -e "$STAGE/config/config.php" ] || [ -e "$STAGE/storage/installed.lock" ] || grep -rqE 'motopass|Initial#Pass|Moto[$]hop|BEGIN [A-Z ]*PRIVATE KEY' "$STAGE" \
   || find "$STAGE" \( -name '*.key' -o -name '.env*' -o -name '*.sql.gz' -o -name 'release.*' \) | grep -q .; then
  echo "Refusing to build: a real config file or local test credentials were found." >&2; exit 1
fi
rm -f "$OUT"
(cd "$STAGE" && zip -qr -X "$OUT" . -x '*.DS_Store')
rm -rf "$STAGE"
echo "Built $OUT ($(du -h "$OUT" | cut -f1)), version $VERSION"
sha256sum "$OUT" | cut -d" " -f1 > "$OUT.sha256"
unzip -l "$OUT" | tail -1
