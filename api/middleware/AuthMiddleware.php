<?php
/**
 * Authentication Middleware
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../utils/JWTHelper.php';
require_once __DIR__ . '/../utils/Response.php';

class AuthMiddleware {
    
    public static function authenticate() {
        $current_user = JWTHelper::getCurrentUser();
        
        if (!$current_user) {
            Response::unauthorized('Invalid or expired token');
        }
        
        return $current_user;
    }
    
    public static function requireRole($required_role) {
        $current_user = self::authenticate();
        
        if ($current_user['role'] !== $required_role && $current_user['role'] !== 'admin') {
            Response::forbidden('Insufficient permissions');
        }
        
        return $current_user;
    }
    
    public static function requireAdmin() {
        return self::requireRole('admin');
    }
}
?>
