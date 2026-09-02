<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/receipt.php';
require __DIR__ . '/app/reports.php';

$page = (string) ($_GET['page'] ?? (current_user() ? (current_user()['role'] === 'admin' ? 'admin-dashboard' : 'sale') : 'login'));
$publicPages = ['login'];

if ($page === 'logout') {
    require_login();
    if (!is_post()) {
        http_response_code(405);
        exit('Method not allowed.');
    }

    try {
        verify_csrf();
    } catch (Throwable $exception) {
        http_response_code(419);
        exit('Your session expired. Please refresh and try again.');
    }

    logout_user();
    header('Location: index.php?page=login');
    exit;
}

if ($page === 'login') {
    if (current_user()) {
        redirect(current_user()['role'] === 'admin' ? 'admin-dashboard' : 'sale');
    }
    $loginError = '';
    if (is_post()) {
        try {
            if (login_user((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''))) {
                $user = current_user();
                redirect($user['role'] === 'admin' ? 'admin-dashboard' : 'sale');
            }
            usleep(250000);
            $loginError = 'Username or password is incorrect.';
        } catch (RuntimeException $exception) {
            $loginError = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Login failed: ' . $exception->getMessage());
            $loginError = 'Unable to sign in right now. Please try again.';
        }
    }
    render_login($loginError);
    exit;
}

if ($page === 'receipt' && isset($_GET['t'])) {
    render_public_receipt((string) $_GET['t']);
    exit;
}

$user = require_login();

handle_shared_actions($page, $user);

if ($user['role'] === 'admin') {
    handle_admin_actions($page);
    $allowed = [
        'admin-dashboard', 'products', 'product-form', 'admin-sales', 'staff', 'staff-form',
        'sale-detail', 'receipt', 'categories', 'category-form', 'customers', 'customer-form',
        'reports', 'shop-settings', 'account',
    ];
    if (!in_array($page, $allowed, true)) {
        redirect('admin-dashboard');
    }
} else {
    $allowed = ['sale', 'my-sales', 'summary', 'sale-detail', 'receipt', 'customers', 'customer-form'];
    if (!in_array($page, $allowed, true)) {
        redirect('sale');
    }
}

switch ($page) {
    case 'sale':
        render_sale_page($user);
        break;
    case 'my-sales':
        render_my_sales($user);
        break;
    case 'summary':
        render_staff_summary($user);
        break;
    case 'sale-detail':
        render_sale_detail($user);
        break;
    case 'admin-dashboard':
        render_admin_dashboard($user);
        break;
    case 'products':
        render_products($user);
        break;
    case 'product-form':
        render_product_form($user);
        break;
    case 'admin-sales':
        render_admin_sales($user);
        break;
    case 'staff':
        render_staff($user);
        break;
    case 'staff-form':
        render_staff_form($user);
        break;
    case 'receipt':
        render_receipt_page($user);
        break;
    case 'categories':
        render_categories($user);
        break;
    case 'category-form':
        render_category_form($user);
        break;
    case 'customers':
        render_customers($user);
        break;
    case 'customer-form':
        render_customer_form($user);
        break;
    case 'reports':
        render_reports($user);
        break;
    case 'shop-settings':
        render_shop_settings($user);
        break;
    case 'account':
        render_account($user);
        break;
}

/**
 * Saving a customer is open to both roles: staff meet new buyers at the
 * counter, admins tidy the directory afterwards.
 */
function handle_shared_actions(string $page, array $user): void
{
    if ($page !== 'customer-save') {
        return;
    }

    if (!is_post()) {
        http_response_code(405);
        exit('Method not allowed.');
    }

    try {
        verify_csrf();

        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $name = trim((string) ($_POST['name'] ?? ''));
        $contact = trim((string) ($_POST['contact'] ?? '')) ?: null;
        $address = trim((string) ($_POST['address'] ?? '')) ?: null;
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if ($name === '' || strlen($name) > 150) {
            throw new RuntimeException('Enter the customer name.');
        }

        if ($id) {
            // Only an admin may rename or deactivate an existing customer.
            if ($user['role'] !== 'admin') {
                http_response_code(403);
                exit('Access denied.');
            }

            $statement = database()->prepare(
                'UPDATE customers SET name=:name, contact=:contact, address=:address, status=:status WHERE id=:id'
            );
            $statement->execute(['name' => $name, 'contact' => $contact, 'address' => $address, 'status' => $status, 'id' => $id]);
        } else {
            $statement = database()->prepare(
                'INSERT INTO customers (name, contact, address, status, created_by) VALUES (:name, :contact, :address, :status, :created_by)'
            );
            $statement->execute(['name' => $name, 'contact' => $contact, 'address' => $address, 'status' => $status, 'created_by' => $user['id']]);
        }

        set_flash('success', $id ? 'Customer updated.' : 'Customer saved.');
    } catch (Throwable $exception) {
        set_flash('error', $exception->getMessage());
    }

    redirect('customers');
}

