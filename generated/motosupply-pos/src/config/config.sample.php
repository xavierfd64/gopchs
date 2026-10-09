<?php
// MotoSupply POS — configuration TEMPLATE (example values only).
// The browser installer creates config/config.php automatically. Only copy this file to
// config/config.php by hand if you are restoring or moving an installation.
defined('MOTO_ROOT') || exit;

return [
    'db' => [
        'host' => 'sqlXXX.infinityfree.com', // from your hosting control panel
        'port' => 3306,
        'name' => 'if0_00000000_motosupply',
        'user' => 'if0_00000000',
        'pass' => 'your-database-password',
    ],
    'app' => [
        'debug' => false,              // never true in production
        'force_https' => false,        // set true once SSL works on your domain
        'session_idle_seconds' => 1800,
        'session_absolute_seconds' => 43200,
        'secret' => 'generate-a-long-random-string',
    ],
    'installed_at' => '',
    'version' => '1.2.0',
];
