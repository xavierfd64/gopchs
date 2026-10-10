# Test report — MotoSupply POS Windows cashier app 1.0.0

**Date:** 2026-10-10

**Server:** MotoSupply 1.4.0 (PHP 8.3, MariaDB 10.11, Apache).

**Artifacts** (built on Linux with electron-builder 26.15.3 and Electron 44.7.0):

| File | Size | SHA-256 |
|---|---|---|
| `MotoSupply-POS-Setup.exe` | 102,720,321 bytes | `8ab0587bd5360cd0780520b4b741bf286be3c6032f28c40338711ea87d633573` |
| `MotoSupply-POS-Portable.exe` | 102,457,898 bytes | `45f72db6bc49e2ef2aa844b89eb65cb61a4e0fd3c180952bd8f4b931c272112f` |

All results below come from tests that were actually run. Nothing was tested on a real Windows PC, with a real receipt printer, or against a live hosted server (see §5).

## Summary

| Suite | Where | Result |
|---|---|---|
| Server cashier API (`tests/api_test.php`) | PHP 8.3 + Apache, fresh install | 32 / 32 |
| Desktop unit tests (`tests/unit`) | Node 22 | 9 / 9 |
| Desktop end-to-end (`tests/e2e.mjs`): the real Electron app against the real server | Linux, Xvfb | 21 / 21 |
| Windows installer: install, shortcuts, uninstall entry, uninstall | Wine 9.0 (not real Windows) | passed (details in §4) |
| Windows app start-up | Wine 9.0 | **not usable under Wine** (§4) |

## 1. Server API (cashier operations only)
- **Sign-in:**
  - Allowed: Cashier and Administrator.
  - Refused: inventory-only, reports-only, POS-access without "record sales", inactive accounts, wrong passwords (same generic message), and accounts that must change their password.
  - Shared lock-out after 5 wrong passwords.
- **Tokens:**
  - Only a hash is stored. They work in `Authorization` or `X-MotoSupply-Token`.
  - Expiry: idle, absolute (12 h) and a sliding window.
  - Revocation: sign-out, deactivation, removal of a permission. Rate limit returns 429.
- **The website does not accept desktop tokens:** admin pages redirect to sign-in. The cashier API has no admin endpoints.
- **Checkout:**
  - Server prices are used (a client price of 0.01 is ignored).
  - 99-in-stock/sell-100 and short multi-line carts are refused, with the latest stock returned and no partial deduction.
  - Archived products, quantities of 0 or below, and short payment are refused. A cashier's crafted discount gets 403.
  - Retrying with the same client token returns the same sale (deducted once). Status lookup by token works, and only for your own sales.
  - 6 parallel tills buying the last 2 units: exactly 2 succeed.
- **Receipts:**
  - Your own receipts work. Another cashier's needs "View sales history".
  - Printing never creates a sale.
- **Audit and secrets:** audit entries for sign-in, refused sign-in, sign-out, sale, refused sale and reprint. Responses never contain hashes or secrets.
- **Production mode:** plain HTTP is refused (`https_required`); HTTPS works.

## 2. Desktop unit tests
- Server URL rules:
  - HTTPS is required.
  - HTTP is allowed only for loopback, and only in an explicit development mode.
  - Credentials, queries, fragments and other schemes are rejected.
- Cart, amount and token validation.
- Cart signature (idempotency).
- Money formatting and parsing.
- Stock limits, totals, and discounts capped at the subtotal (half-up).
- Number-pad logic; quick-cash amounts.
- Receipt HTML escapes everything from the server and drops non-image logos.

## 3. Desktop end-to-end (real app, real server)

