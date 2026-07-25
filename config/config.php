<?php
/**
 * Main Configuration File
 * Update these settings to match your environment
 */

// Prevent direct access
if (!defined('APP_LOADED')) {
    die('Direct access not permitted.');
}

// --- Database Configuration (VistaPanel / Byethost) ---
define('DB_HOST', '');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASS', '');  // Set to your vPanel password
define('DB_CHARSET', 'utf8mb4');

// --- LLM Defaults (users supply their own keys) ---
// These are fallback defaults only — each user stores their own endpoint + key
define('LLM_DEFAULT_MODEL', 'gpt-4o');
define('LLM_MAX_TOKENS', 4096);
define('LLM_TEMPERATURE', 0.7);

// --- Encryption key for storing user API keys at rest ---
// Generate a random 32-byte hex string: openssl rand -hex 32
// IMPORTANT: Change this to your own secret and keep it safe!
define('ENCRYPTION_KEY', '');

// --- Site Configuration ---
define('SITE_NAME', 'VistaPanel AI Chat');
define('SITE_URL', '');  // Change to your actual URL
define('TIMEZONE', 'Asia/Shanghai');

// --- Session / Security ---
define('SESSION_LIFETIME', 7200);       // 24 hours
define('BCRYPT_COST', 10);
define('MAX_FILE_UPLOAD_SIZE', 10 * 1024 * 1024);  // 10 MB

// --- File upload ---
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

date_default_timezone_set(TIMEZONE);