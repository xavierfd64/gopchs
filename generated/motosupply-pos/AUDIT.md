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