| Area | Checks |
|---|---|
| Security | No Node.js in the screen. The bridge exposes exactly 20 fixed functions. `window.open` and network requests from the screen are blocked. Navigation away from the app is blocked. |
| Setup | `http://` (non-local), ftp, credentials-in-URL, unreachable and non-MotoSupply addresses are refused with clear messages. The real server is accepted. |
| Branding and status | Shop name on the sign-in screen; "Connected" status. |
| Sign-in | Inventory-only, inactive and wrong-password accounts are refused. A cashier gets only the POS screen: no admin features, and no discount control without permission. |
| Server address protection | A cashier cannot change the server. An administrator can, and the check's token is revoked at once. A locked machine config hides the option, and the bridge refuses changes too. |
| Search and scanner | By name, SKU and category; archived products hidden. Scans add items and rescans increment. Unknown and archived barcodes are reported. No persistent scanner banner. |
| Number pad | Multi-digit entry, backspace, clear, stock limit, zero refused, Cancel/Escape keep the quantity, physical keys work. Focus stays on a button, so the Windows touch keyboard does not open. |
| Totals and payment | Line totals, subtotal, +/−, remove. Short payment refused; change correct. Double click plus Enter gives exactly one sale. Stock deducted by the server. Automatic print and manual print, without a second sale. |
| Stock changed meanwhile | The line is marked "Only 1 available" and Pay is blocked until corrected. A sell-out while paying is refused by the server, with no sale and no deduction. |
| Reprint | Today's sales → Reprint, marked REPRINT; no new sale. |
| Sessions | Expired session: sign-in screen, cart kept and restored after signing in again. Sign-out revokes the token on the server. |
| Window sizes | 820×600, 1024×768, 1280×800, 1366×768, 1920×1080: no horizontal overflow, Pay always visible. |
| Network | Server unreachable: "No connection" status, checkout fails, no sale, cart kept. Answer lost after the server committed: "Check and retry" shows the saved sale, with no duplicate sale or deduction. App closed with the outcome unknown: the next sign-in reports the saved sale. |
| Console | No JavaScript errors (the expected CSP message from the blocked-fetch probe is excluded). |

Receipt printing in these tests goes to PDF through a development-only switch, because the test machine has no printer. The Windows print path (`webContents.print` to the default or chosen printer) was not exercised on a real printer.

## 4. Windows packaging checks
- **Executables:**
  - Setup and Portable are PE32 (NSIS); the app is PE32+ x64.
  - **None is code-signed.**
  - The app exe carries the icon (16–256 px) and version information (MotoSupply POS 1.0.0, MotoSupply).
- **`app.asar` contents:** only the built main, preload and screen files, the icon and `package.json`. No sources, tests or credentials. The packaged scripts are byte-identical to the code that passed the end-to-end tests.
- **Installer under Wine 9.0:**
  - The GUI runs: licence, "Only for me (root)" as the default per-user scope, `…\AppData\Local\Programs\MotoSupply POS`.
  - The silent install (`/S`) installs 321 MB.
  - Start Menu and Desktop shortcuts are created. The uninstall entry is in HKCU with name, version 1.0.0, publisher, icon and a quiet-uninstall command.
  - The silent uninstall removes the program, shortcuts and registry entry, and keeps `%APPDATA%\MotoSupply POS\settings.json`.
- **Wine artifact:** Wine's stub `powershell.exe` reports success for every command, so the installer's "app is running" check misfires (silent install exits with code 2, and the GUI asks to close the app). With `WINEDLLOVERRIDES=powershell.exe=d` the installer falls back to `tasklist` and installs normally. Real Windows has real PowerShell; this needs confirming on a Windows PC.
- **App under Wine:** the main and GPU processes start and a "MotoSupply POS" window is created. Under Wine it stays black and makes no network requests (Chromium's Windows networking and composition are incomplete in Wine). This is a Wine limitation and says nothing either way about real Windows.

## 5. Not tested — please check on the shop PC
- Installing and running on real **Windows 10/11** (SmartScreen will warn because the files are not signed).
- A real **USB barcode scanner** (tests typed the codes as keyboard input, the way scanners send them).
- A real **receipt printer** (thermal or normal), automatic printing to it, and paper/driver settings.
- A **touchscreen**.
- A live **HTTPS** server on the internet. Tests used `http://127.0.0.1` through the development switch; HTTPS was tested at the API level only.
- The portable build: built and inspected, not run.
