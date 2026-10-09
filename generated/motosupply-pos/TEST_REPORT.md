# Test report — MotoSupply POS 1.3.0

**Packages tested:**
- `dist/MotoSupply-POS-Installer.zip` (sha256 `353e1bffd03bcd830dca51643b0b6cfe076696d1d60da1e157ea00a2859572f5`)
- `dist/MotoSupply-POS-Update.zip` (sha256 `2cab6defe26500ef103d2d4976bde0b2306d5e0449574c76925692b2c3ab0d59`). Signed with the release key; public key `zq0X6gsnb1kfFPbMY/qfAKuVQ6crvvICA8qq2X3zyks=`.

**Date:** 2026-10-09 (UTC)

Every result below comes from tests that were **actually run** on clean extractions of these ZIPs, using `tests/verify_release.sh` (final run: **RELEASE VERIFIED**). Nothing was tested on a live InfinityFree account. See §7.

## Summary

| Suite | PHP 8.3.6 (Apache) | PHP 8.4.26 (Apache container) |
|---|---|---|
| Package checks, both ZIPs: structure, signature, checksums, allowed paths, no secrets/keys/logs, file modes, `php -l` | passed | — |
| Installation wizard: subfolder install | 21 / 21 | — |
| Installation wizard: web-root install, config outside the document root | 21 / 21 | — |
| Installation wizard: `storage/logs` and `uploads/products` uploaded unwritable | — | 21 / 21 |
| App end-to-end in a real browser (subfolder / root / 8.4) | 34 / 34, 34 / 34 | 34 / 34 |
| HTTP security (subfolder / root / 8.4) | 17 / 17, 17 / 17 | 17 / 17 |
| HTTPS production mode, real TLS | — | 7 / 7 |
| **New** 1.3 HTTP tests: roles on every route, CSRF, void approval, users, branding, theme, cron URL | 36 / 36 | not run |
| **New** upgrade: real 1.2.0 ZIP → `MotoSupply-POS-Update.zip` by hand → in-app update → restore | 31 / 31 | not run |
| Service tests (business logic, 1.0–1.2) | 37 / 37 | 37 / 37 |
| **New** 1.3 service tests: stock rules, integrity, permissions, PINs, audit, import, theme, secrets, email | 47 / 47 | 47 / 47 |
| **New** updater tests: signature, tampering, traversal, symlinks, protected paths, downgrade, backups, restore | 13 / 13 | **skipped**: this PHP 8.4 image has no `zip` extension, and the network policy blocked installing it |
| Folder preparation and HTTPS detection (as `www-data`) | 26 / 26 | 26 / 26 |

**Bugs found and fixed while testing 1.3:**
- **POS cart off-screen:** at 1024×768 behind the HTTP notice, PAY NOW was cut off and Clear was hidden. The POS is now a fixed-height layout on screens ≥900 px wide.
- **Updater backup collision:** two updates in the same second used the same backup folder name, so the second was refused. Names are now unique.
- **Update ZIP file modes:** files extracted by hand came out world-writable (666). They are now 0644.
- **Integrity "stock vs history" check:** it could never be cleared after a historical unrecorded change. It now compares stock with the latest movement, so a physical count clears it.
- **Phantom-sale void:** voids now return only stock that was actually deducted, which handles partially damaged sales.
- **Import update mode:** columns missing from the file would have blanked values. They are now kept, and every change is previewed.
- **Migrations:** they now refuse to run if the pre-migration backup fails. Previously they continued.
- **Email reports:** exhausted retries were reported as "busy". They now show "gave up", and a manual send can retry.

## 1. Priority 1: oversale

**Root cause (from probes):**
- Single-till logic was already correct.
- On MyISAM tables, which MySQL silently substitutes when InnoDB is unavailable, a failed sale could not roll back. 8 concurrent buyers for 1 unit left 1 sale but 4 `sale_items` rows.
- The database also accepted negative stock.

| Scenario (from the request) | Test | Result |
|---|---|---|
| Stock 99, sell 100 → rejected, no partial deduction | `run_v13`: nothing written (sales, lines, movements unchanged), stock 99 | PASS |
| Stock 99, sell 99 → accepted, stock 0 | movement 99 → 0 recorded | PASS |
| Stock 99, restock 1,000 → 1,099 | adjustment before/change/after 99/1000/1099 | PASS |
| Stock 0, sell 1 → rejected | | PASS |
| Multi-product cart with one short line → whole sale rejected | stock of both unchanged, no rows written | PASS |
| Same product on two lines exceeding stock | merged and rejected | PASS |
| Zero, negative or fractional quantities | rejected | PASS |
| Concurrency: 12 cashiers, last 5 units | exactly 5 sales, stock 0, 5 lines, 5 movements (no phantom lines) | PASS (8.3 and 8.4) |
| Concurrency: duplicate submissions | one sale | PASS |
| Database guard | direct `UPDATE … stock_qty - 3` on stock 2 is refused by MySQL | PASS |
| MyISAM protection | sales and adjustments refused while a table is MyISAM; work again after InnoDB | PASS |
| Stock ran out while a cart was open (browser, two tabs) | payment refused with a message; stock unchanged | PASS (e2e) |
| Historical audit | negative, ledger and phantom findings detected; scan changes nothing; corrections only through count, void or review; guard enabled afterwards | PASS |
| Upgrade with damaged data | 1.2 DB with MyISAM tables and stock −2 → InnoDB conversion; negative kept for review (guard not forced); listed on the integrity page | PASS (upgrade test) |

