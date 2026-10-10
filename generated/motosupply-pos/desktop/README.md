# MotoSupply POS — Windows cashier app

A Windows app for the shop counter. Cashiers sign in with their usual MotoSupply account, scan or
search products, take cash payments and print receipts. Everything else (products, stock, users,
reports, settings, branding) stays in the MotoSupply website.

- **Version:** 1.0.1. See `CHANGELOG.md`.
- **Needs:** Windows 10 or 11 (64-bit), and a MotoSupply website running **version 1.4.0 or newer** over **HTTPS**.
- **Files:**

  | File | What it is |
  |---|---|
  | `MotoSupply-POS-Setup.exe` | Installer. Installs for the current Windows user, so no administrator rights are needed. Creates Start menu and desktop shortcuts; uninstall from Settings → Apps. |
  | `MotoSupply-POS-Portable.exe` | Runs without installing, e.g. from a USB drive. Each start is slower because it unpacks itself first. |

> **The installer is not code-signed.** Windows SmartScreen may show "Windows protected your PC". Click
> **More info → Run anyway** only if you received the file from your administrator. See "Code signing" below.

## How it works (and what it never does)

- The app talks only to your MotoSupply website (its cashier API at `index.php?api=…`), over HTTPS. It never connects to the database.
- **The server decides everything that matters:**
  - prices, stock, totals, change and permissions;
  - whether a sale is complete. The app shows "Sale completed" only after the server confirms it was saved.
- **Sign-in:**
  - Only active accounts with the **"Use the POS"** and **"Record sales"** permissions can sign in. Inventory-only, reports-only and deactivated accounts are refused.
  - The app stores **no passwords and no sign-in tokens** on the computer. The session lives in memory and ends after 30 minutes without use, or 12 hours at most. Sign-out ends it on the server too.
- **No offline sales.** Checkout needs a connection to the server. If the connection drops, the cart stays on screen and nothing is charged or deducted.
- **No double sales:**
  - The pay button cannot submit twice.
  - If the connection drops just as a sale is saved, **Check and retry** asks the server whether it went through: a saved sale is shown, not created again.
  - The same check runs at the next sign-in if the app was closed meanwhile.

## Setup (administrator / IT)

1. **Update the website first.** Install MotoSupply **1.4.0** (it adds the cashier interface the app uses), then open the website once in a browser so its database update runs.
2. **Turn on HTTPS:** Settings → System Check → **Require HTTPS (production)**. The app refuses plain `http://` addresses.
3. **Cashier accounts:** Settings → Users. The **Cashier** role already has the right permissions. Discounts need the "Apply discounts" permission.
4. **Install the app** on the counter PC: run `MotoSupply-POS-Setup.exe` (or copy the portable `.exe`).
5. **Set the server address.** Choose one:
   - **Simple (per Windows user):** start the app and enter the website address, e.g. `https://shop.example.com` (include the folder if MotoSupply is in one: `https://example.com/pos`). The app checks that it is really a MotoSupply server.
     - Afterwards, **Server settings** on the sign-in screen asks for an administrator account (with "Manage settings") before the address can be changed. A cashier cannot point the app at another server.
   - **Locked (recommended for shared PCs):** as a Windows administrator, create
     `C:\ProgramData\MotoSupply POS\config.json` containing

     ```json
     { "server_url": "https://shop.example.com" }
     ```

     The app then uses this address and hides **Server settings**. With normal Windows permissions, cashiers cannot edit files in ProgramData.
6. **Printer** (on the counter PC): click **Printer** in the app's top bar and choose the receipt printer. "Windows default printer" is used otherwise.
7. **Paper and automatic printing** are set on the website: Settings → Receipt & printing (58 mm / 80 mm / A4, logo, footer, "Print automatically after each sale"). The app reads these at sign-in.
   - With automatic printing on, the app prints **directly to the chosen printer without a dialog**.
   - Otherwise **Print receipt** sends the receipt to the chosen printer, or shows the Windows print dialog if no printer was chosen.

### Thermal receipt printers
- Install the printer's **Windows driver** from its manufacturer (Epson TM series, Xprinter and similar). In the driver's *Printing preferences*, set the paper to the roll width (58 or 80 mm) and the length to "receipt"/"continuous" if offered.
- The app prints like any Windows program (no ESC/POS commands). Cash-drawer kick and auto-cut depend on the driver settings.
- Print a test receipt first: complete a test sale, or reprint one from **Today's sales**.

