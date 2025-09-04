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

<!-- Page Header with Mobile-Optimized Layout -->
<div class="mb-4">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Invoices</h1>
            <p class="text-gray-600 mt-1 hidden sm:block">Manage and track all your invoices</p>
        </div>
        <div class="flex flex-col sm:flex-row gap-3">
            <a href="<?php echo Helper::baseUrl('modules/invoices/create.php'); ?>" 
               class="btn-primary inline-flex items-center justify-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Create Invoice
            </a>
        </div>
    </div>
</div>

<!-- Mobile-First: Search and Quick Filters -->
<div class="bg-white rounded-xl border border-gray-200 p-4 mb-4">
    <form method="GET" class="space-y-4" id="filterForm">
        <!-- Essential Search -->
        <div class="relative">
            <input type="text" 
                   name="search" 
                   value="<?php echo htmlspecialchars($search); ?>"
                   placeholder="Search invoice #, client, project..."
                   class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            <svg class="w-5 h-5 text-gray-400 absolute left-3 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
            </svg>
        </div>

        <!-- Quick Status Filters -->
        <div class="flex flex-wrap gap-2">
            <button type="button" onclick="setStatusFilter('')" 
                    class="status-filter flex-1 sm:flex-none px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo empty($statusFilter) ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'; ?>">
                All Status
            </button>
            <button type="button" onclick="setStatusFilter('sent')"
                    class="status-filter flex-1 sm:flex-none px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo $statusFilter === 'sent' ? 'bg-blue-100 text-blue-800 border-blue-300' : 'bg-white text-gray-700 border-gray-300 hover:bg-blue-50 hover:text-blue-700'; ?>">
                Sent
            </button>
            <button type="button" onclick="setStatusFilter('partially_paid')"
                    class="status-filter flex-1 sm:flex-none px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo $statusFilter === 'partially_paid' ? 'bg-yellow-100 text-yellow-800 border-yellow-300' : 'bg-white text-gray-700 border-gray-300 hover:bg-yellow-50 hover:text-yellow-700'; ?>">
                Partial
            </button>
            <button type="button" onclick="setStatusFilter('overdue')"
                    class="status-filter flex-1 sm:flex-none px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo $statusFilter === 'overdue' ? 'bg-red-100 text-red-800 border-red-300' : 'bg-white text-gray-700 border-gray-300 hover:bg-red-50 hover:text-red-700'; ?>">
                Overdue
            </button>
        </div>

        <!-- Mobile Filter Toggle Button -->
        <div class="flex items-center justify-between">
            <button type="button" id="mobileFiltersToggle" 
                    class="lg:hidden inline-flex items-center text-sm text-blue-600 hover:text-blue-700">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.207A1 1 0 013 6.5V4z"></path>
                </svg>
                More Filters
                <span class="ml-1 bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded-full" id="activeFiltersCount">
                    <?php 
                    $activeFilters = array_filter(['client' => $clientFilter, 'date_from' => $dateFrom, 'date_to' => $dateTo], function($value) { 
                        return !empty($value); 
                    });
                    echo count($activeFilters); 
                    ?>
                </span>
            </button>
            
            <!-- Desktop: Show Advanced Filters Toggle -->
            <button type="button" id="desktopFiltersToggle" 
                    class="hidden lg:inline-flex items-center text-sm text-gray-600 hover:text-gray-900">
                <svg class="w-4 h-4 mr-2 transform transition-transform" id="desktopFiltersIcon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
                Advanced Filters
            </button>
        </div>

        <!-- Advanced Filters - Hidden by Default on Mobile -->
        <div id="advancedFilters" class="hidden border-t pt-4 space-y-4">
            <!-- Client and Status Filters -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                    <select name="client" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">All Clients</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?php echo Helper::encryptId($client['id']); ?>" 
                                    <?php echo $clientFilter === Helper::encryptId($client['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($client['company_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">All Statuses</option>
                        <option value="draft" <?php echo $statusFilter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="sent" <?php echo $statusFilter === 'sent' ? 'selected' : ''; ?>>Sent</option>
                        <option value="partially_paid" <?php echo $statusFilter === 'partially_paid' ? 'selected' : ''; ?>>Partially Paid</option>
                        <option value="paid" <?php echo $statusFilter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                        <option value="overdue" <?php echo $statusFilter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                    </select>
                </div>
            </div>

            <!-- Date Range Filters -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="date_from" class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                    <input type="date" 
                           name="date_from" 
                           id="date_from"
                           value="<?php echo htmlspecialchars($dateFrom); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label for="date_to" class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                    <input type="date" 
                           name="date_to" 
                           id="date_to"
                           value="<?php echo htmlspecialchars($dateTo); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>

            <!-- Sort Options -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sort By</label>
                    <select name="sort" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="created_at" <?php echo $sortBy === 'created_at' ? 'selected' : ''; ?>>Created Date</option>
                        <option value="invoice_date" <?php echo $sortBy === 'invoice_date' ? 'selected' : ''; ?>>Invoice Date</option>
                        <option value="due_date" <?php echo $sortBy === 'due_date' ? 'selected' : ''; ?>>Due Date</option>
                        <option value="invoice_number" <?php echo $sortBy === 'invoice_number' ? 'selected' : ''; ?>>Invoice Number</option>
                        <option value="total_amount" <?php echo $sortBy === 'total_amount' ? 'selected' : ''; ?>>Amount</option>
                        <option value="company_name" <?php echo $sortBy === 'company_name' ? 'selected' : ''; ?>>Client Name</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Order</label>
                    <select name="order" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="DESC" <?php echo $sortOrder === 'DESC' ? 'selected' : ''; ?>>Newest First</option>
                        <option value="ASC" <?php echo $sortOrder === 'ASC' ? 'selected' : ''; ?>>Oldest First</option>
                    </select>
                </div>
            </div>

            <!-- Filter Actions -->
            <div class="flex justify-between items-center pt-4 border-t">
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    Apply Filters
                </button>
                <a href="<?php echo Helper::baseUrl('modules/invoices/'); ?>" class="text-sm text-gray-600 hover:text-gray-900">Clear All</a>
            </div>
        </div>

        <!-- Hidden inputs -->
        <input type="hidden" name="status" id="statusFilter" value="<?php echo htmlspecialchars($statusFilter); ?>">
    </form>
</div>

<!-- Active Filters Display - Compact -->
<?php if (!empty(array_filter([$search, $statusFilter, $clientFilter, $dateFrom, $dateTo]))): ?>
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-4">
        <div class="flex items-center justify-between">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm font-medium text-blue-800">Filters:</span>
                <?php if (!empty($search)): ?>
                    <span class="inline-flex items-center px-2 py-1 bg-white border border-blue-200 rounded text-xs text-blue-800">
                        "<?php echo htmlspecialchars(substr($search, 0, 20)) . (strlen($search) > 20 ? '...' : ''); ?>"
                    </span>
                <?php endif; ?>
                <?php if (!empty($statusFilter)): ?>
                    <span class="inline-flex items-center px-2 py-1 bg-white border border-blue-200 rounded text-xs text-blue-800">
                        <?php echo ucwords(str_replace('_', ' ', $statusFilter)); ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($clientFilter)): ?>
                    <span class="inline-flex items-center px-2 py-1 bg-white border border-blue-200 rounded text-xs text-blue-800">
                        Client Selected
                    </span>
                <?php endif; ?>
            </div>
            <a href="<?php echo Helper::baseUrl('modules/invoices/'); ?>" class="text-sm text-blue-600 hover:text-blue-700">Clear</a>
        </div>
    </div>
<?php endif; ?>

<!-- Invoices List - THE MAIN CONTENT -->
<div class="space-y-4">
    <?php if (empty($invoices)): ?>
        <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
            <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
            </svg>
            <h3 class="text-lg font-medium text-gray-900 mb-2">No invoices found</h3>
            <p class="text-gray-600 mb-6">
                <?php if (!empty(array_filter([$search, $statusFilter, $clientFilter, $dateFrom, $dateTo]))): ?>
                    Try adjusting your filters or search terms.
                <?php else: ?>
                    Get started by creating your first invoice.
                <?php endif; ?>
            </p>
            <?php if (empty(array_filter([$search, $statusFilter, $clientFilter, $dateFrom, $dateTo]))): ?>
                <a href="<?php echo Helper::baseUrl('modules/invoices/create.php'); ?>" 
                   class="inline-flex items-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                    </svg>
                    Create First Invoice
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <!-- Invoices Table/Cards -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="p-4 border-b border-gray-100">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Invoice Records</h3>
                        <p class="text-gray-600 text-sm mt-1">
                            Showing <?php echo number_format(count($invoices)); ?> of <?php echo number_format($totalInvoices); ?> invoices
                        </p>
                    </div>
                </div>
            </div>

            <!-- Desktop Table -->
            <div class="hidden md:block overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Invoice #
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Client
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Project
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Date
                            </th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Total
                            </th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Balance
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Status
                            </th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($invoices as $invoice): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <!-- Invoice # -->
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900">
                                            <a href="<?php echo Helper::baseUrl('modules/invoices/view.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
                                               class="hover:text-blue-600 transition-colors">
                                                #<?php echo htmlspecialchars($invoice['invoice_number']); ?>
                                            </a>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            Due: <?php echo Helper::formatDate($invoice['due_date'], 'M j, Y'); ?>
                                        </div>
                                    </div>
                                </td>

                                <!-- Client -->
                                <td class="px-4 py-4">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($invoice['company_name']); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($invoice['contact_person']); ?></div>
                                </td>

                                <!-- Project -->
                                <td class="px-4 py-4">
                                    <?php if ($invoice['project_name']): ?>
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($invoice['project_name']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo ucfirst(str_replace('_', ' ', $invoice['project_type'])); ?></div>
                                    <?php else: ?>
                                        <span class="text-sm text-gray-400">No project</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Date -->
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo Helper::formatDate($invoice['invoice_date'], 'M j, Y'); ?>
                                </td>

                                <!-- Total -->
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-right font-medium">
                                    <?php echo Helper::formatCurrency($invoice['total_amount']); ?>
                                </td>

                                <!-- Balance -->
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-right">
                                    <?php if ($invoice['balance_amount'] > 0): ?>
                                        <span class="font-medium text-red-600"><?php echo Helper::formatCurrency($invoice['balance_amount']); ?></span>
                                    <?php else: ?>
                                        <span class="font-medium text-green-600">Paid</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Status -->
                                <td class="px-4 py-4 whitespace-nowrap">
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

                                <!-- Actions -->
                                <td class="px-4 py-4 whitespace-nowrap text-right">
                                    <div class="flex items-center justify-end space-x-2">
                                        <a href="<?php echo Helper::baseUrl('modules/invoices/view.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
                                           class="inline-flex items-center px-2 py-1 bg-gray-100 text-gray-700 rounded hover:bg-gray-200 transition-colors text-xs"
                                           title="View">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            </svg>
                                        </a>
                                        <a href="<?php echo Helper::baseUrl('modules/invoices/edit.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
                                           class="inline-flex items-center px-2 py-1 bg-blue-100 text-blue-700 rounded hover:bg-blue-200 transition-colors text-xs"
                                           title="Edit">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                            </svg>
                                        </a>
                                        <?php if ($invoice['balance_amount'] > 0): ?>
                                            <a href="<?php echo Helper::baseUrl('modules/payments/create.php?invoice_id=' . Helper::encryptId($invoice['id'])); ?>" 
                                               class="inline-flex items-center px-2 py-1 bg-green-100 text-green-700 rounded hover:bg-green-200 transition-colors text-xs"
                                               title="Record Payment">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                                </svg>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Cards - Prioritized Content -->
            <div class="md:hidden divide-y divide-gray-200">
                <?php foreach ($invoices as $invoice): ?>
                    <div class="p-4 hover:bg-gray-50 transition-colors">
                        <!-- Invoice Header -->
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex-1 min-w-0">
                                <h3 class="text-sm font-semibold text-gray-900">
                                    <a href="<?php echo Helper::baseUrl('modules/invoices/view.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
                                       class="hover:text-blue-600 transition-colors">
                                        #<?php echo htmlspecialchars($invoice['invoice_number']); ?>
                                    </a>
                                </h3>
                                <p class="text-xs text-gray-500 mt-1">
                                    <?php echo Helper::formatDate($invoice['invoice_date'], 'M j, Y'); ?>
                                </p>
                            </div>
                            <div class="ml-3">
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
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo $badgeClass; ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $status)); ?>
                                </span>
                            </div>
                        </div>

                        <!-- Client & Amount -->
                        <div class="grid grid-cols-2 gap-4 mb-3">
                            <div>
                                <p class="text-xs text-gray-500">Client</p>
                                <p class="text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars($invoice['company_name']); ?>
                                </p>
                                <p class="text-xs text-gray-500">
                                    <?php echo htmlspecialchars($invoice['contact_person']); ?>
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-xs text-gray-500">Total</p>
                                <p class="text-sm font-semibold text-gray-900">
                                    <?php echo Helper::formatCurrency($invoice['total_amount']); ?>
                                </p>
                                <p class="text-xs <?php echo $invoice['balance_amount'] > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                                    <?php if ($invoice['balance_amount'] > 0): ?>
                                        Balance: <?php echo Helper::formatCurrency($invoice['balance_amount']); ?>
                                    <?php else: ?>
                                        Paid in Full
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>

                        <!-- Project & Due Date -->
                        <div class="grid grid-cols-2 gap-4 mb-3">
                            <div>
                                <p class="text-xs text-gray-500">Project</p>
                                <?php if ($invoice['project_name']): ?>
                                    <p class="text-sm text-gray-900"><?php echo htmlspecialchars($invoice['project_name']); ?></p>
                                    <p class="text-xs text-gray-500"><?php echo ucfirst(str_replace('_', ' ', $invoice['project_type'])); ?></p>
                                <?php else: ?>
                                    <p class="text-sm text-gray-400">No project</p>
                                <?php endif; ?>
                            </div>
                            <div class="text-right">
                                <p class="text-xs text-gray-500">Due Date</p>
                                <p class="text-sm text-gray-900">
                                    <?php echo Helper::formatDate($invoice['due_date'], 'M j, Y'); ?>
                                </p>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex space-x-2">
                            <a href="<?php echo Helper::baseUrl('modules/invoices/view.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
                               class="flex-1 inline-flex items-center justify-center px-3 py-2 bg-gray-100 text-gray-700 rounded text-xs font-medium hover:bg-gray-200 transition-colors">
                                View
                            </a>
                            <a href="<?php echo Helper::baseUrl('modules/invoices/edit.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
                               class="flex-1 inline-flex items-center justify-center px-3 py-2 bg-blue-100 text-blue-700 rounded text-xs font-medium hover:bg-blue-200 transition-colors">
                                Edit
                            </a>
                            <?php if ($invoice['balance_amount'] > 0): ?>
                                <a href="<?php echo Helper::baseUrl('modules/payments/create.php?invoice_id=' . Helper::encryptId($invoice['id'])); ?>" 
                                   class="flex-1 inline-flex items-center justify-center px-3 py-2 bg-green-100 text-green-700 rounded text-xs font-medium hover:bg-green-200 transition-colors">
                                    Payment
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-4">
                <div class="flex items-center justify-between">
                    <div class="text-sm text-gray-600 hidden sm:block">
                        Showing <?php echo (($page - 1) * $perPage) + 1; ?> to <?php echo min($page * $perPage, $totalInvoices); ?> of <?php echo $totalInvoices; ?> invoices
                    </div>
                    
                    <div class="flex space-x-2 mx-auto sm:mx-0">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                               class="px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors text-sm">
                                ← Prev
                            </a>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                               class="px-3 py-2 rounded-lg transition-colors text-sm <?php echo $i === $page ? 'bg-gray-800 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                               class="px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors text-sm">
                                Next →
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

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
