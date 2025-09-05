<?php
/**
 * Production API Configuration
 */

// CORS Headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// JWT Configuration - CHANGE THIS SECRET KEY!
define('JWT_SECRET', 'your-very-secure-secret-key-change-this-in-production-2025');
define('JWT_EXPIRY', 3600 * 24); // 24 hours

// API Configuration
define('API_VERSION', 'v1');
define('API_BASE_URL', 'https://crm.digibrandlk.com/api');

// Production settings - disable error display
error_reporting(E_ALL);
ini_set('display_errors', 0); // Set to 0 in production
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');

// Timezone
date_default_timezone_set('Asia/Colombo');

// File upload settings
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB

// Create directories if they don't exist
$dirs = [UPLOAD_DIR, __DIR__ . '/../logs/'];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}
?>
