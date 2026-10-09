# Test report — MotoSupply POS 1.0.0

Generated from runs on 2026-10-09 09:20 UTC. Every result below comes from tests that were actually run.

## Environment
| Item | Value |
|---|---|
| PHP | 8.3.6 (CLI and Apache mod_php) |
| Database | 10.11.14-MariaDB-0ubuntu0.24.04.1 |
| Web servers | PHP built-in server; Apache Apache/2.4.58 with `.htaccess` (AllowOverride All), mod_php, HTTP and HTTPS (self-signed) |
| Install location | Web root (built-in server) and subfolders `/shop/` and `/zipinstall/` (Apache) |
| Release artifact tested | `dist/motosupply-pos-1.0.0.zip`, extracted into Apache and installed through `/install/` |
| Browser | Chromium (Playwright, headless), viewports 1440×900, 768×1024, 375×812 |

**Not tested:** InfinityFree itself (no account access). Run `DEPLOYMENT_CHECKLIST.md` on the live site.

## 1. Service tests — `php tests/run.php` → **37 passed, 0 failed**
Covers money and time, authentication, products, stock adjustments, POS sales, voids, reports, CSV/PDF exports and concurrency. The concurrency tests run 8 parallel PHP processes competing for 3 units, plus 6 parallel duplicate submissions.

| Result | Test | Details |
|---|---|---|
| PASS | Money::parse accepts valid amounts and rejects bad ones |  |
| PASS | Money formatting uses peso sign and grouping |  |
| PASS | Percent discount rounds half-up to the centavo |  |
| PASS | Local date range converts Asia/Manila to UTC |  |
| PASS | Valid login succeeds and invalid login fails |  |
| PASS | Password is stored only as a bcrypt/argon hash |  |
| PASS | Login is throttled after repeated failures |  |
| PASS | Weak new passwords are rejected |  |
| PASS | Password change clears the forced-change flag |  |
| PASS | Product creation records opening stock movement |  |
| PASS | Duplicate SKU is rejected |  |
| PASS | Duplicate barcode is rejected; empty barcodes are allowed many times |  |
| PASS | Invalid prices and quantities are rejected |  |
| PASS | Editing a product does not change its stock |  |
| PASS | Search finds products by name, SKU, barcode and category |  |
| PASS | Adjustment records before/change/after, reason and user |  |
| PASS | Adjustment cannot make stock negative and requires a reason |  |
| PASS | Successful sale: server prices, totals, change, stock and movements |  |
| PASS | Client-supplied prices and totals are ignored |  |
| PASS | Percent discount is calculated on the server |  |
| PASS | Insufficient payment is rejected and nothing is saved |  |
| PASS | Insufficient stock is rejected |  |
| PASS | Discount larger than subtotal and invalid carts are rejected |  |
| PASS | Archived products cannot be sold |  |
| PASS | Repeated request with the same token does not create a duplicate sale |  |
| PASS | Failed transaction rolls back completely |  |
| PASS | Editing a product later keeps historical sale details |  |
| PASS | Void restores stock atomically, keeps the record and cannot repeat |  |
| PASS | Report totals match database records |  |
| PASS | Date filters exclude sales outside the range |  |
| PASS | Every report type builds for daily, weekly and monthly ranges |  |
| PASS | Inventory valuation uses cost prices |  |
| PASS | CSV export escapes fields and neutralizes formula injection |  |
| PASS | PDF export is a structurally valid PDF |  |
| PASS | Large PDF paginates |  |
| PASS | Concurrent sales cannot oversell the last units |  |
| PASS | Concurrent duplicate submissions create one sale |  |

## 2. Browser end-to-end tests — `node tests/e2e.mjs` against the ZIP install on Apache → **21 passed, 0 failed**

