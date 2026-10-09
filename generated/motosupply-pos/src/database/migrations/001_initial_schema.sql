-- MotoSupply POS & Inventory System — database schema (version 1)
-- Compatible with MySQL 5.7+/8.x and MariaDB 10.3+.
-- All DATETIME columns are stored in UTC. The application converts them to the
-- configured shop timezone (default Asia/Manila) for display and reporting.
-- Money columns use DECIMAL(12,2); never floating point.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username             VARCHAR(50)  NOT NULL,
    password_hash        VARCHAR(255) NOT NULL,
    full_name            VARCHAR(100) NOT NULL DEFAULT '',
    role                 VARCHAR(20)  NOT NULL DEFAULT 'admin',
    must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
    is_active            TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at        DATETIME     NULL,
    created_at           DATETIME     NOT NULL,
    updated_at           DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    setting_key   VARCHAR(64)  NOT NULL,
    setting_value TEXT         NOT NULL,
    updated_at    DATETIME     NOT NULL,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(80)  NOT NULL,
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    sku                 VARCHAR(64)   NOT NULL,
    barcode             VARCHAR(64)   NULL,
    name                VARCHAR(150)  NOT NULL,
    category_id         INT UNSIGNED  NULL,
    description         TEXT          NULL,
    unit                VARCHAR(20)   NOT NULL DEFAULT 'pc',
    cost_price          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    selling_price       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    stock_qty           INT           NOT NULL DEFAULT 0,
    low_stock_threshold INT UNSIGNED  NULL,
    image_path          VARCHAR(255)  NULL,
    is_active           TINYINT(1)    NOT NULL DEFAULT 1,
    created_at          DATETIME      NOT NULL,
    updated_at          DATETIME      NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_products_sku (sku),
    UNIQUE KEY uq_products_barcode (barcode),
    KEY idx_products_name (name),
    KEY idx_products_category (category_id),
    KEY idx_products_active_stock (is_active, stock_qty),
    CONSTRAINT fk_products_category FOREIGN KEY (category_id)
        REFERENCES categories (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_products_prices CHECK (cost_price >= 0 AND selling_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    transaction_no  VARCHAR(32)   NOT NULL,
    client_token    CHAR(36)      NOT NULL,
    user_id         INT UNSIGNED  NOT NULL,
    item_count      INT UNSIGNED  NOT NULL,
    subtotal        DECIMAL(12,2) NOT NULL,
    discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total           DECIMAL(12,2) NOT NULL,
    amount_tendered DECIMAL(12,2) NOT NULL,
    change_due      DECIMAL(12,2) NOT NULL,
    payment_method  VARCHAR(20)   NOT NULL DEFAULT 'cash',
    status          VARCHAR(20)   NOT NULL DEFAULT 'completed',
    void_reason     VARCHAR(255)  NULL,
    voided_by       INT UNSIGNED  NULL,
    voided_at       DATETIME      NULL,
    created_at      DATETIME      NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_transaction_no (transaction_no),
    UNIQUE KEY uq_sales_client_token (client_token),
    KEY idx_sales_created (created_at),
    KEY idx_sales_status_created (status, created_at),
    CONSTRAINT fk_sales_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_sales_voided_by FOREIGN KEY (voided_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sale lines keep a snapshot of the product name, SKU, unit, price and cost
-- at the time of sale so later product edits never alter history.
CREATE TABLE IF NOT EXISTS sale_items (
    id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    sale_id      INT UNSIGNED  NOT NULL,
    product_id   INT UNSIGNED  NOT NULL,
    product_name VARCHAR(150)  NOT NULL,
    sku          VARCHAR(64)   NOT NULL,
    unit         VARCHAR(20)   NOT NULL,
    quantity     INT UNSIGNED  NOT NULL,
    unit_price   DECIMAL(12,2) NOT NULL,
    unit_cost    DECIMAL(12,2) NOT NULL,
    line_total   DECIMAL(12,2) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_sale_items_sale (sale_id),
    KEY idx_sale_items_product (product_id),
    CONSTRAINT fk_sale_items_sale FOREIGN KEY (sale_id) REFERENCES sales (id),
    CONSTRAINT fk_sale_items_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every change to products.stock_qty writes one row here.
-- movement_type: initial | adjustment | sale | void
CREATE TABLE IF NOT EXISTS stock_movements (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id    INT UNSIGNED NOT NULL,
    user_id       INT UNSIGNED NOT NULL,
    movement_type VARCHAR(20)  NOT NULL,
    qty_before    INT          NOT NULL,
    qty_change    INT          NOT NULL,
    qty_after     INT          NOT NULL,
    reason        VARCHAR(255) NOT NULL DEFAULT '',
    sale_id       INT UNSIGNED NULL,
    created_at    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_movements_product_created (product_id, created_at),
    KEY idx_movements_created (created_at),
    KEY idx_movements_sale (sale_id),
    CONSTRAINT fk_movements_product FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT fk_movements_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_movements_sale FOREIGN KEY (sale_id) REFERENCES sales (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username     VARCHAR(50)  NOT NULL,
    ip_address   VARCHAR(45)  NOT NULL,
    success      TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_login_ip_time (ip_address, attempted_at),
    KEY idx_login_user_time (username, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
    version    INT UNSIGNED NOT NULL,
    applied_at DATETIME     NOT NULL,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
