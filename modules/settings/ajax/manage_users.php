<?php
// modules/settings/ajax/manage_users.php
session_start();

require_once '../../../core/Database.php';
require_once '../../../core/Helper.php';

// Check if user is logged in and is admin
if (!Helper::isLoggedIn() || !Helper::hasRole('admin')) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Verify CSRF token
if (!Helper::verifyCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$database = new Database();
$db = $database->getConnection();

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'get_users':
        getUsers($db);
        break;
    case 'get_user':
        getUser($db);
        break;
    case 'create_user':
        createUser($db);
        break;
    case 'update_user':
        updateUser($db);
        break;
    case 'delete_user':
        deleteUser($db);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

function getUsers($db) {
    try {
        $query = "SELECT id, username, email, role, full_name, is_active, created_at 
                  FROM users ORDER BY created_at DESC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'users' => $users]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error fetching users: ' . $e->getMessage()]);
    }
}

function getUser($db) {
    $userId = (int)($_POST['user_id'] ?? 0);
    
    if (!$userId) {
        echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
        return;
    }
    
    try {
        $query = "SELECT id, username, email, role, full_name, is_active, created_at 
                  FROM users WHERE id = :user_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            echo json_encode(['success' => true, 'user' => $user]);
        } else {
            echo json_encode(['success' => false, 'message' => 'User not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error fetching user: ' . $e->getMessage()]);
    }
}

function createUser($db) {
    // Validate input
    $fullName = Helper::sanitize($_POST['full_name'] ?? '');
    $username = Helper::sanitize($_POST['username'] ?? '');
    $email = Helper::sanitize($_POST['email'] ?? '');
    $role = Helper::sanitize($_POST['role'] ?? 'employee');
    $password = $_POST['password'] ?? '';
    $isActive = (int)($_POST['is_active'] ?? 1);
    
    $errors = [];
    
    // Validation
    if (empty($fullName)) {
        $errors[] = 'Full name is required';
    } elseif (strlen($fullName) > 100) {
        $errors[] = 'Full name must be less than 100 characters';
    }
    
    if (empty($username)) {
        $errors[] = 'Username is required';
    } elseif (strlen($username) < 3 || strlen($username) > 50) {
        $errors[] = 'Username must be between 3 and 50 characters';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = 'Username can only contain letters, numbers, and underscores';
    }
    
    if (empty($email)) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    } elseif (strlen($email) > 100) {
        $errors[] = 'Email must be less than 100 characters';
    }
    
    if (!in_array($role, ['admin', 'employee'])) {
        $errors[] = 'Invalid role selected';
    }
    
    if (empty($password)) {
        $errors[] = 'Password is required';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters';
    }
    
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
        return;
    }
    
    try {
        // Check if username already exists
        $checkQuery = "SELECT id FROM users WHERE username = :username";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':username', $username);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() > 0) {
            echo json_encode(['success' => false, 'message' => 'Username already exists']);
            return;
        }
        
        // Check if email already exists
        $checkQuery = "SELECT id FROM users WHERE email = :email";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':email', $email);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() > 0) {
            echo json_encode(['success' => false, 'message' => 'Email already exists']);
            return;
        }
        
        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert user
        $insertQuery = "INSERT INTO users (username, email, password, role, full_name, is_active) 
                        VALUES (:username, :email, :password, :role, :full_name, :is_active)";
        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->bindParam(':username', $username);
        $insertStmt->bindParam(':email', $email);
        $insertStmt->bindParam(':password', $hashedPassword);
        $insertStmt->bindParam(':role', $role);
        $insertStmt->bindParam(':full_name', $fullName);
        $insertStmt->bindParam(':is_active', $isActive);
        
        if ($insertStmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'User created successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error creating user']);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error creating user: ' . $e->getMessage()]);
    }
}