function handle_admin_actions(string $page): void
{
    $actions = [
        'product-save', 'product-status', 'product-delete', 'staff-save', 'staff-status',
        'category-save', 'category-status', 'category-delete', 'customer-status', 'settings-save',
        'account-save', 'sale-delete',
    ];
    if (!in_array($page, $actions, true)) {
        return;
    }
    require_role('admin');
    if (!is_post()) {
        http_response_code(405);
        exit('Method not allowed.');
    }

    try {
        verify_csrf();
        if ($page === 'product-save') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $name = trim((string) ($_POST['product_name'] ?? ''));
            $barcode = trim((string) ($_POST['barcode'] ?? ''));
            $rate = filter_var($_POST['rate'] ?? null, FILTER_VALIDATE_FLOAT);
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
            // The photo itself was already cropped, compressed and stored by
            // api/product_photo.php; the form only carries the resulting name.
            $photo = trim((string) ($_POST['photo'] ?? ''));
            $photo = preg_match('/^[a-f0-9]{24}\.jpg$/', $photo) === 1 ? $photo : null;
            if ($name === '' || strlen($name) > 180 || $barcode === '' || strlen($barcode) > 80 || $rate === false || $rate <= 0 || $rate > 99999999) {
                throw new RuntimeException('Enter a valid product name, barcode, and sale rate.');
            }
            if ($categoryId) {
                $check = database()->prepare('SELECT id FROM categories WHERE id = :id LIMIT 1');
                $check->execute(['id' => $categoryId]);
                if (!$check->fetchColumn()) {
                    throw new RuntimeException('Choose a category from the list.');
                }
            }
            if ($id) {
                $statement = database()->prepare(
                    'UPDATE products SET product_name=:name, barcode=:barcode, rate=:rate, status=:status, category_id=:category_id, photo=:photo WHERE id=:id'
                );
                $statement->execute(['name' => $name, 'barcode' => $barcode, 'rate' => $rate, 'status' => $status, 'category_id' => $categoryId, 'photo' => $photo, 'id' => $id]);
            } else {
                $statement = database()->prepare(
                    'INSERT INTO products (product_name, barcode, rate, status, category_id, photo) VALUES (:name, :barcode, :rate, :status, :category_id, :photo)'
                );
                $statement->execute(['name' => $name, 'barcode' => $barcode, 'rate' => $rate, 'status' => $status, 'category_id' => $categoryId, 'photo' => $photo]);
            }
            set_flash('success', $id ? 'Product updated.' : 'Product added.');
            redirect('products');
        }

        if ($page === 'product-status') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $status = ($_POST['status'] ?? '') === 'active' ? 'active' : 'inactive';
            $statement = database()->prepare('UPDATE products SET status=:status WHERE id=:id');
            $statement->execute(['status' => $status, 'id' => $id]);
            set_flash('success', $status === 'active' ? 'Product activated.' : 'Product deactivated.');
            redirect('products');
        }

        if ($page === 'product-delete') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $statement = database()->prepare('DELETE FROM products WHERE id=:id');
            $statement->execute(['id' => $id]);
            set_flash('success', 'Product deleted. Historical sale item details were preserved.');
            redirect('products');
        }

        if ($page === 'staff-save') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $name = trim((string) ($_POST['name'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            // The ceiling on what this salesman may discount, set by the admin.
            $maxDiscount = filter_var($_POST['max_discount_percent'] ?? 0, FILTER_VALIDATE_FLOAT);
            if ($maxDiscount === false || $maxDiscount < 0 || $maxDiscount > 100) {
                throw new RuntimeException('Discount limit must be between 0 and 100 percent.');
            }
            if ($name === '' || strlen($name) > 120 || $username === '' || strlen($username) > 100 || (!$id && strlen($password) < 8) || ($password !== '' && strlen($password) < 8)) {
                throw new RuntimeException('Enter a name, username/mobile, and a password of at least 8 characters.');
            }
            if ($id) {
                $sql = 'UPDATE users SET name=:name, username=:username, status=:status, max_discount_percent=:max_discount';
                $params = ['name' => $name, 'username' => $username, 'status' => $status, 'max_discount' => $maxDiscount, 'id' => $id];
                if ($password !== '') {
                    $sql .= ', password=:password';
                    $params['password'] = password_hash($password, PASSWORD_DEFAULT);
                }
                $sql .= " WHERE id=:id AND role='staff'";
                database()->prepare($sql)->execute($params);
            } else {
                $statement = database()->prepare(
                    "INSERT INTO users (name, username, password, role, status, max_discount_percent) VALUES (:name, :username, :password, 'staff', :status, :max_discount)"
                );
                $statement->execute([
                    'name' => $name,
                    'username' => $username,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'status' => $status,
                    'max_discount' => $maxDiscount,
                ]);
            }
            set_flash('success', $id ? 'Staff account updated.' : 'Staff account created.');
            redirect('staff');
        }

        if ($page === 'category-save') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $name = trim((string) ($_POST['name'] ?? ''));
            $sortOrder = filter_var($_POST['sort_order'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            if ($name === '' || strlen($name) > 120) {
                throw new RuntimeException('Enter a category name.');
            }
            if ($id) {
                $statement = database()->prepare('UPDATE categories SET name=:name, sort_order=:sort_order, status=:status WHERE id=:id');
                $statement->execute(['name' => $name, 'sort_order' => $sortOrder, 'status' => $status, 'id' => $id]);
            } else {
                $statement = database()->prepare('INSERT INTO categories (name, sort_order, status) VALUES (:name, :sort_order, :status)');
                $statement->execute(['name' => $name, 'sort_order' => $sortOrder, 'status' => $status]);
            }
            set_flash('success', $id ? 'Category updated.' : 'Category added.');
            redirect('categories');
        }

        if ($page === 'category-status') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $status = ($_POST['status'] ?? '') === 'active' ? 'active' : 'inactive';
            $statement = database()->prepare('UPDATE categories SET status=:status WHERE id=:id');
            $statement->execute(['status' => $status, 'id' => $id]);
            set_flash('success', $status === 'active' ? 'Category activated.' : 'Category hidden from staff.');
            redirect('categories');
        }

        if ($page === 'category-delete') {
            // Products keep their history; the foreign key clears their tag.
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $statement = database()->prepare('DELETE FROM categories WHERE id=:id');
            $statement->execute(['id' => $id]);
            set_flash('success', 'Category deleted. Its products are now uncategorised.');
            redirect('categories');
        }

        if ($page === 'customer-status') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $status = ($_POST['status'] ?? '') === 'active' ? 'active' : 'inactive';
            $statement = database()->prepare('UPDATE customers SET status=:status WHERE id=:id');
            $statement->execute(['status' => $status, 'id' => $id]);
            set_flash('success', $status === 'active' ? 'Customer activated.' : 'Customer archived.');
            redirect('customers');
        }

        if ($page === 'sale-delete') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);

            if (!$id) {
                throw new RuntimeException('That sale could not be found.');
            }

            // Read it first so the confirmation message can name what went.
            $lookup = database()->prepare('SELECT id, staff_name, total_amount, sale_date FROM sales WHERE id = :id LIMIT 1');
            $lookup->execute(['id' => $id]);
            $sale = $lookup->fetch();

            if (!$sale) {
                throw new RuntimeException('That sale could not be found.');
            }

            // sale_items are removed by the foreign key's ON DELETE CASCADE.
            $delete = database()->prepare('DELETE FROM sales WHERE id = :id');
            $delete->execute(['id' => $id]);

            set_flash(
                'success',
                'Sale #' . (int) $sale['id'] . ' by ' . $sale['staff_name']
                . ' (' . money($sale['total_amount']) . ') was deleted.'
            );
            redirect('admin-sales');
        }

        if ($page === 'account-save') {
            $admin = require_role('admin');
            $name = trim((string) ($_POST['name'] ?? ''));
            $current = (string) ($_POST['current_password'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            $confirm = (string) ($_POST['password_confirm'] ?? '');

            if ($name === '' || strlen($name) > 120) {
                throw new RuntimeException('Enter your name.');
            }

            // Changing the password requires proving you know the old one, so a
            // walk-up on an unlocked phone cannot lock the owner out.
            $check = database()->prepare('SELECT password FROM users WHERE id = :id LIMIT 1');
            $check->execute(['id' => $admin['id']]);
            $hash = (string) $check->fetchColumn();

            if (!password_verify($current, $hash)) {
                throw new RuntimeException('Your current password is not correct.');
            }

            if ($password !== '' || $confirm !== '') {
                if (strlen($password) < 8) {
                    throw new RuntimeException('The new password must be at least 8 characters.');
                }

                if ($password !== $confirm) {
                    throw new RuntimeException('The two new passwords do not match.');
                }

                $update = database()->prepare('UPDATE users SET name = :name, password = :password WHERE id = :id');
                $update->execute([
                    'name' => $name,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'id' => $admin['id'],
                ]);

                // The signed-in session survives, but every other device is cut
                // off because the cookie is re-issued against the new password.
                session_regenerate_id(true);
                set_auth_cookie((int) $admin['id']);
                unset($_SESSION['user']);

                set_flash('success', 'Password changed. Other devices have been signed out.');
                redirect('account');
            }

            $update = database()->prepare('UPDATE users SET name = :name WHERE id = :id');
            $update->execute(['name' => $name, 'id' => $admin['id']]);
            unset($_SESSION['user']);
            set_flash('success', 'Your name was updated.');
            redirect('account');
        }

        if ($page === 'settings-save') {
            $width = (string) ($_POST['receipt_width'] ?? '100');
            if (!array_key_exists($width, receipt_widths())) {
                throw new RuntimeException('Choose a printer roll width from the list.');
            }
            save_setting('receipt_width', $width);
            save_setting('shop_name', mb_substr(trim((string) ($_POST['shop_name'] ?? '')), 0, 120));
            save_setting('shop_phone', mb_substr(trim((string) ($_POST['shop_phone'] ?? '')), 0, 60));
            save_setting('shop_address', mb_substr(trim((string) ($_POST['shop_address'] ?? '')), 0, 300));
            save_setting('receipt_footer', mb_substr(trim((string) ($_POST['receipt_footer'] ?? '')), 0, 200));
            set_flash('success', 'Receipt settings saved.');
            redirect('shop-settings');
        }

        if ($page === 'staff-status') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $status = ($_POST['status'] ?? '') === 'active' ? 'active' : 'inactive';
            $statement = database()->prepare("UPDATE users SET status=:status WHERE id=:id AND role='staff'");
            $statement->execute(['status' => $status, 'id' => $id]);
            set_flash('success', $status === 'active' ? 'Staff account activated.' : 'Staff account deactivated.');
            redirect('staff');
        }
    } catch (PDOException $exception) {
        if ((string) $exception->getCode() === '23000') {
            set_flash('error', 'That barcode or username/mobile already exists.');
        } else {
            error_log($exception->getMessage());
            set_flash('error', 'The record could not be saved.');
        }
    } catch (Throwable $exception) {
        set_flash('error', $exception->getMessage());
    }

    if (str_starts_with($page, 'product')) {
        redirect('products');
    }

    if (str_starts_with($page, 'category')) {
        redirect('categories');
    }

    if (str_starts_with($page, 'customer')) {
        redirect('customers');
    }

    if (str_starts_with($page, 'sale')) {
        redirect('admin-sales');
    }

    if (str_starts_with($page, 'settings')) {
        redirect('shop-settings');
    }

    if (str_starts_with($page, 'account')) {
        redirect('account');
    }

    redirect('staff');
}

