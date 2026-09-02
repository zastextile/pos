<?php
declare(strict_types=1);

if (
    PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    http_response_code(404);
    exit;
}

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '2.3');

function load_environment(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    foreach ($lines as $line) {
        $line = trim($line);

        if (
            $line === ''
            || str_starts_with($line, '#')
            || !str_contains($line, '=')
        ) {
            continue;
        }

        [$key, $value] = array_map(
            'trim',
            explode('=', $line, 2)
        );

        $value = trim($value, "\"'");

        $hasProcessValue =
            function_exists('getenv')
            && getenv($key) !== false;

        if (
            $key !== ''
            && !array_key_exists($key, $_ENV)
            && !$hasProcessValue
        ) {
            $_ENV[$key] = $value;
        }
    }
}

function env_value(string $key, string $default = ''): string
{
    if (array_key_exists($key, $_ENV)) {
        return (string) $_ENV[$key];
    }

    if (function_exists('getenv')) {
        $value = getenv($key);

        if ($value !== false) {
            return (string) $value;
        }
    }

    return $default;
}

load_environment(APP_ROOT . '/.env');

date_default_timezone_set(
    env_value('APP_TIMEZONE', 'Asia/Karachi')
);

if (
    PHP_SAPI !== 'cli'
    && session_status() !== PHP_SESSION_ACTIVE
) {
    $secure =
        (!empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off')
        || (
            ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')
            === 'https'
        );

    $sessionPath =
        sys_get_temp_dir()
        . '/zas-sales-sessions-'
        . substr(hash('sha256', APP_ROOT), 0, 16);

    if (
        !is_dir($sessionPath)
        && !mkdir($sessionPath, 0700, true)
        && !is_dir($sessionPath)
    ) {
        throw new RuntimeException(
            'Unable to create session storage.'
        );
    }

    session_save_path($sessionPath);

    ini_set('session.gc_maxlifetime', '2592000');
    ini_set('session.use_cookies', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');

    session_name('zas_sales_session');

    session_set_cookie_params([
        'lifetime' => 2592000,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        throw new RuntimeException(
            'Unable to start the application session.'
        );
    }

    header(
        'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
    );
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header(
        'Permissions-Policy: camera=(self), microphone=(), geolocation=()'
    );
}

function database(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env_value('DB_HOST', '127.0.0.1');
    $port = env_value('DB_PORT', '3306');
    $name = env_value('DB_NAME', 'zas_sales');
    $username = env_value('DB_USER');
    $password = env_value('DB_PASS');

    if (
        $name === ''
        || $username === ''
        || $password === ''
    ) {
        throw new RuntimeException(
            'Database configuration is missing.'
        );
    }

    $dsn =
        "mysql:host={$host};port={$port};"
        . "dbname={$name};charset=utf8mb4";

    $pdo = new PDO(
        $dsn,
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE =>
                PDO::ERRMODE_EXCEPTION,

            PDO::ATTR_DEFAULT_FETCH_MODE =>
                PDO::FETCH_ASSOC,

            PDO::ATTR_EMULATE_PREPARES =>
                false,
        ]
    );

    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function money(float|int|string|null $value): string
{
    return 'Rs. ' . number_format((float) $value, 0);
}

function app_name(): string
{
    return env_value(
        'APP_NAME',
        'ZAS Sales Recorder'
    );
}

function is_post(): bool
{
    return (
        $_SERVER['REQUEST_METHOD'] ?? 'GET'
    ) === 'POST';
}

function request_is_secure(): bool
{
    return (
        !empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off'
    ) || (
        ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')
        === 'https'
    );
}

function auth_cookie_name(): string
{
    return 'zas_sales_auth';
}

function auth_cookie_secret(): string
{
    return hash(
        'sha256',
        env_value('DB_PASS')
        . '|'
        . APP_ROOT
        . '|zas-sales-auth'
    );
}

function set_auth_cookie(int $userId): void
{
    $expires = time() + 2592000;
    $payload = $userId . '.' . $expires;

    $value =
        $payload
        . '.'
        . hash_hmac(
            'sha256',
            $payload,
            auth_cookie_secret()
        );

    setcookie(
        auth_cookie_name(),
        $value,
        [
            'expires' => $expires,
            'path' => '/',
            'secure' => request_is_secure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );

    $_COOKIE[auth_cookie_name()] = $value;
}

function clear_auth_cookie(): void
{
    setcookie(
        auth_cookie_name(),
        '',
        [
            'expires' => time() - 42000,
            'path' => '/',
            'secure' => request_is_secure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );

    unset($_COOKIE[auth_cookie_name()]);
}

function auth_cookie_user_id(): ?int
{
    $value = (string) (
        $_COOKIE[auth_cookie_name()] ?? ''
    );

    $parts = explode('.', $value, 3);

    if (count($parts) !== 3) {
        return null;
    }

    [$userId, $expires, $signature] = $parts;

    if (
        !ctype_digit($userId)
        || !ctype_digit($expires)
        || (int) $expires < time()
    ) {
        return null;
    }

    $payload = $userId . '.' . $expires;

    $expected = hash_hmac(
        'sha256',
        $payload,
        auth_cookie_secret()
    );

    if (!hash_equals($expected, $signature)) {
        return null;
    }

    return (int) $userId;
}

function redirect(
    string $page,
    array $parameters = []
): never {
    $query = http_build_query(
        array_merge(
            ['page' => $page],
            $parameters
        )
    );

    header('Location: index.php?' . $query);
    exit;
}

function csrf_token(): string
{
    $authCookie = (string) (
        $_COOKIE[auth_cookie_name()] ?? ''
    );

    if (
        $authCookie !== ''
        && auth_cookie_user_id() !== null
    ) {
        return hash_hmac(
            'sha256',
            'csrf|' . $authCookie,
            auth_cookie_secret()
        );
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] =
            bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return
        '<input type="hidden" name="csrf_token" value="'
        . e(csrf_token())
        . '">';
}

function verify_csrf(?string $token = null): void
{
    $page = (string) (
        $_GET['page'] ?? 'login'
    );

    if (is_post() && $page === 'login') {
        return;
    }

    $provided =
        $token
        ?? ($_POST['csrf_token'] ?? '');

    if (
        !is_string($provided)
        || !hash_equals(
            csrf_token(),
            $provided
        )
    ) {
        http_response_code(419);

        throw new RuntimeException(
            'Your session expired. Please refresh and try again.'
        );
    }
}

function current_user(): ?array
{
    if (
        isset($_SESSION['user'])
        && is_array($_SESSION['user'])
    ) {
        return $_SESSION['user'];
    }

    $userId = auth_cookie_user_id();

    if (!$userId) {
        return null;
    }

    $statement = database()->prepare(
        "SELECT id, name, username, role, status, max_discount_percent
         FROM users
         WHERE id = :id
         AND status = 'active'
         LIMIT 1"
    );

    $statement->execute([
        'id' => $userId,
    ]);

    $user = $statement->fetch();

    if (!$user) {
        clear_auth_cookie();
        return null;
    }

    $_SESSION['user'] = $user;
    $_SESSION['last_activity'] = time();

    return $user;
}

function active_session_user(): ?array
{
    $sessionUser = current_user();

    if (
        !$sessionUser
        || empty($sessionUser['id'])
    ) {
        return null;
    }

    $statement = database()->prepare(
        "SELECT id, name, username, role, status, max_discount_percent
         FROM users
         WHERE id = :id
         AND status = 'active'
         LIMIT 1"
    );

    $statement->execute([
        'id' => $sessionUser['id'],
    ]);

    $freshUser = $statement->fetch();

    if (!$freshUser) {
        logout_user();
        return null;
    }

    $_SESSION['user'] = $freshUser;

    return $freshUser;
}

function login_user(
    string $username,
    string $password
): bool {
    $statement = database()->prepare(
        "SELECT id, name, username, password,
                role, status, max_discount_percent
         FROM users
         WHERE username = :username
         LIMIT 1"
    );

    $statement->execute([
        'username' => trim($username),
    ]);

    $user = $statement->fetch();

    if (
        !$user
        || $user['status'] !== 'active'
        || !password_verify(
            $password,
            $user['password']
        )
    ) {
        password_verify(
            $password,
            '$2y$10$wH5N0N7JQohEcp4y/pSyTuOh/t1JuSSJ78nReE0qb8cGmYQkm4A3K'
        );

        return false;
    }

    if (
        password_needs_rehash(
            $user['password'],
            PASSWORD_DEFAULT
        )
    ) {
        $rehash = database()->prepare(
            'UPDATE users
             SET password = :password
             WHERE id = :id'
        );

        $rehash->execute([
            'password' =>
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                ),

            'id' => $user['id'],
        ]);
    }

    session_regenerate_id(true);

    $userId = (int) $user['id'];

    unset($user['password']);

    $_SESSION['user'] = $user;
    $_SESSION['last_activity'] = time();

    set_auth_cookie($userId);

    return true;
}

function logout_user(): void
{
    clear_auth_cookie();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params =
            session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'] ?? '',
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

function require_login(): array
{
    $user = active_session_user();

    if (!$user) {
        redirect('login');
    }

    if (
        isset($_SESSION['last_activity'])
        && time()
            - (int) $_SESSION['last_activity']
            > 43200
    ) {
        logout_user();

        redirect(
            'login',
            ['expired' => 1]
        );
    }

    $_SESSION['last_activity'] = time();

    return $user;
}

function require_role(string $role): array
{
    $user = require_login();

    if (($user['role'] ?? '') !== $role) {
        http_response_code(403);
        exit('Access denied.');
    }

    return $user;
}

function set_flash(
    string $type,
    string $message
): void {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function pull_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;

    unset($_SESSION['flash']);

    return is_array($flash)
        ? $flash
        : null;
}

function json_response(
    array $data,
    int $status = 200
): never {
    http_response_code($status);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    header('Cache-Control: no-store');

    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
    );

    exit;
}

function api_user(string $role = ''): array
{
    if (
        isset($_SESSION['last_activity'])
        && time()
            - (int) $_SESSION['last_activity']
            > 43200
    ) {
        logout_user();

        json_response(
            [
                'ok' => false,
                'message' =>
                    'Please sign in again.',
            ],
            401
        );
    }

    $user = active_session_user();

    if (!$user) {
        json_response(
            [
                'ok' => false,
                'message' =>
                    'Please sign in again.',
            ],
            401
        );
    }

    if (
        $role !== ''
        && $user['role'] !== $role
    ) {
        json_response(
            [
                'ok' => false,
                'message' =>
                    'Access denied.',
            ],
            403
        );
    }

    $_SESSION['last_activity'] = time();

    return $user;
}
/**
 * Settings are a tiny key/value table so the shop can retune receipts
 * without a redeploy. Cached per request.
 */
function settings(): array
{
    static $cache = null;

    if (is_array($cache)) {
        return $cache;
    }

    $cache = [];

    foreach (database()->query('SELECT setting_key, setting_value FROM settings')->fetchAll() as $row) {
        $cache[$row['setting_key']] = $row['setting_value'];
    }

    return $cache;
}

function setting(string $key, string $default = ''): string
{
    $all = settings();

    return array_key_exists($key, $all) && $all[$key] !== ''
        ? $all[$key]
        : $default;
}

function save_setting(string $key, string $value): void
{
    $statement = database()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );

    $statement->execute(['k' => $key, 'v' => $value]);
}

/** Roll widths a thermal/label printer can actually feed, in millimetres. */
function receipt_widths(): array
{
    return [
        '58'  => '58 mm roll (2 inch)',
        '80'  => '80 mm roll (3 inch)',
        '100' => '100 mm roll (4 inch)',
        '112' => '112 mm roll (4.4 inch)',
    ];
}

function receipt_width_mm(): int
{
    $width = setting('receipt_width', '100');

    return array_key_exists($width, receipt_widths())
        ? (int) $width
        : 100;
}

/**
 * Money is handled in integer paisa everywhere a total is derived, so a
 * percentage discount can never introduce a rounding drift.
 */
function to_paisa(float|int|string $rupees): int
{
    return (int) round((float) $rupees * 100);
}

function from_paisa(int $paisa): string
{
    return number_format($paisa / 100, 2, '.', '');
}

/**
 * Resolves a requested discount against the ceiling the admin set for this
 * staff member. Returns the discount in paisa plus the reason it was capped,
 * and is the single authority for both the API and the UI.
 */
function resolve_discount(
    int $subtotalPaisa,
    string $type,
    float $value,
    float $maxPercent
): array {
    if ($type !== 'percent' && $type !== 'amount') {
        return ['type' => 'none', 'value' => 0.0, 'paisa' => 0, 'error' => ''];
    }

    if ($value <= 0) {
        return ['type' => 'none', 'value' => 0.0, 'paisa' => 0, 'error' => ''];
    }

    $ceilingPaisa = (int) floor($subtotalPaisa * $maxPercent / 100);

    if ($maxPercent <= 0) {
        return [
            'type' => 'none',
            'value' => 0.0,
            'paisa' => 0,
            'error' => 'You are not allowed to give a discount. Ask the admin to set your limit.',
        ];
    }

    if ($type === 'percent') {
        if ($value > $maxPercent) {
            return [
                'type' => 'percent',
                'value' => $value,
                'paisa' => 0,
                'error' => 'Your discount limit is ' . rtrim(rtrim(number_format($maxPercent, 2), '0'), '.') . '%.',
            ];
        }

        $paisa = (int) floor($subtotalPaisa * $value / 100);
    } else {
        $paisa = to_paisa($value);

        if ($paisa > $ceilingPaisa) {
            return [
                'type' => 'amount',
                'value' => $value,
                'paisa' => 0,
                'error' => 'Highest discount you can give on this sale is Rs. '
                    . number_format($ceilingPaisa / 100, 0)
                    . ' (' . rtrim(rtrim(number_format($maxPercent, 2), '0'), '.') . '%).',
            ];
        }
    }

    if ($paisa > $subtotalPaisa) {
        $paisa = $subtotalPaisa;
    }

    return ['type' => $type, 'value' => $value, 'paisa' => $paisa, 'error' => ''];
}

function product_photo_url(?string $photo): string
{
    return $photo ? 'uploads/products/' . rawurlencode($photo) : '';
}

function app_base_url(): string
{
    $configured = trim(env_value('APP_URL'), '/');

    if ($configured !== '') {
        return $configured;
    }

    $scheme = request_is_secure() ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');

    return $scheme . '://' . $host . $dir;
}

function public_receipt_url(string $token): string
{
    return app_base_url() . '/index.php?page=receipt&t=' . rawurlencode($token);
}

/**
 * Appends the file's modification time to an asset URL. A new upload therefore
 * gets a new URL, so neither the browser cache nor the service worker can pin
 * a stale stylesheet or script after a deploy.
 */
function asset(string $path): string
{
    $file = APP_ROOT . '/' . ltrim($path, '/');
    $stamp = is_file($file) ? filemtime($file) : null;

    return $stamp ? $path . '?v=' . $stamp : $path;
}
