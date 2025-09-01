<?php
// modules/invoices/index.php
session_start();

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

$pageTitle = 'Invoices - Invoice Manager';

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Initialize filters and search
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$clientFilter = $_GET['client'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$sortBy = $_GET['sort'] ?? 'created_at';
$sortOrder = $_GET['order'] ?? 'DESC';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Valid sort columns
$validSortColumns = ['invoice_number', 'invoice_date', 'due_date', 'total_amount', 'balance_amount', 'status', 'company_name', 'created_at'];
if (!in_array($sortBy, $validSortColumns)) {
    $sortBy = 'created_at';
}

$validSortOrders = ['ASC', 'DESC'];
if (!in_array($sortOrder, $validSortOrders)) {
    $sortOrder = 'DESC';
}

try {
    // Build base query
    $whereConditions = [];
    $params = [];
    
    // Search filter
    if (!empty($search)) {
        $whereConditions[] = "(i.invoice_number LIKE :search OR c.company_name LIKE :search OR p.project_name LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    // Status filter
    if (!empty($statusFilter)) {
        $whereConditions[] = "i.status = :status";
        $params[':status'] = $statusFilter;
    }
    
    // Client filter
    if (!empty($clientFilter)) {
        $clientId = Helper::decryptId($clientFilter);
        if ($clientId) {
            $whereConditions[] = "i.client_id = :client_id";
            $params[':client_id'] = $clientId;
        }
    }
    
    // Date range filter
    if (!empty($dateFrom)) {
        $whereConditions[] = "i.invoice_date >= :date_from";
        $params[':date_from'] = $dateFrom;
    }
    
    if (!empty($dateTo)) {
        $whereConditions[] = "i.invoice_date <= :date_to";
        $params[':date_to'] = $dateTo;
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Get total count
    $countQuery = "
        SELECT COUNT(i.id) as total
        FROM invoices i 
        LEFT JOIN clients c ON i.client_id = c.id 
        LEFT JOIN projects p ON i.project_id = p.id
        $whereClause
    ";
    $countStmt = $db->prepare($countQuery);
    $countStmt->execute($params);
    $totalInvoices = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalInvoices / $perPage);
    
    // Get invoices
    $invoicesQuery = "
        SELECT i.*, 
               c.company_name, c.contact_person,
               p.project_name, p.project_type,
               u.username as created_by_name
        FROM invoices i 
        LEFT JOIN clients c ON i.client_id = c.id 
        LEFT JOIN projects p ON i.project_id = p.id
        LEFT JOIN users u ON i.created_by = u.id
        $whereClause
        ORDER BY $sortBy $sortOrder
        LIMIT $perPage OFFSET $offset
    ";
    $invoicesStmt = $db->prepare($invoicesQuery);
    $invoicesStmt->execute($params);
    $invoices = $invoicesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get clients for filter dropdown
    $clientsQuery = "
        SELECT id, company_name 
        FROM clients 
        WHERE is_active = 1 
        ORDER BY company_name ASC
    ";
    $clientsStmt = $db->prepare($clientsQuery);
    $clientsStmt->execute();
    $clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    Helper::setMessage('Error loading invoices: ' . $e->getMessage(), 'error');
    $invoices = [];
    $clients = [];
    $totalPages = 0;
    $totalInvoices = 0;
}

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="mb-8">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Invoices</h1>
            <p class="text-gray-600 mt-1">Manage and track all your invoices</p>
        </div>
        <div class="flex items-center space-x-3">
            <a href="<?php echo Helper::baseUrl('modules/invoices/create.php'); ?>" 
               class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Create Invoice
            </a>
        </div>
    </div>
</div>

<!-- Filters and Search -->
<div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
    <form method="GET" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- Search -->
            <div>
                <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                <div class="relative">
                    <input type="text" name="search" id="search" 
                           value="<?php echo htmlspecialchars($search); ?>"
                           placeholder="Invoice number, client, project..."
                           class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Status Filter -->
            <div>
                <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select name="status" id="status" 
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Statuses</option>
                    <option value="draft" <?php echo $statusFilter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="sent" <?php echo $statusFilter === 'sent' ? 'selected' : ''; ?>>Sent</option>
                    <option value="partially_paid" <?php echo $statusFilter === 'partially_paid' ? 'selected' : ''; ?>>Partially Paid</option>
                    <option value="paid" <?php echo $statusFilter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                    <option value="overdue" <?php echo $statusFilter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                </select>
            </div>

            <!-- Client Filter -->
            <div>
                <label for="client" class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                <select name="client" id="client" 
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Clients</option>
                    <?php foreach ($clients as $client): ?>
                        <option value="<?php echo Helper::encryptId($client['id']); ?>" 
                                <?php echo $clientFilter === Helper::encryptId($client['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($client['company_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Date Range -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date Range</label>
                <div class="flex space-x-2">
                    <input type="date" name="date_from" 
                           value="<?php echo htmlspecialchars($dateFrom); ?>"
                           class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <input type="date" name="date_to" 
                           value="<?php echo htmlspecialchars($dateTo); ?>"
                           class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>
        </div>

        <div class="flex flex-col sm:flex-row gap-3">
            <button type="submit" 
                    class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.414A1 1 0 013 6.707V4z"></path>
                </svg>
                Apply Filters
            </button>
            
            <a href="<?php echo Helper::baseUrl('modules/invoices/'); ?>" 
               class="inline-flex items-center px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                </svg>
                Clear Filters
            </a>
        </div>

        <!-- Hidden sort parameters -->
        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sortBy); ?>">
        <input type="hidden" name="order" value="<?php echo htmlspecialchars($sortOrder); ?>">
    </form>
</div>

<!-- Invoices Table -->
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <!-- Desktop Table -->
    <div class="hidden lg:block overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left">
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'invoice_number', 'order' => $sortBy === 'invoice_number' && $sortOrder === 'ASC' ? 'DESC' : 'ASC'])); ?>" 
                           class="group inline-flex items-center text-xs font-medium text-gray-500 uppercase tracking-wider hover:text-gray-700">
                            Invoice #
                            <?php if ($sortBy === 'invoice_number'): ?>
                                <svg class="ml-2 h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <?php if ($sortOrder === 'ASC'): ?>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path>
                                    <?php else: ?>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    <?php endif; ?>
                                </svg>
                            <?php endif; ?>
                        </a>
                    </th>
                    <th class="px-6 py-3 text-left">
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'company_name', 'order' => $sortBy === 'company_name' && $sortOrder === 'ASC' ? 'DESC' : 'ASC'])); ?>" 
                           class="group inline-flex items-center text-xs font-medium text-gray-500 uppercase tracking-wider hover:text-gray-700">
                            Client
                            <?php if ($sortBy === 'company_name'): ?>
                                <svg class="ml-2 h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <?php if ($sortOrder === 'ASC'): ?>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path>
                                    <?php else: ?>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    <?php endif; ?>
                                </svg>
                            <?php endif; ?>
                        </a>
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Project</th>
                    <th class="px-6 py-3 text-left">
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'invoice_date', 'order' => $sortBy === 'invoice_date' && $sortOrder === 'ASC' ? 'DESC' : 'ASC'])); ?>" 
                           class="group inline-flex items-center text-xs font-medium text-gray-500 uppercase tracking-wider hover:text-gray-700">
                            Date
                            <?php if ($sortBy === 'invoice_date'): ?>
                                <svg class="ml-2 h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <?php if ($sortOrder === 'ASC'): ?>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path>
                                    <?php else: ?>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    <?php endif; ?>
                                </svg>
                            <?php endif; ?>
                        </a>
                    </th>
                    <th class="px-6 py-3 text-right">
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'total_amount', 'order' => $sortBy === 'total_amount' && $sortOrder === 'ASC' ? 'DESC' : 'ASC'])); ?>" 
                           class="group inline-flex items-center text-xs font-medium text-gray-500 uppercase tracking-wider hover:text-gray-700">
                            Total
                            <?php if ($sortBy === 'total_amount'): ?>
                                <svg class="ml-2 h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <?php if ($sortOrder === 'ASC'): ?>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path>
                                    <?php else: ?>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    <?php endif; ?>
                                </svg>
                            <?php endif; ?>
                        </a>
                    </th>
                    <th class="px-6 py-3 text-right">
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'balance_amount', 'order' => $sortBy === 'balance_amount' && $sortOrder === 'ASC' ? 'DESC' : 'ASC'])); ?>" 
                           class="group inline-flex items-center text-xs font-medium text-gray-500 uppercase tracking-wider hover:text-gray-700">
                            Balance
                            <?php if ($sortBy === 'balance_amount'): ?>
                                <svg class="ml-2 h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <?php if ($sortOrder === 'ASC'): ?>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path>
                                    <?php else: ?>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    <?php endif; ?>
                                </svg>
                            <?php endif; ?>
                        </a>
                    </th>
                    <th class="px-6 py-3 text-left">
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'status', 'order' => $sortBy === 'status' && $sortOrder === 'ASC' ? 'DESC' : 'ASC'])); ?>" 
                           class="group inline-flex items-center text-xs font-medium text-gray-500 uppercase tracking-wider hover:text-gray-700">
                            Status
                            <?php if ($sortBy === 'status'): ?>
                                <svg class="ml-2 h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <?php if ($sortOrder === 'ASC'): ?>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path>
                                    <?php else: ?>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    <?php endif; ?>
                                </svg>
                            <?php endif; ?>
                        </a>
                    </th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($invoices)): ?>
                    <tr>
                        <td colspan="8" class="px-6 py-12 text-center">
                            <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                            </svg>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">No invoices found</h3>
                            <p class="text-gray-600 mb-4">Get started by creating your first invoice.</p>
                            <a href="<?php echo Helper::baseUrl('modules/invoices/create.php'); ?>" 
                               class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                </svg>
                                Create Invoice
                            </a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($invoices as $invoice): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900">
                                            <a href="<?php echo Helper::baseUrl('modules/invoices/view.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
                                               class="hover:text-blue-600 transition-colors">
                                                #<?php echo htmlspecialchars($invoice['invoice_number']); ?>
                                            </a>
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            <?php echo Helper::formatDate($invoice['due_date'], 'M j, Y'); ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($invoice['company_name']); ?></div>
                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($invoice['contact_person']); ?></div>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($invoice['project_name']): ?>
                                    <div class="text-sm text-gray-900"><?php echo htmlspecialchars($invoice['project_name']); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo ucfirst(str_replace('_', ' ', $invoice['project_type'])); ?></div>
                                <?php else: ?>
                                    <span class="text-sm text-gray-400">No project</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo Helper::formatDate($invoice['invoice_date'], 'M j, Y'); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right font-medium">
                                <?php echo Helper::formatCurrency($invoice['total_amount']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                <?php if ($invoice['balance_amount'] > 0): ?>
                                    <span class="font-medium text-red-600"><?php echo Helper::formatCurrency($invoice['balance_amount']); ?></span>
                                <?php else: ?>
                                    <span class="font-medium text-green-600">Paid</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php 
                                $status = $invoice['status'];
                                $statusClasses = [
                                    'draft' => 'bg-gray-100 text-gray-800',
                                    'sent' => 'bg-blue-100 text-blue-800',
                                    'paid' => 'bg-green-100 text-green-800',
                                    'partially_paid' => 'bg-yellow-100 text-yellow-800',
                                    'overdue' => 'bg-red-100 text-red-800'
                                ];
                                $badgeClass = $statusClasses[$status] ?? 'bg-gray-100 text-gray-800';
                                ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $badgeClass; ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $status)); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex items-center justify-end space-x-2">
                                    <a href="<?php echo Helper::baseUrl('modules/invoices/view.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
                                       class="text-blue-600 hover:text-blue-900 transition-colors">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                        </svg>
                                    </a>
                                    <a href="<?php echo Helper::baseUrl('modules/invoices/edit.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
                                       class="text-gray-600 hover:text-gray-900 transition-colors">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                        </svg>
                                    </a>
                                    <?php if ($invoice['balance_amount'] > 0): ?>
                                        <a href="<?php echo Helper::baseUrl('modules/payments/create.php?invoice_id=' . Helper::encryptId($invoice['id'])); ?>" 
                                           class="text-green-600 hover:text-green-900 transition-colors" title="Record Payment">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                            </svg>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile Cards -->
    <div class="lg:hidden divide-y divide-gray-200">
        <?php if (empty($invoices)): ?>
            <div class="p-6 text-center">
                <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No invoices found</h3>
                <p class="text-gray-600 mb-4">Get started by creating your first invoice.</p>
                <a href="<?php echo Helper::baseUrl('modules/invoices/create.php'); ?>" 
                   class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                    </svg>
                    Create Invoice
                </a>
            </div>
        <?php else: ?>
            <?php foreach ($invoices as $invoice): ?>
                <div class="p-6">
                    <div class="flex items-start justify-between mb-3">
                        <div>
                            <h3 class="text-lg font-medium text-gray-900">
                                <a href="<?php echo Helper::baseUrl('modules/invoices/view.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
                                   class="hover:text-blue-600 transition-colors">
                                    #<?php echo htmlspecialchars($invoice['invoice_number']); ?>
                                </a>
                            </h3>
                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($invoice['company_name']); ?></p>
                        </div>
                        <?php 
                        $status = $invoice['status'];
                        $statusClasses = [
                            'draft' => 'bg-gray-100 text-gray-800',
                            'sent' => 'bg-blue-100 text-blue-800',
                            'paid' => 'bg-green-100 text-green-800',
                            'partially_paid' => 'bg-yellow-100 text-yellow-800',
                            'overdue' => 'bg-red-100 text-red-800'
                        ];
                        $badgeClass = $statusClasses[$status] ?? 'bg-gray-100 text-gray-800';
                        ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $badgeClass; ?>">
                            <?php echo ucwords(str_replace('_', ' ', $status)); ?>
                        </span>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 text-sm mb-4">
                        <div>
                            <span class="text-gray-500">Date:</span>
                            <span class="font-medium"><?php echo Helper::formatDate($invoice['invoice_date'], 'M j, Y'); ?></span>
                        </div>
                        <div>
                            <span class="text-gray-500">Due:</span>
                            <span class="font-medium"><?php echo Helper::formatDate($invoice['due_date'], 'M j, Y'); ?></span>
                        </div>
                        <div>
                            <span class="text-gray-500">Total:</span>
                            <span class="font-medium"><?php echo Helper::formatCurrency($invoice['total_amount']); ?></span>
                        </div>
                        <div>
                            <span class="text-gray-500">Balance:</span>
                            <?php if ($invoice['balance_amount'] > 0): ?>
                                <span class="font-medium text-red-600"><?php echo Helper::formatCurrency($invoice['balance_amount']); ?></span>
                            <?php else: ?>
                                <span class="font-medium text-green-600">Paid</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="flex items-center justify-between">
                        <?php if ($invoice['project_name']): ?>
                            <span class="text-sm text-gray-600"><?php echo htmlspecialchars($invoice['project_name']); ?></span>
                        <?php else: ?>
                            <span class="text-sm text-gray-400">No project</span>
                        <?php endif; ?>
                        
                        <div class="flex items-center space-x-3">
                            <a href="<?php echo Helper::baseUrl('modules/invoices/view.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
                               class="text-blue-600 hover:text-blue-900 transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                </svg>
                            </a>
                            <a href="<?php echo Helper::baseUrl('modules/invoices/edit.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
                               class="text-gray-600 hover:text-gray-900 transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                </svg>
                            </a>
                            <?php if ($invoice['balance_amount'] > 0): ?>
                                <a href="<?php echo Helper::baseUrl('modules/payments/create.php?invoice_id=' . Helper::encryptId($invoice['id'])); ?>" 
                                   class="text-green-600 hover:text-green-900 transition-colors">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                    </svg>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
    <div class="mt-6 flex items-center justify-between">
        <div class="flex-1 flex justify-between sm:hidden">
            <?php if ($page > 1): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                   class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    Previous
                </a>
            <?php endif; ?>
            
            <?php if ($page < $totalPages): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                   class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    Next
                </a>
            <?php endif; ?>
        </div>
        
        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
            <div>
                <p class="text-sm text-gray-700">
                    Showing <span class="font-medium"><?php echo (($page - 1) * $perPage) + 1; ?></span> 
                    to <span class="font-medium"><?php echo min($page * $perPage, $totalInvoices); ?></span> 
                    of <span class="font-medium"><?php echo $totalInvoices; ?></span> results
                </p>
            </div>
            
            <div>
                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                           class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                            <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                            </svg>
                        </a>
                    <?php endif; ?>
                    
                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <?php if ($i == $page): ?>
                            <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-blue-50 text-sm font-medium text-blue-600">
                                <?php echo $i; ?>
                            </span>
                        <?php else: ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                               class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">
                                <?php echo $i; ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                           class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                            <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                            </svg>
                        </a>
                    <?php endif; ?>
                </nav>
            </div>
        </div>
    </div>
