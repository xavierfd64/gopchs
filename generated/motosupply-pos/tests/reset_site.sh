#!/usr/bin/env bash
# Redeploys src/ into the local Apache test web root and runs the install wizard (test machines only).
# Usage: tests/reset_site.sh [webroot] [base-url] [database] [admin-user] [admin-password] [--force-change]
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
ROOT="${1:-/var/www/mototest}"; B="${2:-http://127.0.0.1:8090}"; DB="${3:-moto_e2e}"
rm -rf "$ROOT" && cp -a "$HERE/src" "$ROOT" && chown -R www-data:www-data "$ROOT"
bash "$HERE/tests/fresh_install.sh" "$B" "$DB" "${4:-admin}" "${5:-Moto!Counter2026}" ${6:-} | tail -1
