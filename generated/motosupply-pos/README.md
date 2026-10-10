# MotoSupply POS & Inventory System

A point-of-sale and inventory system for a motorcycle parts shop. It runs on ordinary PHP/MySQL web hosting such as InfinityFree or any cPanel host.

**Installing is like WordPress:**
1. Create a database.
2. Upload and extract one ZIP file.
3. Open your website and follow the installation wizard.

You never need to edit files, import SQL, or use Composer, npm, Node.js or a terminal.

- **Package:** `MotoSupply-POS-Installer.zip`
- **Needs:** PHP 8.1 or newer (tested on 8.3 and 8.4), MySQL or MariaDB, Apache hosting with `.htaccess` (InfinityFree and cPanel hosts have this). A free SSL certificate (HTTPS) is needed for real use.
- **Includes:**
  - Dashboard, POS with barcode-scanner support and a touch quantity keypad, Products / Inventory, CSV product import.
  - Sales History with supervisor-approved voids, Reports (PDF and CSV), end-of-day email reports.
  - Users with roles and per-user permissions, an audit log.
  - Receipt printing settings, logo and theme colours, and an in-app updater for signed update packages.
- **Version:** 1.4.0. See `CHANGELOG.md`. Updating from 1.0–1.3: see [Updating](#updating-to-a-new-version).
- **Windows cashier app:** optional desktop app for the shop counter (`MotoSupply-POS-Setup.exe`). See [Windows cashier app](#windows-cashier-app).

> The names below (`sql123.infinityfree.com`, `if0_12345678`, …) are **examples**. Always use the values from your own hosting control panel.

---

## Install in 12 steps

### A. Create the database (hosting control panel)
1. **Download** `MotoSupply-POS-Installer.zip`.
2. **Open your hosting control panel.**
3. **Create a MySQL database and user.**
   - **InfinityFree:** Client Area → your account → **Control Panel** → **MySQL Databases** → enter a name (for example `motosupply`) → **Create Database**. InfinityFree creates the user for you. Write down the 4 values shown:
     - **MySQL Host Name**, e.g. `sql123.infinityfree.com`
     - **MySQL DB Name**, e.g. `if0_12345678_motosupply`
     - **MySQL User Name**, e.g. `if0_12345678`
     - **Password:** your hosting account password, shown in the Client Area under *Account details*
   - **cPanel:** **MySQL® Databases**:
     1. Create a database.
     2. Create a user with a strong password.
     3. Under **Add User To Database**, pick both and tick **ALL PRIVILEGES**.

     The host is usually `localhost`, and names look like `cpuser_motosupply`.

### B. Upload the files (File Manager)
4. **Upload the ZIP** to your website folder:
   - **InfinityFree:** `htdocs`
   - **cPanel:** `public_html`

   To put MotoSupply in a subfolder (e.g. `yoursite.com/pos`), create the folder first and upload the ZIP into it. Do not name the folder `app`, `config`, `database` or `storage`.
5. **Extract the ZIP:** right-click it → **Extract**. Then delete the ZIP file, and delete the host's default placeholder page (`index2.html`, `default.php`) if there is one.
   - If your File Manager cannot extract ZIPs, unzip on your computer and upload all files and folders with an FTP program such as FileZilla.
   - **Turn on "show hidden files"**, so the `.htaccess` files are uploaded too.

### C. Run the installation wizard (browser)
6. **Open your website**, e.g. `https://yoursite.com` or `https://yoursite.com/pos`. The wizard starts automatically. If it doesn't, open `/install/`.
7. **Welcome → Requirements:** the wizard checks your server and **fixes what it safely can by itself**:
   - It creates missing folders (`storage/logs`, `uploads/products`, …).
   - It corrects their permissions (to 755, never 777), or recreates a folder that was uploaded with the wrong owner.
   - It then proves each folder works with a real write test.

   Each item shows **OK**, **Warning** (worth fixing, does not block) or **Failed** (must be fixed). Anything the wizard cannot fix comes with a "What to do" instruction. Fix it in the File Manager, then click **Recheck Requirements**.
   - **On `http://` (no SSL yet):** you may continue **only as a test installation**. Tick the confirmation box, and do not use real passwords or business data until HTTPS is active (see below).
8. **Database:** enter the host, database name, username and password from step 3.
   - Click **Test connection**. You should see *"Connection successful"*.
   - Then click **Continue**.
9. **Shop:** enter the shop name. Address and contact number are optional. The timezone defaults to *Asia/Manila* and the currency to *Philippine Peso*.
10. **Administrator:** choose a username and a **strong password**:
    - 8+ characters
    - at least three of: lowercase, uppercase, numbers, symbols
    - not "admin", "password" or your username

    Then click **Continue**.
11. **Install:** check the summary, click **Install MotoSupply**, and wait a few seconds.
12. **Finished:** click **Go to Login** and sign in with the account you just created.

The installer is now **locked**. It cannot run again, and it can never reset your password or overwrite your data. For extra safety you may delete the `install` folder in the File Manager.

**Last step: turn on HTTPS** (see the next section). A site installed over `http://` runs in **testing mode** and shows a yellow "Not secure" bar on every page until you do.

---

## HTTPS: testing mode and production mode

| Mode | When | What happens |
|---|---|---|
| **Testing** | Installed over `http://`, or switched back for testing | HTTP is allowed. Every page shows a yellow **"Not secure. Testing mode only"** bar. Logins, sessions and every other protection still work normally. |
| **Production** | Installed over `https://`, or switched on in **Settings → System Check** | HTTPS is **required**: `http://` visits are redirected to `https://`, and session cookies are marked Secure. |

**How to enable HTTPS after a test installation:**
1. **Get the free SSL certificate.**
   - **InfinityFree:** Client Area → your account → **Free SSL Certificates** → choose your domain → follow the verification steps (usually adding a CNAME record) → **Install** the issued certificate. It can take a little while to become active.
   - **cPanel:** **SSL/TLS Status** → select your domain → **Run AutoSSL**.
2. Open your site with **`https://`** and check that the browser shows a padlock.
3. Log in → **Settings → System Check** → **Require HTTPS (production)**. This button only works when the page itself is open over HTTPS, so you cannot lock yourself out.

**HTTPS behind a proxy (CDN or load balancer):** some setups, such as Cloudflare, handle SSL in front of the server, so PHP only sees `http`. For safety MotoSupply does **not** trust `X-Forwarded-Proto` headers from just anyone. Add the proxy's IP ranges to the configuration file, for example:
```php
'app' => [ ..., 'trusted_proxies' => ['173.245.48.0/20', '103.21.244.0/22'] ],
```
(Use the official IP list published by your proxy provider.) If the site is set to require HTTPS but the server cannot confirm it, MotoSupply shows a **"Secure connection problem"** page instead of looping between redirects.

**Locked out after enabling HTTPS?** In phpMyAdmin, open the `settings` table and set `security_mode` to `testing`. Alternatively, add `'force_https' => false,` to the `app` section of the configuration file.

---

## Using MotoSupply (quick tour)

- **Settings:** check your shop details, receipt footer and low-stock level first. Settings has tabs: Store, Receipt printing, Email reports, Appearance, System Check (each needs its own permission).
- **Users (administrators):**
  - **Users → Add user**: choose a role (Administrator, Cashier, Inventory Staff, Reports Viewer or Custom) and a temporary password. The user must change it at first sign-in.
  - Optionally allow or deny single permissions for one user. Everything not allowed is denied, and the server checks every request.
  - You cannot change your own role or permissions, and the last active administrator cannot be deactivated or demoted.
  - **Reset password** shows a one-time temporary password. **Deactivate** signs the user out at once and keeps their sales history.
- **My Account:** change your password, and (if you may approve voids) set your **void approval PIN**: 6–12 digits, different from your password, stored only as a hash.
- **Inventory → Add Product:**
  - Enter name, SKU, an optional barcode (click the field and scan the item), category, prices and opening stock.
  - Use **⋯ → Adjust stock** for deliveries and corrections. A reason is required, and every change is logged.
  - **Import CSV:** download the template (one version has an example row that is never imported, the other has headers only), fill it in Excel, choose **Save As → CSV UTF-8**, upload it.
    - The preview shows every row's action and problems. For existing SKUs you choose **skip** or **update details and prices**, and the preview lists every change (old → new).
    - Stock of existing products is never changed by an import. Use Adjust stock for that.
    - All rows are imported in one step. If anything fails, nothing is imported.
  - **Integrity check:** compares stock with its history and sales. It never changes anything by itself. Fix issues with a physical count (recorded as an adjustment) or an approved void.
- **POS:**
  - Type a product name or SKU, or scan a barcode. USB scanners add items automatically.
  - Tap the quantity to open the **number pad**: 0–9, backspace and Clear, then Confirm. It shows the available stock and refuses more than that. A physical keyboard works too: digits, Backspace, Enter, Esc.
  - Press **F8** or **PAY NOW**, enter the cash received, and **Complete sale**.
  - A sale is all-or-nothing: if any item no longer has enough stock (for example another till sold it), the whole sale is refused and nothing is deducted.
  - On phones and portrait tablets the cart is a bar at the bottom: tap it to open the cart.
  - Shortcuts: **F2** search, **F4** discount (if you have the discount permission), **F8** pay.
- **Receipts (Settings → Receipt printing):**
  - Paper 58 mm, 80 mm or A4, logo on/off, a **Test print**, and **Print automatically after each sale**.
  - Automatic printing opens the browser's print dialog by itself. Browsers do not let websites print silently or detect printers, so the cashier confirms the print, or uses a kiosk-mode browser set up for silent printing.
  - A printing problem never blocks or undoes a sale. **Print receipt** always works.
- **Sales History:**
  - Find any sale and reprint its receipt.
  - **Void** needs the "Request voids" permission, a reason, and a supervisor's username and **void PIN** (not a login password). The sale is kept, marked "Voided" with who requested and who approved it, and its stock is returned. A sale can be voided only once.
  - 5 wrong PINs for one approver, or 15 from one network, lock void approval for 15 minutes.
- **Reports:**
  - Daily, weekly, monthly or custom sales; transactions; sales by product; inventory valuation; low stock; out of stock; stock movements.
  - Each report has **Export CSV** and **Download PDF**.
- **Appearance (Settings → Appearance):**
  - Primary and sidebar colours with a live preview. Colours with poor contrast are refused. **Reset to default** restores the original look.
  - Upload a **logo** (PNG, JPG or WEBP, up to 1 MB) and a **browser icon** (PNG or ICO, up to 256 KB). They are shown on the sidebar, sign-in page, receipts and browser tab.
  - Updates never replace your logo or colours.
- **Sidebar:** the arrow button collapses it to icons (desktop). The choice is remembered on that device. On tablets and phones it opens with the ☰ button.
- **Audit log (Settings):** sign-ins, failed sign-ins, user and permission changes, voids (approved and refused), stock corrections, imports, settings changes and updates. It records who, what, when, the record, the reason and the result, and never passwords or PINs. Entries cannot be edited or deleted in the app.

## End-of-day email reports

**Settings → Email reports:**
1. Enter recipients (comma-separated), the delivery time and timezone, the sections, and whether to attach PDF and CSV files.
2. Enter your SMTP server. For example, for Gmail: `smtp.gmail.com`, port 587, TLS, and an *app password*. Many free hosts block outgoing mail ports; if the test fails with "could not connect", use your email provider's SMTP or another host. The SMTP password is stored encrypted and is never shown again.
3. Click **Send test email**.

**What the report contains:** one complete day (00:00–23:59 in the report timezone). Net sales = gross − discounts. Voided sales are excluded and counted separately. Low- and out-of-stock lists show stock at the moment the email is generated. Each date is sent **once**: repeated triggers never send duplicates. Failures are recorded on the page and retried up to 3 times automatically; **Send report for this day** retries by hand.

**How it is triggered** (choose one; PHP cannot run on a timer by itself):
- **cPanel cron job (best):** Cron Jobs → every 15 minutes → the command shown on the page (`php …/app/cli/daily-report.php`).
- **External scheduler (InfinityFree and hosts without cron):** click **New scheduler secret** and copy the HTTPS address shown once. Then add it to a free service such as cron-job.org, every 15 minutes.
  - The address works only over HTTPS and with the secret. Only a fingerprint of the secret is stored.
  - Treat the address like a password. Create a new secret if it leaks.
- **On visit (fallback):** sends the report when someone uses MotoSupply after the delivery time. Timing depends on visits, and on some hosts that page loads a few seconds slower once a day.

---

## Windows cashier app

From 1.4.0, cashiers can use the **MotoSupply POS** Windows app at the counter, instead of a browser. Administration stays on this website.

- **Install:** `MotoSupply-POS-Setup.exe` on the counter PC. It installs for the current Windows user, no administrator rights needed.
- **Connect:** on first start, enter this website's **https://** address. To lock the address for all users, create `C:\ProgramData\MotoSupply POS\config.json` with `{ "server_url": "https://your-site" }`.
- **Sign in:** cashiers use their normal accounts. Only active accounts with the "Use the POS" and "Record sales" permissions can sign in.
- **What the website controls:** prices, stock, totals and permissions (rechecked on every request), and the receipt settings (paper, logo, automatic printing) in Settings → Receipt & printing.
- **Server side:** the app talks only to this website's cashier API (`index.php?api=…`), over HTTPS (required once "Require HTTPS" is on). Sign-in tokens last 30 minutes without use, 12 hours at most. They end at sign-out, deactivation or a password change, and only their hash is stored.

Full instructions: `desktop/README.md` (included with the app download).

---

## Common installation problems

| What you see | What to do |
|---|---|
| "The database username or password is incorrect" | Copy the values again from the control panel. On InfinityFree the password is your **hosting account** password, not your client-area login. |
| "Could not reach the database server" | The **Database host** is wrong. On InfinityFree it looks like `sqlXXX.infinityfree.com`, not `localhost`. |
| "A database with that name was not found" | Use the full name including its prefix, e.g. `if0_12345678_motosupply`. |
| "This database user is not allowed to use that database" / "cannot create tables" | cPanel: **MySQL® Databases → Add User To Database → ALL PRIVILEGES**. |
| "…tables with the same names… from another application" | That database belongs to another app. Create a new, empty database for MotoSupply. |
| "…already contains a MotoSupply installation" | MotoSupply is already installed in that database. Log in normally, or use a new empty database to start fresh. |
| Requirements: **Folder … Not writable (write test failed)** | The wizard already tried to fix it automatically. In the File Manager, go to the folder shown (e.g. *(website folder)/storage/logs/*) → right-click → **Permissions** → `755` (try `775` if 755 fails; never `777`). If the folder is missing, create it with **New Folder**. Then click **Recheck Requirements**. `storage/logs` and `uploads/products` are Warnings: MotoSupply still works (logs go to the host's private PHP error log, and product images are disabled until it is fixed). `config` and `storage` are Failed and must be fixed. |
| Folders named `.logs-unwritable-…` or `.products-unwritable-…` | Left behind when the wizard recreated a folder your upload created with the wrong owner. They contain only placeholder files, are blocked from the web, and can be deleted. |
| Requirements: **Security rules (.htaccess) Missing** / **Application files Missing** | Some files did not upload. Use the File Manager's **Extract**, or enable hidden files in your FTP program and upload again. |
| Blank page or "500 Internal Server Error" right after uploading | Your host may reject a directive in the main `.htaccess`. Delete the `<IfModule mod_headers.c> … </IfModule>` block from the `.htaccess` in the website folder and reload. Keep the other `.htaccess` files. |
| "This page expired" in the wizard | Reload the page and try again. Make sure cookies are allowed for your site. |
| "Secure connection problem" page | The site requires HTTPS but the server could not confirm it. Wait for the SSL certificate to become active, or see "HTTPS behind a proxy" above. To get back in meanwhile, set `security_mode` to `testing` in the `settings` table (phpMyAdmin). |
| Locked out after failed logins | Wait 15 minutes. 5 wrong passwords for a username, or 20 from one network, pause logins for 15 minutes. |

### Where is my configuration?
The wizard stores the database settings **outside your public website folder** when the host allows it: `motosupply-private/config-XXXX.php`, next to `htdocs`/`public_html`. In that case `config/config.php` is only a small pointer. If the host does not allow it, the settings are saved in `config/config.php`, which is protected from web access. The Finished page tells you which one was used.

### Forgot the administrator password?
Another administrator can use **Users → Reset password**. Otherwise (there is deliberately no public "reset password" page):
1. In **phpMyAdmin**, open the `users` table.
2. Set the user's `must_change_password` to `1`, and `password_hash` to a new hash. You can create one on any PHP setup with `password_hash('NewTemp#Pass1', PASSWORD_DEFAULT)`.
3. Log in with the temporary password. You will be asked to choose a new one.

Alternatively, restore a backup.

### Reinstalling from scratch
The installer refuses to run once installed. To deliberately start over (this needs File Manager access, so visitors cannot do it):
1. Back up first (see below).
2. Delete `storage/installed.lock` and `config/config.php` (and the `motosupply-private` folder, if one was created).
3. Create a **new empty database**, or empty the old one in phpMyAdmin.
4. Open `/install/` again.

The wizard will never write over a database that already has a MotoSupply administrator.

---

## Backups

Back up regularly, and always before an update.
1. **Database:** phpMyAdmin → select the database → **Export** → *Quick*, *SQL* → **Go**. Keep the `.sql` file on your computer or cloud storage, **not** in the website folder.
2. **Files:** in the File Manager, download:
   - `uploads/products/` (product images) and `uploads/branding/` (logo and browser icon)
   - `config/config.php`, and `motosupply-private/` if it exists. These contain the database password, so keep them private.

**To restore:**
1. Import the `.sql` file into an empty database (phpMyAdmin → **Import**).
2. Upload the application files.
3. Put back the config file(s) and `uploads/products/`.
4. Create `storage/installed.lock` (an empty file is fine) so the installer stays locked.

## Updating to a new version

Updates come as **`MotoSupply-POS-Update.zip`**. It contains only application code, plus a signed list of files and checksums. It never contains `install/`, your configuration, `storage/`, `uploads/`, or any password. Updates never reset your administrator account, and never delete products, sales or settings.

### From 1.3 or newer: in the app
1. **Settings → Updates** (administrators, or users with the "Manage application updates" permission).
2. Upload `MotoSupply-POS-Update.zip`. MotoSupply checks it before changing anything:
   - **Signature:** official release key.
   - **Checksums:** every file.
   - **Paths:** no unsafe or absolute paths and no links. Only `app/`, `assets/`, `database/migrations/`, `index.php` and the security `.htaccess` files can be written.
   - **Version:** must be newer; downgrades are refused.
   - **Folders:** must be writable.
3. Read the summary (version, changed files, database changes) and click **Install update**. MotoSupply then:
   1. backs up the database and the current application files to `storage/backups/` (download links on the page);
   2. switches to maintenance mode;
   3. stages and installs the files;
   4. applies database updates;
   5. checks the result.
4. If any step fails, the previous files are put back automatically and the page says what happened. **Restore previous files** under *Update history and backups* restores the files from before an update, and removes files the update added.

### From 1.0, 1.1 or 1.2 (no in-app updater yet): upload by hand, once
1. **Back up** (see [Backups](#backups)). Download the database with phpMyAdmin → Export.
2. Unzip `MotoSupply-POS-Update.zip` on your computer.
3. Upload its contents into your MotoSupply folder, replacing existing files. The ZIP has no `install/`, `config/`, `storage/` or `uploads/` content, so your settings, data and images stay.
4. Open MotoSupply. On the first visit it backs up the database to `storage/backups/` and then applies the database updates. If the backup cannot be written, nothing is changed and the page says how to fix the folder permission.
5. Sign in with your usual account. Existing accounts become **Administrators**.
6. Recommended next steps:
   - set your void approval PIN in **My Account**;
   - open **Inventory → Integrity check** to review historical stock;
   - create staff accounts in **Users**.
7. You may delete `motosupply-update.json`, `motosupply-update.sig`, `UPDATE-README.md` and `CHANGELOG.md` from the website folder. They are blocked from the web anyway.

### Recovery
- **Files:** each update's backup folder `storage/backups/update-…/` has `files.zip`. Use **Restore previous files** in Settings → Updates, or unzip `files.zip` over the website folder with the File Manager.
- **Database:** `storage/backups/*.sql.gz` (or `.sql`) are full dumps. In phpMyAdmin, select the database → **Import** the file. This replaces the current data with the backup's, so download a fresh export first.
- Database updates only add tables and columns, so after rolling back files the previous version keeps working with the updated database.

---

## Security summary

- **Installer**
  - Every installer request is protected against cross-site form submission (CSRF).
  - Input is validated on the server, and raw database errors are never shown.
  - Passwords are never written to logs and never shown again after you type them.
  - After installation the wizard is locked by `storage/installed.lock` and the config file. Even if both were removed, it refuses any database that already has an administrator.
- **Passwords and sessions**
  - Passwords are stored only as `password_hash()` hashes.
  - Sessions use HttpOnly and SameSite cookies, plus Secure on HTTPS.
  - Sessions time out after 30 minutes of inactivity.
  - Logins are throttled.
- **Permissions**
  - Every route declares the permission it needs and the server checks it on every request. Anything not declared is denied, and refused requests are written to the audit log.
  - Nobody can change their own role or permissions, grant permissions they do not hold, or remove the last administrator.
- **Voids**
  - A separate, hashed void PIN (never the login password) is required, plus a reason and the void permission.
  - Approval is rate-limited, CSRF-protected, recorded with requester and approver, and possible only once per sale.
- **Stock integrity**
  - Every sale and adjustment runs in a database transaction with row locks.
  - MotoSupply refuses to sell on tables that cannot roll back (MyISAM).
  - The database itself rejects negative stock (`UNSIGNED`).
- **Secrets**
  - The SMTP password is stored encrypted (libsodium) and is never sent unencrypted to a remote server.
  - The scheduler secret is stored only as a SHA-256 fingerprint.
  - The audit log strips anything that looks like a password, PIN, token or key.
- **Updates**
  - Packages need a valid Ed25519 signature from the release key; the private key is never in the packages or the repository.
  - Every file is checked against its SHA-256 checksum, and only application code paths can be written.
- **Requests and output**
  - Every page and API endpoint requires login.
  - SQL uses prepared statements.
  - Output is escaped, and a strict Content-Security-Policy is sent.
- **Files**
  - `app/`, `config/`, `database/`, `storage/` and the installer's internal folders are blocked from the web by `.htaccess`.
  - `uploads/` never executes scripts.
  - There is no public `phpinfo()`. Server diagnostics are only in **Settings → System Check**, for logged-in administrators.
- **HTTPS**
  - HTTPS is detected only from the web server itself. Proxy headers are trusted only from configured proxies.
  - Testing mode (HTTP) shows a warning on every page.
  - Production mode enforces HTTPS and is protected against redirect loops.
- **Folders**
  - Created with 755 (never 777), verified with a real write test, and protected with `.htaccess` (logs and sessions denied; uploads serve images only and never execute scripts).

## Known limitations
- **Hosting**
  - Tested on PHP 8.3 and PHP 8.4 with MariaDB 10.11 and Apache. **Not yet verified on a live InfinityFree account.** Follow `INSTALLATION-CHECKLIST.md` on your site.
  - InfinityFree shows a browser "security check" to new visitors. Normal browsers pass it automatically.
  - Free hosting limits CPU and daily hits, so very large exports may be slow.
- **Features**
  - **Payments:** cash only.
  - **Corrections:** whole-sale voids only, no item-level returns.
  - **Not included:** customers, held sales, tax/VAT and multi-store. These were not in the requirements.
  - **Roles:** the five built-in roles cannot be renamed. Use per-user permissions (or the Custom role) for other combinations.
  - **Email reports:** need a scheduler (cron, an external HTTPS scheduler, or the on-visit fallback), because PHP cannot run on a timer by itself. Some free hosts block outgoing SMTP.
  - **Automatic receipt printing** still shows the browser's print dialog unless the browser is set up for kiosk/silent printing. Browsers do not allow websites to detect printers.
  - **Updater** needs the PHP `zip` and `sodium` extensions. Without them, update by uploading files (see Updating).
  - No Purchase Orders, by design.
- **Printing and PDFs**
  - Receipts print through the browser. There is no direct thermal-printer driver.
  - PDF reports write amounts as `PHP 1,250.00`, because built-in PDF fonts have no ₱ sign.
- **Fonts:** the interface uses the device's system fonts, so no font files are needed.
