<?php
/**
 * Dashboard Controller
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class DashboardController {
    private $db;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }
    
    public function getStats() {
        AuthMiddleware::authenticate();
        
        $query = "SELECT 
                    (SELECT COUNT(*) FROM clients WHERE is_active = 1) as total_clients,
                    (SELECT COUNT(*) FROM projects) as total_projects,
                    (SELECT COUNT(*) FROM invoices) as total_invoices,
                    (SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE status = 'paid') as total_revenue,
                    (SELECT COALESCE(SUM(balance_amount), 0) FROM invoices WHERE status IN ('sent', 'partially_paid')) as pending_amount,
                    (SELECT COUNT(*) FROM invoices WHERE status = 'overdue' OR (due_date < CURDATE() AND status NOT IN ('paid', 'cancelled'))) as overdue_invoices";
        
        $stmt = $this->db->prepare($query);
        
        if ($stmt->execute()) {
            $stats = $stmt->fetch();
            Response::success($stats, 'Dashboard statistics retrieved successfully');
        } else {
            Response::error('Failed to retrieve dashboard statistics');
        }
    }
    
    public function getRecentActivity() {
        AuthMiddleware::authenticate();
        
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        
        $query = "
            (SELECT 'client' as type, CONCAT('New client: ', company_name) as title, 
                    CONCAT('Added by ', (SELECT full_name FROM users WHERE id = 1)) as description,
                    created_at as date, 'active' as status, id
             FROM clients ORDER BY created_at DESC LIMIT :limit)
            UNION ALL
            (SELECT 'project' as type, CONCAT('Project: ', project_name) as title,
                    CONCAT('Status: ', status) as description,
                    created_at as date, status, id
             FROM projects ORDER BY created_at DESC LIMIT :limit)
            UNION ALL
            (SELECT 'invoice' as type, CONCAT('Invoice: ', invoice_number) as title,
                    CONCAT('Amount: Rs. ', FORMAT(total_amount, 2)) as description,
                    created_at as date, status, id
             FROM invoices ORDER BY created_at DESC LIMIT :limit)
            UNION ALL
            (SELECT 'payment' as type, CONCAT('Payment received') as title,
                    CONCAT('Amount: Rs. ', FORMAT(payment_amount, 2)) as description,
                    created_at as date, 'paid' as status, id
             FROM payments ORDER BY created_at DESC LIMIT :limit)
            ORDER BY date DESC LIMIT :total_limit";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':total_limit', $limit, PDO::PARAM_INT);
        
        if ($stmt->execute()) {
            $activities = $stmt->fetchAll();
            Response::success($activities, 'Recent activity retrieved successfully');
        } else {
            Response::error('Failed to retrieve recent activity');
        }
    }
    
    public function getRevenueChart() {
        AuthMiddleware::authenticate();
        
        $period = isset($_GET['period']) ? $_GET['period'] : 'month';
        $date_format = '';
        $date_interval = '';
        
        switch ($period) {
            case 'week':
                $date_format = '%Y-%m-%d';
                $date_interval = 'INTERVAL 7 DAY';
                break;
            case 'quarter':
                $date_format = '%Y-%m';
                $date_interval = 'INTERVAL 3 MONTH';
                break;
            case 'year':
                $date_format = '%Y-%m';
                $date_interval = 'INTERVAL 12 MONTH';
                break;
            default: // month
                $date_format = '%Y-%m-%d';
                $date_interval = 'INTERVAL 30 DAY';
        }
        
        $query = "SELECT 
                    DATE_FORMAT(invoice_date, '$date_format') as date,
                    COALESCE(SUM(total_amount), 0) as amount
                  FROM invoices 
                  WHERE invoice_date >= DATE_SUB(CURDATE(), $date_interval)
                    AND status = 'paid'
                  GROUP BY DATE_FORMAT(invoice_date, '$date_format')
                  ORDER BY date ASC";
        
        $stmt = $this->db->prepare($query);
        
        if ($stmt->execute()) {
            $chart_data = $stmt->fetchAll();
            Response::success($chart_data, 'Revenue chart data retrieved successfully');
        } else {
            Response::error('Failed to retrieve revenue chart data');
        }
    }
    
    public function getInvoiceStatusBreakdown() {
        AuthMiddleware::authenticate();
        
        $query = "SELECT 
                    status,
                    COUNT(*) as count
                  FROM invoices 
                  GROUP BY status";
        
        $stmt = $this->db->prepare($query);
        
        if ($stmt->execute()) {
            $breakdown = [];
            while ($row = $stmt->fetch()) {
                $breakdown[$row['status']] = (int)$row['count'];
            }
            Response::success($breakdown, 'Invoice status breakdown retrieved successfully');
        } else {
            Response::error('Failed to retrieve invoice status breakdown');
        }
    }
    
    public function getTodayStats() {
        AuthMiddleware::authenticate();
        
        $query = "SELECT 
                    (SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE DATE(created_at) = CURDATE() AND status = 'paid') as today_revenue,
                    (SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE DATE(expense_date) = CURDATE()) as today_expenses,
                    (SELECT COUNT(*) FROM invoices WHERE DATE(created_at) = CURDATE()) as new_invoices,
                    (SELECT COUNT(*) FROM payments WHERE DATE(payment_date) = CURDATE()) as payments_received";
        
        $stmt = $this->db->prepare($query);
        
        if ($stmt->execute()) {
            $stats = $stmt->fetch();
            Response::success($stats, 'Today statistics retrieved successfully');
        } else {
            Response::error('Failed to retrieve today statistics');
        }
    }
}

// Handle the request
$controller = new DashboardController();
$request_method = $_SERVER['REQUEST_METHOD'];
$path_info = $_SERVER['PATH_INFO'] ?? '';

if ($request_method === 'GET') {
    switch ($path_info) {
        case '/stats':
            $controller->getStats();
            break;
        case '/activity':
            $controller->getRecentActivity();
            break;
        case '/revenue-chart':
            $controller->getRevenueChart();
            break;
        case '/invoice-status':
            $controller->getInvoiceStatusBreakdown();
            break;
        case '/today-stats':
            $controller->getTodayStats();
            break;
        default:
            Response::notFound('Dashboard endpoint not found');
            break;
    }
} else {
    Response::error('Method not allowed', 405);
}
?>
