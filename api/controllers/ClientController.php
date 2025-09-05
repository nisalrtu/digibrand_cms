<?php
/**
 * Client Controller
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Client.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class ClientController {
    private $db;
    private $client;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->client = new Client($this->db);
    }
    
    public function getAll() {
        AuthMiddleware::authenticate();
        
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        $search = isset($_GET['search']) ? $_GET['search'] : '';
        $is_active = isset($_GET['is_active']) ? (bool)$_GET['is_active'] : null;
        
        $offset = ($page - 1) * $limit;
        
        $filters = [
            'limit' => $limit,
            'offset' => $offset
        ];
        
        if (!empty($search)) {
            $filters['search'] = $search;
        }
        
        if ($is_active !== null) {
            $filters['is_active'] = $is_active;
        }
        
        $clients = $this->client->getAll($filters);
        $total = $this->client->getCount($filters);
        
        Response::success([
            'clients' => $clients,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil($total / $limit)
        ], 'Clients retrieved successfully');
    }
    
    public function getById($id) {
        AuthMiddleware::authenticate();
        
        $client = $this->client->getById($id);
        
        if ($client) {
            Response::success($client, 'Client retrieved successfully');
        } else {
            Response::notFound('Client not found');
        }
    }
    
    public function create() {
        AuthMiddleware::authenticate();
        
        $data = json_decode(file_get_contents("php://input"), true);
        
        $required_fields = ['company_name', 'contact_person', 'mobile_number', 'address', 'city'];
        $errors = [];
        
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }
        
        if (!empty($errors)) {
            Response::validationError($errors);
        }
        
        $client_id = $this->client->create($data);
        
        if ($client_id) {
            $new_client = $this->client->getById($client_id);
            Response::created($new_client, 'Client created successfully');
        } else {
            Response::error('Failed to create client');
        }
    }
    
    public function update($id) {
        AuthMiddleware::authenticate();
        
        $data = json_decode(file_get_contents("php://input"), true);
        
        if ($this->client->update($id, $data)) {
            $updated_client = $this->client->getById($id);
            Response::success($updated_client, 'Client updated successfully');
        } else {
            Response::error('Failed to update client');
        }
    }
    
    public function delete($id) {
        AuthMiddleware::authenticate();
        
        if ($this->client->delete($id)) {
            Response::success(null, 'Client deleted successfully');
        } else {
            Response::error('Failed to delete client');
        }
    }
    
    public function toggleStatus($id) {
        AuthMiddleware::authenticate();
        
        if ($this->client->toggleStatus($id)) {
            $updated_client = $this->client->getById($id);
            Response::success($updated_client, 'Client status updated successfully');
        } else {
            Response::error('Failed to update client status');
        }
    }
    
    public function getStats($id) {
        AuthMiddleware::authenticate();
        
        $stats = $this->client->getStats($id);
        
        if ($stats !== false) {
            Response::success($stats, 'Client statistics retrieved successfully');
        } else {
            Response::error('Failed to retrieve client statistics');
        }
    }
}

// Handle the request
$controller = new ClientController();
$request_method = $_SERVER['REQUEST_METHOD'];
$path_info = $_SERVER['PATH_INFO'] ?? '';

// Extract ID from path if present
$path_parts = explode('/', trim($path_info, '/'));
$id = isset($path_parts[0]) && is_numeric($path_parts[0]) ? (int)$path_parts[0] : null;
$action = isset($path_parts[1]) ? $path_parts[1] : null;

switch ($request_method) {
    case 'GET':
        if ($id && $action === 'stats') {
            $controller->getStats($id);
        } elseif ($id) {
            $controller->getById($id);
        } else {
            $controller->getAll();
        }
        break;
        
    case 'POST':
        if (!$id) {
            $controller->create();
        } else {
            Response::error('Method not allowed for this endpoint', 405);
        }
        break;
        
    case 'PUT':
        if ($id && $action === 'toggle-status') {
            $controller->toggleStatus($id);
        } elseif ($id) {
            $controller->update($id);
        } else {
            Response::error('Client ID is required for update', 400);
        }
        break;
        
    case 'DELETE':
        if ($id) {
            $controller->delete($id);
        } else {
            Response::error('Client ID is required for delete', 400);
        }
        break;
        
    default:
        Response::error('Method not allowed', 405);
        break;
}
?>
