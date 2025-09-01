<?php
// index.php - Main entry point
session_start();

require_once 'core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    // Redirect to login
    Helper::redirect('modules/auth/login.php');
} else {
    // Redirect to dashboard if logged in
    Helper::redirect('modules/dashboard/');
}
?>