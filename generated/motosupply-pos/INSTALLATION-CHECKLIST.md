# Installation checklist — MotoSupply POS

Tick each item on your live site. If something fails, write down the exact message before trying fixes.

## 1. Prepare
- [ ] MySQL database created in the hosting panel (cPanel: user added with **ALL PRIVILEGES**)
- [ ] Host, database name, username and password written down (InfinityFree host: `sqlXXX.infinityfree.com`)
- [ ] `MotoSupply-POS-Installer.zip` uploaded to `htdocs/` or `public_html/` (or a subfolder) and **extracted**
- [ ] ZIP file and the host's placeholder page deleted after extracting

## 2. Wizard
- [ ] Opening the website shows the **Welcome** step
- [ ] **Requirements:** no **Failed** items. Note the PHP version shown: ______
- [ ] Every folder row says *Writable (write test passed)*. If not, follow its "What to do" text and click **Recheck Requirements**
- [ ] On `http://`: testing-mode box ticked (test installation only)
- [ ] **Database:** "Test connection" shows *Connection successful*
- [ ] **Shop:** name, timezone (Asia/Manila) and currency (PHP) entered
- [ ] **Administrator:** strong password accepted and saved in a password manager
- [ ] **Install:** "Installation completed successfully" is shown
- [ ] Finished page notes where the configuration was saved: ______ (private folder / protected config folder)
- [ ] Opening `/install/` again shows **"MotoSupply is already installed"**
- [ ] Optional: `install/` folder deleted

## 3. Security checks (each should be blocked: 403 or 404)
- [ ] `https://yoursite/config/config.php`
- [ ] `https://yoursite/storage/installed.lock`
- [ ] `https://yoursite/install/lib/Installer.php`
- [ ] `https://yoursite/database/migrations/001_initial_schema.sql`
- [ ] `https://yoursite/README.md`
- [ ] Free SSL certificate installed; site opens with `https://` (padlock)
- [ ] **Settings → System Check → Require HTTPS (production)** turned on; `http://` now redirects to `https://`
- [ ] Yellow "Not secure" bar no longer appears
- [ ] **Settings → System Check:** all items Passed (HTTPS too, once SSL is active)

## 4. First-use test
- [ ] Log in, log out, log back in
- [ ] **Settings:** address, phone and receipt footer saved
- [ ] **Inventory:** add 2 products (one with a barcode); a duplicate SKU is rejected
- [ ] Upload a product image; it shows in the product list
- [ ] **Adjust stock:** the change appears in Stock history
- [ ] **POS:** find a product by name, by SKU and by barcode (scanner or type + Enter)
- [ ] **POS:** complete a cash sale; change is correct; receipt prints
- [ ] Stock went down by the quantity sold
- [ ] **Sales History:** the sale is listed and opens; a void (test sale) returns the stock
- [ ] **Dashboard:** shows today's sales
- [ ] **Reports:** Daily Sales shows the sale; **Export CSV** and **Download PDF** both open
- [ ] Pages look right on a phone or tablet

## 5. Before real use
- [ ] Running in **production mode** over HTTPS (never enter real passwords over `http://`)
- [ ] Test products and sales removed (or reinstall into a fresh database; see README "Reinstalling")
- [ ] First database backup exported from phpMyAdmin and kept off the server
- [ ] Backup routine agreed (e.g. weekly export plus before every update)
