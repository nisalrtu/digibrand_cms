<?php
// core/Helper.php

class Helper {
    
    // Base URL method
    public static function baseUrl($path = '') {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        
        // Get the application root directory by finding the digibrandcrm folder
        $scriptPath = $_SERVER['SCRIPT_NAME'];
        $appDir = '';
        
        // Find the position of 'digibrandcrm' in the path
        if (strpos($scriptPath, '/digibrandcrm/') !== false) {
            $parts = explode('/digibrandcrm/', $scriptPath);
            $appDir = dirname($parts[0]) . '/digibrandcrm';
        } else {
            // Fallback: assume script is in the root
            $appDir = dirname($_SERVER['SCRIPT_NAME']);
        }
        
        $baseUrl = $protocol . '://' . $host . $appDir;
        
        if ($path) {
            return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
        }
        
        return rtrim($baseUrl, '/') . '/';
    }

    // Simple ID encryption for URLs
    public static function encryptId($id) {
        $key = 'inv2024'; // Simple key
        $encrypted = base64_encode($id . '-' . $key);
        return str_replace(['+', '/', '='], ['-', '_', ''], $encrypted);
    }

    // Simple ID decryption from URLs
    public static function decryptId($encryptedId) {
        $key = 'inv2024'; // Simple key
        $encrypted = str_replace(['-', '_'], ['+', '/'], $encryptedId);
        
        // Add padding if needed
        $padding = 4 - (strlen($encrypted) % 4);
        if ($padding !== 4) {
            $encrypted .= str_repeat('=', $padding);
        }
        
        $decrypted = base64_decode($encrypted);
        if ($decrypted === false) {
            return false;
        }
        
        $parts = explode('-', $decrypted);
        if (count($parts) !== 2 || $parts[1] !== $key) {
            return false;
        }
        
        return (int)$parts[0];
    }

    // Format currency in LKR
    public static function formatCurrency($amount) {
        return 'LKR ' . number_format($amount, 2);
    }

    // Sanitize input
    public static function sanitize($data) {
        return htmlspecialchars(strip_tags(trim($data)));
    }

    // Check if user is logged in
    public static function isLoggedIn() {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }

    // Check user role
    public static function hasRole($role) {
        return isset($_SESSION['user_role']) && $_SESSION['user_role'] === $role;
    }

    // Redirect function
    public static function redirect($path = '') {
        header('Location: ' . self::baseUrl($path));
        exit();
    }

    // Generate CSRF token
    public static function generateCSRF() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    // Verify CSRF token
    public static function verifyCSRF($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    // Format date
    public static function formatDate($date, $format = 'Y-m-d') {
        return date($format, strtotime($date));
    }

    // Success message
    public static function setMessage($message, $type = 'success') {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = $type;
    }

    // Get and clear flash message
    public static function getFlashMessage() {
        if (isset($_SESSION['flash_message'])) {
            $message = $_SESSION['flash_message'];
            $type = $_SESSION['flash_type'] ?? 'info';
            unset($_SESSION['flash_message'], $_SESSION['flash_type']);
            return ['message' => $message, 'type' => $type];
        }
        return null;
    }

    // Status badge HTML
    public static function statusBadge($status) {
        $colors = [
            'pending' => 'bg-yellow-100 text-yellow-800',
            'in_progress' => 'bg-blue-100 text-blue-800',
            'completed' => 'bg-green-100 text-green-800',
            'cancelled' => 'bg-red-100 text-red-800',
            'draft' => 'bg-gray-100 text-gray-800',
            'sent' => 'bg-blue-100 text-blue-800',
            'paid' => 'bg-green-100 text-green-800',
            'partially_paid' => 'bg-orange-100 text-orange-800',
            'overdue' => 'bg-red-100 text-red-800'
        ];
        
        $color = $colors[$status] ?? 'bg-gray-100 text-gray-800';
        $label = ucwords(str_replace('_', ' ', $status));
        
        return "<span class='px-2 py-1 text-xs font-medium rounded-full {$color}'>{$label}</span>";
    }
}
?>