# Phase 1–2: Audit and compatibility plan

## Phase 1: Audit of the existing project

| Item | Finding |
|---|---|
| Repository `xavierfd64/gopchs` | A **Next.js 16 / React 19 / Tailwind 4** website for "Go PCHS", a school. It contains pages (about, news, events, portals), static data files and school images. **No POS, inventory, database or backend code.** |
| Reusable components | None relevant. The school site's components (Hero, NewsSection, PortalCard…) are unrelated to a POS. |
| Database / backend | None. |
| Node.js dependency | The whole Next.js site needs Node to build, and its server features need a Node server. It cannot run on PHP-only shared hosting. |
| Figma Make reference | The `figma.com/make/…` and `*.figma.site` links could not be reached from the build environment (network policy). The design was taken from **six screenshots** supplied by the project owner: Dashboard, POS, Inventory, Sales History, Reports and Settings. No Figma source export was available. |

**Conclusion:** there was nothing POS-related to preserve or adapt. The existing Next.js site is left untouched. The MotoSupply system is a new PHP/MySQL application in `generated/motosupply-pos/`. It reproduces the Figma design with server-rendered PHP templates, plain CSS and small vanilla-JS files.

### Figma elements and how they were handled

| Figma element | Decision |
|---|---|
| Dark sidebar, MOTOSUPPLY logo, orange accent, warm grey background, flat white cards | Reproduced (CSS tokens in `assets/css/app.css`) |
| Nav: Dashboard, POS, Inventory (low-stock badge), Sales History, Reports, Settings | Reproduced, plus **Logout** as required |
| Top bar: title, "Welcome back", search, clock, bell, avatar | Reproduced. Search goes to product search, the bell links to low-stock products, and the clock uses the shop timezone. The help button was dropped because it had no function. |
| Dashboard stat cards, sales bar chart, stock alerts, recent transactions | Reproduced with live data and empty states |
| POS: big search, category tabs, 2-column product cards, Current Sale panel, PAY NOW, shortcuts | Reproduced. Walk-in customer, Hold Sale and Tax were **omitted** (not in the requirements; no fake buttons). |
| Inventory: stat strip, filters, table, "⋯" row menu, pagination | Reproduced. **Import CSV omitted** (not in the requirements). |
| Sales History: search, date range, table, View | Reproduced. The payment filter became a status filter (cash only). |
| Reports: date range, report library cards, summary with Export CSV / Download PDF | Reproduced. The "Location" filter and the "Sales by Payment Method" card were omitted (single store, cash only). |
| Settings: section nav, store info, currency, toggles | Reproduced. All three toggles are real settings. Tax rate was omitted. |
| "Scanner ready" banner above POS search | **Not present**, as required |

## Phase 2: Compatibility plan (as implemented)

### Architecture
- **Front controller:** `index.php?r=<route>`. Query-string routing needs no `mod_rewrite`, and works in the web root or a subfolder.
- **Layers**
  - `app/Core`: config, PDO wrapper, sessions, CSRF, auth, money, clock, settings, views.
  - `app/Services`: products, inventory, sales, reports, exports, migrations, uploads.
  - `app/Controllers`: thin HTTP handlers.
  - `app/views`: escaped PHP templates.
- **No third-party PHP dependencies.** PDF generation uses a small built-in writer (`app/Lib/SimplePdf.php`), so Composer is not needed at all.
- **Frontend:** server-rendered HTML with plain CSS. Vanilla JS handles the POS cart, scanner input, dialogs and the clock. There is no build step.

### Database (`database/migrations/001_initial_schema.sql`)
- **Tables:**
  - `users` and `settings`
  - `categories` and `products` (unique SKU; unique nullable barcode)
  - `sales` (unique `transaction_no`; unique `client_token` for idempotency)
  - `sale_items` (snapshot of name, SKU, unit, price and cost)
  - `stock_movements` (before, change, after, reason, user, sale)
  - `login_attempts` and `schema_migrations`
