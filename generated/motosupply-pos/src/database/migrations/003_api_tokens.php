<?php
declare(strict_types=1);

/*
 * 1.4.0 — Sign-in tokens for the cashier desktop app (api.php).
 * Only a SHA-256 hash of each token is stored; the token itself exists only in the app's memory.
 * Idempotent and additive: safe to run again, never touches business data.
 */
return static function (PDO $pdo): void {
    $exists = (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'api_tokens'"
    )->fetchColumn();
    if ($exists) {
        return;
    }
    $pdo->exec("CREATE TABLE api_tokens (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id       INT UNSIGNED NOT NULL,
        token_hash    CHAR(64)     NOT NULL,
        device_name   VARCHAR(100) NOT NULL DEFAULT '',
        ip_address    VARCHAR(45)  NOT NULL DEFAULT '',
        created_at    DATETIME     NOT NULL,
        last_used_at  DATETIME     NOT NULL,
        expires_at    DATETIME     NOT NULL,
        revoked_at    DATETIME     NULL,
        window_start  DATETIME     NOT NULL,
        window_count  INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY uq_api_tokens_hash (token_hash),
        KEY idx_api_tokens_user (user_id),
        CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
