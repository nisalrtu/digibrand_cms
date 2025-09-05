<?php
/**
 * API Router - Main Entry Point
 */

require_once __DIR__ . '/config/config.php';

// Get the request URI and method
$request_uri = $_SERVER['REQUEST_URI'];
$request_method = $_SERVER['REQUEST_METHOD'];

// Remove query string from URI
$request_uri = strtok($request_uri, '?');

// Remove /api from the beginning if present
$request_uri = preg_replace('/^\/api/', '', $request_uri);

// Route the request
if (preg_match('/^\/auth/', $request_uri)) {
    // Authentication routes
    $_SERVER['PATH_INFO'] = preg_replace('/^\/auth/', '', $request_uri);
    require_once __DIR__ . '/controllers/AuthController.php';
    
} elseif (preg_match('/^\/clients/', $request_uri)) {
    // Client routes
    $_SERVER['PATH_INFO'] = preg_replace('/^\/clients/', '', $request_uri);
    require_once __DIR__ . '/controllers/ClientController.php';
    
} elseif (preg_match('/^\/projects/', $request_uri)) {
    // Project routes
    $_SERVER['PATH_INFO'] = preg_replace('/^\/projects/', '', $request_uri);
    require_once __DIR__ . '/controllers/ProjectController.php';
    
} elseif (preg_match('/^\/invoices/', $request_uri)) {
    // Invoice routes
    $_SERVER['PATH_INFO'] = preg_replace('/^\/invoices/', '', $request_uri);
    require_once __DIR__ . '/controllers/InvoiceController.php';
    
} elseif (preg_match('/^\/payments/', $request_uri)) {
    // Payment routes
    $_SERVER['PATH_INFO'] = preg_replace('/^\/payments/', '', $request_uri);
    require_once __DIR__ . '/controllers/PaymentController.php';
    
} elseif (preg_match('/^\/expenses/', $request_uri)) {
    // Expense routes
    $_SERVER['PATH_INFO'] = preg_replace('/^\/expenses/', '', $request_uri);
    require_once __DIR__ . '/controllers/ExpenseController.php';
    
} elseif (preg_match('/^\/dashboard/', $request_uri)) {
    // Dashboard routes
    $_SERVER['PATH_INFO'] = preg_replace('/^\/dashboard/', '', $request_uri);
    require_once __DIR__ . '/controllers/DashboardController.php';
    
} elseif ($request_uri === '/' || $request_uri === '') {
    // API info endpoint
    require_once __DIR__ . '/utils/Response.php';
    Response::success([
        'name' => 'DigiBrand CRM API',
        'version' => API_VERSION,
        'endpoints' => [
            'auth' => [
                'POST /auth/login',
                'POST /auth/logout',
                'GET /auth/profile',
                'PUT /auth/profile',
                'POST /auth/change-password',
                'POST /auth/register'
            ],
            'clients' => [
                'GET /clients',
                'GET /clients/{id}',
                'POST /clients',
                'PUT /clients/{id}',
                'DELETE /clients/{id}',
                'PUT /clients/{id}/toggle-status',
                'GET /clients/{id}/stats'
            ],
            'projects' => [
                'GET /projects',
                'GET /projects/{id}',
                'POST /projects',
                'PUT /projects/{id}',
                'DELETE /projects/{id}',
                'PUT /projects/{id}/status'
            ],
            'invoices' => [
                'GET /invoices',
                'GET /invoices/{id}',
                'POST /invoices',
                'PUT /invoices/{id}',
                'DELETE /invoices/{id}',
                'POST /invoices/{id}/generate-pdf',
                'POST /invoices/{id}/send-email'
            ],
            'payments' => [
                'GET /payments',
                'GET /payments/{id}',
                'POST /payments',
                'PUT /payments/{id}',
                'DELETE /payments/{id}'
            ],
            'expenses' => [
                'GET /expenses',
                'GET /expenses/{id}',
                'POST /expenses',
                'PUT /expenses/{id}',
                'DELETE /expenses/{id}'
            ],
            'dashboard' => [
                'GET /dashboard/stats',
                'GET /dashboard/activity'
            ]
        ]
    ], 'DigiBrand CRM API');
    
} else {
    // 404 - Endpoint not found
    require_once __DIR__ . '/utils/Response.php';
    Response::notFound('API endpoint not found');
}
?>
