<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$user = api_user('staff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 1048576) {
    json_response(['ok' => false, 'message' => 'Request is too large.'], 413);
}

$csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) {
    json_response(['ok' => false, 'message' => 'Your session expired. Refresh and try again.'], 419);
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
    json_response(['ok' => false, 'message' => 'Invalid sale data.'], 422);
}

if (count($payload['items']) < 1 || count($payload['items']) > 200) {
    json_response(['ok' => false, 'message' => 'Add at least one valid product.'], 422);
}

$quantities = [];
foreach ($payload['items'] as $item) {
    if (!is_array($item)) {
        continue;
    }
    $productId = filter_var($item['id'] ?? null, FILTER_VALIDATE_INT);
    $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
    if (!$productId || !$quantity || $quantity < 1 || $quantity > 9999) {
        json_response(['ok' => false, 'message' => 'One or more product quantities are invalid.'], 422);
    }
    $quantities[(int) $productId] = min(9999, ($quantities[(int) $productId] ?? 0) + (int) $quantity);
}

if (!$quantities) {
    json_response(['ok' => false, 'message' => 'Add at least one valid product.'], 422);
}

$ids = array_keys($quantities);
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$productQuery = database()->prepare(
    "SELECT id, product_name, barcode, rate FROM products WHERE status = 'active' AND id IN ({$placeholders})"
);
$productQuery->execute($ids);
$products = [];
foreach ($productQuery->fetchAll() as $product) {
    $products[(int) $product['id']] = $product;
}

if (count($products) !== count($ids)) {
    json_response(['ok' => false, 'message' => 'A product is missing or inactive. Please refresh the cart.'], 409);
}

$saleItems = [];
$subtotalPaisa = 0;
foreach ($quantities as $productId => $quantity) {
    $product = $products[$productId];
    $ratePaisa = to_paisa($product['rate']);
    $linePaisa = $ratePaisa * $quantity;
    $subtotalPaisa += $linePaisa;
    $saleItems[] = [
        'product_id' => $productId,
        'product_name' => $product['product_name'],
        'barcode' => $product['barcode'],
        'rate' => from_paisa($ratePaisa),
        'quantity' => $quantity,
        'line_total' => from_paisa($linePaisa),
    ];
}

// The discount is recomputed here from the staff member's own ceiling. Whatever
// the phone sent is a request, never the authority.
$maxPercent = (float) ($user['max_discount_percent'] ?? 0);
$discountType = (string) ($payload['discount_type'] ?? 'none');
$discountValue = (float) filter_var($payload['discount_value'] ?? 0, FILTER_VALIDATE_FLOAT, ['options' => ['default' => 0]]);
$discount = resolve_discount($subtotalPaisa, $discountType, $discountValue, $maxPercent);

if ($discount['error'] !== '') {
    json_response(['ok' => false, 'message' => $discount['error']], 422);
}

$totalPaisa = $subtotalPaisa - $discount['paisa'];

$clean = static function (mixed $value, int $limit): ?string {
    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }
    return function_exists('mb_substr') ? mb_substr($text, 0, $limit) : substr($text, 0, $limit);
};

$customerName = $clean($payload['customer_name'] ?? '', 150);
$customerContact = $clean($payload['customer_contact'] ?? '', 60);
$customerAddress = $clean($payload['customer_address'] ?? '', 500);
$customerId = filter_var($payload['customer_id'] ?? null, FILTER_VALIDATE_INT) ?: null;

$pdo = database();

// A picked customer wins over typed text, so the sale always points at the
// directory record rather than a near-duplicate spelling.
if ($customerId) {
    $lookup = $pdo->prepare("SELECT id, name, contact, address FROM customers WHERE id = :id AND status = 'active' LIMIT 1");
    $lookup->execute(['id' => $customerId]);
    $customer = $lookup->fetch();

    if (!$customer) {
        json_response(['ok' => false, 'message' => 'That customer is no longer available.'], 409);
    }

    $customerName = $customer['name'];
    $customerContact = $customerContact ?? $customer['contact'];
    $customerAddress = $customerAddress ?? $customer['address'];
} elseif ($customerName !== null && !empty($payload['save_customer'])) {
    // "Save this customer" at the counter: reuse an exact match, else create.
    $match = $pdo->prepare('SELECT id FROM customers WHERE name = :name AND (contact <=> :contact) LIMIT 1');
    $match->execute(['name' => $customerName, 'contact' => $customerContact]);
    $matchId = $match->fetchColumn();

    if ($matchId) {
        $customerId = (int) $matchId;
    } else {
        $create = $pdo->prepare('INSERT INTO customers (name, contact, address, created_by) VALUES (:name, :contact, :address, :created_by)');
        $create->execute([
            'name' => $customerName,
            'contact' => $customerContact,
            'address' => $customerAddress,
            'created_by' => $user['id'],
        ]);
        $customerId = (int) $pdo->lastInsertId();
    }
}

$now = new DateTimeImmutable('now');
$token = bin2hex(random_bytes(16));

try {
    $pdo->beginTransaction();
    $saleStatement = $pdo->prepare(
        "INSERT INTO sales
        (staff_id, staff_name, customer_id, customer_name, customer_contact, customer_address,
         subtotal_amount, discount_type, discount_value, discount_amount, total_amount,
         status, public_token, sale_date, sale_time)
        VALUES (:staff_id, :staff_name, :customer_id, :customer_name, :customer_contact, :customer_address,
                :subtotal, :discount_type, :discount_value, :discount_amount, :total_amount,
                'draft', :public_token, :sale_date, :sale_time)"
    );
    $saleStatement->execute([
        'staff_id' => $user['id'],
        'staff_name' => $user['name'],
        'customer_id' => $customerId,
        'customer_name' => $customerName,
        'customer_contact' => $customerContact,
        'customer_address' => $customerAddress,
        'subtotal' => from_paisa($subtotalPaisa),
        'discount_type' => $discount['type'],
        'discount_value' => from_paisa(to_paisa($discount['value'])),
        'discount_amount' => from_paisa($discount['paisa']),
        'total_amount' => from_paisa($totalPaisa),
        'public_token' => $token,
        'sale_date' => $now->format('Y-m-d'),
        'sale_time' => $now->format('H:i:s'),
    ]);
    $saleId = (int) $pdo->lastInsertId();

    $itemStatement = $pdo->prepare(
        "INSERT INTO sale_items
        (sale_id, product_id, product_name, barcode, rate, quantity, line_total)
        VALUES (:sale_id, :product_id, :product_name, :barcode, :rate, :quantity, :line_total)"
    );
    foreach ($saleItems as $item) {
        $itemStatement->execute(array_merge(['sale_id' => $saleId], $item));
    }

    $pdo->commit();
    json_response([
        'ok' => true,
        'sale_id' => $saleId,
        'subtotal' => $subtotalPaisa / 100,
        'discount' => $discount['paisa'] / 100,
        'total' => $totalPaisa / 100,
        'receipt_url' => 'index.php?page=receipt&id=' . $saleId,
        'share_url' => public_receipt_url($token),
        'message' => 'Sale saved successfully.',
    ], 201);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Sale save failed: ' . $exception->getMessage());
    json_response(['ok' => false, 'message' => 'Sale could not be saved. Please try again.'], 500);
}