function render_login(string $error): void
{
    $expired = isset($_GET['expired']);
    ?><!doctype html>
    <html lang="en"><head>
        <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
        <meta name="theme-color" content="#0b7656"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="ZAS Sales">
        <title><?= e(app_name()) ?> — Login</title>
        <link rel="manifest" href="<?= e(asset('manifest.json')) ?>"><link rel="apple-touch-icon" href="icons/icon-192.png"><link rel="apple-touch-icon" sizes="512x512" href="icons/icon-512.png">
        <link rel="stylesheet" href="<?= e(asset('assets/app.css')) ?>">
    </head><body class="login-body">
    <main class="login-page">
        <div class="brand-mark" aria-hidden="true"><span>|||||</span></div>
        <p class="eyebrow">FAST SALES RECORDING</p>
        <h1><?= e(app_name()) ?></h1>
        <p class="muted center">Sign in and start recording sales.</p>
        <?php if ($error || $expired): ?><div class="alert error"><?= e($error ?: 'Your session expired. Please sign in again.') ?></div><?php endif; ?>
        <form method="post" class="form-card login-form" autocomplete="on">
            <?= csrf_field() ?>
            <label>Username / Mobile<input name="username" autocomplete="username" required autofocus placeholder="Enter username or mobile"></label>
            <label>Password<input type="password" name="password" autocomplete="current-password" required placeholder="Enter password"></label>
            <button class="button primary full" type="submit">LOGIN</button>
        </form>
        <?= install_banner() ?>
        <p class="secure-note">Secure staff access</p>
    </main><script src="<?= e(asset('assets/app.js')) ?>" defer></script>
    <script src="<?= e(asset('assets/install.js')) ?>" defer></script></body></html><?php
}

/**
 * Prompts for installation to the home screen. Android supplies a real
 * install prompt; iOS Safari has none, so it gets the Share-sheet wording.
 */
function install_banner(): string
{
    return '<div class="install-banner hidden" id="install-banner">'
        . '<div class="install-text"><b>Add to your phone</b><span id="install-hint"></span></div>'
        . '<button class="button primary" id="install-go" type="button">INSTALL</button>'
        . '<button class="install-dismiss" id="install-dismiss" type="button" aria-label="Not now">×</button>'
        . '</div>';
}

function layout_start(string $title, array $user, string $page, string $class = ''): void
{
    $flash = pull_flash();
    ?><!doctype html>
    <html lang="en"><head>
        <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover">
        <meta name="theme-color" content="#0b7656"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="default"><meta name="apple-mobile-web-app-title" content="ZAS Sales">
        <title><?= e($title) ?> — <?= e(app_name()) ?></title>
        <link rel="manifest" href="<?= e(asset('manifest.json')) ?>"><link rel="apple-touch-icon" href="icons/icon-192.png"><link rel="apple-touch-icon" sizes="512x512" href="icons/icon-512.png"><link rel="stylesheet" href="<?= e(asset('assets/app.css')) ?>">
    </head><body class="<?= e($class) ?>" data-page="<?= e($page) ?>">
    <main class="page-shell <?= $user['role'] === 'admin' ? 'admin-shell' : 'staff-shell' ?>">
    <?= install_banner() ?>
    <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div><?php endif;
}

function layout_end(array $user, string $page, bool $scanner = false, bool $receipt = false): void
{
    ?></main><?= bottom_navigation($user, $page) ?>
    <?php if ($scanner): ?><script src="<?= e(asset('vendor/html5-qrcode.min.js')) ?>" defer></script><?php endif; ?>
    <?php if ($receipt): ?><script src="<?= e(asset('assets/receipt.js')) ?>" defer></script><?php endif; ?>
    <script src="<?= e(asset('assets/install.js')) ?>" defer></script>
    <script src="<?= e(asset('assets/app.js')) ?>" defer></script></body></html><?php
}

function bottom_navigation(array $user, string $page): string
{
    $items = $user['role'] === 'admin'
        ? [
            ['admin-dashboard', '⌂', 'Dashboard'], ['products', '▦', 'Products'],
            ['reports', '▤', 'Reports'], ['staff', '♙', 'Staff'],
        ]
        : [
            ['sale', '＋', 'Sale'], ['my-sales', '▤', 'My Sales'],
            ['customers', '☺', 'Customers'], ['summary', '↗', 'Summary'],
        ];
    ob_start(); ?>
    <nav class="bottom-nav" aria-label="Main navigation">
        <?php foreach ($items as [$route, $icon, $label]): ?>
            <a href="index.php?page=<?= e($route) ?>" class="<?= $page === $route ? 'active' : '' ?>"><span><?= $icon ?></span><?= e($label) ?></a>
        <?php endforeach; ?>
        <form method="post" action="index.php?page=logout">
            <?= csrf_field() ?><button type="submit"><span>↪</span>Logout</button>
        </form>
    </nav><?php
    return (string) ob_get_clean();
}

function page_heading(string $eyebrow, string $title, array $user, string $back = ''): void
{
    ?><header class="page-heading">
        <div class="heading-left">
            <?php if ($back): ?><a class="back-button" href="<?= e($back) ?>" aria-label="Go back">‹</a><?php endif; ?>
            <div><p class="eyebrow"><?= e($eyebrow) ?></p><h1><?= e($title) ?></h1></div>
        </div>
        <span class="avatar"><?= e(initials($user['name'])) ?></span>
    </header><?php
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= strtoupper(substr($part, 0, 1));
    }
    return $letters ?: 'U';
}

function scalar(string $sql, array $params = []): float
{
    $statement = database()->prepare($sql);
    $statement->execute($params);
    return (float) ($statement->fetchColumn() ?: 0);
}

function friendly_date(string $date): string
{
    return (new DateTimeImmutable($date))->format('d M Y');
}

