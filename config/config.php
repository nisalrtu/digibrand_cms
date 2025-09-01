<?php
// config/config.php - Updated with manual base URL

// Manual Base URL Configuration (CHANGE THIS TO YOUR ACTUAL PATH)
define('APP_BASE_URL', 'http://localhost/digibrandcrm/');

// Alternative: Auto-detect base URL (if the above doesn't work, uncomment this)
/*
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
define('APP_BASE_URL', $protocol . '://' . $host . '/digibrandcrm/');
*/

// Application Configuration
define('APP_NAME', 'Invoice Manager');
define('APP_VERSION', '1.0.0');

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'invoicing_app');
define('DB_USER', 'root');
define('DB_PASS', '');

// Security Configuration
define('ENCRYPTION_KEY', 'your-32-character-secret-key-here'); // Change this!
define('SESSION_TIMEOUT', 3600); // 1 hour

// File Upload Configuration
define('UPLOAD_DIR', 'assets/uploads/');
define('MAX_FILE_SIZE', 5242880); // 5MB

// Pagination
define('RECORDS_PER_PAGE', 20);

// Date and Time
date_default_timezone_set('Asia/Colombo'); // Sri Lanka timezone

// Error Reporting (Set to 0 in production)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Session Configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS

// Start session
session_start();
?>