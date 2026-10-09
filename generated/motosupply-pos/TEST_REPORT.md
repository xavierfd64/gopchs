# Test report — MotoSupply POS 1.1.0 installer package

**Package tested:** `dist/MotoSupply-POS-Installer.zip` (sha256 `54c019c9d727d06269ec1b68805eea8b604104a8c4cb6ede997e8acdabfd4fe6`).
**Date:** 2026-10-09 09:35 UTC.

Every result in this report comes from tests that were actually run. Each run started from a **clean extraction of the ZIP**, not from the development folder. Reproduce with `tests/verify_release.sh`.

## Summary

| Suite | Subfolder install (`/wiz`) | Root install (like `htdocs/`) |
|---|---|---|
| Installation wizard (browser) | 19 passed, 0 failed | 19 passed, 0 failed |
| Application end-to-end (browser) | 21 passed, 0 failed | 21 passed, 0 failed |
| HTTP security checks | 17 passed, 0 failed | 17 passed, 0 failed |
| Service tests (PHP, database) | 37 passed, 0 failed | (same code) |

**Live InfinityFree deployment was NOT verified.** No InfinityFree account was available to this build. Run `INSTALLATION-CHECKLIST.md` on your test site before relying on it.

## Test environment

| Item | Value |
|---|---|
| PHP | 8.3.6 (Apache mod_php and CLI) |
| Database | 10.11.14-MariaDB-0ubuntu0.24.04.1 |
| Web server | Apache/2.4.58, `.htaccess` enabled (AllowOverride All), mod_headers, mod_rewrite; HTTPS checked with a self-signed certificate |
| Layout A | Subfolder `http://127.0.0.1:8090/wiz/`. The parent folder is not writable, so the config is saved in the protected `config/` folder. |
| Layout B | Website root `http://127.0.0.1:8091/`, like InfinityFree `htdocs/`. The parent is writable, so the config is saved in `../motosupply-private/`, outside the document root. |
| Browser | Chromium (Playwright, headless) at 1440×900, 1280×860, 768×1024 and 375×812 |
| Node.js | Used **only** to run the browser tests on the development machine. Not needed on the host. |

## 1. Package checks (`tests/verify_release.sh`)
```
== Package
No errors detected in compressed data of /home/user/gopchs/generated/motosupply-pos/dist/MotoSupply-POS-Installer.zip.
ZIP integrity OK
files: 91
php -l OK on 65 files (8.3.6)
assets OK
```
- **Present:** `index.php`, `.htaccess`, the installer, the schema and migrations, `config.sample.php`, `README.md`, `INSTALLATION-CHECKLIST.md`, and every asset referenced by the templates.
- **Absent (verified):** `config/config.php`, `storage/installed.lock`, `.env`, `.git`, `node_modules`, `tests/` and `*.log`.
- The build script refuses to package a real config file or the local test credentials.
- **Dependencies:** no third-party PHP or JS libraries. No build step: the CSS and JS are hand-written production files, and the interface uses system fonts and inline SVG icons.

## 2. Installation wizard — layout A (subfolder)
| Result | Test |
|---|---|
| PASS | Opening the website starts the wizard (Welcome step) |
| PASS | Later steps cannot be skipped |
| PASS | Requirements step shows Passed/Warning/Failed statuses |
| PASS | Wrong database password gives a plain-language error and is not echoed |
| PASS | Unknown database host and unknown database name are explained |
| PASS | Database with conflicting tables from another application is refused |
| PASS | Test connection succeeds with correct details |
| PASS | Shop step validates and defaults to Asia/Manila and PHP |
| PASS | Administrator step rejects admin/admin, weak and mismatched passwords |
| PASS | Install summary shows no secrets |
| PASS | A failed install (config folder not writable) rolls back and can be retried |
| PASS | Success page: login URL, lock confirmation, password reminder, no secrets |
| PASS | Database state after install: one hashed admin, schema version, shop settings |
| PASS | Go to Login works and the first login succeeds |
| PASS | Installer is locked afterwards (GET and forged POSTs) |
| PASS | Even with the lock and config removed, an installed database is never overwritten |
| PASS | Installer internals and config are not web-accessible |
| PASS | Wizard pages fit phone and tablet widths without horizontal scrolling |
| PASS | No console errors during the wizard |

Layout B (root) ran the same 19 checks: **19 passed, 0 failed**. It also confirmed the config was written outside the document root: `config/config.php` holds only a relative pointer to `../motosupply-private/config-<hash>.php`, and that folder is not reachable over HTTP.