function render_sale_page(array $user): void
{
    layout_start('New Sale', $user, 'sale', 'sale-page');
    ?><section id="sale-app" data-staff-id="<?= (int) $user['id'] ?>" data-product-url="api/product.php" data-sale-url="api/sales.php"
        data-catalog-url="api/catalog.php" data-customer-url="api/customers.php"
        data-max-discount="<?= e(number_format((float) ($user['max_discount_percent'] ?? 0), 2, '.', '')) ?>"
        data-csrf="<?= e(csrf_token()) ?>">
        <?php page_heading('SALES STAFF', 'New Sale', $user); ?>
        <section class="scan-hero">
            <button class="scan-button" id="scan-open" type="button"><span class="barcode-symbol">▥</span><b>SCAN BARCODE</b><small>Point camera at product label</small></button>
            <button class="link-button" id="manual-open" type="button">Enter barcode manually</button>
        </section>

        <section class="catalog" id="catalog" aria-labelledby="catalog-title">
            <div class="section-heading">
                <div><p class="eyebrow muted-label">OR PICK FROM THE SHELF</p><h2 id="catalog-title">All products</h2></div>
            </div>
            <div class="catalog-search"><input id="catalog-search" type="search" autocomplete="off" placeholder="Search product name or barcode" aria-label="Search products"></div>
            <div class="category-tabs" id="category-tabs" role="tablist" aria-label="Product categories"></div>
            <div class="product-grid" id="product-grid"></div>
            <p class="muted small center hidden" id="catalog-empty">No products match.</p>
        </section>
        <section class="cart-section" aria-labelledby="cart-title">
            <div class="section-heading"><div><p class="eyebrow muted-label">CURRENT CART</p><h2 id="cart-title">No products yet</h2></div><button class="danger-link hidden" id="cart-clear" type="button">Clear</button></div>
            <div id="cart-list"><div class="empty-card"><span class="barcode-symbol">▥</span><p>Scan a product to start the sale.</p></div></div>
        </section>
        <div class="sale-dock">
            <div class="total-row"><span>TOTAL SALE</span><strong id="sale-total">Rs. 0</strong></div>
            <button class="button primary full" id="checkout-open" type="button" disabled>PROCEED TO CHECKOUT</button>
        </div>

        <div class="overlay hidden" id="scanner-overlay" role="dialog" aria-modal="true" aria-labelledby="scanner-title">
            <section class="sheet scanner-sheet"><div class="sheet-handle"></div>
                <div class="sheet-heading"><div><p class="eyebrow">ADD PRODUCT</p><h2 id="scanner-title">Scan barcode</h2></div><button class="close-button" id="scanner-close" type="button" aria-label="Close scanner">×</button></div>
                <div id="reader" class="camera-reader"></div>
                <p class="scanner-help" id="scanner-help">Allow camera access, then point at a product barcode.</p>
                <div class="divider"><span>or enter barcode</span></div>
                <form id="manual-barcode-form" class="manual-form"><input id="barcode-input" inputmode="numeric" autocomplete="off" maxlength="80" placeholder="Barcode number" aria-label="Barcode number"><button class="button primary" type="submit">ADD</button></form>
                <button class="button secondary full scanner-next hidden" id="scanner-next" type="button">SCAN NEXT PRODUCT</button>
            </section>
        </div>

        <div class="overlay hidden" id="checkout-overlay" role="dialog" aria-modal="true" aria-labelledby="checkout-title">
            <section class="sheet checkout-sheet"><div class="sheet-handle"></div>
                <div class="sheet-heading"><div><p class="eyebrow">CHECKOUT</p><h2 id="checkout-title">Customer details</h2></div><button class="close-button" id="checkout-close" type="button" aria-label="Close checkout">×</button></div>
                <p class="muted small">All customer fields are optional.</p>
                <div class="customer-search">
                    <label>Find saved customer <span>Type to search</span>
                        <input id="customer-search" type="search" autocomplete="off" placeholder="Search name or number">
                    </label>
                    <div class="customer-results hidden" id="customer-results" role="listbox"></div>
                    <p class="picked-customer hidden" id="picked-customer">
                        <span id="picked-customer-name"></span>
                        <button type="button" id="picked-customer-clear">Change</button>
                    </p>
                </div>
                <label>Customer Name <span>Optional</span><input id="customer-name" autocomplete="name" maxlength="150" placeholder="Enter customer name"></label>
                <label>Contact Number <span>Optional</span><input id="customer-contact" inputmode="tel" autocomplete="tel" maxlength="60" placeholder="03XX XXXXXXX"></label>
                <label>Address <span>Optional</span><textarea id="customer-address" rows="2" maxlength="500" placeholder="Enter address"></textarea></label>
                <label class="checkbox-row" id="save-customer-row"><input type="checkbox" id="save-customer" checked> Save this customer for next time</label>

                <?php $limit = (float) ($user['max_discount_percent'] ?? 0); ?>
                <div class="discount-block" id="discount-block" data-limit="<?= e(number_format($limit, 2, '.', '')) ?>">
                    <div class="discount-head">
                        <span class="field-label">Discount</span>
                        <span class="discount-limit"><?= $limit > 0
                            ? 'Your limit ' . e(rtrim(rtrim(number_format($limit, 2), '0'), '.')) . '%'
                            : 'Not allowed for your account' ?></span>
                    </div>
                    <?php if ($limit > 0): ?>
                    <div class="discount-controls">
                        <div class="discount-toggle" role="group" aria-label="Discount type">
                            <button type="button" class="active" data-discount-type="none">None</button>
                            <button type="button" data-discount-type="percent">%</button>
                            <button type="button" data-discount-type="amount">Rs.</button>
                        </div>
                        <input id="discount-value" type="number" inputmode="decimal" min="0" step="0.5" placeholder="0" disabled aria-label="Discount value">
                    </div>
                    <p class="discount-error hidden" id="discount-error" role="alert"></p>
                    <?php endif; ?>
                </div>

                <div class="checkout-lines">
                    <div><span>Subtotal</span><strong id="checkout-subtotal">Rs. 0</strong></div>
                    <div class="is-discount hidden" id="checkout-discount-row"><span id="checkout-discount-label">Discount</span><strong id="checkout-discount">− Rs. 0</strong></div>
                </div>
                <div class="checkout-total"><span>Total payable</span><strong id="checkout-total">Rs. 0</strong></div>
                <button class="button primary full" id="save-sale" type="button">SAVE AS DRAFT SALE</button>
            </section>
        </div>

        <div class="success-screen hidden" id="sale-success" role="dialog" aria-modal="true">
            <div class="success-mark">✓</div><p class="eyebrow">DRAFT SALE</p><h1>Sale Saved Successfully</h1><p class="muted center" id="success-reference"></p>
            <a class="button primary full" id="success-receipt" href="#">PRINT / SHARE RECEIPT</a>
            <button class="button secondary full" id="new-sale" type="button">NEW SALE</button>
        </div>
        <div class="toast hidden" id="toast" role="status"></div>
    </section><?php
    layout_end($user, 'sale', true);
}

function render_my_sales(array $user): void
{
    layout_start('My Sales', $user, 'my-sales');
    page_heading('SALES STAFF', 'My Sales', $user);
    $statement = database()->prepare('SELECT id, sale_date, sale_time, customer_name, total_amount, status FROM sales WHERE staff_id=:staff_id ORDER BY id DESC LIMIT 100');
    $statement->execute(['staff_id' => $user['id']]);
    $sales = $statement->fetchAll();
    ?><div class="section-heading padded-top"><div><p class="eyebrow muted-label">RECORDED SALES</p><h2><?= count($sales) ?> recent sale<?= count($sales) === 1 ? '' : 's' ?></h2></div></div>
    <section class="record-list">
        <?php if (!$sales): ?><div class="empty-card"><p>No sales recorded yet.</p><a class="button primary" href="index.php?page=sale">NEW SALE</a></div><?php endif; ?>
        <?php foreach ($sales as $sale): ?><a class="record-card" href="index.php?page=sale-detail&id=<?= (int) $sale['id'] ?>">
            <div><span class="status-badge">DRAFT</span><h3>Sale #<?= (int) $sale['id'] ?></h3><p><?= e(friendly_date($sale['sale_date'])) ?> · <?= e(date('h:i A', strtotime($sale['sale_time']))) ?></p></div>
            <div class="record-amount"><strong><?= e(money($sale['total_amount'])) ?></strong><span><?= e($sale['customer_name'] ?: 'Walk-in customer') ?> ›</span></div>
        </a><?php endforeach; ?>
    </section><?php
    layout_end($user, 'my-sales');
}

function render_staff_summary(array $user): void
{
    $today = (new DateTimeImmutable())->format('Y-m-d');
    $month = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
    $params = ['id' => $user['id']];
    $todayTotal = scalar('SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE staff_id=:id AND sale_date=:today', $params + ['today' => $today]);
    $monthTotal = scalar('SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE staff_id=:id AND sale_date>=:month', $params + ['month' => $month]);
    $allTotal = scalar('SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE staff_id=:id', $params);
    $count = scalar('SELECT COUNT(*) FROM sales WHERE staff_id=:id', $params);
    $recent = database()->prepare('SELECT id, sale_date, customer_name, total_amount FROM sales WHERE staff_id=:id ORDER BY id DESC LIMIT 8');
    $recent->execute($params);
    layout_start('Summary', $user, 'summary');
    page_heading('MY PERFORMANCE', 'Sales Summary', $user);
    ?><section class="metric-grid staff-metrics">
        <?= metric_card("Today's Sale", money($todayTotal), 'green') ?>
        <?= metric_card('This Month', money($monthTotal), 'blue') ?>
        <?= metric_card('Total Sale', money($allTotal), 'dark') ?>
        <?= metric_card('Number of Sales', number_format($count), 'sand') ?>
    </section>
    <div class="section-heading padded-top"><div><p class="eyebrow muted-label">RECENT ACTIVITY</p><h2>Latest sales</h2></div><a class="link-button" href="index.php?page=my-sales">View all</a></div>
    <section class="compact-list">
        <?php foreach ($recent->fetchAll() as $sale): ?><a href="index.php?page=sale-detail&id=<?= (int) $sale['id'] ?>"><div><b>#<?= (int) $sale['id'] ?></b><span><?= e(friendly_date($sale['sale_date'])) ?> · <?= e($sale['customer_name'] ?: 'Walk-in') ?></span></div><strong><?= e(money($sale['total_amount'])) ?> ›</strong></a><?php endforeach; ?>
    </section>
    <section class="signed-in-as">
        <div><span>Signed in as</span><strong><?= e($user['name']) ?></strong></div>
        <form method="post" action="index.php?page=logout">
            <?= csrf_field() ?>
            <button class="button secondary full" type="submit">SIGN OUT</button>
        </form>
    </section><?php
    layout_end($user, 'summary');
}

