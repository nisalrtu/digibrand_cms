<?php
// modules/auth/logout.php
session_start();

// Include helper functions
require_once '../../core/Helper.php';

// Check if user was actually logged in
$wasLoggedIn = Helper::isLoggedIn();
$userName = $_SESSION['user_name'] ?? 'User';

// Clear all session variables
$_SESSION = array();

// Delete the session cookie if it exists
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Start a new session for the flash message
session_start();

// Set logout success message
if ($wasLoggedIn) {
    Helper::setMessage('You have been logged out successfully. See you soon, ' . htmlspecialchars($userName) . '!', 'success');
} else {
    Helper::setMessage('You are already logged out.', 'info');
}

// Redirect to login page using absolute redirect
$loginUrl = Helper::baseUrl('modules/auth/login.php');
header('Location: ' . $loginUrl);
exit();
?>