- **Money:** `DECIMAL(12,2)`. PHP works in integer centavos and never uses floats.
- **Timestamps:** stored in UTC. The connection uses `time_zone = '+00:00'`, and times are displayed in the shop timezone (default Asia/Manila).
- **Foreign keys and indexes** cover the searched and filtered columns: name, category, stock, `created_at`, status.

### Sale transaction
1. Validate the cart (whole quantities; at most 100 lines).
2. Start a transaction.
3. Lock the product rows with `SELECT … FOR UPDATE`, in ID order.
4. Check that each product is active and has enough stock.
5. Price each line from the database and compute the discount on the server.
6. Validate the cash tendered and compute the change.
7. Insert the sale. The transaction number is generated and retried on a collision.
8. Insert the sale items.
9. Deduct stock with a conditional `UPDATE … WHERE stock_qty >= ?`.
10. Write the stock movements and commit.

Any failure rolls the whole transaction back. A repeated `client_token` returns the existing sale instead of creating a duplicate.

### Hosting adaptations
- **Installer and diagnostics:** a browser installer replaces SSH/CLI setup. Diagnostics are an admin-only page instead of a public `phpinfo()`.
- **Private folders:** blocked by per-folder and root `.htaccess` rules. Files in them do nothing if executed directly.
- **No background jobs:** login-attempt cleanup and session garbage collection happen opportunistically during requests.
- **No email:** no reliance on `mail()`, shell commands, cron, Node or a persistent process.

## Version 1.1.0: installer package
- **No new features:** the application is unchanged apart from a stronger password rule and the requirement checker shared with Settings → System Check.
- **Database:** no schema change, so no migration. The installer never drops tables or touches a database that already has a MotoSupply administrator. Other applications' tables are left untouched.
- **New 7-step wizard** in `install/`: Welcome, Requirements (Passed/Warning/Failed with plain-language fixes), Database (with Test connection), Shop, Administrator, Install, Finished.
- **Strong password required:** the old temporary `admin`/`admin` option was removed from the public installer.
- **Config location:** stored outside the document root when the host allows it (`../motosupply-private/`), with only a relative pointer left in `config/config.php`. Otherwise it goes in the web-denied `config/` folder.
- **Failure handling:** installs that fail after writing data are rolled back (admin, settings and config removed), so the wizard can be retried.
- **Package:** `dist/MotoSupply-POS-Installer.zip`, built and checked by `tools/build-release.sh` and verified by `tests/verify_release.sh`.

## Version 1.2.0: shared-hosting requirement fixes
- **Folders:** prepared automatically (`Requirements::prepareDirectory`):
  1. Create with 0755.
  2. If not writable, `chmod` 0755 then 0775, only when PHP owns the folder; never 0777, no `chown`, no shell commands.
  3. Otherwise, recreate a placeholder-only folder that was uploaded with another owner.

  Every result is proven with a real write test: a random file opened in exclusive mode, so nothing is ever overwritten, and removed straight away.
- **Statuses** are OK / Warning / Failed, each with the detected result, an explanation and an action, plus a **Recheck Requirements** button. Folder paths are shown relative to the website folder.
- **HTTPS:** detected from server variables only. Forwarded headers count only from `app.trusted_proxies`, which also fixes the previous trust of any client's `X-Forwarded-Proto`.
- **Security mode** (`settings.security_mode`):
  - testing: HTTP allowed, with a warning on every page.
  - production: HTTPS enforced, with loop protection. It can only be enabled over HTTPS.
- **Logging:** falls back to the host's private PHP error log when `storage/logs` is not writable. System Check reports which one is in use.
- No database schema change; existing installations keep their data.

