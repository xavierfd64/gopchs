# MotoSupply POS & Inventory System

A point-of-sale and inventory system for a motorcycle parts shop. It runs on ordinary PHP/MySQL shared hosting such as InfinityFree or a cPanel host.

- **Stack:** PHP 8.3 (works on 8.1+), MySQL 5.7+/8.x or MariaDB 10.3+, PDO, plain HTML/CSS/JavaScript.
- **No Node.js, Composer, Docker, SSH or build step** is needed on the server. Upload the files, then run the browser installer.
- **Modules:** Dashboard, POS (with USB barcode scanners), Products / Inventory, Sales History (with voids), Reports (CSV and PDF), Settings, Logout.

Example values below (hostnames, usernames) are **examples only**. Use the values from your own hosting control panel.

---

## 1. Requirements

| Item | Requirement |
|---|---|
| PHP | 8.1 or newer (8.3 recommended and tested) |
| PHP extensions | `pdo`, `pdo_mysql`, `mbstring`, `fileinfo`, `json` (all standard). `zlib` is optional and makes PDFs smaller. |
| Database | MySQL 5.7+ / 8.x or MariaDB 10.3+, InnoDB, with `CREATE`, `ALTER`, `INDEX`, `REFERENCES`, `SELECT`, `INSERT`, `UPDATE`, `DELETE` privileges |
| Web server | Apache with `.htaccess` support (InfinityFree and cPanel hosts have this) |
| Writable folders | `config/`, `storage/`, `storage/logs/`, `uploads/products/` |

The installer checks all of these on your server and shows the result.

## 2. Installation (InfinityFree or cPanel)

### Step 1: Create a MySQL database
1. Log in to your hosting control panel.
   - **InfinityFree:** Client Area → your account → **Control Panel** → **MySQL Databases**.
   - **cPanel:** **MySQL® Databases** (or the **Database Wizard**).
2. Create a new database, for example `motosupply`.
3. **cPanel only:** create a database user, give it a strong password, and add it to the database with **All Privileges**.

### Step 2: Write down the database details
You need four values. They are shown in your control panel.

| Field | InfinityFree example | cPanel example |
|---|---|---|
| Database host | `sql123.infinityfree.com` (not `localhost`) | `localhost` |
| Database name | `if0_12345678_motosupply` | `cpuser_motosupply` |
| Database username | `if0_12345678` | `cpuser_motouser` |
| Database password | your vPanel/hosting account password (shown in the Client Area) | the password you chose in step 1 |

### Step 3: Upload and extract the ZIP
1. Download `motosupply-pos-1.0.0.zip`.
2. Open the **File Manager** (or connect with FTP, for example FileZilla).
3. Go to the website's document root:
   - **InfinityFree:** `htdocs/`
   - **cPanel:** `public_html/`
   - To install in a subfolder, create one first (for example `htdocs/pos/`). **Do not** name the folder `app`, `config`, `database` or `storage`.
4. Upload the ZIP and use **Extract**. If your File Manager cannot extract ZIPs, unzip on your computer and upload the extracted files and folders with FTP.
5. Delete the default `index2.html` / `default.php` placeholder if your host created one.

After extracting, the document root should contain `index.php`, `.htaccess`, `app/`, `assets/`, `config/`, `database/`, `install/`, `storage/` and `uploads/`.

> **Hidden files:** make sure `.htaccess` files were uploaded. Some FTP clients hide files that start with a dot. In FileZilla, use *Server → Force showing hidden files*.

### Step 4: Run the installer
1. Open `https://your-domain/install/` in your browser, or `https://your-domain/pos/install/` for a subfolder.
2. **Server requirements:** every item should show **OK**. "HTTPS" may show **Check** until SSL is active. That is allowed for testing.
3. **Database:** enter the host, port (`3306`), database name, username and password from Step 2.
4. **Shop:** enter the shop name and timezone (the default is `Asia/Manila`).
5. **Administrator account:** choose a username and a strong password (8+ characters).
   - For a quick **test** install you may use username `admin` and password `admin`. The system then **forces a password change** at first login.
6. Click **Install**. The installer will:
   - validate the connection
   - create the tables
   - create the administrator, with the password stored as a hash
   - write `config/config.php`
   - verify the result
   - lock itself

### Step 5: Remove the installer
Once installation succeeds, the installer is locked and refuses to run again. It never resets existing accounts. Still, **delete the `install/` folder** using the File Manager or FTP.

### Step 6: Log in and test
Open `https://your-domain/` and log in, then work through `DEPLOYMENT_CHECKLIST.md`:
1. Add a product.
2. Make a test sale in the POS.
3. Print the receipt.
4. Check that the stock went down.
5. Open Reports and download the CSV and PDF.
6. Check **Settings → System Check**.

### Step 7: Turn on HTTPS
1. Enable the free SSL certificate in your hosting panel (InfinityFree: **Free SSL Certificates**).
2. Once `https://` works, open `config/config.php` and set `'force_https' => true`.
3. Alternatively, uncomment the HTTPS block in `.htaccess`.

Session cookies get the `Secure` flag automatically on HTTPS. **Use HTTPS in production.**

## 3. Using the system

