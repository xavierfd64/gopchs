# Changelog — MotoSupply POS Windows cashier app

## [1.0.0] — 2026-10-10
First release. Requires a MotoSupply website 1.4.0 or newer.

### Added
- **Sign-in:**
  - Cashier sign-in with existing MotoSupply accounts; only active accounts with POS permissions.
  - Shop logo, name and colour on the sign-in screen.
- **POS screen:**
  - Product search by name, SKU or barcode; category tabs; USB barcode scanners; no persistent scanner banner.
  - Cart with − / + buttons, a touch number pad for quantities (no Windows touch keyboard), stock limits and remove.
  - Discount for accounts with the discount permission.
- **Payment:**
  - Cash payment with a number pad, "Exact" and round-amount buttons; live change.
  - Double submission is impossible.
- **Server checks and safe retries:**
  - Latest stock is checked before payment, and again by the server at checkout. Lines over stock are highlighted until corrected.
  - Idempotent checkout: a lost answer after the server saved the sale shows the saved sale instead of creating another. This also works after the app is restarted.
- **Receipts:**
  - Shop name, logo, transaction number, date and time, cashier, lines, subtotal, discount, total, cash tendered and change.
  - Automatic printing when the shop enables it; manual print; reprints of today's sales (marked REPRINT).
  - Choice of Windows printer.
- **Connection and sessions:**
  - Status: connected, connecting, no connection, server unavailable, server updating.
  - Session expiry keeps the cart and restores it after signing in again. Sign-out revokes the session on the server.
- **Server address:**
  - First-run screen; changes later need an administrator account.
  - Lockable machine-wide in `C:\ProgramData\MotoSupply POS\config.json`.
- **Windows packaging:**
  - Per-user installer (no administrator rights needed) with Start menu and desktop shortcuts and a normal uninstall.
  - Portable build.

### Known limitations
- **Not code-signed:** SmartScreen warns on first run.
- **No offline sales:** a connection to the server is required to complete a sale.
- **No automatic updater:** install new versions with the new installer.
- **Printing:**
  - The app uses the Windows printer driver; there are no direct ESC/POS commands.
  - Cash-drawer kick and paper cut depend on the driver.
- **Payments:** cash only, as in the website.