### Development only
`"allow_insecure_localhost": true` in `config.json` allows `http://localhost` / `http://127.0.0.1` addresses, for a test server on the same computer. Never use it on a shop PC.

## Using the app (cashier)

| Action | How |
|---|---|
| Sign in | Your MotoSupply username and password. |
| Find a product | Type a name or SKU in the search box (F2), or tap a category. |
| Scan | Just scan: the scanner types the barcode and presses Enter, and the item is added. Unknown or archived codes are reported. |
| Change a quantity | Tap the number between − and +, enter the quantity on the number pad and press Confirm. It never asks for more than the available stock. |
| Remove an item | Bin icon on the line. |
| Discount | F4 or "Discount" (only if your account may give discounts). |
| Take payment | **PAY** or **F8** → enter the cash received (number pad or keyboard; "Exact" button) → **Complete sale**. |
| Receipt | Printed automatically if your shop turned that on; otherwise press **Print receipt**. |
| Reprint | **Today's sales** (F9) → **Reprint**. Reprints are marked "REPRINT". |
| Cancel a sale | **Cancel sale**. Nothing is charged. |
| Sign out | **Sign out** at the top right. |

**If something goes wrong:**
- **"Only N available"**: another till sold the stock in the meantime. Lower the quantity or remove the line; the server refuses the sale until the cart is correct.
- **"No connection"**: check the network. The cart stays; nothing is charged.
- **"…not known yet whether it was saved"**: do **not** take the payment again. When the connection is back press **Check and retry**: the app shows the sale if it was saved, or completes it otherwise.
- **"Your session expired"**: sign in again. Your cart is still there.
- **Printer problem**: the sale is already saved. Fix the printer and use **Today's sales → Reprint**.

## Updating the app

Close the app, then install the new `MotoSupply-POS-Setup.exe` over the old one. Your server address and printer choice are kept (they live in `%APPDATA%\MotoSupply POS`), and nothing on the server changes.

- The app never updates the website or its database.
- There is no automatic updater.
- Uninstalling keeps `%APPDATA%\MotoSupply POS` (server address, printer). Delete that folder to forget them.

## Code signing

The installer and app are **not signed** in this release (no code-signing certificate is available in the build environment). To sign:

1. Get an OV or EV code-signing certificate.
2. Build on Windows with `CSC_LINK` / `CSC_KEY_PASSWORD` set.
3. Set `"signAndEditExecutable": true` in `package.json`.

Until then, distribute the files only from a trusted place (your administrator), and compare the SHA-256 checksums in `SHA256SUMS.txt` (next to the files in `windows-release/`).

## Building from source (developers)

Needs Node.js 22. The commands below run on Linux or Windows; the cashier PC needs none of this.

```bash
npm ci
npm run typecheck
npm run test:unit
npm run dist:win     # release/MotoSupply-POS-Setup.exe and MotoSupply-POS-Portable.exe
```

End-to-end tests (Linux, against a local MotoSupply test site):

```bash
xvfb-run node tests/e2e.mjs http://127.0.0.1:8090 <test-database>
```

Start-up check of the packaged app, installed in a folder with a space in its name (like the Windows default):

```bash
npx electron-builder --linux dir --x64 --publish never -c.directories.output=/tmp/moto-linux
xvfb-run node tests/packaged_start.mjs /tmp/moto-linux/linux-unpacked
```

**Security design:**
- **The screen is locked down:**
  - It runs sandboxed with `contextIsolation` and no Node.js.
  - It loads only the bundled files, under a strict Content-Security-Policy that forbids network access from the screen.
  - Navigation, pop-ups, webviews and permission requests are blocked.
- **One narrow bridge:** the screen can call only the fixed functions in `src/preload/preload.ts`. Each is validated again in `src/main/main.ts`, which accepts calls only from the app's own page.
- **Server traffic happens only in the main process (`src/main/api.ts`):**
  - redirects are refused;
  - the token is sent in `Authorization` and `X-MotoSupply-Token` (for hosts that strip `Authorization`).
