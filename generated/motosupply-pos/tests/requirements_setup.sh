#!/usr/bin/env bash
# Creates the folder scenarios for tests/requirements_test.php (run as root), then runs the
# test as the unprivileged www-data user. Usage: tests/requirements_setup.sh [scratch-dir]
set -euo pipefail
B="${1:-/tmp/moto-reqtest}"; U="${MOTO_TEST_USER_NAME:-www-data}"
rm -rf "$B"; mkdir -p "$B"/{a,b,c,d}
# b: owned by PHP's user, but read-only (0555)
mkdir -p "$B/b/storage/logs" "$B/b/uploads/products"; echo keep > "$B/b/storage/logs/keep.log"
# c: placeholder folders owned by another user (root), parent writable by PHP
mkdir -p "$B/c/storage/logs" "$B/c/uploads/products"; touch "$B/c/storage/logs/.gitkeep" "$B/c/uploads/products/index.html"
# d: folder and parent both owned by another user: cannot be fixed by PHP
mkdir -p "$B/d/storage/logs" "$B/d/uploads/products"; touch "$B/d/storage/logs/.gitkeep"
chown -R "$U:$U" "$B"
chmod 0555 "$B/b/storage/logs" "$B/b/uploads/products"
chown root:root "$B/c/storage/logs" "$B/c/storage/logs/.gitkeep" "$B/c/uploads/products" "$B/c/uploads/products/index.html"
chown -R root:root "$B/d/storage" "$B/d/uploads"
HERE="$(cd "$(dirname "$0")/.." && pwd)"
if [ -n "${MOTO_PHP84_IMAGE:-}" ]; then
  docker run --rm -u 33:33 -v "$HERE:/app:ro" -v "$B:$B" "$MOTO_PHP84_IMAGE" php /app/tests/requirements_test.php "$B"
else
  runuser -u "$U" -- php "$HERE/tests/requirements_test.php" "$B"
fi