## 2. Priorities 2, 3 and 14: users, permissions, void approval, audit

**HTTP role matrix (`http_v13.php`):**
- 21 routes × 5 users (admin, cashier, inventory, reports viewer, custom with no permissions), plus anonymous.
- Every allowed and refused combination returned the expected 200 / 403 / redirect-to-login.
- Refusals are written to the audit log.
- Forged POSTs without permission:
  - create user, change settings, change theme, upload update → all 403, nothing changed;
  - a cashier's crafted discount request → 403, stock unchanged.
- POSTs without a valid CSRF token → 403.

**User rules (service + HTTP):**
- No self-escalation (own role and overrides are ignored).
- Non-admins cannot assign Administrator or grant permissions they lack.
- The last active administrator is protected.
- Unknown permission names are ignored.
- A deactivated user is logged out on their next request and cannot log in.
- Reset password shows a temporary password once and forces a change.
- New passwords must be strong and must not contain the username.

**Void approval:**
- **PIN policy:** refuses short, repeated and sequence PINs.
- **Storage:** hash only, never equal to the login password. Setting it needs the current password.
- **Approval:**
  - approver must hold `sales.void.approve`;
  - wrong PIN, wrong user, or the login password used as the PIN → refused;
  - 5 failures lock that approver and 15 lock that IP, both for 15 minutes.
- **Void result:** a reason is required; requester, approver, reason and time are recorded; the original lines are kept; stock is returned once; a second void has no effect.
- **Browser:** the same flow, with "approved by" shown on the sale.

**Audit:**
- Secrets (PIN, passwords, SMTP password, tokens, cron key) never appear in `audit_log`.
- The browser audit page lists sign-ins, voids, user creation and PIN changes.

## 3. Priorities 4, 5, 6 and 9: responsive, keypad, sidebar, login (browser, Chromium)

- **Layouts:**
  - No horizontal overflow on 18 pages at 360×740, 390×844, 768×1024, 1024×768, 820×1180, 1180×820, 1280×800 and 1440×900.
  - On the POS, total and Pay stay inside the viewport on tablet landscape (side cart) and on tablet portrait / phone (sticky bar and bottom sheet).
  - Screenshots: `docs/screenshots/24-*`, `26-mobile-sales-cards.png`.
- **Keypad:**
  - Multi-digit entry, backspace, Clear, Cancel (quantity unchanged), Escape; above stock or zero refused; the cart changes only on Confirm.
  - Physical keyboard digits, Backspace and Enter work.
  - No text field gets focus, so the phone keyboard stays closed.
  - It fits the screen at all three sizes, and keys are at least 44 px.
- **Sidebar:** the rail collapses to 72 px; every icon has a tooltip; the choice persists after reload. Drawer on mobile.
- **Login:**
  - Show/hide password; correct `autocomplete`; empty submit blocked; generic error; no default credentials on the page.
  - Forced password change works.
- **Not tested:**
  - physical touch devices and Safari/iOS (only Chromium emulation of the viewport sizes was used);
  - screen-reader output.

## 4. Priorities 7, 8 and 13: printing, branding, theme

- **Receipt settings:**
  - 58 mm paper applied; **Test print** opens a receipt marked TEST.
  - With auto-print on, a completed sale creates the hidden print frame, and the sale is not blocked.
  - Not tested: real printers, and the browser's print dialog itself (headless).
- **Branding:**
  - PNG logo stored as `uploads/branding/logo-<random>.png`, served as `image/png`, shown on the sign-in page.
  - Refused: PHP disguised as PNG, SVG, files over 1 MB.
  - A PHP file placed in `uploads/branding/` is **not executed** (403).
  - Favicon upload and removal; the old file is deleted.
- **Theme:**
  - Live preview changes `--accent` before saving; the saved colour is applied on other pages.
  - Refused: invalid values, CSS injection, and low-contrast yellow.
  - Reset restores `#dd4a2b`.

## 5. Priorities 10 and 11: CSV import and email reports

