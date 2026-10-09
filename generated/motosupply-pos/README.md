# MotoSupply POS & Inventory System

A point-of-sale and inventory system for a motorcycle parts shop. It runs on ordinary PHP/MySQL web hosting such as InfinityFree or any cPanel host.

**Installing is like WordPress:**
1. Create a database.
2. Upload and extract one ZIP file.
3. Open your website and follow the installation wizard.

You never need to edit files, import SQL, or use Composer, npm, Node.js or a terminal.

- **Package:** `MotoSupply-POS-Installer.zip`
- **Needs:** PHP 8.1 or newer (tested on 8.3 and 8.4), MySQL or MariaDB, Apache hosting with `.htaccess` (InfinityFree and cPanel hosts have this). A free SSL certificate (HTTPS) is needed for real use.
- **Includes:** Dashboard, POS with barcode-scanner support, Products / Inventory, Sales History, Reports (PDF and CSV), Settings

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

- **Settings:** check your shop details, receipt footer and low-stock level first.
- **Inventory → Add Product:**
  - Enter name, SKU, an optional barcode (click the field and scan the item), category, prices and opening stock.
  - Use **⋯ → Adjust stock** for deliveries and corrections. A reason is required, and every change is logged.
- **POS:**
  - Type a product name or SKU, or scan a barcode. USB scanners add items automatically.
  - Press **F8** or **PAY NOW**, enter the cash received, and **Complete sale**.
  - Print the receipt from the browser.
  - Shortcuts: **F2** search, **F4** discount, **F8** pay.
- **Sales History:**
  - Find any sale and reprint its receipt.
  - **Void** a sale with a reason and your password. The sale is kept, marked "Voided", and its stock is returned.
- **Reports:**
  - Daily, weekly, monthly or custom sales; transactions; sales by product; inventory valuation; low stock; out of stock; stock movements.
  - Each report has **Export CSV** and **Download PDF**.

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
There is deliberately no public "reset password" page.
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
   - `uploads/products/` (product images)
   - `config/config.php`, and `motosupply-private/` if it exists. These contain the database password, so keep them private.

**To restore:**
1. Import the `.sql` file into an empty database (phpMyAdmin → **Import**).
2. Upload the application files.
3. Put back the config file(s) and `uploads/products/`.
4. Create `storage/installed.lock` (an empty file is fine) so the installer stays locked.

## Updating to a new version
1. **Back up** the database and files.
2. Unzip the new `MotoSupply-POS-Installer.zip` on your computer.
3. Upload everything **except** `install/`, `config/`, `storage/` and `uploads/`, replacing the old files.
4. Log in → **Settings → System Check**. If it lists pending database updates, click **Apply database updates**. Updates only add or change structure; they never delete your sales or products.

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
  - **Accounts:** one administrator; no cashier accounts.
  - **Corrections:** whole-sale voids only, no item-level returns.
  - **Not included:** customers, held sales, tax/VAT, CSV import and multi-store. These were not in the requirements.
  - No Purchase Orders, by design.
- **Printing and PDFs**
  - Receipts print through the browser. There is no direct thermal-printer driver.
  - PDF reports write amounts as `PHP 1,250.00`, because built-in PDF fonts have no ₱ sign.
- **Fonts:** the interface uses the device's system fonts, so no font files are needed.
