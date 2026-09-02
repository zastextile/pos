<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$user = api_user('staff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}

$categories = database()->query(
    "SELECT c.id, c.name, COUNT(p.id) AS product_count
     FROM categories c
     LEFT JOIN products p ON p.category_id = c.id AND p.status = 'active'
     WHERE c.status = 'active'
     GROUP BY c.id, c.name, c.sort_order
     HAVING product_count > 0
     ORDER BY c.sort_order, c.name"
)->fetchAll();

$products = database()->query(
    "SELECT id, product_name, barcode, rate, photo, category_id
     FROM products
     WHERE status = 'active'
     ORDER BY product_name
     LIMIT 1000"
)->fetchAll();

$uncategorised = 0;

$list = array_map(static function (array $product) use (&$uncategorised): array {
    if ($product['category_id'] === null) {
        $uncategorised++;
    }

    return [
        'id' => (int) $product['id'],
        'name' => $product['product_name'],
        'barcode' => $product['barcode'],
        'rate' => (float) $product['rate'],
        'photo' => product_photo_url($product['photo']),
        'category_id' => $product['category_id'] === null ? 0 : (int) $product['category_id'],
    ];
}, $products);

$tabs = array_map(static fn (array $row): array => [
    'id' => (int) $row['id'],
    'name' => $row['name'],
    'count' => (int) $row['product_count'],
], $categories);

if ($uncategorised > 0) {
    $tabs[] = ['id' => 0, 'name' => 'Uncategorised', 'count' => $uncategorised];
}

json_response([
    'ok' => true,
    'categories' => $tabs,
    'products' => $list,
    'max_discount_percent' => (float) ($user['max_discount_percent'] ?? 0),
]);