## Version 1.3.0: bug fixes and system enhancements
- **Oversale root cause (P1):**
  - **Probes:**
    - Single-till rules were already right (99 in stock, 100 sold → refused).
    - With MyISAM tables (MySQL silently substitutes MyISAM when InnoDB is unavailable), 8 concurrent buyers of 1 unit gave 1 accepted sale but 4 `sale_items` rows. The rejected sales could not roll back.
    - The schema also allowed negative stock through direct updates.
  - **Fixes:**
    - `NO_ENGINE_SUBSTITUTION`, and an InnoDB conversion in migration 002.
    - `SaleService::assertTransactional()` refuses sales and adjustments on non-transactional tables.
    - `products.stock_qty` is `INT UNSIGNED`. This is applied only when no product is negative, so historical data is never rewritten; otherwise it is enabled from the integrity page after correction.
    - Voids return only what the stock ledger shows was deducted.
- **Historical data:** `StockIntegrity::scan()` is read-only. Corrections go through audited paths:
  - a count adjustment (stock movement + audit);
  - an approved void (PIN);
  - "mark reviewed" (`integrity_reviews` + audit).

  Nothing is corrected automatically.
- **Permissions:**
  - Every route declares `perm` (or `auth`/`guest`); undeclared means denied. 403s are written to `audit_log`.
  - `UserService` enforces: no self-escalation, no granting permissions you lack, Administrator role only by administrators, and protection of the last active administrator.
  - Deactivation ends sessions on the next request (`Auth::load` requires `is_active`).
- **Void approval:**
  - The approver username plus a void PIN: 6–12 digits, no repeats or straight sequences, `password_hash`, must differ from the login password.
  - `pin_attempts` rate limits: 5 per approver and 15 per IP in 15 minutes.
  - CSRF on every POST. The conditional `UPDATE … WHERE status='completed'` inside the transaction prevents double voids.
- **Audit log:** `Audit::log` strips keys matching pass/pin/secret/token/key/hash/csrf before storing. There is no edit or delete route; access requires `audit.view`.
- **Secrets:**
  - SMTP password: sodium secretbox (OpenSSL AES-GCM fallback) with a key derived from `app.secret` in the private config.
  - Cron URL secret: only its SHA-256 is stored. The URL requires HTTPS and adds a random delay on failure.
  - SMTP AUTH is refused over unencrypted connections except to localhost.
- **Uploads:**
  - **Branding:** checked with finfo + getimagesize (PNG/JPEG/WEBP; favicon PNG/ICO). Size and dimension limits; random names in `uploads/branding/`. SVG is not accepted (script risk).
  - **`uploads/.htaccess`:** denies everything except image extensions and removes PHP handlers.
  - **CSV import:** only `.csv`/`.txt` without NUL bytes or ZIP signatures, stored under `storage/imports/` (web-denied), deleted after use or after 1 hour.
- **Updater:**
  - **Trust:** an Ed25519 signature over the manifest. Public keys in `UpdateKeys`, plus optional `update_trusted_keys` in config. The private key lives outside the repository and the packages.
  - **Package checks:**
    - SHA-256 per file; path validation (no `..`, absolute, drive-letter or backslash paths); symlink detection from ZIP external attributes;
    - unlisted entries rejected; target allow-list `app/`, `assets/`, `database/migrations/`, `index.php` and `.htaccess` files, so config, uploads, storage and install are never written;
    - version must be newer; `min_version` is supported.
  - **Install:** files are written one by one (no `ZipArchive::extractTo`) to staging, then by temp file + rename. Database dump + file backup first; maintenance flag; migrations; post-checks; automatic file restore on failure. File restore removes files the update added.
- **Schema updates after a manual upload:** `SchemaUpdater` takes a lock, refuses to migrate without a successful backup, and then applies idempotent migrations.
- **Accepted risks / notes:**
  - The cron key can appear in web-server access logs when sent as a query parameter (POST is also accepted).
  - Automatic printing cannot be silent in browsers.
  - With opcache, a manual upload may serve old code for a few seconds (`opcache.revalidate_freq`).
