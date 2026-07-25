<?php
/**
 * Application Initialization
 * Loaded by every entry point
 */

define('APP_LOADED', true);

// Load config
require_once __DIR__ . '/config.php';

// Start session with secure settings
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
    session_start();
}

// --- Database Connection (PDO) ---
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed. Please check your configuration.']));
        }
    }
    return $pdo;
}

// --- Simple Router Helper ---
function getRequestPath(): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $uri = parse_url($uri, PHP_URL_PATH);
    // Remove base path if in subdirectory
    $basePath = parse_url(SITE_URL, PHP_URL_PATH) ?? '';
    if ($basePath && strpos($uri, $basePath) === 0) {
        $uri = substr($uri, strlen($basePath));
    }
    return '/' . trim($uri, '/');
}

// --- JSON Response Helper ---
function jsonResponse($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// --- Sanitize input ---
function sanitize(string $value): string {
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}