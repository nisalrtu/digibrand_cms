<?php
// api/get_all_projects.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

session_start();

require_once '../core/Database.php';
require_once '../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get and validate parameters
$clientId = Helper::decryptId($_GET['client_id'] ?? '');
$offset = max(0, intval($_GET['offset'] ?? 0));
$limit = min(50, max(1, intval($_GET['limit'] ?? 10))); // Max 50 projects per request

if (!$clientId) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid client ID']);
    exit;
}

try {
    // Get database connection
    $database = new Database();
    $db = $database->getConnection();
    
    // Verify client exists and user has access
    $clientQuery = "SELECT id FROM clients WHERE id = :client_id AND is_active = 1";
    $clientStmt = $db->prepare($clientQuery);
    $clientStmt->bindParam(':client_id', $clientId);
    $clientStmt->execute();
    
    if (!$clientStmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Client not found']);
        exit;
    }
    
    // Get projects with pagination
    $projectsQuery = "
        SELECT p.*, 
               (SELECT COUNT(*) FROM project_items WHERE project_id = p.id) as items_count
        FROM projects p 
        WHERE p.client_id = :client_id 
        ORDER BY p.created_at DESC 
        LIMIT :limit OFFSET :offset
    ";
    
    $projectsStmt = $db->prepare($projectsQuery);
    $projectsStmt->bindParam(':client_id', $clientId, PDO::PARAM_INT);
    $projectsStmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $projectsStmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $projectsStmt->execute();
    
    $projects = $projectsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count for pagination info
    $countQuery = "SELECT COUNT(*) as total FROM projects WHERE client_id = :client_id";
    $countStmt = $db->prepare($countQuery);
    $countStmt->bindParam(':client_id', $clientId, PDO::PARAM_INT);
    $countStmt->execute();
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Format projects data
    $formattedProjects = [];
    foreach ($projects as $project) {
        $formattedProjects[] = [
            'id' => $project['id'],
            'encrypted_id' => Helper::encryptId($project['id']),
            'project_name' => htmlspecialchars($project['project_name']),
            'project_type' => $project['project_type'],
            'project_type_formatted' => ucwords(str_replace('_', ' ', $project['project_type'])),
            'status' => $project['status'],
            'status_formatted' => ucwords(str_replace('_', ' ', $project['status'])),
            'status_class' => str_replace('_', '-', $project['status']),
            'total_amount' => $project['total_amount'],
            'total_amount_formatted' => Helper::formatCurrency($project['total_amount']),
            'items_count' => intval($project['items_count']),
            'created_at' => $project['created_at'],
            'created_at_formatted' => Helper::formatDate($project['created_at'], 'M j, Y'),
            'view_url' => Helper::baseUrl('modules/projects/view.php?id=' . Helper::encryptId($project['id']))
        ];
    }
    
    // Prepare response
    $response = [
        'success' => true,
        'data' => [
            'projects' => $formattedProjects,
            'pagination' => [
                'offset' => $offset,
                'limit' => $limit,
                'total' => intval($totalCount),
                'has_more' => ($offset + $limit) < $totalCount,
                'current_page' => floor($offset / $limit) + 1,
                'total_pages' => ceil($totalCount / $limit)
            ]
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log('API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?>
