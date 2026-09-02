<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

$pdo = database();

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $statement->execute(['t' => $table, 'c' => $column]);
    return (int) $statement->fetchColumn() > 0;
}

function table_exists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $statement->execute(['t' => $table]);
    return (int) $statement->fetchColumn() > 0;
}

$applied = [];

// --- categories -----------------------------------------------------------
if (!table_exists($pdo, 'categories')) {
    $pdo->exec(<<<'SQL'
CREATE TABLE categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY categories_name_unique (name),
    KEY categories_sort_index (status, sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $applied[] = 'created table categories';
}

// --- settings -------------------------------------------------------------
if (!table_exists($pdo, 'settings')) {
    $pdo->exec(<<<'SQL'
CREATE TABLE settings (
    setting_key VARCHAR(60) NOT NULL,
    setting_value VARCHAR(500) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $applied[] = 'created table settings';
}

$defaults = [
    'receipt_width'  => '100',
    'shop_name'      => app_name(),
    'shop_phone'     => '',
    'shop_address'   => '',
    'receipt_footer' => 'Thank you for your business.',
];
$seed = $pdo->prepare('INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (:k, :v)');
foreach ($defaults as $key => $value) {
    $seed->execute(['k' => $key, 'v' => $value]);
}

// --- users: per-staff discount ceiling ------------------------------------
if (!column_exists($pdo, 'users', 'max_discount_percent')) {
    $pdo->exec("ALTER TABLE users ADD COLUMN max_discount_percent DECIMAL(5,2) UNSIGNED NOT NULL DEFAULT 0.00 AFTER role");
    $applied[] = 'users.max_discount_percent';
}

// --- products: category + photo -------------------------------------------
if (!column_exists($pdo, 'products', 'category_id')) {
    $pdo->exec('ALTER TABLE products ADD COLUMN category_id BIGINT UNSIGNED NULL AFTER product_name');
    $pdo->exec('ALTER TABLE products ADD KEY products_category_index (category_id)');
    $pdo->exec('ALTER TABLE products ADD CONSTRAINT products_category_fk FOREIGN KEY (category_id) REFERENCES categories (id) ON UPDATE CASCADE ON DELETE SET NULL');
    $applied[] = 'products.category_id';
}
if (!column_exists($pdo, 'products', 'photo')) {
    $pdo->exec('ALTER TABLE products ADD COLUMN photo VARCHAR(160) NULL AFTER rate');
    $applied[] = 'products.photo';
}

// --- sales: discount + shareable receipt token ----------------------------
if (!column_exists($pdo, 'sales', 'subtotal_amount')) {
    $pdo->exec('ALTER TABLE sales ADD COLUMN subtotal_amount DECIMAL(14,2) UNSIGNED NOT NULL DEFAULT 0.00 AFTER customer_address');
    $pdo->exec("ALTER TABLE sales ADD COLUMN discount_type ENUM('none','percent','amount') NOT NULL DEFAULT 'none' AFTER subtotal_amount");
    $pdo->exec('ALTER TABLE sales ADD COLUMN discount_value DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00 AFTER discount_type');
    $pdo->exec('ALTER TABLE sales ADD COLUMN discount_amount DECIMAL(14,2) UNSIGNED NOT NULL DEFAULT 0.00 AFTER discount_value');
    // Existing rows predate discounts: their gross equals their net.
    $pdo->exec('UPDATE sales SET subtotal_amount = total_amount WHERE subtotal_amount = 0');
    $applied[] = 'sales discount columns (backfilled subtotal from total)';
}
if (!column_exists($pdo, 'sales', 'public_token')) {
    $pdo->exec('ALTER TABLE sales ADD COLUMN public_token CHAR(32) NULL AFTER status');
    $pdo->exec('ALTER TABLE sales ADD UNIQUE KEY sales_public_token_unique (public_token)');
    $applied[] = 'sales.public_token';
}

// Backfill share tokens for sales recorded before this release.
$missing = $pdo->query('SELECT id FROM sales WHERE public_token IS NULL')->fetchAll();
if ($missing) {
    $set = $pdo->prepare('UPDATE sales SET public_token = :token WHERE id = :id');
    foreach ($missing as $row) {
        $set->execute(['token' => bin2hex(random_bytes(16)), 'id' => $row['id']]);
    }
    $applied[] = 'backfilled ' . count($missing) . ' receipt share tokens';
}

// --- customers ------------------------------------------------------------
if (!table_exists($pdo, 'customers')) {
    $pdo->exec(<<<'SQL'
CREATE TABLE customers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    contact VARCHAR(60) NULL,
    address VARCHAR(500) NULL,
    notes VARCHAR(500) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY customers_name_index (name),
    KEY customers_contact_index (contact),
    KEY customers_status_index (status, name),
    CONSTRAINT customers_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $applied[] = 'created table customers';
}

if (!column_exists($pdo, 'sales', 'customer_id')) {
    $pdo->exec('ALTER TABLE sales ADD COLUMN customer_id BIGINT UNSIGNED NULL AFTER staff_name');
    $pdo->exec('ALTER TABLE sales ADD KEY sales_customer_index (customer_id)');
    $pdo->exec('ALTER TABLE sales ADD CONSTRAINT sales_customer_fk FOREIGN KEY (customer_id) REFERENCES customers (id) ON UPDATE CASCADE ON DELETE SET NULL');
    $applied[] = 'sales.customer_id';
}

// Lift the customers already typed into past sales into the new directory, so
// the shop starts with its real repeat buyers instead of an empty list.
$orphans = $pdo->query(
    "SELECT customer_name, MAX(customer_contact) AS contact, MAX(customer_address) AS address, COUNT(*) AS n
     FROM sales
     WHERE customer_id IS NULL AND customer_name IS NOT NULL AND customer_name <> ''
     GROUP BY customer_name"
)->fetchAll();

if ($orphans) {
    $find = $pdo->prepare('SELECT id FROM customers WHERE name = :name LIMIT 1');
    $make = $pdo->prepare('INSERT INTO customers (name, contact, address) VALUES (:name, :contact, :address)');
    $link = $pdo->prepare('UPDATE sales SET customer_id = :cid WHERE customer_name = :name AND customer_id IS NULL');
    $lifted = 0;

    foreach ($orphans as $row) {
        $find->execute(['name' => $row['customer_name']]);
        $customerId = $find->fetchColumn();

        if (!$customerId) {
            $make->execute([
                'name' => $row['customer_name'],
                'contact' => $row['contact'] ?: null,
                'address' => $row['address'] ?: null,
            ]);
            $customerId = (int) $pdo->lastInsertId();
            $lifted++;
        }

        $link->execute(['cid' => $customerId, 'name' => $row['customer_name']]);
    }

    $applied[] = "imported {$lifted} customers from existing sales";
}

// --- product photo storage -------------------------------------------------
$uploads = APP_ROOT . '/uploads/products';
if (!is_dir($uploads) && !mkdir($uploads, 0755, true) && !is_dir($uploads)) {
    fwrite(STDERR, "Could not create {$uploads}\n");
    exit(1);
}
$guard = APP_ROOT . '/uploads/.htaccess';
if (!is_file($guard)) {
    file_put_contents($guard, "php_flag engine off\nOptions -Indexes -ExecCGI\nAddType text/plain .php .phtml .phar\n<FilesMatch \"\\.(?!jpe?g$)[^.]+$\">\n    Require all denied\n</FilesMatch>\n");
    $applied[] = 'uploads/.htaccess guard';
}

fwrite(STDOUT, $applied ? "Applied:\n  - " . implode("\n  - ", $applied) . "\n" : "Already up to date.\n");