function render_sale_detail(array $user): void
{
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    $sql = 'SELECT * FROM sales WHERE id=:id';
    $params = ['id' => $id];
    if ($user['role'] === 'staff') {
        $sql .= ' AND staff_id=:staff_id';
        $params['staff_id'] = $user['id'];
    }
    $statement = database()->prepare($sql . ' LIMIT 1');
    $statement->execute($params);
    $sale = $statement->fetch();
    if (!$sale) {
        http_response_code(404);
        set_flash('error', 'Sale not found.');
        redirect($user['role'] === 'admin' ? 'admin-sales' : 'my-sales');
    }
    $items = database()->prepare('SELECT * FROM sale_items WHERE sale_id=:id ORDER BY id');
    $items->execute(['id' => $id]);
    $back = $user['role'] === 'admin' ? 'index.php?page=admin-sales' : 'index.php?page=my-sales';
    layout_start('Sale #' . $id, $user, 'sale-detail');
    page_heading('DRAFT SALE', 'Sale #' . $id, $user, $back);
    ?><section class="detail-summary">
        <div><span>Date & Time</span><strong><?= e(friendly_date($sale['sale_date'])) ?> · <?= e(date('h:i A', strtotime($sale['sale_time']))) ?></strong></div>
        <div><span>Sales Staff</span><strong><?= e($sale['staff_name']) ?></strong></div>
        <?php if ((float) $sale['discount_amount'] > 0): ?>
        <div><span>Subtotal</span><strong><?= e(money($sale['subtotal_amount'])) ?></strong></div>
        <div class="detail-discount"><span><?= e(discount_label($sale)) ?></span><strong>− <?= e(money($sale['discount_amount'])) ?></strong></div>
        <?php endif; ?>
        <div class="detail-total"><span>Total Sale</span><strong><?= e(money($sale['total_amount'])) ?></strong></div>
    </section>
    <a class="button primary full receipt-cta" href="index.php?page=receipt&id=<?= (int) $sale['id'] ?>">PRINT / SHARE RECEIPT</a>
    <?php if ($user['role'] === 'admin'): ?>
    <form class="danger-zone" method="post" action="index.php?page=sale-delete"
          onsubmit="return confirm('Delete sale #<?= (int) $sale['id'] ?> by <?= e(addslashes($sale['staff_name'])) ?> for <?= e(money($sale['total_amount'])) ?>?\n\nThis cannot be undone, and the sale will drop out of every report.')">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $sale['id'] ?>">
        <p>Deleting removes this sale and its items permanently, and every report will change to match.</p>
        <button class="button delete-sale full" type="submit">DELETE THIS SALE</button>
    </form>
    <?php endif; ?>
    <div class="section-heading padded-top"><div><p class="eyebrow muted-label">PRODUCTS</p><h2>Sale items</h2></div></div>
    <section class="item-list">
        <?php foreach ($items->fetchAll() as $item): ?><article><div><h3><?= e($item['product_name']) ?></h3><p><?= e($item['barcode']) ?> · <?= e(money($item['rate'])) ?> × <?= (int) $item['quantity'] ?></p></div><strong><?= e(money($item['line_total'])) ?></strong></article><?php endforeach; ?>
    </section>
    <div class="section-heading padded-top"><div><p class="eyebrow muted-label">CUSTOMER</p><h2>Customer details</h2></div></div>
    <section class="customer-card">
        <div><span>Name</span><strong><?= e($sale['customer_name'] ?: 'Not entered') ?></strong></div>
        <div><span>Contact</span><strong><?= e($sale['customer_contact'] ?: 'Not entered') ?></strong></div>
        <div><span>Address</span><strong><?= nl2br(e($sale['customer_address'] ?: 'Not entered')) ?></strong></div>
    </section><?php
    layout_end($user, 'sale-detail');
}

function metric_card(string $label, string $value, string $tone): string
{
    return '<article class="metric-card ' . e($tone) . '"><span>' . e($label) . '</span><strong>' . e($value) . '</strong></article>';
}

function render_admin_dashboard(array $user): void
{
    $today = (new DateTimeImmutable())->format('Y-m-d');
    $month = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
    $todayTotal = scalar('SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date=:date', ['date' => $today]);
    $monthTotal = scalar('SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date>=:date', ['date' => $month]);
    $allTotal = scalar('SELECT COALESCE(SUM(total_amount),0) FROM sales');
    $staffCount = scalar("SELECT COUNT(*) FROM users WHERE role='staff' AND status='active'");
    $staff = database()->prepare(
        "SELECT u.id,u.name,u.status,
        COALESCE(SUM(CASE WHEN s.sale_date=:today THEN s.total_amount ELSE 0 END),0) today_total,
        COALESCE(SUM(CASE WHEN s.sale_date>=:month THEN s.total_amount ELSE 0 END),0) month_total,
        COALESCE(SUM(s.total_amount),0) total_amount
        FROM users u LEFT JOIN sales s ON s.staff_id=u.id WHERE u.role='staff' GROUP BY u.id,u.name,u.status ORDER BY total_amount DESC,u.name"
    );
    $staff->execute(['today' => $today, 'month' => $month]);
    layout_start('Dashboard', $user, 'admin-dashboard');
    page_heading('ADMIN', 'Sales Dashboard', $user);
    ?><section class="metric-grid admin-metrics">
        <?= metric_card("Today's Total", money($todayTotal), 'green') ?>
        <?= metric_card('This Month', money($monthTotal), 'blue') ?>
        <?= metric_card('All Recorded Sales', money($allTotal), 'dark') ?>
        <?= metric_card('Active Sales Staff', number_format($staffCount), 'sand') ?>
    </section>
    <nav class="admin-shortcuts" aria-label="Admin sections">
        <a href="index.php?page=reports&view=product&preset=month">Reports</a>
        <a href="index.php?page=admin-sales">All Sales</a>
        <a href="index.php?page=categories">Categories</a>
        <a href="index.php?page=customers">Customers</a>
        <a href="index.php?page=shop-settings">Receipt Setup</a>
        <a href="index.php?page=account">My Account</a>
    </nav>
    <div class="section-heading padded-top"><div><p class="eyebrow muted-label">BY SALES STAFF</p><h2>Staff performance</h2></div></div>
    <section class="staff-performance">
        <?php foreach ($staff->fetchAll() as $row): ?><a href="index.php?page=admin-sales&staff_id=<?= (int) $row['id'] ?>&preset=month" class="performance-card">
            <div class="performance-head"><span class="avatar small-avatar"><?= e(initials($row['name'])) ?></span><div><h3><?= e($row['name']) ?></h3><p><?= e(ucfirst($row['status'])) ?> · Tap for sales</p></div><b>›</b></div>
            <div class="performance-values"><div><span>Today</span><strong><?= e(money($row['today_total'])) ?></strong></div><div><span>Month</span><strong><?= e(money($row['month_total'])) ?></strong></div><div><span>Total</span><strong><?= e(money($row['total_amount'])) ?></strong></div></div>
        </a><?php endforeach; ?>
    </section><?php
    layout_end($user, 'admin-dashboard');
}

function render_products(array $user): void
{
    $q = trim((string) ($_GET['q'] ?? ''));
    $sql = 'SELECT * FROM products';
    $params = [];
    if ($q !== '') {
        $sql .= ' WHERE product_name LIKE :name OR barcode LIKE :barcode';
        $params['name'] = '%' . $q . '%';
        $params['barcode'] = '%' . $q . '%';
    }
    $sql .= " ORDER BY status='active' DESC, product_name LIMIT 500";
    $statement = database()->prepare($sql);
    $statement->execute($params);
    $products = $statement->fetchAll();
    layout_start('Products', $user, 'products');
    page_heading('ADMIN', 'Products', $user);
    ?><div class="action-row"><form class="search-form" method="get"><input type="hidden" name="page" value="products"><input name="q" value="<?= e($q) ?>" placeholder="Search name or barcode"><button class="button secondary" type="submit">SEARCH</button></form><a class="button primary" href="index.php?page=product-form">+ ADD</a></div>
    <p class="list-count"><?= count($products) ?> product<?= count($products) === 1 ? '' : 's' ?></p>
    <section class="product-list">
        <?php foreach ($products as $product): ?><article class="management-card <?= $product['status'] === 'inactive' ? 'inactive' : '' ?>">
            <div class="management-main"><div><span class="status-badge <?= e($product['status']) ?>"><?= e(strtoupper($product['status'])) ?></span><h3><?= e($product['product_name']) ?></h3><p>Barcode: <?= e($product['barcode']) ?></p></div><strong><?= e(money($product['rate'])) ?></strong></div>
            <div class="card-actions"><a href="index.php?page=product-form&id=<?= (int) $product['id'] ?>">Edit</a>
                <form method="post" action="index.php?page=product-status"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $product['id'] ?>"><input type="hidden" name="status" value="<?= $product['status'] === 'active' ? 'inactive' : 'active' ?>"><button type="submit"><?= $product['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form>
                <form method="post" action="index.php?page=product-delete" onsubmit="return confirm('Delete this product? Historical sale details will remain.')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $product['id'] ?>"><button class="danger" type="submit">Delete</button></form>
            </div>
        </article><?php endforeach; ?>
    </section><?php
    layout_end($user, 'products');
}

