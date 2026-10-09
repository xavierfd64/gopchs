<?php defined('MOTO_ROOT') || exit; ?>
<h1>MotoSupply POS &amp; Inventory System</h1>
<p class="lead">Welcome! This wizard sets up your point-of-sale and inventory system in a few minutes. You will not need to edit any files or import anything by hand.</p>
<h2>Before you start</h2>
<ul class="checklist">
  <li><strong>A MySQL database</strong>: create one in your hosting control panel (InfinityFree: <em>MySQL Databases</em>; cPanel: <em>MySQL® Databases</em>).</li>
  <li><strong>The database details</strong>: host, database name, username and password, as shown in your hosting control panel.</li>
  <li><strong>A strong administrator password</strong>: at least 8 characters with a mix of letters, numbers and symbols.</li>
</ul>
<h2>Server requirements</h2>
<p class="muted">PHP 8.1 or newer (8.3 and 8.4 tested) with PDO MySQL, a MySQL or MariaDB database, and Apache hosting with .htaccess support. HTTPS (a free SSL certificate) is needed for real use. The next step checks all of this and fixes folder permissions automatically where possible.</p>
<div class="wizard-actions">
  <a class="btn btn-primary" href="<?= e(wizard_url('requirements')) ?>">Install</a>
</div>
