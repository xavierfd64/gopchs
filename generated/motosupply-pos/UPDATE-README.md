# Updating MotoSupply POS with MotoSupply-POS-Update.zip

This package updates an existing MotoSupply installation to version 1.4.0.

**It contains:**
- application code only: `app/`, `assets/`, `database/migrations/`, `index.php`, and the security `.htaccess` files;
- a signed list of files and checksums (`motosupply-update.json`, `motosupply-update.sig`).

**It does not contain:**
- the installer;
- your configuration or any password;
- `storage/` data, uploads or logs.

**Your data is safe.** The update never resets your administrator account, and never deletes products, sales, users or settings.

## Before you start
1. Back up the database: phpMyAdmin → select the database → **Export** → **Go**. Keep the file on your computer.
2. Download `uploads/` and `config/config.php` (and `motosupply-private/` if it exists) with the File Manager.

## A. Your site runs 1.3 or newer
1. Sign in as an administrator.
2. Go to **Settings → Updates**.
3. Upload this ZIP **without unzipping it**.
4. Check the summary and click **Install update**.

MotoSupply verifies the package, backs up the database and files, installs, and checks the result. If anything fails, it puts the previous files back by itself.

## B. Your site runs 1.0, 1.1 or 1.2 (one-time manual update)
1. Unzip this ZIP on your computer.
2. In your hosting File Manager, upload **everything inside it** into the MotoSupply folder (the one with `index.php`), replacing existing files.
   - With FTP, turn on "show hidden files" so the `.htaccess` files are included.
3. Open your MotoSupply address. On this first visit MotoSupply:
   - backs up the database to `storage/backups/`;
   - adds the new tables and columns. Nothing is deleted.

   If you see an error page right after uploading, wait a minute and reload. Some hosts keep the old program files in a cache for a short time.
   If the page says the backup could not be written, set the `storage` folder permission to 755 and reload. Nothing has been changed in that case.
4. Sign in with your usual account. Existing accounts are now **Administrators**.
5. Then:
   - **My Account → Void approval PIN:** needed to approve voids.
   - **Inventory → Integrity check:** reviews stock history from before this version.
   - **Users:** create accounts for cashiers and staff.
6. Optional: delete `motosupply-update.json`, `motosupply-update.sig`, `UPDATE-README.md` and `CHANGELOG.md` from the website folder. The web server already blocks them.

## If something goes wrong
- **Files:** **Settings → Updates → Restore previous files**, or unzip `storage/backups/update-…/files.zip` over the website folder.
- **Database:** phpMyAdmin → **Import** your exported `.sql` file, or one of the `storage/backups/*.sql.gz` files. This replaces the data with the backup's data.
- The previous version keeps working with the updated database (the update only adds tables and columns).

See `CHANGELOG.md` for everything that changed.
