#!/usr/bin/env bash
# HTTP-level security checks against a running installation.
# Usage: tests/http_security.sh <base-url> <admin-user> <admin-password>
set -uo pipefail
B="${1:?base url}"; U="${2:-admin}"; P="${3:?password}"
CJ="$(mktemp)"; CJ2="$(mktemp)"; pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1 (expected $2, got $3)"; fail=$((fail+1)); fi; }
code() { curl -s -o /dev/null -w "%{http_code}" "$@"; }
tok() { curl -s -c "$CJ" -b "$CJ" "$B/index.php?r=$1" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1; }

check "API search without login returns 401" 401 "$(code -H 'Accept: application/json' "$B/index.php?r=api.products.search&q=a")"
check "Checkout without login returns 401" 401 "$(code -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' -d '{}' "$B/index.php?r=api.sales.checkout")"
check "Reports export without login redirects" 303 "$(code "$B/index.php?r=reports.export&type=inventory&format=csv")"
check "Unknown route returns 404" 404 "$(code "$B/index.php?r=phpinfo")"

T=$(tok login)
check "Login POST without CSRF token is rejected" 403 "$(code -c "$CJ" -b "$CJ" -X POST -d "username=$U" --data-urlencode "password=$P" "$B/index.php?r=login")"
check "Login succeeds" 303 "$(code -c "$CJ" -b "$CJ" -X POST --data-urlencode "_csrf=$T" -d "username=$U" --data-urlencode "password=$P" "$B/index.php?r=login")"
check "Dashboard accessible after login" 200 "$(code -c "$CJ" -b "$CJ" "$B/index.php?r=dashboard")"
check "GET on a POST-only route returns 405" 405 "$(code -c "$CJ" -b "$CJ" "$B/index.php?r=products.save")"
check "Checkout without CSRF header returns 403" 403 "$(code -c "$CJ" -b "$CJ" -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' -d '{}' "$B/index.php?r=api.sales.checkout")"
check "Checkout with wrong CSRF header returns 403" 403 "$(code -c "$CJ" -b "$CJ" -X POST -H 'X-CSRF-Token: nope' -H 'Accept: application/json' -H 'Content-Type: application/json' -d '{}' "$B/index.php?r=api.sales.checkout")"
T=$(tok products.create)
XSS='<script>alert(1)</script>"><img src=x onerror=alert(2)>'
curl -s -o /dev/null -c "$CJ" -b "$CJ" -X POST --data-urlencode "_csrf=$T" --data-urlencode "name=$XSS" -d "sku=XSS-$RANDOM" -d selling_price=1 "$B/index.php?r=products.save"
BODY=$(curl -s -c "$CJ" -b "$CJ" "$B/index.php?r=products&q=alert")
check "Product name is HTML-escaped (no raw <script>)" 0 "$(echo "$BODY" | grep -c '<script>alert(1)</script>')"
check "Escaped form is present" yes "$( [ "$(echo "$BODY" | grep -c '&lt;script&gt;alert(1)&lt;/script&gt;')" -ge 1 ] && echo yes || echo no)"
check "Checkout ignores client prices (invalid cart rejected with 422)" 422 "$(code -c "$CJ" -b "$CJ" -X POST -H "X-CSRF-Token: $T" -H 'Accept: application/json' -H 'Content-Type: application/json' -d '{"items":[{"product_id":1,"quantity":-1,"price":"0.01"}],"tendered":"1","client_token":"6f1c1e7a-2b3c-4d5e-8f90-a1b2c3d4e5f6"}' "$B/index.php?r=api.sales.checkout")"

OLD=$(grep MOTOSESS "$CJ" | awk '{print $7}')
T=$(tok dashboard)
check "Logout" 303 "$(code -c "$CJ" -b "$CJ" -X POST --data-urlencode "_csrf=$T" "$B/index.php?r=logout")"
check "Old session cookie no longer works after logout" 303 "$(code -H "Cookie: MOTOSESS=$OLD" "$B/index.php?r=dashboard")"
check "Session fixation: unknown session ID is not accepted as logged in" 303 "$(code -H 'Cookie: MOTOSESS=attackerchosenid123456' "$B/index.php?r=dashboard")"

# Throttling (uses a separate cookie jar; 5 failures lock the username for 15 minutes).
TU="nobody$RANDOM"
for i in 1 2 3 4 5; do
  T2=$(curl -s -c "$CJ2" -b "$CJ2" "$B/index.php?r=login" | grep -oP 'name="_csrf" value="\K[^"]+')
  curl -s -o /dev/null -c "$CJ2" -b "$CJ2" -X POST --data-urlencode "_csrf=$T2" -d "username=$TU" -d password=wrong "$B/index.php?r=login"
done
T2=$(curl -s -c "$CJ2" -b "$CJ2" "$B/index.php?r=login" | grep -oP 'name="_csrf" value="\K[^"]+')
check "6th failed login is throttled (429)" 429 "$(code -c "$CJ2" -b "$CJ2" -X POST --data-urlencode "_csrf=$T2" -d "username=$TU" -d password=wrong "$B/index.php?r=login")"

rm -f "$CJ" "$CJ2"
echo; echo "$pass passed, $fail failed"; [ "$fail" -eq 0 ]
