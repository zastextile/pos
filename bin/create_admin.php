<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php bin/create_admin.php \"Admin Name\" \"username\" \"password\"\n");
    exit(1);
}

[$script, $name, $username, $password] = $argv;
$name = trim($name);
$username = trim($username);

if ($name === '' || $username === '' || strlen($password) < 8) {
    fwrite(STDERR, "Name and username are required. Password must be at least 8 characters.\n");
    exit(1);
}

try {
    $statement = database()->prepare(
        "INSERT INTO users (name, username, password, role, status) VALUES (:name, :username, :password, 'admin', 'active')"
    );
    $statement->execute([
        'name' => $name,
        'username' => $username,
        'password' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    fwrite(STDOUT, "Admin account created successfully.\n");
} catch (PDOException $exception) {
    if ((string) $exception->getCode() === '23000') {
        fwrite(STDERR, "That username already exists.\n");
        exit(1);
    }
    throw $exception;
}