**Also tested by hand on a broken upload** (`assets/js/pos.js` and `uploads/.htaccess` deleted):
- Both were reported as **Failed**, each with a plain-language fix.
- The Continue button was hidden.
- A forced POST stayed on the Requirements step, and opening the Database step redirected back to Requirements.

## 3. Application end-to-end after installation — layout A (subfolder)
| Result | Test |
|---|---|
| PASS | Unauthenticated pages redirect to login |
| PASS | Invalid login shows a generic error |
| PASS | Login works and a flagged account is forced to change its password |
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

Layout B (root): **21 passed, 0 failed**.

## 4. HTTP security checks — layout A (subfolder)
| Result | Test |
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

Layout B (root): **17 passed, 0 failed**.

## 5. Service tests (business logic against MariaDB)
| Result | Test |
|---|---|
| PASS | Money::parse accepts valid amounts and rejects bad ones |
| PASS | Money formatting uses peso sign and grouping |
| PASS | Percent discount rounds half-up to the centavo |
| PASS | Local date range converts Asia/Manila to UTC |
| PASS | Valid login succeeds and invalid login fails |
| PASS | Password is stored only as a bcrypt/argon hash |
| PASS | Login is throttled after repeated failures |
| PASS | Weak new passwords are rejected |
| PASS | Password change clears the forced-change flag |
| PASS | Product creation records opening stock movement |
| PASS | Duplicate SKU is rejected |
| PASS | Duplicate barcode is rejected; empty barcodes are allowed many times |
| PASS | Invalid prices and quantities are rejected |
| PASS | Editing a product does not change its stock |
| PASS | Search finds products by name, SKU, barcode and category |
| PASS | Adjustment records before/change/after, reason and user |
| PASS | Adjustment cannot make stock negative and requires a reason |
| PASS | Successful sale: server prices, totals, change, stock and movements |
| PASS | Client-supplied prices and totals are ignored |
| PASS | Percent discount is calculated on the server |
| PASS | Insufficient payment is rejected and nothing is saved |
| PASS | Insufficient stock is rejected |
| PASS | Discount larger than subtotal and invalid carts are rejected |
| PASS | Archived products cannot be sold |
| PASS | Repeated request with the same token does not create a duplicate sale |
| PASS | Failed transaction rolls back completely |
| PASS | Editing a product later keeps historical sale details |
| PASS | Void restores stock atomically, keeps the record and cannot repeat |
| PASS | Report totals match database records |
| PASS | Date filters exclude sales outside the range |
| PASS | Every report type builds for daily, weekly and monthly ranges |
| PASS | Inventory valuation uses cost prices |
| PASS | CSV export escapes fields and neutralizes formula injection |
| PASS | PDF export is a structurally valid PDF |
| PASS | Large PDF paginates |
| PASS | Concurrent sales cannot oversell the last units |
| PASS | Concurrent duplicate submissions create one sale |

## 6. Compatibility
- **PHP 8.3.6:** `php -l` passes on all 65 PHP files in the package. No PHP 8.4-only functions are used, and nothing is deprecated in 8.3.
- **Errors:** all suites ran with `error_reporting(E_ALL)`, and the app turns warnings and deprecations into exceptions. None occurred, and the Apache error log has no PHP warnings, notices or deprecations.
- **Extensions:** only standard ones (`pdo`, `pdo_mysql`, `mbstring`, `json`; optional `fileinfo` and `zlib`).
- **Server requirements:** no shell commands, cron jobs, background workers, Composer, npm or Node.js on the server.

## 7. Remaining limitations
- **Not verified on InfinityFree.** Things to confirm there:
  - That InfinityFree's PHP may write next to `htdocs/`. If not, the installer automatically keeps the config in the protected `config/` folder instead.
  - That the `.htaccess` directives used are accepted.
  - That InfinityFree's browser "security check" does not interfere. It should not, for normal browsers.
- The HTTPS behaviour (`Secure` cookie, automatic `force_https` when installed over HTTPS) was checked on Apache with a self-signed certificate, not with a real certificate.
- Real USB barcode scanners were simulated: fast typing followed by Enter, which is how keyboard-mode scanners behave. No physical scanner was used.
- Printing was checked as the browser receipt page only. No physical printer was used.
- Feature limits (cash only, one administrator, whole-sale voids) are listed in `README.md` under "Known limitations".
