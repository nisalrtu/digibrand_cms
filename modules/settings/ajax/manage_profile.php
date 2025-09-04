<?php
// modules/settings/ajax/manage_profile.php
session_start();

require_once '../../../core/Database.php';
require_once '../../../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
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

if ($action === 'update_profile') {
    updateProfile($db);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function updateProfile($db) {
    $userId = $_SESSION['user_id'];
    
    // Validate input
    $fullName = Helper::sanitize($_POST['full_name'] ?? '');
    $username = Helper::sanitize($_POST['username'] ?? '');
    $email = Helper::sanitize($_POST['email'] ?? '');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
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
    
    // Password validation (only if user wants to change password)
    $passwordUpdate = false;
    if (!empty($currentPassword) || !empty($newPassword) || !empty($confirmPassword)) {
        $passwordUpdate = true;
        
        if (empty($currentPassword)) {
            $errors[] = 'Current password is required to change password';
        }
        
        if (empty($newPassword)) {
            $errors[] = 'New password is required';
        } elseif (strlen($newPassword) < 6) {
            $errors[] = 'New password must be at least 6 characters';
        }
        
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'New password and confirm password do not match';
        }
    }
    
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
        return;
    }
    
    try {
        // If password update is requested, verify current password first
        if ($passwordUpdate) {
            $passwordQuery = "SELECT password FROM users WHERE id = :user_id";
            $passwordStmt = $db->prepare($passwordQuery);
            $passwordStmt->bindParam(':user_id', $userId);
            $passwordStmt->execute();
            $userPasswordData = $passwordStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$userPasswordData || !password_verify($currentPassword, $userPasswordData['password'])) {
                echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
                return;
            }
        }
        
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
        
        // Prepare update query
        if ($passwordUpdate) {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $updateQuery = "UPDATE users SET username = :username, email = :email, full_name = :full_name, 
                            password = :password, updated_at = CURRENT_TIMESTAMP WHERE id = :user_id";
        } else {
            $updateQuery = "UPDATE users SET username = :username, email = :email, full_name = :full_name, 
                            updated_at = CURRENT_TIMESTAMP WHERE id = :user_id";
        }
        
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindParam(':username', $username);
        $updateStmt->bindParam(':email', $email);
        $updateStmt->bindParam(':full_name', $fullName);
        $updateStmt->bindParam(':user_id', $userId);
        
        if ($passwordUpdate) {
            $updateStmt->bindParam(':password', $hashedPassword);
        }
        
        if ($updateStmt->execute()) {
            // Update session variables
            $sessionUpdated = false;
            if ($_SESSION['username'] !== $username) {
                $_SESSION['username'] = $username;
                $sessionUpdated = true;
            }
            if ($_SESSION['user_email'] !== $email) {
                $_SESSION['user_email'] = $email;
                $sessionUpdated = true;
            }
            if ($_SESSION['user_name'] !== $fullName) {
                $_SESSION['user_name'] = $fullName;
                $sessionUpdated = true;
            }
            
            $message = 'Profile updated successfully';
            if ($passwordUpdate) {
                $message .= ' and password changed';
            }
            
            echo json_encode([
                'success' => true, 
                'message' => $message,
                'updated_data' => $sessionUpdated
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error updating profile']);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error updating profile: ' . $e->getMessage()]);
    }
}
?>