| Result | Test |
|---|---|
| PASS | Unauthenticated pages redirect to login |
| PASS | Invalid login shows a generic error |
| PASS | Login with temporary admin/admin forces a password change |
| PASS | Dashboard shows empty states with no data |
| PASS | Create products through the form |
| PASS | Duplicate SKU shows a field error |
| PASS | Inventory page renders with stock status |
| PASS | Manual stock adjustment is recorded |
| PASS | POS: barcode scan (type + Enter) adds to cart; unknown barcode is reported |
| PASS | POS: add by search/click, category tab, change quantity, discount |
| PASS | POS: insufficient payment is rejected, then sale completes |
| PASS | Receipt shows all required fields |
| PASS | Stock deducted after the sale |
| PASS | Sales history, detail and void with password |
| PASS | Second sale for reports |
| PASS | Dashboard shows real data |
| PASS | Reports page and CSV/PDF downloads |
| PASS | Settings save and system check |
| PASS | No horizontal overflow on phone and tablet widths |
| PASS | Logout destroys the session |
| PASS | No JavaScript or CSP errors in the console |

The same suite also passed (21/21) on the PHP built-in server in the web root, and on Apache in `/shop/`.

## 3. HTTP security checks — `tests/http_security.sh` against the ZIP install → **17 passed, 0 failed**

| Result | Check |
|---|---|
| PASS | API search without login returns 401 |
| PASS | Checkout without login returns 401 |
| PASS | Reports export without login redirects |
| PASS | Unknown route returns 404 |
| PASS | Login POST without CSRF token is rejected |
| PASS | Login succeeds |
| PASS | Dashboard accessible after login |
| PASS | GET on a POST-only route returns 405 |
| PASS | Checkout without CSRF header returns 403 |
| PASS | Checkout with wrong CSRF header returns 403 |
| PASS | Product name is HTML-escaped (no raw <script>) |
| PASS | Escaped form is present |
| PASS | Checkout ignores client prices (invalid cart rejected with 422) |
| PASS | Logout |
| PASS | Old session cookie no longer works after logout |
| PASS | Session fixation: unknown session ID is not accepted as logged in |
| PASS | 6th failed login is throttled (429) |

## 4. Apache access rules (manual curl probes on Apache, `/shop/` and `/zipinstall/`)

| Path | Result |
|---|---|
| `config/config.php`, `config/config.sample.php`, `config/` | 403 |
| `app/bootstrap.php`, `app/Core/DB.php`, `app/views/layout/app.php` | 403 |
| `database/migrations/001_initial_schema.sql` | 403 |
| `storage/installed.lock`, `storage/logs/`, `storage/sessions/` | 403 |
| `.htaccess`, `uploads/.htaccess`, `README.md` | 403 |
| A `.php` file placed in `uploads/products/` | 403 (not executed) |
| An image in `uploads/products/` | 200 |
| `assets/…` (CSS, JS, SVG) | 200 |
| `/install/` after installation | 403 "Already installed" |
| Session cookie over HTTPS | `path=/shop/; secure; HttpOnly; SameSite=Lax` |
| Session cookie over HTTP | `path=/shop/; HttpOnly; SameSite=Lax` |

## 5. PHP compatibility
- `php -l` passes on every PHP file under PHP 8.3.6.
- All suites ran with `error_reporting(E_ALL)`, and warnings and deprecations were converted into exceptions. None occurred, and the Apache error log shows no PHP warnings, notices or deprecations.
- No PHP 8.4-only functions are used. Only core extensions are needed: PDO, pdo_mysql, mbstring, fileinfo, json, and optionally zlib.

## 6. Defects found and fixed during testing
| Defect | Fix |
|---|---|
| Stock-movement report crashed with division by zero when exporting all rows | Treat a page size of 0 as "all rows" |
| CSRF failures used HTTP 419, which Apache turns into 500 | Use 403 |
| Login lockout counted the IP address, so 5 failed logins from a shop locked out every user there | 5 failures per username, 20 per IP |
| Inventory table caused page-wide horizontal scrolling on phones (screen-reader label positioned against the page) | Made the table container `position: relative` |
| The cart's empty-state message stayed visible next to cart items; the header search icon overlapped the placeholder | Global `[hidden]` rule; more specific input selectors |
| Session regeneration raised a warning outside a web request | Regenerate only when a session is active |
