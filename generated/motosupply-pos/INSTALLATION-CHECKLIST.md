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
- [ ] `https://yoursite/storage/backups/` (after the first update or migration)
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
- [ ] Pages look right on a phone and a tablet (portrait and landscape). On the POS, total and Pay stay visible
- [ ] **POS:** tap a quantity, use the number pad, try more than the stock (refused), then Confirm
- [ ] **My Account:** void approval PIN set. **Sales History:** void a test sale with your username and PIN; a wrong PIN is refused
- [ ] **Users:** create a Cashier account. Signed in as the cashier, Settings, Users and Reports are not available
- [ ] **Inventory → Integrity check:** "Issues found" is 0, and the negative-stock guard is **On**
- [ ] **Inventory → Import CSV:** download the template, import 1 test product, check the summary
- [ ] **Settings → Receipt & printing:** paper size chosen; **Test print** works on the shop printer
- [ ] **Settings → Appearance:** logo uploaded (optional); it shows on the sign-in page and receipt
- [ ] **Settings → Email reports** (optional): **Send test email** arrives. A scheduler is set up: cron job, external HTTPS scheduler, or on-visit
- [ ] **Settings → Audit log:** your sign-ins and the test void are listed
- [ ] **Settings → System Check:** zip and sodium rows are OK (needed for in-app updates); Database tables row says InnoDB

## 5. Updating an existing site (1.0–1.2 → 1.3)
- [ ] Database exported with phpMyAdmin, and `uploads/` and `config/` downloaded
- [ ] Contents of `MotoSupply-POS-Update.zip` uploaded over the site (see `UPDATE-README.md`)
- [ ] First visit works; `storage/backups/` contains a `before-migration` file
- [ ] Sign-in works with the old password; products, sales and settings are all there
- [ ] Void PIN set; Integrity check reviewed; staff accounts created

## 6. Before real use
- [ ] Running in **production mode** over HTTPS (never enter real passwords over `http://`)
- [ ] Test products and sales removed (or reinstall into a fresh database; see README "Reinstalling")
- [ ] First database backup exported from phpMyAdmin and kept off the server
- [ ] Backup routine agreed (e.g. weekly export plus before every update)
