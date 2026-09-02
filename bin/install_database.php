<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

$statements = [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    username VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'staff') NOT NULL DEFAULT 'staff',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY users_username_unique (username),
    KEY users_role_status_index (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_name VARCHAR(180) NOT NULL,
    barcode VARCHAR(80) NOT NULL,
    rate DECIMAL(12,2) UNSIGNED NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY products_barcode_unique (barcode),
    KEY products_status_name_index (status, product_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS sales (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id BIGINT UNSIGNED NOT NULL,
    staff_name VARCHAR(120) NOT NULL,
    customer_name VARCHAR(150) NULL,
    customer_contact VARCHAR(60) NULL,
    customer_address VARCHAR(500) NULL,
    total_amount DECIMAL(14,2) UNSIGNED NOT NULL,
    status ENUM('draft') NOT NULL DEFAULT 'draft',
    sale_date DATE NOT NULL,
    sale_time TIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY sales_staff_date_index (staff_id, sale_date),
    KEY sales_date_index (sale_date),
    CONSTRAINT sales_staff_fk FOREIGN KEY (staff_id) REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS sale_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sale_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NULL,
    product_name VARCHAR(180) NOT NULL,
    barcode VARCHAR(80) NOT NULL,
    rate DECIMAL(12,2) UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    line_total DECIMAL(14,2) UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY sale_items_sale_index (sale_id),
    KEY sale_items_product_index (product_id),
    CONSTRAINT sale_items_sale_fk FOREIGN KEY (sale_id) REFERENCES sales (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT sale_items_product_fk FOREIGN KEY (product_id) REFERENCES products (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];

try {
    $pdo = database();
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
    fwrite(STDOUT, "Database tables installed successfully.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Database installation failed: " . $exception->getMessage() . "\n");
    exit(1);
}
