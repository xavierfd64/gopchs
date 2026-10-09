# Test report — MotoSupply POS 1.2.0 installer package

**Package tested:** `dist/MotoSupply-POS-Installer.zip` (sha256 `d0cfc913c68af3eb08479e3e8f56e96251bc213274d949cfee99062f18fc0a12`)
**Date:** 2026-10-09 10:03 UTC

**What changed in 1.2.0:**
- The installer now prepares folders automatically and proves each one works with a real write test.
- HTTPS detection is safe: proxy headers are trusted only from configured proxies.
- Testing mode (HTTP, with warnings) and production mode (HTTPS required) are separate, and the HTTPS redirect is protected against loops.
- Product-image upload and application logging are now covered by tests.

All results below come from tests that were actually run on **clean extractions of the ZIP**. Reproduce them with `tests/verify_release.sh`.

## Summary

| Suite | Result |
|---|---|
| Package checks (structure, assets, `php -l` on 8.3, no secrets or logs) | passed (see §1) |
| **PHP 8.3.6, Apache, subfolder install** | |
| Installation wizard | 21 passed, 0 failed |
| App end-to-end | 22 passed, 0 failed |
| HTTP security | 17 passed, 0 failed |
| **PHP 8.3.6, Apache, web-root install (config stored outside the document root)** | |
| Installation wizard | 21 passed, 0 failed |
| App end-to-end | 22 passed, 0 failed |
| HTTP security | 17 passed, 0 failed |
| **PHP 8.4.26, Apache (your hosting's PHP line), with `storage/logs/` and `uploads/products/` uploaded unwritable** | |
| Installation wizard (HTTP / testing mode) | 21 passed, 0 failed |
| App end-to-end | 22 passed, 0 failed |
| HTTP security | 17 passed, 0 failed |
| HTTPS / production mode | 7 passed, 0 failed |
| **Folder preparation and HTTPS detection (run as unprivileged `www-data`)** | |
| PHP 8.3 | 26 passed, 0 failed |
| PHP 8.4 | 26 passed, 0 failed |
| **Business logic (sales, stock, reports, concurrency)** | |
| PHP 8.3 | 37 passed, 0 failed |
| PHP 8.4 | 37 passed, 0 failed |

**Live InfinityFree deployment was NOT verified.** No hosting account was available to this build. Run `INSTALLATION-CHECKLIST.md` on your site.

## Your reported issues, and how each was verified

| Issue | Fix | Evidence |
|---|---|---|
| `storage/logs/` not writable | The wizard creates missing folders with 0755. If a folder isn't writable, it tries `chmod` 0755 then 0775 (only when PHP owns the folder; never 0777). Failing that, it moves a placeholder-only folder aside and recreates it as PHP's own. Every result is proven with a real write test. | On PHP 8.4, the folders were uploaded owned by another user. The wizard reported *"Writable (write test passed); fixed automatically: folder recreated"*, and the install, uploads and logging all worked. |
| `uploads/products/` not writable | Same as above. Product images were then uploaded and served. | App e2e: *"Product image upload is stored and served"*. A PHP file renamed to `.png` is rejected. A `.php` file in `uploads/` is not executed (403). |
| Unfixable folder | Shown as **Warning** (`storage/logs`, `storage/sessions`, `uploads`) or **Failed** (`config`, `storage`). The message names the folder relative to the website folder, e.g. *(website folder)/storage/logs/*, gives File Manager steps, and offers **Recheck Requirements**. No absolute server path is shown. | Folder tests (scenario d). Wizard test: the install is blocked when `config/` is owned by another user, then Recheck shows OK after the fix. |
| HTTPS "Not active" | HTTPS is detected from `HTTPS`, `REQUEST_SCHEME` and port 443. `X-Forwarded-Proto` and `X-Forwarded-SSL` count only from proxies listed in `trusted_proxies`. On HTTP the row is a **Warning**, and installing requires ticking a "test installation" box. Every page then shows a "Not secure. Testing mode only" bar. Production mode (HTTPS required) can only be switched on from a page loaded over HTTPS. | HTTPS e2e (7/7), folder/HTTPS tests (10 detection cases), and wizard steps *"HTTP install requires the testing-mode confirmation"* and *"System Check … HTTPS cannot be required over HTTP"*. |
| Redirect loops | If HTTPS is required but cannot be confirmed (an untrusted proxy header, or a redirect that came straight back), MotoSupply shows a "Secure connection problem" page instead of redirecting again. | HTTPS e2e: both loop scenarios return the explanation page (503), not another redirect. |

## 1. Package checks
```
== Package
No errors detected in compressed data of /home/user/gopchs/generated/motosupply-pos/dist/MotoSupply-POS-Installer.zip.
ZIP integrity OK
files: 94
php -l OK on 68 files (8.3.6)
assets OK
```
- **Absent (verified):** `config/config.php`, `storage/installed.lock`, `.env`, `.git`, `node_modules`, `tests/` and logs.
- **No build step or third-party libraries.** CSS and JS are production files, icons are inline SVG, and system fonts are used.
- `php -l` was also run with PHP 8.4.26 on all 68 PHP files extracted from the final ZIP: 0 failures.

## 2. Installation wizard (PHP 8.4, folders uploaded unwritable)
| Result | Test |
|---|---|
| PASS | Opening the website starts the wizard (Welcome step) |
| PASS | Later steps cannot be skipped |
| PASS | Requirements step shows OK/Warning statuses, write-tested folders and HTTPS warning |
| PASS | HTTP install requires the testing-mode confirmation |
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
| PASS | System Check: write-tested folders, log written, HTTPS cannot be required over HTTP |
| PASS | Installer is locked afterwards (GET and forged POSTs) |
| PASS | Even with the lock and config removed, an installed database is never overwritten |
| PASS | Installer internals and config are not web-accessible |
| PASS | Wizard pages fit phone and tablet widths without horizontal scrolling |
| PASS | No console errors during the wizard |

## 3. HTTPS / production mode (PHP 8.4, real TLS with a self-signed certificate)
| Result | Test |
|---|---|
| PASS | Over HTTPS the requirement shows OK and no testing confirmation is needed |
| PASS | Wizard installs in production mode over HTTPS |
| PASS | Login over HTTPS: Secure session cookie, no insecure banner |
| PASS | Production mode redirects plain HTTP to HTTPS |
| PASS | No redirect loop: untrusted proxy header gets an explanation page instead |
| PASS | No redirect loop: a second redirect within seconds is stopped |
| PASS | System Check over HTTPS: HTTPS OK and mode switch works both ways |

## 4. Folder preparation and HTTPS detection (PHP 8.4, as `www-data`)
| Result | Test |
|---|---|
| PASS | Missing folders are created recursively |
| PASS | Created folders pass a real write test |
| PASS | Created folders use 0755 (never 0777) |
| PASS | Safe default files written (.htaccess deny, uploads no-exec) |
| PASS | Write-test files are cleaned up |
| PASS | Read-only folders owned by PHP are fixed with chmod 755 |
| PASS | Existing files in a fixed folder are untouched |
| PASS | Foreign-owned placeholder folders are recreated and pass the write test |
| PASS | Recreated uploads folder still has its index.html |
| PASS | Unfixable folder is reported (not OK) after a failed write test |
| PASS | Instructions name the folder relative to the website folder |
| PASS | No absolute server path is shown |
| PASS | Unwritable storage/ is a blocking failure |
| PASS | Folder left exactly as it was when the fix is impossible |
| PASS | Write test passes and leaves existing files alone |
| PASS | Write test fails on a missing folder |
| PASS | HTTPS=on is detected |
| PASS | HTTPS=off is not HTTPS |
| PASS | X-Forwarded-Proto from an untrusted client is ignored |
| PASS | X-Forwarded-Proto from a trusted proxy (CIDR) is honoured |
| PASS | X-Forwarded-SSL from a trusted proxy (single IP) is honoured |
| PASS | Proxy outside the trusted range is ignored |
| PASS | IPv6 trusted proxy range works |
| PASS | Client IP taken from X-Forwarded-For only via trusted proxies |
| PASS | Spoofed X-Forwarded-For is ignored without a trusted proxy |
| PASS | HTTP shows a Warning (not Failed) with SSL instructions |

## 5. Application end-to-end after install (PHP 8.4)
| Result | Test |
|---|---|
| PASS | Unauthenticated pages redirect to login |
| PASS | Invalid login shows a generic error |
| PASS | Login works and a flagged account is forced to change its password |
| PASS | Dashboard shows empty states with no data |
| PASS | Create products through the form |
| PASS | Duplicate SKU shows a field error |
| PASS | Product image upload is stored and served |
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

## 6. HTTP security checks (PHP 8.4)
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

## 7. Business-logic tests (PHP 8.4)
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

## 8. Environment
| Item | Value |
|---|---|
| PHP | 8.3.6 (Ubuntu Apache mod_php) and 8.4.26 (official `php:8.4-apache` image with `pdo_mysql`) |
| Database | 10.11.14-MariaDB-0ubuntu0.24.04.1 |
| Web server | Apache 2.4 with `.htaccess` (AllowOverride All); HTTPS with a self-signed certificate |
| Browser | Chromium (Playwright, headless) at 1440×900, 1280×860, 768×1024 and 375×812 |
| Node.js | Used only to run the browser tests on the development machine. Not needed on the host. |

## 9. Remaining limitations
- **InfinityFree itself is untested.** Things to confirm there:
  - Its `.htaccess` support.
  - Whether PHP can write next to `htdocs/` (if not, the config stays in the protected `config/` folder automatically).
  - How its file ownership behaves after extraction.

  The PHP 8.4 container reproduces the reported folder problem, but it is not InfinityFree.
- **When a folder is recreated,** the original is left beside it as `.logs-unwritable-…` or `.products-unwritable-…`. PHP cannot delete another user's files. These leftovers contain only placeholder files, are blocked from the web (verified 403), and can be deleted in the File Manager.
- **HTTPS was tested with a self-signed certificate,** not a real one. Proxy-terminated SSL (for example Cloudflare) needs `trusted_proxies` in the configuration file, as documented in README.
- **Hardware was simulated:** barcode scanners as fast typing plus Enter, and receipts checked on screen rather than printed.
- Feature limits (cash only, one administrator, whole-sale voids) are unchanged; see README "Known limitations".