<?php endif; ?>

<style>
/* Custom styles for better UX */
.table-hover tbody tr:hover {
    background-color: #f9fafb;
}

/* Status badge animations */
.status-badge {
    transition: all 0.2s ease-in-out;
}

.status-badge:hover {
    transform: scale(1.05);
}

/* Sortable column headers */
th a:hover {
    background-color: rgba(59, 130, 246, 0.05);
    border-radius: 4px;
    padding: 4px;
}

/* Mobile card hover effect */
@media (max-width: 1024px) {
    .mobile-card:hover {
        background-color: #f9fafb;
        transform: translateY(-1px);
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }
}

/* Loading states */
.loading {
    opacity: 0.6;
    pointer-events: none;
}

/* Pagination active state */
.pagination-active {
    background-color: #3b82f6 !important;
    color: white !important;
    border-color: #3b82f6 !important;
}

/* Custom scrollbar for table */
.overflow-x-auto::-webkit-scrollbar {
    height: 8px;
}

.overflow-x-auto::-webkit-scrollbar-track {
    background: #f1f5f9;
}

.overflow-x-auto::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}

.overflow-x-auto::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}
</style>

<script>
// Auto-submit form on filter change
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    const autoSubmitFields = ['status', 'client'];
    
    autoSubmitFields.forEach(fieldName => {
        const field = document.querySelector(`[name="${fieldName}"]`);
        if (field) {
            field.addEventListener('change', function() {
                form.submit();
            });
        }
    });
    
    // Date range auto-submit with slight delay
    const dateFields = document.querySelectorAll('input[type="date"]');
    dateFields.forEach(field => {
        field.addEventListener('change', function() {
            setTimeout(() => {
                form.submit();
            }, 500);
        });
    });
});

