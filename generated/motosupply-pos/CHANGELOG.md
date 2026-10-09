# Changelog

## [1.3.0] — 2026-10-09

### Fixed
- **Overselling (Priority 1).**
  - **Root cause:** on hosts where tables are created as MyISAM (the server silently substitutes it for InnoDB), a failed sale could not be rolled back. When several cashiers sold the last units at the same moment, the rejected sales left sale lines behind without a stock deduction. The database also accepted negative stock.
  - Single-till checks were already correct: 99 in stock and 100 sold was refused.
  - **Fixes:**
    - All tables are converted to InnoDB and `NO_ENGINE_SUBSTITUTION` is enforced.
    - Sales and stock adjustments refuse to run if any table cannot roll back.
    - `products.stock_qty` is `UNSIGNED`, so the database itself rejects negative stock. This is enabled automatically when no product is negative; otherwise from the integrity page once corrected.
    - Every sale is validated, locked and written in one transaction: the whole sale is refused if any line is short, and nothing is deducted.
- **Voids** now return exactly the quantity the stock history shows was deducted, so damaged historical records cannot add stock that never left the shelf.
- **POS on landscape tablets and short screens:** the cart, total and Pay button always fit on screen.

### Added
- **Inventory integrity check** (Inventory → Integrity check). It lists:
  - negative stock;
  - stock that differs from its latest recorded movement;
  - sales recorded without a stock deduction;
  - historical oversales and inconsistent history entries.

  Nothing is changed automatically. Corrections are audited actions: a physical count, an approved void, or "mark reviewed" with a note.
- **Users, roles and permissions:**
  - Administrator, Cashier, Inventory Staff, Reports Viewer and Custom roles, plus per-user allow/deny overrides.
  - Server-side, deny-by-default checks on every route.
  - Create, edit, activate, deactivate and reset passwords (temporary password shown once, change forced at sign-in).
  - Nobody can change their own role or permissions, grant permissions they lack, or remove the last administrator.
- **Supervisor-approved voids:**
  - The approver's username plus a separate 6–12 digit **void PIN**, stored hashed and never the login password.
  - A reason is required. Requester and approver are recorded.
  - Rate limits: 5 failures per approver, 15 per network, 15-minute lock. A sale can be voided only once.
- **Audit log:** sign-ins, permission changes, voids, stock corrections, imports, settings, email reports and updates. Secrets are never stored, and entries cannot be edited or deleted in the app.
- **Number pad for quantities** in the POS:
  - Touch keys (0–9, backspace, Clear, Cancel, Confirm), so the phone keyboard does not open.
  - Shows available stock and refuses quantities above it or below 1.
  - Physical keyboard supported.
- **Responsive layouts** for phones and tablets (portrait and landscape):
  - POS cart as a bottom sheet with a sticky total and Pay bar;
  - tables become cards on phones;
  - dialogs fit the screen;
  - larger touch targets.
- **Collapsible sidebar:** icon rail with tooltips on desktop, remembered per device; drawer on tablets and phones.
- **Receipt printing settings:**
  - 58 mm, 80 mm or A4 paper, logo on/off, test print.
  - Optional automatic print dialog after each sale. It never blocks the sale; browsers still ask before printing.
- **Logo and browser icon upload** (validated, random file names, never executable), shown on the sidebar, sign-in page, receipts and browser tab.
- **Theme colours** with live preview, contrast checks and reset.
- **New sign-in page:** shop branding, show/hide password, loading state, accessible labels, no default credentials.
- **Product CSV import:**
  - Templates with and without an example row; the example row is never imported.
  - Row-level preview with duplicate SKU and barcode detection.
  - Skip or update existing products, with an old → new change list and explicit confirmation.
  - Stock of existing products is never changed. All-or-nothing import with a summary.
- **End-of-day email reports:**
  - Recipients, time, timezone, sections, and PDF/CSV attachments.
  - SMTP (TLS/SSL), with the password stored encrypted.
  - Test email.
  - Exactly one report per date; failures are recorded and retried.
  - Triggers: cPanel cron, an HTTPS URL with a secret, or on-visit fallback.
- **In-app updater** for signed `MotoSupply-POS-Update.zip` packages:
  - Checks: Ed25519 signature, SHA-256 checksums, safe paths only, no links, no downgrades.
  - Safety: database and file backups, maintenance mode, staged install, migrations, post-update checks.
  - Recovery: automatic restore on failure, and one-click file restore later.
- **Automatic database updates** after uploading new files by hand, with a backup made first. Nothing is changed if the backup cannot be written.
- **System Check rows** for the zip and sodium extensions, encryption support and InnoDB tables.

### Changed
- Settings are split into tabs: Store, Receipt & printing, Email reports, Appearance, Users & permissions, Audit log, System check, Updates.
- Voids no longer use the login password.
- Passwords must use three character types and must not contain the username.
- `.json` and `.sig` files are blocked from the web.
- `.ico` files are allowed in `uploads/` (browser icon).

### Upgrade notes
- From 1.0–1.2, upload the update files by hand once. See `UPDATE-README.md`. Existing accounts become Administrators.
- After upgrading, set your void PIN in **My Account** and review **Inventory → Integrity check**.

## [1.2.0]
- Installer: automatic folder preparation with real write tests, OK/Warning/Failed results, Recheck button, and safe HTTPS testing/production modes.

## [1.1.0]
- WordPress-style installation wizard and `MotoSupply-POS-Installer.zip`.

## [1.0.0]
- First release: dashboard, POS, inventory, sales history, reports (PDF/CSV), settings.
