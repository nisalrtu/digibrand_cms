<?php
// modules/auth/AuthController.php

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

class AuthController {
    private $db;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }
    
    public function login($loginInput, $password) {
        try {
            // Check if login input is email or username
            $isEmail = filter_var($loginInput, FILTER_VALIDATE_EMAIL);
            
            if ($isEmail) {
                $query = "SELECT id, username, email, password, role, full_name, is_active 
                         FROM users WHERE email = :login AND is_active = 1";
            } else {
                $query = "SELECT id, username, email, password, role, full_name, is_active 
                         FROM users WHERE username = :login AND is_active = 1";
            }
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':login', $loginInput);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (password_verify($password, $user['password'])) {
                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['user_name'] = $user['full_name'];
                    $_SESSION['login_time'] = time();
                    
                    return ['success' => true, 'message' => 'Login successful'];
                } else {
                    return ['success' => false, 'message' => 'Invalid password'];
                }
            } else {
                return ['success' => false, 'message' => 'User not found or inactive'];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Login failed: ' . $e->getMessage()];
        }
    }
    
    public function logout() {
        session_unset();
        session_destroy();
        return true;
    }
    
    public function isLoggedIn() {
        return Helper::isLoggedIn();
    }
}

// Handle AJAX login requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    session_start();
    
    $loginInput = Helper::sanitize($_POST['login_input'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($loginInput) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Please fill all fields']);
        exit;
    }
    
    if (!Helper::verifyCSRF($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }
    
    $auth = new AuthController();
    $result = $auth->login($loginInput, $password);
    
    echo json_encode($result);
    exit;
}
?>