- **POS:** type a product name or SKU, or scan a barcode, into the search box.
  - USB scanners that type the code and press Enter add the item automatically. You can turn this off in *Settings → POS Preferences*.
  - Unknown barcodes show a short message.
  - Shortcuts: **F2** search, **F4** discount, **F8** payment, **Esc** clear search or close a dialog.
- **Payment:** cash only. Enter the amount tendered. The server checks it, calculates the change and saves the sale. Then print the receipt from the browser.
- **Inventory:** add or edit products, archive or restore them, and **Adjust stock**. Every adjustment needs a reason and is logged with the user and the before/after quantities.
- **Sales History:**
  - Search by transaction number or product, and filter by date and status.
  - Open a sale to reprint its receipt.
  - **Void** a sale with a reason and your password. The record is kept, its stock is returned to inventory, and a sale cannot be voided twice.
- **Reports:**
  - Daily, weekly, monthly or custom-range sales; transactions; sales by product; inventory valuation; low stock; out of stock; stock movements.
  - Each report exports to **CSV** or **PDF**.
  - Calculation rules:
    - Gross sales = sum of line totals.
    - Net sales (revenue) = gross sales − discounts.
    - Gross profit = net sales − cost of goods, using each item's cost at the time of sale.
    - Voided sales are excluded and listed separately.

## 4. Backups

Back up regularly, and before every update.

1. **Database:** open **phpMyAdmin** from the control panel, select the database, click **Export → Quick → SQL → Go**, and save the `.sql` file somewhere safe. **Do not** store it inside the website folders.
2. **Files:** download `config/config.php` (it contains the database password, so keep it private) and the `uploads/products/` folder.

**To restore:**
1. Import the `.sql` file into an empty database with phpMyAdmin (**Import**).
2. Upload the application files.
3. Put back `config/config.php` and `uploads/products/`.

## 5. Updating to a new version

1. **Back up** the database and files (section 4).
2. Extract the new ZIP on your computer.
3. Upload everything **except** `install/`, `config/config.php`, `storage/` and `uploads/products/`, replacing the existing files.
4. Log in and open **Settings → System Check**. If it lists pending database updates, click **Apply database updates**.

## 6. Troubleshooting

| Problem | Fix |
|---|---|
| "Could not connect to the database" | Re-check the host (on InfinityFree it is `sqlXXX.infinityfree.com`, not `localhost`), database name, username and password in the control panel. |
| 500 error on every page right after uploading | A host may not allow one of the `.htaccess` directives. Rename the root `.htaccess` to test. If the site then works, remove the `<IfModule mod_headers.c>` block and restore the file. Keep the private-folder `.htaccess` files. |
| Redirect loop after enabling `force_https` | SSL is not active yet. Set `'force_https' => false` in `config/config.php`. |
| "Session expired" on every form | Cookies are blocked, or the site is open on two different hostnames (with and without `www`). Use one address. |
| Locked out after failed logins | Wait 15 minutes. 5 failures per username, or 20 per IP address, lock logins for 15 minutes. |
| Forgot the admin password | Open phpMyAdmin, run `SELECT` on the `users` table, and set `password_hash` to a value produced by PHP `password_hash()` and `must_change_password` to `1`. Or restore from a backup. There is deliberately no public reset page. |

## 7. Security notes

- **Passwords and authentication**
  - Passwords are stored only as `password_hash()` hashes.
  - Login regenerates the session ID.
  - Session cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` over HTTPS.
  - Sessions expire after 30 minutes of inactivity, or 12 hours in total.
  - Logins are throttled.
- **Requests**
  - Every page and API endpoint checks the login on the server.
  - Every state-changing request needs a CSRF token.
  - All SQL with user input uses PDO prepared statements.
  - Output is HTML-escaped, and a strict Content-Security-Policy blocks inline scripts.
- **Private files and uploads**
  - `app/`, `config/`, `database/` and `storage/` deny all web access, both through their own `.htaccess` and through the root `.htaccess`.
  - `config/config.php` also exits immediately if it is requested directly.
  - Uploaded images are checked by content (JPG/PNG/WEBP/GIF, max 2 MB) and saved under random names, and the `uploads/` folder never executes scripts.
- **Errors and diagnostics**
  - Errors show a generic message.
  - Details go to `storage/logs/` (web-denied). Passwords and session IDs are never logged.
  - The diagnostics page (**Settings → System Check**) is for logged-in administrators only. There is no public `phpinfo()`.

## 8. Development

```
src/                    the application (this is what gets uploaded)
  index.php             front controller (routes via ?r=…)
  app/                  Core (DB, auth, sessions, CSRF, money), Services, Controllers, views
  assets/               CSS, JS, images
  database/migrations/  SQL schema/migrations (001_initial_schema.sql can be imported by hand)
  install/              one-time browser installer
tests/                  automated tests (not deployed)
tools/build-release.sh  builds dist/motosupply-pos-<version>.zip
```

Run the tests locally. You need PHP 8.3 and MariaDB/MySQL with a throwaway database.

```bash
php tests/run.php                                   # service tests (uses database motosupply_test)
tests/fresh_install.sh /tmp/motoweb 8080            # install a copy via the browser installer
node tests/e2e.mjs http://127.0.0.1:8080 ./shots    # browser tests (dev only; needs Playwright)
tests/http_security.sh http://127.0.0.1:8080 admin 'YourPassword'
tools/build-release.sh                              # release ZIP
```
