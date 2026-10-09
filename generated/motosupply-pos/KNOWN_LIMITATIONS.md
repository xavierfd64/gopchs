# Known limitations — MotoSupply POS 1.0.0

## Not deployed or verified on InfinityFree yet
- The system was tested locally on **PHP 8.3.6** with **MariaDB 10.11**, under PHP's built-in server and under **Apache 2.4 with mod_php** (in a subfolder, over HTTP and HTTPS).
- **It has not been installed on an InfinityFree account.** No access to the account was available. Run `DEPLOYMENT_CHECKLIST.md` on the live test site, and treat compatibility as unverified until that is done.
- **InfinityFree browser check:** InfinityFree serves a JavaScript "security check" to new visitors. Normal browsers pass it automatically. Scripts, uptime monitors and API clients that are not browsers may be blocked. The POS calls its API from the same browser page, so it is not affected.
- **Free hosting performance:** InfinityFree limits CPU, entry processes and daily hits on free accounts. Very large exports, such as a full-year transaction CSV, may be slower or hit those limits.

## Features intentionally not included
- No Purchase Orders or supplier purchasing workflow, as specified.
- **Cash payments only.** No card, GCash or Maya integration, and no payment gateway.
- **One administrator account.** There are no cashier accounts or role management. The database has a `role` column for future use.
- **Voids only, no partial returns.** A sale is voided as a whole: stock is returned, and the record is kept and marked "Voided". Item-level returns and refunds are not implemented.
- No customer records, held/parked sales, tax/VAT calculation, product CSV import, or multi-store/location support. These appear in the Figma mockups but were not in the requirements.
- **No password reset by email.** PHP `mail()` is unreliable on free hosting. Reset through phpMyAdmin or a backup (see README).

## Technical notes
- **Receipts** are printed with the browser's print dialog. There is no native ESC/POS thermal printer integration. The receipt layout fits 80 mm and standard paper.
- **PDF reports** use the built-in PDF fonts, which have no peso sign. Amounts in PDFs are written as `PHP 1,250.00`. Screens, receipts and CSV use `₱` (CSV holds plain numbers with a `(PHP)` column header).
- **Report grouping:** sales are grouped into local days using the shop timezone's UTC offset at the start of the range. This is exact for Asia/Manila, which has no daylight saving. For zones with daylight saving, a few sales near midnight on a switch-over day may land in the adjacent day.
- **Report size limits:** on-screen report tables show 50 rows per page. Exports include every row up to 20,000, and the date range is capped at 366 days per report.
- **Login throttling:** 5 failed attempts per username, or 20 per IP address, lock logins for 15 minutes. Shops whose devices share one public IP share the IP counter.
- **Directory listings:** the root `.htaccess` does not use `Options -Indexes`, because some hosts reject it with a 500 error. Public folders contain empty `index.html` files, and private folders deny all access.
- **Installing in a folder** named `app`, `config`, `database` or `storage` is not supported, because the root `.htaccess` blocks those path names.
- **Design:** the UI follows the Figma Make screenshots provided (Dashboard, POS, Inventory, Sales History, Reports, Settings). The Figma source files were not accessible from the build environment, so spacing and colours were matched by eye.