function render_product_form(array $user): void
{
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    $product = ['id' => '', 'product_name' => '', 'barcode' => '', 'rate' => '', 'status' => 'active', 'category_id' => null, 'photo' => null];
    $categories = database()->query("SELECT id, name FROM categories WHERE status='active' ORDER BY sort_order, name")->fetchAll();
    if ($id) {
        $statement = database()->prepare('SELECT * FROM products WHERE id=:id');
        $statement->execute(['id' => $id]);
        $product = $statement->fetch() ?: $product;
    }
    layout_start($id ? 'Edit Product' : 'Add Product', $user, 'products');
    page_heading('PRODUCT MASTER', $id ? 'Edit Product' : 'Add Product', $user, 'index.php?page=products');
    ?><form class="form-card management-form" method="post" action="index.php?page=product-save">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $product['id']) ?>">
        <label>Product Name<input name="product_name" maxlength="180" required value="<?= e($product['product_name']) ?>" placeholder="e.g. Bath Towel White"></label>
        <label>Barcode Number<input name="barcode" maxlength="80" inputmode="numeric" required value="<?= e($product['barcode']) ?>" placeholder="Scan or enter barcode"></label>
        <label>Sale Rate (Rs.)<input name="rate" type="number" inputmode="decimal" min="0.01" max="99999999" step="0.01" required value="<?= e((string) $product['rate']) ?>" placeholder="850"></label>
        <label>Category
            <select name="category_id">
                <option value="">Uncategorised</option>
                <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['id'] ?>" <?= (int) $product['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if (!$categories): ?><p class="muted small"><a href="index.php?page=category-form">Add a category</a> to group this product into a tab.</p><?php endif; ?>

        <div class="photo-field" id="photo-field"
             data-upload-url="api/product_photo.php"
             data-csrf="<?= e(csrf_token()) ?>"
             data-photo="<?= e((string) ($product['photo'] ?? '')) ?>">
            <span class="field-label">Product Photo</span>
            <div class="photo-row">
                <div class="photo-preview" id="photo-preview">
                    <?php if ($product['photo']): ?><img src="<?= e(product_photo_url($product['photo'])) ?>" alt="Product photo"><?php else: ?><span>No photo</span><?php endif; ?>
                </div>
                <div class="photo-controls">
                    <button class="button secondary" id="photo-pick" type="button">Choose photo</button>
                    <button class="button secondary <?= $product['photo'] ? '' : 'hidden' ?>" id="photo-remove" type="button">Remove</button>
                    <p class="muted small" id="photo-note">Cropped to a square and shrunk under 100 KB automatically.</p>
                </div>
            </div>
            <input type="file" id="photo-input" accept="image/*" hidden>
            <input type="hidden" name="photo" id="photo-value" value="<?= e((string) ($product['photo'] ?? '')) ?>">
        </div>

        <label>Status<select name="status"><option value="active" <?= $product['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $product['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></label>
        <button class="button primary full" type="submit">SAVE PRODUCT</button>
    </form><?php
    layout_end($user, 'products');
}

function report_dates(string $preset): array
{
    $today = new DateTimeImmutable('today');
    return match ($preset) {
        'yesterday' => [$today->modify('-1 day'), $today->modify('-1 day')],
        'week' => [$today->modify('monday this week'), $today],
        'month' => [$today->modify('first day of this month'), $today],
        'custom' => valid_custom_dates($today),
        default => [$today, $today],
    };
}

function valid_custom_dates(DateTimeImmutable $fallback): array
{
    $fromText = (string) ($_GET['from'] ?? '');
    $toText = (string) ($_GET['to'] ?? '');
    $from = DateTimeImmutable::createFromFormat('!Y-m-d', $fromText);
    $to = DateTimeImmutable::createFromFormat('!Y-m-d', $toText);
    if (!$from || !$to || $from->format('Y-m-d') !== $fromText || $to->format('Y-m-d') !== $toText || $from > $to) {
        return [$fallback, $fallback];
    }
    return [$from, $to];
}

