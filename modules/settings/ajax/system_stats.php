<?php
// modules/settings/ajax/system_stats.php
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

if ($action === 'get_stats') {
    getSystemStats($db);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function getSystemStats($db) {
    try {
        $stats = [];
        
        // Get active users count
        $userQuery = "SELECT COUNT(*) as count FROM users WHERE is_active = 1";
        $userStmt = $db->prepare($userQuery);
        $userStmt->execute();
        $userResult = $userStmt->fetch(PDO::FETCH_ASSOC);
        $stats['active_users'] = $userResult['count'] ?? 0;
        
        // Get total clients count
        $clientQuery = "SELECT COUNT(*) as count FROM clients WHERE is_active = 1";
        $clientStmt = $db->prepare($clientQuery);
        $clientStmt->execute();
        $clientResult = $clientStmt->fetch(PDO::FETCH_ASSOC);
        $stats['total_clients'] = $clientResult['count'] ?? 0;
        
        // Get active projects count
        $projectQuery = "SELECT COUNT(*) as count FROM projects WHERE status IN ('pending', 'in_progress')";
        $projectStmt = $db->prepare($projectQuery);
        $projectStmt->execute();
        $projectResult = $projectStmt->fetch(PDO::FETCH_ASSOC);
        $stats['active_projects'] = $projectResult['count'] ?? 0;
        
        // Get total invoices count (optional)
        try {
            $invoiceQuery = "SELECT COUNT(*) as count FROM invoices";
            $invoiceStmt = $db->prepare($invoiceQuery);
            $invoiceStmt->execute();
            $invoiceResult = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
            $stats['total_invoices'] = $invoiceResult['count'] ?? 0;
        } catch (Exception $e) {
            $stats['total_invoices'] = 0;
        }
        
        // Get pending payments count (optional)
        try {
            $paymentQuery = "SELECT COUNT(*) as count FROM invoices WHERE status = 'pending'";
            $paymentStmt = $db->prepare($paymentQuery);
            $paymentStmt->execute();
            $paymentResult = $paymentStmt->fetch(PDO::FETCH_ASSOC);
            $stats['pending_payments'] = $paymentResult['count'] ?? 0;
        } catch (Exception $e) {
            $stats['pending_payments'] = 0;
        }
        
        // Get recent activity count (last 30 days)
        $activityQuery = "SELECT 
            (SELECT COUNT(*) FROM projects WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as new_projects,
            (SELECT COUNT(*) FROM clients WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as new_clients";
        $activityStmt = $db->prepare($activityQuery);
        $activityStmt->execute();
        $activityResult = $activityStmt->fetch(PDO::FETCH_ASSOC);
        $stats['new_projects_30d'] = $activityResult['new_projects'] ?? 0;
        $stats['new_clients_30d'] = $activityResult['new_clients'] ?? 0;
        
        echo json_encode(['success' => true, 'stats' => $stats]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error fetching system stats: ' . $e->getMessage()]);
    }
}
?>