function updateUser($db) {
    $userId = (int)($_POST['user_id'] ?? 0);
    
    if (!$userId) {
        echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
        return;
    }
    
    // Validate input
    $fullName = Helper::sanitize($_POST['full_name'] ?? '');
    $username = Helper::sanitize($_POST['username'] ?? '');
    $email = Helper::sanitize($_POST['email'] ?? '');
    $role = Helper::sanitize($_POST['role'] ?? 'employee');
    $isActive = (int)($_POST['is_active'] ?? 1);
    
    $errors = [];
    
    // Validation
    if (empty($fullName)) {
        $errors[] = 'Full name is required';
    } elseif (strlen($fullName) > 100) {
        $errors[] = 'Full name must be less than 100 characters';
    }
    
    if (empty($username)) {
        $errors[] = 'Username is required';
    } elseif (strlen($username) < 3 || strlen($username) > 50) {
        $errors[] = 'Username must be between 3 and 50 characters';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = 'Username can only contain letters, numbers, and underscores';
    }
    
    if (empty($email)) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    } elseif (strlen($email) > 100) {
        $errors[] = 'Email must be less than 100 characters';
    }
    
    if (!in_array($role, ['admin', 'employee'])) {
        $errors[] = 'Invalid role selected';
    }
    
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
        return;
    }
    
    try {
        // Check if username already exists for other users
        $checkQuery = "SELECT id FROM users WHERE username = :username AND id != :user_id";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':username', $username);
        $checkStmt->bindParam(':user_id', $userId);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() > 0) {
            echo json_encode(['success' => false, 'message' => 'Username already exists']);
            return;
        }
        
        // Check if email already exists for other users
        $checkQuery = "SELECT id FROM users WHERE email = :email AND id != :user_id";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':email', $email);
        $checkStmt->bindParam(':user_id', $userId);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() > 0) {
            echo json_encode(['success' => false, 'message' => 'Email already exists']);
            return;
        }
        
        // Update user
        $updateQuery = "UPDATE users SET username = :username, email = :email, role = :role, 
                        full_name = :full_name, is_active = :is_active, updated_at = CURRENT_TIMESTAMP 
                        WHERE id = :user_id";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindParam(':username', $username);
        $updateStmt->bindParam(':email', $email);
        $updateStmt->bindParam(':role', $role);
        $updateStmt->bindParam(':full_name', $fullName);
        $updateStmt->bindParam(':is_active', $isActive);
        $updateStmt->bindParam(':user_id', $userId);
        
        if ($updateStmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'User updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error updating user']);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error updating user: ' . $e->getMessage()]);
    }
}

function deleteUser($db) {
    $userId = (int)($_POST['user_id'] ?? 0);
    
    if (!$userId) {
        echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
        return;
    }
    
    // Prevent self-deletion
    if ($userId == $_SESSION['user_id']) {
        echo json_encode(['success' => false, 'message' => 'Cannot delete your own account']);
        return;
    }
    
    try {
        // Check if user exists
        $checkQuery = "SELECT id, username FROM users WHERE id = :user_id";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':user_id', $userId);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() === 0) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            return;
        }
        
        // Check if user has created any records (optional - for data integrity)
        $recordsQuery = "SELECT COUNT(*) as record_count FROM projects WHERE created_by = :user_id";
        $recordsStmt = $db->prepare($recordsQuery);
        $recordsStmt->bindParam(':user_id', $userId);
        $recordsStmt->execute();
        $recordsResult = $recordsStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($recordsResult['record_count'] > 0) {
            // Instead of deleting, deactivate the user
            $deactivateQuery = "UPDATE users SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :user_id";
            $deactivateStmt = $db->prepare($deactivateQuery);
            $deactivateStmt->bindParam(':user_id', $userId);
            
            if ($deactivateStmt->execute()) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'User has existing records and has been deactivated instead of deleted'
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error deactivating user']);
            }
        } else {
            // Safe to delete user
            $deleteQuery = "DELETE FROM users WHERE id = :user_id";
            $deleteStmt = $db->prepare($deleteQuery);
            $deleteStmt->bindParam(':user_id', $userId);
            
            if ($deleteStmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error deleting user']);
            }
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error deleting user: ' . $e->getMessage()]);
    }
}
?>