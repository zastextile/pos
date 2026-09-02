<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$user = api_user('staff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}

$barcode = trim((string) ($_GET['barcode'] ?? ''));
if ($barcode === '' || strlen($barcode) > 80) {
    json_response(['ok' => false, 'message' => 'Enter a valid barcode.'], 422);
}

$statement = database()->prepare(
    "SELECT id, product_name, barcode, rate FROM products WHERE barcode = :barcode AND status = 'active' LIMIT 1"
);
$statement->execute(['barcode' => $barcode]);
$product = $statement->fetch();

if (!$product) {
    json_response(['ok' => false, 'message' => 'Product not found or inactive.'], 404);
}

json_response([
    'ok' => true,
    'product' => [
        'id' => (int) $product['id'],
        'name' => $product['product_name'],
        'barcode' => $product['barcode'],
        'rate' => (float) $product['rate'],
    ],
]);
