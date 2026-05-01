<?php
// ══════════════════════════════════════════════
//  Sukary — إعدادات قاعدة البيانات
// ══════════════════════════════════════════════
ob_start(); // منع أي output قبل الـ headers

define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost');
define('DB_NAME',    getenv('DB_NAME')    ?: 'malek2026_sukary');
define('DB_USER',    getenv('DB_USER')    ?: 'malek2026');
define('DB_PASS',    getenv('DB_PASS')    ?: 'Malek@2026');
define('DB_CHARSET', 'utf8mb4');

define('SESSION_KEY', getenv('SESSION_KEY') ?: 'sukary_secret_2025');

// ──────────────────────────────────────────────
//  Session — لازم تبدأ قبل أي header
// ──────────────────────────────────────────────
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

session_set_cookie_params([
    'lifetime' => 86400 * 30,
    'path'     => '/',
    'secure'   => $is_https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name(SESSION_KEY);
session_start();

// ──────────────────────────────────────────────
//  Headers مشتركة
// ──────────────────────────────────────────────
ob_clean(); // امسح أي output مش مقصود
header('Content-Type: application/json; charset=utf-8');

// Fix CORS — wildcard (*) مع credentials مش بيشتغل، لازم نحدد الـ origin
$allowed_origin = $is_https ? 'https://' . $_SERVER['HTTP_HOST'] : 'http://' . $_SERVER['HTTP_HOST'];
header('Access-Control-Allow-Origin: ' . $allowed_origin);
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ──────────────────────────────────────────────
//  اتصال بقاعدة البيانات
// ──────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('DB Connection Error: ' . $e->getMessage());
            die(json_encode(['error' => 'خطأ في الاتصال بقاعدة البيانات'], JSON_UNESCAPED_UNICODE));
        }
    }
    return $pdo;
}

// ──────────────────────────────────────────────
//  Auth Helpers
// ──────────────────────────────────────────────
function getCurrentUserId(): int {
    if (isset($_SESSION['user_id'])) {
        return (int)$_SESSION['user_id'];
    }
    http_response_code(401);
    die(json_encode(['error' => 'غير مصرح — سجّل دخولك أولاً'], JSON_UNESCAPED_UNICODE));
}

function json_out($data, int $code = 200): void {
    ob_clean();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function get_body(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}
