<?php
declare(strict_types=1);

/*
 * Migration 002 — MotoSupply 1.3.0
 *  - Inventory integrity: convert tables to InnoDB (transactions), make products.stock_qty
 *    UNSIGNED so the database itself rejects negative stock (only when no negative rows exist;
 *    otherwise it is enabled later from Inventory → Integrity after the counts are corrected).
 *  - Roles, role permissions, per-user permission overrides, separate void-approval PIN.
 *  - Audit log, PIN attempt log, email report delivery log, application update history.
 * Every step checks whether it already ran, so a retried migration never fails or duplicates.
 * Nothing is dropped and no business record is modified.
 */

defined('MOTO_ROOT') || exit;

return static function (PDO $pdo): void {
    $now = gmdate('Y-m-d H:i:s');
    $tableExists = static fn (string $t): bool => (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($t)
    )->fetchColumn();
    $columnExists = static fn (string $t, string $c): bool => (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($t)
        . " AND column_name = " . $pdo->quote($c)
    )->fetchColumn();
    $constraintExists = static fn (string $t, string $name): bool => (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($t)
        . " AND constraint_name = " . $pdo->quote($name)
    )->fetchColumn();
    $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    // 1. Transactional storage for every application table.
    foreach (['users', 'settings', 'categories', 'products', 'sales', 'sale_items', 'stock_movements', 'login_attempts', 'schema_migrations'] as $t) {
        $engine = $pdo->query("SELECT engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($t))->fetchColumn();
        if ($engine !== false && strcasecmp((string) $engine, 'InnoDB') !== 0) {
            $pdo->exec("ALTER TABLE `$t` ENGINE=InnoDB");
        }
    }

    // 2. Database-level guard against negative stock.
    $negatives = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE stock_qty < 0')->fetchColumn();
    $type = (string) $pdo->query("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'products' AND column_name = 'stock_qty'")->fetchColumn();
    if ($negatives === 0 && !str_contains(strtolower($type), 'unsigned')) {
        $pdo->exec('ALTER TABLE products MODIFY stock_qty INT UNSIGNED NOT NULL DEFAULT 0');
    }

    // 3. Roles and permissions.
    if (!$tableExists('roles')) {
        $pdo->exec("CREATE TABLE roles (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug        VARCHAR(40)  NOT NULL,
            name        VARCHAR(80)  NOT NULL,
            description VARCHAR(255) NOT NULL DEFAULT '',
            is_system   TINYINT(1)   NOT NULL DEFAULT 0,
            created_at  DATETIME     NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_roles_slug (slug)
        ) $opts");
    }
    if (!$tableExists('role_permissions')) {
        $pdo->exec("CREATE TABLE role_permissions (
            role_id    INT UNSIGNED NOT NULL,
            permission VARCHAR(64)  NOT NULL,
            PRIMARY KEY (role_id, permission),
            CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
        ) $opts");
    }
    if (!$tableExists('user_permissions')) {
        // Per-user overrides on top of the role: allowed = 1 grants, allowed = 0 removes.
        $pdo->exec("CREATE TABLE user_permissions (
            user_id    INT UNSIGNED NOT NULL,
            permission VARCHAR(64)  NOT NULL,
            allowed    TINYINT(1)   NOT NULL,
            PRIMARY KEY (user_id, permission),
            CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) $opts");
    }
    $roles = [
        'administrator' => ['Administrator', 'Full access to every feature, including users, settings and updates.', ['*']],
        'cashier' => ['Cashier', 'Runs the POS and views sales and inventory.', ['dashboard.view', 'pos.access', 'pos.sell', 'sales.view', 'inventory.view']],
        'inventory' => ['Inventory Staff', 'Manages products and stock.', ['dashboard.view', 'inventory.view', 'products.manage', 'products.import', 'inventory.adjust', 'inventory.movements', 'reports.view']],
        'reports' => ['Reports Viewer', 'Views and exports reports.', ['dashboard.view', 'sales.view', 'reports.view', 'reports.export']],
        'custom' => ['Custom Role', 'Starts with no permissions; grant them per user.', []],
    ];
    $insRole = $pdo->prepare('INSERT INTO roles (slug, name, description, is_system, created_at) VALUES (?, ?, ?, 1, ?)');
    $insPerm = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id, permission) VALUES (?, ?)');
    foreach ($roles as $slug => [$name, $desc, $perms]) {
        $id = $pdo->query('SELECT id FROM roles WHERE slug = ' . $pdo->quote($slug))->fetchColumn();
        if ($id === false) {
            $insRole->execute([$slug, $name, $desc, $now]);
            $id = $pdo->lastInsertId();
            foreach ($perms as $perm) {
                $insPerm->execute([$id, $perm]);
            }
        }
    }

    // 4. Users: role link and separate void-approval PIN (hash only).
    if (!$columnExists('users', 'role_id')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN role_id INT UNSIGNED NULL AFTER role, ADD KEY idx_users_role (role_id)');
    }
    if (!$constraintExists('users', 'fk_users_role')) {
        $pdo->exec('ALTER TABLE users ADD CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id)');
    }
    if (!$columnExists('users', 'void_pin_hash')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN void_pin_hash VARCHAR(255) NULL, ADD COLUMN void_pin_set_at DATETIME NULL');
    }
    // Existing accounts were all administrators in 1.0–1.2.
    $adminRole = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'administrator'")->fetchColumn();
    $pdo->exec("UPDATE users SET role_id = $adminRole, role = 'administrator' WHERE role_id IS NULL");

    // 5. Void approval on sales.
    if (!$columnExists('sales', 'void_approved_by')) {
        $pdo->exec('ALTER TABLE sales ADD COLUMN void_approved_by INT UNSIGNED NULL AFTER voided_by');
    }
    if (!$constraintExists('sales', 'fk_sales_void_approver')) {
        $pdo->exec('ALTER TABLE sales ADD CONSTRAINT fk_sales_void_approver FOREIGN KEY (void_approved_by) REFERENCES users (id)');
    }

    // 6. Audit log (append-only from the application's point of view).
    if (!$tableExists('audit_log')) {
        $pdo->exec("CREATE TABLE audit_log (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     INT UNSIGNED NULL,
            username    VARCHAR(50)  NOT NULL DEFAULT '',
            action      VARCHAR(64)  NOT NULL,
            entity_type VARCHAR(32)  NOT NULL DEFAULT '',
            entity_id   VARCHAR(64)  NOT NULL DEFAULT '',
            status      VARCHAR(16)  NOT NULL DEFAULT 'success',
            details     TEXT         NULL,
            ip_address  VARCHAR(45)  NOT NULL DEFAULT '',
            created_at  DATETIME     NOT NULL,
            PRIMARY KEY (id),
            KEY idx_audit_created (created_at),
            KEY idx_audit_action (action, created_at),
            KEY idx_audit_user (user_id, created_at),
            KEY idx_audit_entity (entity_type, entity_id)
        ) $opts");
    }

    // 7. Void PIN attempts (rate limiting).
    if (!$tableExists('pin_attempts')) {
        $pdo->exec("CREATE TABLE pin_attempts (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            approver_id  INT UNSIGNED NULL,
            ip_address   VARCHAR(45)  NOT NULL,
            success      TINYINT(1)   NOT NULL DEFAULT 0,
            attempted_at DATETIME     NOT NULL,
            PRIMARY KEY (id),
            KEY idx_pin_approver_time (approver_id, attempted_at),
            KEY idx_pin_ip_time (ip_address, attempted_at)
        ) $opts");
    }

    // 8. Daily email report deliveries: one row per report date prevents duplicate sends.
    if (!$tableExists('email_report_runs')) {
        $pdo->exec("CREATE TABLE email_report_runs (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_date DATE         NOT NULL,
            status      VARCHAR(16)  NOT NULL,
            attempts    INT UNSIGNED NOT NULL DEFAULT 0,
            recipients  VARCHAR(500) NOT NULL DEFAULT '',
            trigger_src VARCHAR(16)  NOT NULL DEFAULT '',
            error       VARCHAR(500) NOT NULL DEFAULT '',
            created_at  DATETIME     NOT NULL,
            updated_at  DATETIME     NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_email_report_date (report_date)
        ) $opts");
    }

    // 9. Application update history.
    if (!$tableExists('update_history')) {
        $pdo->exec("CREATE TABLE update_history (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            from_version VARCHAR(20)  NOT NULL,
            to_version   VARCHAR(20)  NOT NULL,
            status       VARCHAR(16)  NOT NULL,
            backup_dir   VARCHAR(255) NOT NULL DEFAULT '',
            details      TEXT         NULL,
            user_id      INT UNSIGNED NULL,
            created_at   DATETIME     NOT NULL,
            updated_at   DATETIME     NOT NULL,
            PRIMARY KEY (id)
        ) $opts");
    }
};