**Import:**
- Templates with and without the example row; the example row is never imported.
- Row errors and duplicate SKU/barcode are found within the file and against the database.
- **Create mode:** skips existing products, leaving their price and stock untouched.
- **Update mode:**
  - changes only details and prices;
  - keeps columns missing from the file;
  - lists old → new values;
  - skips rows with no changes;
  - never changes stock.
- **Transactions:** a conflict mid-import rolls back every row.
- **File formats:** semicolon CSV, Windows-1252, and our own formula-escaped exports.
- **Browser:** upload → preview counts → confirm → summary.

**Email:**
- **Delivery:** a local SMTP server (`tests/smtp_sink.py`) received the test email over **STARTTLS with AUTH LOGIN**. In the browser, the test email was received and its decoded subject marked `[TEST]`.
- **Accuracy:** report totals (count, net, discounts) equal the database figures; voided sales are excluded.
- **Scheduling:**
  - Exactly one email per date across the cron, URL and visit triggers.
  - Not due before the send time; disabled → nothing sent.
- **Attachments:** 2 PDFs (valid `%PDF` … `%%EOF`) and 1 CSV, decoded from the delivered email.
- **Failures:** a failure is recorded and retried 3 times automatically, then reported as "gave up"; a manual send retries. The SMTP password does not appear in runs, the audit log or the logs.
- **Security:** the SMTP password is never sent unencrypted to a remote host.
- **Cron URL:**
  - refused over HTTP and with a wrong key (403);
  - works over HTTPS with the right key;
  - key shown once and stored as SHA-256 only.
- **Not tested:** real providers (Gmail and others), and whether InfinityFree allows outgoing SMTP.

## 6. Priority 12: updates and migrations

**Package validation (`updater_test.php`):**
- **Rejected:**
  - wrong signing key;
  - a file modified after signing;
  - `../`, absolute, `C:` and backslash paths;
  - a symlink entry;
  - unlisted files;
  - signed manifests targeting `config/config.php`, `uploads/…`, `storage/…`, `install/…` or files outside the app;
  - downgrade and same-version packages;
  - installer or random ZIPs;
  - non-ZIP files.
- **Valid package:**
  - files and a new migration are installed;
  - products, users, sales, `config.php` and uploads are byte-for-byte unchanged;
  - database dump and file backup are created; maintenance mode ends.
- **Backup:** the dump was imported into a new database with identical row counts.
- **Restore:** brings back the previous version and **deletes files the update added**.
- **Failure recovery:** a failing migration triggers automatic restore of the previous files.

**Real upgrade (`upgrade_test.sh`):**
1. Installed the actual 1.2.0 release ZIP from git history and created data through the 1.2 UI and API.
2. Damaged it: MyISAM tables, negative stock.
3. Extracted `MotoSupply-POS-Update.zip` over it by hand.
4. Results:
   - `config.php` unchanged; extracted files not world-writable;
   - the first visit made a full database backup and then migrated to schema 2;
   - all tables InnoDB; data, password hashes and settings unchanged; admin became Administrator and signs in with the same password;
   - the old installer stays locked;
   - the update's json, sig and md files are blocked from the web (403); no errors were logged.
5. Then the **in-app updater over HTTP** with a 1.3.1 test package:
   - verified, summarised and installed; backups made; data unchanged;
   - restore back to 1.3.0 worked;
   - a 1.2.9 package was refused as a downgrade.

**Notes on these tests:**
- Test packages were signed with a throwaway key trusted only by the test site. The release private key stays outside the repository.
- PHP's opcache serves cached old files for up to `opcache.revalidate_freq` seconds (2 s here) after files are replaced by hand. The tests wait 3 s. `UPDATE-README.md` tells users to reload if they see an error right after uploading.

## 7. Not verified / limitations

- **Live hosting:** not tested on InfinityFree or any live shared host. Hosting differences (PHP extensions, opcache settings, blocked SMTP ports, upload limits) can only be confirmed on your site: follow `INSTALLATION-CHECKLIST.md`.
- **PHP 8.4:** the updater was not tested there, because the test image lacks `zip`. On a host without `zip` or `sodium`, Settings → Updates says so and disables uploading; manual updates still work.
- **Devices:** no real tablets, phones or iOS Safari; Chromium only.
- **Printing:** no real printers. Silent printing is not possible from a web page.
- **Email:** no real email providers.
- **Load:** concurrency was tested with 12 parallel PHP processes against MariaDB 10.11; no large-scale load testing.

## 8. Environment

- PHP 8.3.6 (Apache 2.4 mod_php, opcache on) and PHP 8.4.26 (official `php:8.4-apache` image).
- MariaDB 10.11.
- Chromium via Playwright.
- Python 3 SMTP test server.
- Reproduce with `tests/verify_release.sh` (needs the local Apache vhosts described in the script header).