// Clear search on escape
document.getElementById('search').addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        this.value = '';
        this.form.submit();
    }
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl/Cmd + K to focus search
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        document.getElementById('search').focus();
    }
    
    // Ctrl/Cmd + N to create new invoice
    if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
        e.preventDefault();
        window.location.href = '<?php echo Helper::baseUrl('modules/invoices/create.php'); ?>';
    }
});

// Enhanced table interactions
document.querySelectorAll('tbody tr').forEach(row => {
    // Click anywhere on row to view invoice (except action buttons)
    row.addEventListener('click', function(e) {
        if (!e.target.closest('a') && !e.target.closest('button')) {
            const viewLink = this.querySelector('a[href*="view.php"]');
            if (viewLink) {
                window.location.href = viewLink.href;
            }
        }
    });
    
    // Add hover cursor
    row.style.cursor = 'pointer';
});

// Status filter quick buttons
function filterByStatus(status) {
    const statusSelect = document.getElementById('status');
    statusSelect.value = status;
    statusSelect.form.submit();
}

// Auto-refresh for real-time updates (optional)
let autoRefreshEnabled = false;
let autoRefreshInterval;

function toggleAutoRefresh() {
    autoRefreshEnabled = !autoRefreshEnabled;
    
    if (autoRefreshEnabled) {
        autoRefreshInterval = setInterval(() => {
            // Only refresh if no form inputs are focused
            if (!document.querySelector('input:focus, select:focus, textarea:focus')) {
                window.location.reload();
            }
        }, 60000); // Refresh every minute
        
        console.log('Auto-refresh enabled');
    } else {
        clearInterval(autoRefreshInterval);
        console.log('Auto-refresh disabled');
    }
}

// Bulk actions (future enhancement)
function selectAllInvoices() {
    // Implementation for bulk operations
    console.log('Bulk selection would be implemented here');
}

// Export functionality (future enhancement)
function exportInvoices(format) {
    // Implementation for export (CSV, PDF, Excel)
    console.log(`Export to ${format} would be implemented here`);
}

// Print invoice list
function printInvoiceList() {
    window.print();
}

// Add print styles
const printStyles = `
@media print {
    .no-print {
        display: none !important;
    }
    
    body {
        background: white !important;
    }
    
    .bg-white {
        background: white !important;
        border: none !important;
        box-shadow: none !important;
    }
    
    table {
        page-break-inside: auto;
    }
    
    tr {
        page-break-inside: avoid;
        page-break-after: auto;
    }
    
    thead {
        display: table-header-group;
    }
    
    tfoot {
        display: table-footer-group;
    }
}
`;

// Inject print styles
const styleSheet = document.createElement('style');
styleSheet.textContent = printStyles;
document.head.appendChild(styleSheet);
</script>

<?php include '../../includes/footer.php'; ?>
