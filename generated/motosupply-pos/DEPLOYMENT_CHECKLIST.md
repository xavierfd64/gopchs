# Deployment checklist — MotoSupply POS

Tick each item on the live site. Record failures, with the exact error message, before trying workarounds.

## Before upload
- [ ] MySQL database created in the hosting panel
- [ ] Database host, name, username and password written down (InfinityFree host is `sqlXXX.infinityfree.com`)
- [ ] Latest `motosupply-pos-<version>.zip` downloaded

## Upload
- [ ] ZIP extracted into the document root (`htdocs/` on InfinityFree, `public_html/` on cPanel) or a subfolder
- [ ] Hidden `.htaccess` files present in the root, `app/`, `config/`, `database/`, `storage/` and `uploads/`
- [ ] Host placeholder page (`index2.html`, `default.php`) removed

## Installer (`/install/`)
- [ ] PHP version shows OK. Note the actual version: ________
- [ ] PDO and PDO MySQL show OK
- [ ] mbstring and fileinfo show OK
- [ ] `config/`, `storage/`, `storage/logs/` and `uploads/products/` show "Writable"
- [ ] Database connection succeeds with the provider's credentials
- [ ] "Installation complete" shown
- [ ] Revisiting `/install/` shows "Already installed" (HTTP 403)
- [ ] `install/` folder deleted

## Security probes (each should be blocked, 403 or 404)
- [ ] `/config/config.php`
- [ ] `/database/migrations/001_initial_schema.sql`
- [ ] `/storage/logs/`
- [ ] `/app/bootstrap.php`
- [ ] `/README.md`
- [ ] `/index.php?r=api.products.search&q=a` while logged out returns 401
- [ ] HTTPS works. With `force_https` enabled, `http://` redirects to `https://`
- [ ] In the browser dev tools, the `MOTOSESS` cookie shows `HttpOnly`, `Secure` (on HTTPS) and `SameSite=Lax`

## Functional smoke test
- [ ] Log in. If the temporary `admin`/`admin` was used, the forced password change appears and works
- [ ] Wrong password shows a generic error
- [ ] **Settings:** shop name, address, phone, timezone `Asia/Manila` and currency PHP saved
- [ ] **Settings → System Check:** all items OK (HTTPS OK once SSL is active)
- [ ] Add a product with SKU and barcode; a duplicate SKU is rejected
- [ ] Upload a product image (optional feature); the image displays
- [ ] **Adjust stock:** the movement shows in Stock history with reason and user
- [ ] **POS:** search by name, SKU and barcode works; a USB scanner (or typing a code and pressing Enter) adds the item
- [ ] **POS:** an unknown barcode shows "No product found"
- [ ] **POS:** cash payment with insufficient amount is rejected; a correct payment completes and shows the change
- [ ] Receipt prints from the browser with shop name, transaction number, items, totals, tendered and change
- [ ] Stock decreased by the sold quantity
- [ ] **Sales History:** the sale is listed; detail view works; void with reason and password returns the stock
- [ ] **Dashboard:** shows today's sales and the recent transaction
- [ ] **Reports:** Daily, Weekly and Monthly sales, Inventory valuation and Stock movements display
- [ ] CSV download opens in a spreadsheet; PDF download opens and prints
- [ ] Log out, then press Back: protected pages redirect to login

## After go-live
- [ ] Temporary/test products and sales removed, or the database reinstalled clean before real use
- [ ] First database backup exported with phpMyAdmin and stored off-site
- [ ] Backup schedule agreed (for example daily export)
