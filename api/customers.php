<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

// Both roles reach this endpoint: staff look customers up at the counter and
// add the ones they meet, admins manage the same directory from the office.
$user = api_user();
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $term = trim((string) ($_GET['q'] ?? ''));

    $sql = "SELECT c.id, c.name, c.contact, c.address,
                   COUNT(s.id) AS sale_count,
                   COALESCE(SUM(s.total_amount), 0) AS lifetime_value,
                   MAX(s.sale_date) AS last_sale
            FROM customers c
            LEFT JOIN sales s ON s.customer_id = c.id
            WHERE c.status = 'active'";

    $params = [];

    if ($term !== '') {
        $sql .= ' AND (c.name LIKE :name OR c.contact LIKE :contact)';
        $params['name'] = '%' . $term . '%';
        $params['contact'] = '%' . $term . '%';
    }

    $sql .= ' GROUP BY c.id, c.name, c.contact, c.address
              ORDER BY sale_count DESC, c.name
              LIMIT 25';

    $statement = database()->prepare($sql);
    $statement->execute($params);

    json_response([
        'ok' => true,
        'customers' => array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'contact' => $row['contact'] ?? '',
            'address' => $row['address'] ?? '',
            'sale_count' => (int) $row['sale_count'],
            'lifetime_value' => (float) $row['lifetime_value'],
            'last_sale' => $row['last_sale'],
        ], $statement->fetchAll()),
    ]);
}

if ($method !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}

$csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) {
    json_response(['ok' => false, 'message' => 'Your session expired. Refresh and try again.'], 419);
}

$payload = json_decode((string) file_get_contents('php://input'), true);

if (!is_array($payload)) {
    json_response(['ok' => false, 'message' => 'Invalid customer data.'], 422);
}

$clean = static function (mixed $value, int $limit): ?string {
    $text = trim((string) $value);

    if ($text === '') {
        return null;
    }

    return function_exists('mb_substr') ? mb_substr($text, 0, $limit) : substr($text, 0, $limit);
};

$name = $clean($payload['name'] ?? '', 150);
$contact = $clean($payload['contact'] ?? '', 60);
$address = $clean($payload['address'] ?? '', 500);

if ($name === null) {
    json_response(['ok' => false, 'message' => 'Enter the customer name.'], 422);
}

// A repeat customer typed in again shouldn't fork into a second record.
$existing = database()->prepare(
    'SELECT id FROM customers WHERE name = :name AND (contact <=> :contact) LIMIT 1'
);
$existing->execute(['name' => $name, 'contact' => $contact]);
$found = $existing->fetchColumn();

if ($found) {
    json_response([
        'ok' => true,
        'duplicate' => true,
        'customer' => ['id' => (int) $found, 'name' => $name, 'contact' => $contact ?? '', 'address' => $address ?? ''],
        'message' => 'This customer is already saved.',
    ]);
}

$insert = database()->prepare(
    'INSERT INTO customers (name, contact, address, created_by) VALUES (:name, :contact, :address, :created_by)'
);
$insert->execute([
    'name' => $name,
    'contact' => $contact,
    'address' => $address,
    'created_by' => $user['id'],
]);

json_response([
    'ok' => true,
    'duplicate' => false,
    'customer' => [
        'id' => (int) database()->lastInsertId(),
        'name' => $name,
        'contact' => $contact ?? '',
        'address' => $address ?? '',
    ],
    'message' => 'Customer saved.',
], 201);