function render_admin_sales(array $user): void
{
    $preset = in_array($_GET['preset'] ?? 'today', ['today', 'yesterday', 'week', 'month', 'custom'], true) ? (string) $_GET['preset'] : 'today';
    [$from, $to] = report_dates($preset);
    $staffId = filter_var($_GET['staff_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    $where = 'sale_date BETWEEN :from AND :to';
    $params = ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
    if ($staffId) {
        $where .= ' AND staff_id=:staff_id';
        $params['staff_id'] = $staffId;
    }
    $summary = database()->prepare("SELECT staff_id, staff_name, COUNT(*) sale_count, SUM(total_amount) total_amount FROM sales WHERE {$where} GROUP BY staff_id,staff_name ORDER BY total_amount DESC");
    $summary->execute($params);
    $sales = database()->prepare("SELECT id,staff_name,customer_name,sale_date,sale_time,total_amount FROM sales WHERE {$where} ORDER BY sale_date DESC,sale_time DESC,id DESC LIMIT 500");
    $sales->execute($params);
    $staff = database()->query("SELECT id,name FROM users WHERE role='staff' ORDER BY name")->fetchAll();
    $reportTotal = scalar("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE {$where}", $params);
    $reportCount = scalar("SELECT COUNT(*) FROM sales WHERE {$where}", $params);
    layout_start('Sales Report', $user, 'admin-sales');
    page_heading('ADMIN', 'Sales Report', $user);
    ?><div class="preset-tabs">
        <?php foreach (['today'=>'Today','yesterday'=>'Yesterday','week'=>'This Week','month'=>'This Month','custom'=>'Custom'] as $key=>$label): ?><a class="<?= $preset === $key ? 'active' : '' ?>" href="index.php?page=admin-sales&preset=<?= e($key) ?><?= $staffId ? '&staff_id=' . $staffId : '' ?>"><?= e($label) ?></a><?php endforeach; ?>
    </div>
    <form class="report-filter form-card" method="get"><input type="hidden" name="page" value="admin-sales"><input type="hidden" name="preset" value="custom">
        <label>From<input type="date" name="from" value="<?= e($from->format('Y-m-d')) ?>"></label><label>To<input type="date" name="to" value="<?= e($to->format('Y-m-d')) ?>"></label>
        <label>Sales Staff<select name="staff_id"><option value="">All staff</option><?php foreach ($staff as $member): ?><option value="<?= (int) $member['id'] ?>" <?= $staffId === (int) $member['id'] ? 'selected' : '' ?>><?= e($member['name']) ?></option><?php endforeach; ?></select></label>
        <button class="button primary" type="submit">APPLY</button>
    </form>
    <section class="report-total"><div><span><?= e(friendly_date($from->format('Y-m-d'))) ?><?= $from != $to ? ' — ' . e(friendly_date($to->format('Y-m-d'))) : '' ?></span><strong><?= number_format($reportCount) ?> sales</strong></div><b><?= e(money($reportTotal)) ?></b></section>
    <div class="section-heading padded-top"><div><p class="eyebrow muted-label">STAFF SUMMARY</p><h2>Performance</h2></div></div>
    <section class="summary-list"><?php foreach ($summary->fetchAll() as $row): ?><article><div><h3><?= e($row['staff_name']) ?></h3><p><?= (int) $row['sale_count'] ?> sale<?= (int) $row['sale_count'] === 1 ? '' : 's' ?></p></div><strong><?= e(money($row['total_amount'])) ?></strong></article><?php endforeach; ?></section>
    <div class="section-heading padded-top"><div><p class="eyebrow muted-label">TRANSACTIONS</p><h2>Sale details</h2></div></div>
    <section class="record-list"><?php foreach ($sales->fetchAll() as $sale): ?><a class="record-card" href="index.php?page=sale-detail&id=<?= (int) $sale['id'] ?>"><div><span class="status-badge">DRAFT</span><h3>#<?= (int) $sale['id'] ?> · <?= e($sale['staff_name']) ?></h3><p><?= e(friendly_date($sale['sale_date'])) ?> · <?= e($sale['customer_name'] ?: 'Walk-in') ?></p></div><div class="record-amount"><strong><?= e(money($sale['total_amount'])) ?></strong><span>Open ›</span></div></a><?php endforeach; ?></section><?php
    layout_end($user, 'admin-sales');
}

function render_staff(array $user): void
{
    $staff = database()->query("SELECT id,name,username,status,created_at,max_discount_percent FROM users WHERE role='staff' ORDER BY status='active' DESC,name")->fetchAll();
    layout_start('Staff', $user, 'staff');
    page_heading('ADMIN', 'Sales Staff', $user);
    ?><div class="action-row single-action"><p class="list-count"><?= count($staff) ?> staff account<?= count($staff) === 1 ? '' : 's' ?></p><a class="button primary" href="index.php?page=staff-form">+ ADD STAFF</a></div>
    <section class="staff-list">
        <?php foreach ($staff as $member): ?><article class="management-card <?= $member['status'] === 'inactive' ? 'inactive' : '' ?>">
            <div class="staff-card-head"><span class="avatar"><?= e(initials($member['name'])) ?></span><div><span class="status-badge <?= e($member['status']) ?>"><?= e(strtoupper($member['status'])) ?></span><h3><?= e($member['name']) ?></h3><p><?= e($member['username']) ?> · discount limit <?= e(rtrim(rtrim(number_format((float) ($member['max_discount_percent'] ?? 0), 2), '0'), '.')) ?>%</p></div></div>
            <div class="card-actions"><a href="index.php?page=admin-sales&staff_id=<?= (int) $member['id'] ?>&preset=month">View Sales</a><a href="index.php?page=staff-form&id=<?= (int) $member['id'] ?>">Edit</a>
                <form method="post" action="index.php?page=staff-status"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $member['id'] ?>"><input type="hidden" name="status" value="<?= $member['status'] === 'active' ? 'inactive' : 'active' ?>"><button type="submit"><?= $member['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form>
            </div>
        </article><?php endforeach; ?>
    </section><?php
    layout_end($user, 'staff');
}

function render_staff_form(array $user): void
{
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    $member = ['id' => '', 'name' => '', 'username' => '', 'status' => 'active', 'max_discount_percent' => '0.00'];
    if ($id) {
        $statement = database()->prepare("SELECT id,name,username,status,max_discount_percent FROM users WHERE id=:id AND role='staff'");
        $statement->execute(['id' => $id]);
        $member = $statement->fetch() ?: $member;
    }
    layout_start($id ? 'Edit Staff' : 'Add Staff', $user, 'staff');
    page_heading('STAFF ACCOUNT', $id ? 'Edit Staff' : 'Add Sales Staff', $user, 'index.php?page=staff');
    ?><form class="form-card management-form" method="post" action="index.php?page=staff-save">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $member['id']) ?>">
        <label>Staff Name<input name="name" maxlength="120" required value="<?= e($member['name']) ?>" placeholder="Full name"></label>
        <label>Username / Mobile<input name="username" maxlength="100" autocomplete="off" required value="<?= e($member['username']) ?>" placeholder="Login username or mobile"></label>
        <label><?= $id ? 'New Password' : 'Password' ?> <?= $id ? '<span>Leave blank to keep current</span>' : '' ?><input type="password" name="password" minlength="8" autocomplete="new-password" <?= $id ? '' : 'required' ?> placeholder="Minimum 8 characters"></label>
        <label>Maximum Discount <span>Percent of the sale</span>
            <input name="max_discount_percent" type="number" inputmode="decimal" min="0" max="100" step="0.5" value="<?= e(rtrim(rtrim(number_format((float) $member['max_discount_percent'], 2, '.', ''), '0'), '.') ?: '0') ?>">
        </label>
        <p class="muted small">This salesman cannot exceed this percentage, whether they enter a percentage or a rupee amount. Set 0 to block discounts entirely.</p>
        <label>Status<select name="status"><option value="active" <?= $member['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $member['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></label>
        <button class="button primary full" type="submit">SAVE STAFF ACCOUNT</button>
    </form><?php
    layout_end($user, 'staff');
}

function render_categories(array $user): void
{
    $categories = database()->query(
        "SELECT c.*, COUNT(p.id) AS product_count
         FROM categories c
         LEFT JOIN products p ON p.category_id = c.id
         GROUP BY c.id
         ORDER BY c.status='active' DESC, c.sort_order, c.name"
    )->fetchAll();

    layout_start('Categories', $user, 'categories');
    page_heading('PRODUCT MASTER', 'Categories', $user, 'index.php?page=products');
    ?><div class="action-row single-action">
        <p class="list-count"><?= count($categories) ?> categor<?= count($categories) === 1 ? 'y' : 'ies' ?></p>
        <a class="button primary" href="index.php?page=category-form">+ ADD</a>
    </div>
    <p class="muted small padded">Categories become the tabs your salesmen tap on the sale screen.</p>
    <section class="product-list">
        <?php if (!$categories): ?><div class="empty-card"><p>No categories yet. Add one so staff can browse products by tab.</p></div><?php endif; ?>
        <?php foreach ($categories as $category): ?><article class="management-card <?= $category['status'] === 'inactive' ? 'inactive' : '' ?>">
            <div class="management-main">
                <div><span class="status-badge <?= e($category['status']) ?>"><?= e(strtoupper($category['status'])) ?></span>
                <h3><?= e($category['name']) ?></h3>
                <p><?= (int) $category['product_count'] ?> product<?= (int) $category['product_count'] === 1 ? '' : 's' ?> · order <?= (int) $category['sort_order'] ?></p></div>
            </div>
            <div class="card-actions"><a href="index.php?page=category-form&id=<?= (int) $category['id'] ?>">Edit</a>
                <form method="post" action="index.php?page=category-status"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $category['id'] ?>"><input type="hidden" name="status" value="<?= $category['status'] === 'active' ? 'inactive' : 'active' ?>"><button type="submit"><?= $category['status'] === 'active' ? 'Hide' : 'Show' ?></button></form>
                <form method="post" action="index.php?page=category-delete" onsubmit="return confirm('Delete this category? Its products stay, but become uncategorised.')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $category['id'] ?>"><button class="danger" type="submit">Delete</button></form>
            </div>
        </article><?php endforeach; ?>
    </section><?php
    layout_end($user, 'categories');
}

function render_category_form(array $user): void
{
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    $category = ['id' => '', 'name' => '', 'sort_order' => 0, 'status' => 'active'];

    if ($id) {
        $statement = database()->prepare('SELECT * FROM categories WHERE id=:id');
        $statement->execute(['id' => $id]);
        $category = $statement->fetch() ?: $category;
    }

    layout_start($id ? 'Edit Category' : 'Add Category', $user, 'categories');
    page_heading('PRODUCT MASTER', $id ? 'Edit Category' : 'Add Category', $user, 'index.php?page=categories');
    ?><form class="form-card management-form" method="post" action="index.php?page=category-save">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $category['id']) ?>">
        <label>Category Name<input name="name" maxlength="120" required value="<?= e($category['name']) ?>" placeholder="e.g. Towels"></label>
        <label>Tab Order <span>Lower shows first</span><input name="sort_order" type="number" min="0" max="999" value="<?= (int) $category['sort_order'] ?>"></label>
        <label>Status<select name="status"><option value="active" <?= $category['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $category['status'] === 'inactive' ? 'selected' : '' ?>>Hidden</option></select></label>
        <button class="button primary full" type="submit">SAVE CATEGORY</button>
    </form><?php
    layout_end($user, 'categories');
}

function render_customers(array $user): void
{
    $q = trim((string) ($_GET['q'] ?? ''));
    $sql = "SELECT c.*, COUNT(s.id) AS sale_count,
                   COALESCE(SUM(s.total_amount),0) AS lifetime_value,
                   MAX(s.sale_date) AS last_sale
            FROM customers c
            LEFT JOIN sales s ON s.customer_id = c.id
            WHERE 1 = 1";
    $params = [];

    if ($q !== '') {
        $sql .= ' AND (c.name LIKE :name OR c.contact LIKE :contact)';
        $params['name'] = '%' . $q . '%';
        $params['contact'] = '%' . $q . '%';
    }

    if ($user['role'] !== 'admin') {
        $sql .= " AND c.status = 'active'";
    }

    $sql .= " GROUP BY c.id ORDER BY c.status='active' DESC, sale_count DESC, c.name LIMIT 300";
    $statement = database()->prepare($sql);
    $statement->execute($params);
    $customers = $statement->fetchAll();

    layout_start('Customers', $user, 'customers');
    page_heading($user['role'] === 'admin' ? 'ADMIN' : 'SALES STAFF', 'Customers', $user);
    ?><div class="action-row">
        <form class="search-form" method="get"><input type="hidden" name="page" value="customers">
            <input name="q" value="<?= e($q) ?>" placeholder="Search name or contact"><button class="button secondary" type="submit">SEARCH</button></form>
        <a class="button primary" href="index.php?page=customer-form">+ ADD</a>
    </div>
    <p class="list-count"><?= count($customers) ?> customer<?= count($customers) === 1 ? '' : 's' ?></p>
    <section class="product-list">
        <?php if (!$customers): ?><div class="empty-card"><p>No customers saved yet.</p></div><?php endif; ?>
        <?php foreach ($customers as $customer): ?><article class="management-card <?= $customer['status'] === 'inactive' ? 'inactive' : '' ?>">
            <div class="management-main">
                <div><span class="status-badge <?= e($customer['status']) ?>"><?= e(strtoupper($customer['status'])) ?></span>
                <h3><?= e($customer['name']) ?></h3>
                <p><?= e($customer['contact'] ?: 'No contact saved') ?><?= $customer['last_sale'] ? ' · last ' . e(friendly_date($customer['last_sale'])) : '' ?></p></div>
                <strong><?= e(money($customer['lifetime_value'])) ?></strong>
            </div>
            <p class="muted small"><?= (int) $customer['sale_count'] ?> sale<?= (int) $customer['sale_count'] === 1 ? '' : 's' ?><?= $customer['address'] ? ' · ' . e($customer['address']) : '' ?></p>
            <div class="card-actions">
                <?php if ($user['role'] === 'admin'): ?>
                    <a href="index.php?page=reports&view=customer&preset=month">In reports</a>
                    <a href="index.php?page=customer-form&id=<?= (int) $customer['id'] ?>">Edit</a>
                    <form method="post" action="index.php?page=customer-status"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $customer['id'] ?>"><input type="hidden" name="status" value="<?= $customer['status'] === 'active' ? 'inactive' : 'active' ?>"><button type="submit"><?= $customer['status'] === 'active' ? 'Archive' : 'Restore' ?></button></form>
                <?php else: ?>
                    <a href="index.php?page=sale">Start a sale</a>
                <?php endif; ?>
            </div>
        </article><?php endforeach; ?>
    </section><?php
    layout_end($user, 'customers');
}

function render_customer_form(array $user): void
{
    $id = $user['role'] === 'admin' ? filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT) : null;
    $customer = ['id' => '', 'name' => '', 'contact' => '', 'address' => '', 'status' => 'active'];

    if ($id) {
        $statement = database()->prepare('SELECT * FROM customers WHERE id=:id');
        $statement->execute(['id' => $id]);
        $customer = $statement->fetch() ?: $customer;
    }

    layout_start($id ? 'Edit Customer' : 'Add Customer', $user, 'customers');
    page_heading('CUSTOMER', $id ? 'Edit Customer' : 'Add Customer', $user, 'index.php?page=customers');
    ?><form class="form-card management-form" method="post" action="index.php?page=customer-save">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $customer['id']) ?>">
        <label>Customer Name<input name="name" maxlength="150" required value="<?= e($customer['name']) ?>" placeholder="e.g. Serena Hotels"></label>
        <label>Contact Number <span>Optional</span><input name="contact" inputmode="tel" maxlength="60" value="<?= e((string) $customer['contact']) ?>" placeholder="03XX XXXXXXX"></label>
        <label>Address <span>Optional</span><textarea name="address" rows="3" maxlength="500" placeholder="Delivery or billing address"><?= e((string) $customer['address']) ?></textarea></label>
        <?php if ($user['role'] === 'admin'): ?>
        <label>Status<select name="status"><option value="active" <?= $customer['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $customer['status'] === 'inactive' ? 'selected' : '' ?>>Archived</option></select></label>
        <?php endif; ?>
        <button class="button primary full" type="submit">SAVE CUSTOMER</button>
    </form><?php
    layout_end($user, 'customers');
}

function render_shop_settings(array $user): void
{
    $width = (string) receipt_width_mm();
    layout_start('Receipt Settings', $user, 'shop-settings');
    page_heading('ADMIN', 'Receipt Settings', $user, 'index.php?page=admin-dashboard');
    ?><form class="form-card management-form" method="post" action="index.php?page=settings-save">
        <?= csrf_field() ?>
        <label>Printer Roll Width
            <select name="receipt_width">
                <?php foreach (receipt_widths() as $value => $label): ?>
                <option value="<?= e((string) $value) ?>" <?= $width === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <p class="muted small">Receipts print at this width instead of A4. Change it if you swap printers.</p>
        <label>Shop Name on Receipt<input name="shop_name" maxlength="120" value="<?= e(setting('shop_name', app_name())) ?>"></label>
        <label>Phone <span>Optional</span><input name="shop_phone" maxlength="60" value="<?= e(setting('shop_phone')) ?>" placeholder="+92 ..."></label>
        <label>Address <span>Optional</span><textarea name="shop_address" rows="2" maxlength="300"><?= e(setting('shop_address')) ?></textarea></label>
        <label>Footer Line<input name="receipt_footer" maxlength="200" value="<?= e(setting('receipt_footer', 'Thank you for your business.')) ?>"></label>
        <button class="button primary full" type="submit">SAVE SETTINGS</button>
    </form><?php
    layout_end($user, 'shop-settings');
}

function render_account(array $user): void
{
    $statement = database()->prepare('SELECT name, username, created_at FROM users WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $user['id']]);
    $account = $statement->fetch() ?: ['name' => $user['name'], 'username' => $user['username'], 'created_at' => ''];

    layout_start('My Account', $user, 'account');
    page_heading('ADMIN', 'My Account', $user, 'index.php?page=admin-dashboard');
    ?>
    <section class="detail-summary">
        <div><span>Signed in as</span><strong><?= e($account['username']) ?></strong></div>
        <div><span>Role</span><strong>Administrator</strong></div>
    </section>

    <form class="form-card management-form" method="post" action="index.php?page=account-save" autocomplete="off">
        <?= csrf_field() ?>
        <label>Your Name<input name="name" maxlength="120" required value="<?= e($account['name']) ?>"></label>

        <label>Current Password<input type="password" name="current_password" autocomplete="current-password" required placeholder="Enter your current password"></label>
        <p class="muted small">Required to save any change on this page.</p>

        <label>New Password <span>Leave blank to keep current</span><input type="password" name="password" minlength="8" autocomplete="new-password" placeholder="Minimum 8 characters"></label>
        <label>Confirm New Password<input type="password" name="password_confirm" minlength="8" autocomplete="new-password" placeholder="Type the new password again"></label>

        <button class="button primary full" type="submit">SAVE CHANGES</button>
    </form>

    <form method="post" action="index.php?page=logout" class="account-signout">
        <?= csrf_field() ?>
        <button class="button secondary full" type="submit">SIGN OUT OF THIS DEVICE</button>
    </form>
    <p class="muted small center">Changing your password signs out every other device.</p>
    <?php
    layout_end($user, 'account');
}
