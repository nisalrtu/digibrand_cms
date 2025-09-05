<?php
/**
 * Authentication Controller
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/JWTHelper.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class AuthController {
    private $db;
    private $user;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->user = new User($this->db);
    }
    
    public function login() {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['username']) || !isset($data['password'])) {
            Response::validationError(['username' => 'Username is required', 'password' => 'Password is required']);
        }
        
        $user = $this->user->login($data['username'], $data['password']);
        
        if ($user) {
            $token = JWTHelper::generateToken($user);
            
            Response::success([
                'user' => $user,
                'token' => [
                    'token' => $token,
                    'expires_in' => JWT_EXPIRY
                ]
            ], 'Login successful');
        } else {
            Response::unauthorized('Invalid credentials');
        }
    }
    
    public function logout() {
        // For JWT, logout is handled client-side by removing the token
        // Server-side logout would require token blacklisting
        Response::success(null, 'Logout successful');
    }
    
    public function profile() {
        $current_user = AuthMiddleware::authenticate();
        
        $user_data = $this->user->getById($current_user['user_id']);
        
        if ($user_data) {
            Response::success($user_data, 'Profile retrieved successfully');
        } else {
            Response::notFound('User not found');
        }
    }
    
    public function updateProfile() {
        $current_user = AuthMiddleware::authenticate();
        $data = json_decode(file_get_contents("php://input"), true);
        
        // Remove sensitive fields
        unset($data['id'], $data['password'], $data['role']);
        
        if ($this->user->update($current_user['user_id'], $data)) {
            $updated_user = $this->user->getById($current_user['user_id']);
            Response::success($updated_user, 'Profile updated successfully');
        } else {
            Response::error('Failed to update profile');
        }
    }
    
    public function changePassword() {
        $current_user = AuthMiddleware::authenticate();
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['current_password']) || !isset($data['new_password'])) {
            Response::validationError([
                'current_password' => 'Current password is required',
                'new_password' => 'New password is required'
            ]);
        }
        
        // Verify current password
        $user = $this->user->getById($current_user['user_id']);
        if (!$user || !password_verify($data['current_password'], $user['password'])) {
            Response::error('Current password is incorrect');
        }
        
        if ($this->user->changePassword($current_user['user_id'], $data['new_password'])) {
            Response::success(null, 'Password changed successfully');
        } else {
            Response::error('Failed to change password');
        }
    }
    
    public function register() {
        // Only admin can register new users
        AuthMiddleware::requireAdmin();
        
        $data = json_decode(file_get_contents("php://input"), true);
        
        $required_fields = ['username', 'email', 'password', 'full_name'];
        $errors = [];
        
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $errors[$field] = ucfirst($field) . ' is required';
            }
        }
        
        if (!empty($errors)) {
            Response::validationError($errors);
        }
        
        // Set default role
        if (!isset($data['role']) || !in_array($data['role'], ['admin', 'employee'])) {
            $data['role'] = 'employee';
        }
        
        $user_id = $this->user->create($data);
        
        if ($user_id) {
            $new_user = $this->user->getById($user_id);
            Response::created($new_user, 'User registered successfully');
        } else {
            Response::error('Failed to register user');
        }
    }
}

// Handle the request
$controller = new AuthController();
$request_method = $_SERVER['REQUEST_METHOD'];
$path_info = $_SERVER['PATH_INFO'] ?? '';

switch ($request_method) {
    case 'POST':
        if ($path_info === '/login') {
            $controller->login();
        } elseif ($path_info === '/logout') {
            $controller->logout();
        } elseif ($path_info === '/register') {
            $controller->register();
        } elseif ($path_info === '/change-password') {
            $controller->changePassword();
        } else {
            Response::notFound('Endpoint not found');
        }
        break;
        
    case 'GET':
        if ($path_info === '/profile') {
            $controller->profile();
        } else {
            Response::notFound('Endpoint not found');
        }
        break;
        
    case 'PUT':
        if ($path_info === '/profile') {
            $controller->updateProfile();
        } else {
            Response::notFound('Endpoint not found');
        }
        break;
        
    default:
        Response::error('Method not allowed', 405);
        break;
}
?>
