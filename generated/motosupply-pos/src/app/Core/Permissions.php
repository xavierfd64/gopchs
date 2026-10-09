<?php
declare(strict_types=1);

namespace App\Core;

/** Catalog of permissions. Every route and state-changing action checks one of these. */
final class Permissions
{
    /** group => [permission => label] */
    public const CATALOG = [
        'Dashboard & POS' => [
            'dashboard.view' => 'View dashboard',
            'pos.access' => 'Open the POS and search products',
            'pos.sell' => 'Complete sales',
            'pos.discount' => 'Apply discounts',
        ],
        'Sales' => [
            'sales.view' => 'View sales history and receipts',
            'sales.void' => 'Request voids',
            'sales.void.approve' => 'Approve voids (with own void PIN)',
        ],
        'Inventory' => [
            'inventory.view' => 'View inventory',
            'products.manage' => 'Add, edit, archive products',
            'products.import' => 'Import products from CSV',
            'inventory.adjust' => 'Restock and adjust stock',
            'inventory.movements' => 'View stock movements and integrity audit',
        ],
        'Reports' => [
            'reports.view' => 'View sales and inventory reports',
            'reports.export' => 'Export PDF and CSV',
        ],
        'Administration' => [
            'users.manage' => 'Manage users and permissions',
            'settings.manage' => 'Change shop settings, branding and theme',
            'settings.notifications' => 'Configure receipt printing and email reports',
            'system.update' => 'Manage application updates and database updates',
            'audit.view' => 'View audit log',
        ],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::CATALOG)));
    }

    public static function exists(string $perm): bool
    {
        return in_array($perm, self::all(), true);
    }

    public static function label(string $perm): string
    {
        foreach (self::CATALOG as $perms) {
            if (isset($perms[$perm])) {
                return $perms[$perm];
            }
        }
        return $perm;
    }
}
