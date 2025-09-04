<?php
// modules/payments/index.php
session_start();

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

$pageTitle = 'Payments - Invoice Manager';

// Initialize variables
$payments = [];
$stats = [];
$filters = [
    'payment_method' => $_GET['payment_method'] ?? '',
    'client' => $_GET['client'] ?? '',
    'invoice' => $_GET['invoice'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'search' => trim($_GET['search'] ?? '')
];

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;
$sort = $_GET['sort'] ?? 'payment_date';
$order = $_GET['order'] ?? 'DESC';

// Validate sort parameters
$validSort = ['payment_date', 'payment_amount', 'payment_method', 'invoice_number', 'company_name', 'created_at'];
$validOrder = ['ASC', 'DESC'];

if (!in_array($sort, $validSort)) $sort = 'payment_date';
if (!in_array($order, $validOrder)) $order = 'DESC';

// Get database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Build WHERE conditions for filtering
    $whereConditions = ['1=1']; // Always true condition
    $params = [];

    // Search filter
    if (!empty($filters['search'])) {
        $whereConditions[] = '(i.invoice_number LIKE :search OR c.company_name LIKE :search OR p.payment_reference LIKE :search)';
        $params['search'] = '%' . $filters['search'] . '%';
    }

    // Payment method filter
    if (!empty($filters['payment_method'])) {
        $whereConditions[] = 'p.payment_method = :payment_method';
        $params['payment_method'] = $filters['payment_method'];
    }

    // Client filter
    if (!empty($filters['client'])) {
        $clientId = Helper::decryptId($filters['client']);
        if ($clientId) {
            $whereConditions[] = 'c.id = :client_id';
            $params['client_id'] = $clientId;
        }
    }

    // Invoice filter
    if (!empty($filters['invoice'])) {
        $invoiceId = Helper::decryptId($filters['invoice']);
        if ($invoiceId) {
            $whereConditions[] = 'i.id = :invoice_id';
            $params['invoice_id'] = $invoiceId;
        }
    }

    // Date range filters
    if (!empty($filters['date_from'])) {
        $whereConditions[] = 'p.payment_date >= :date_from';
        $params['date_from'] = $filters['date_from'];
    }

    if (!empty($filters['date_to'])) {
        $whereConditions[] = 'p.payment_date <= :date_to';
        $params['date_to'] = $filters['date_to'];
    }

    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);



    // Get total count for pagination
    $countQuery = "
        SELECT COUNT(p.id) as total
        FROM payments p
        LEFT JOIN invoices i ON p.invoice_id = i.id
        LEFT JOIN clients c ON i.client_id = c.id
        $whereClause
    ";
    
    $countStmt = $db->prepare($countQuery);
    $countStmt->execute($params);
    $totalPayments = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalPayments / $limit);

    // Get payments with related data
    $paymentsQuery = "
        SELECT 
            p.id,
            p.payment_amount,
            p.payment_date,
            p.payment_method,
            p.payment_reference,
            p.notes,
            p.created_at,
            i.id as invoice_id,
            i.invoice_number,
            i.total_amount as invoice_total,
            i.balance_amount as invoice_balance,
            c.id as client_id,
            c.company_name,
            c.contact_person,
            pr.project_name,
            u.full_name as created_by_name
        FROM payments p
        LEFT JOIN invoices i ON p.invoice_id = i.id
        LEFT JOIN clients c ON i.client_id = c.id
        LEFT JOIN projects pr ON i.project_id = pr.id
        LEFT JOIN users u ON p.created_by = u.id
        $whereClause
        ORDER BY p.$sort $order
        LIMIT :limit OFFSET :offset
    ";
    
    $paymentsStmt = $db->prepare($paymentsQuery);
    
    // Bind filter parameters
    foreach ($params as $key => $value) {
        $paymentsStmt->bindValue($key, $value);
    }
    
    // Bind pagination parameters
    $paymentsStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $paymentsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $paymentsStmt->execute();
    $payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get clients for filter dropdown
    $clientsQuery = "SELECT id, company_name FROM clients WHERE is_active = 1 ORDER BY company_name ASC";
    $clientsStmt = $db->prepare($clientsQuery);
    $clientsStmt->execute();
    $clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get recent invoices with outstanding balances for quick payment
    $recentInvoicesQuery = "
        SELECT i.id, i.invoice_number, i.balance_amount, c.company_name
        FROM invoices i
        LEFT JOIN clients c ON i.client_id = c.id
        WHERE i.balance_amount > 0
        ORDER BY i.created_at DESC
        LIMIT 10
    ";
    $recentInvoicesStmt = $db->prepare($recentInvoicesQuery);
    $recentInvoicesStmt->execute();
    $recentInvoices = $recentInvoicesStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    Helper::setMessage('Error loading payments: ' . $e->getMessage(), 'error');
    $payments = [];
}

include '../../includes/header.php';
?>

<!-- Page Header with Mobile-Optimized Layout -->
<div class="mb-4">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Payments</h1>
            <p class="text-gray-600 mt-1 hidden sm:block">Manage and track all payment transactions</p>
        </div>
        <div class="flex flex-col sm:flex-row gap-3">
            <a href="<?php echo Helper::baseUrl('modules/payments/create.php'); ?>" 
               class="btn-primary inline-flex items-center justify-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Record Payment
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
                   value="<?php echo htmlspecialchars($filters['search']); ?>"
                   placeholder="Search invoice #, client, reference..."
                   class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            <svg class="w-5 h-5 text-gray-400 absolute left-3 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
            </svg>
        </div>

        <!-- Quick Payment Method Filters -->
        <div class="flex flex-wrap gap-2">
            <button type="button" onclick="setPaymentMethodFilter('')" 
                    class="method-filter flex-1 sm:flex-none px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo empty($filters['payment_method']) ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'; ?>">
                All Methods
            </button>
            <button type="button" onclick="setPaymentMethodFilter('cash')"
                    class="method-filter flex-1 sm:flex-none px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo $filters['payment_method'] === 'cash' ? 'bg-green-100 text-green-800 border-green-300' : 'bg-white text-gray-700 border-gray-300 hover:bg-green-50 hover:text-green-700'; ?>">
                Cash
            </button>
            <button type="button" onclick="setPaymentMethodFilter('bank_transfer')"
                    class="method-filter flex-1 sm:flex-none px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo $filters['payment_method'] === 'bank_transfer' ? 'bg-blue-100 text-blue-800 border-blue-300' : 'bg-white text-gray-700 border-gray-300 hover:bg-blue-50 hover:text-blue-700'; ?>">
                Bank Transfer
            </button>
            <button type="button" onclick="setPaymentMethodFilter('card')"
                    class="method-filter flex-1 sm:flex-none px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo $filters['payment_method'] === 'card' ? 'bg-purple-100 text-purple-800 border-purple-300' : 'bg-white text-gray-700 border-gray-300 hover:bg-purple-50 hover:text-purple-700'; ?>">
                Card
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
                    $activeFilters = array_filter($filters, function($value, $key) { 
                        return !empty($value) && !in_array($key, ['search', 'payment_method', 'sort', 'order']); 
                    }, ARRAY_FILTER_USE_BOTH);
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
            <!-- Client Filter -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Client</label>
                <select name="client" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">All Clients</option>
                    <?php foreach ($clients as $client): ?>
                        <option value="<?php echo Helper::encryptId($client['id']); ?>" 
                                <?php echo $filters['client'] === Helper::encryptId($client['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($client['company_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Date Range Filters -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="date_from" class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                    <input type="date" 
                           name="date_from" 
                           id="date_from"
                           value="<?php echo htmlspecialchars($filters['date_from']); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label for="date_to" class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                    <input type="date" 
                           name="date_to" 
                           id="date_to"
                           value="<?php echo htmlspecialchars($filters['date_to']); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>

            <!-- Sort Options -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sort By</label>
                    <select name="sort" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="payment_date" <?php echo $sort === 'payment_date' ? 'selected' : ''; ?>>Payment Date</option>
                        <option value="payment_amount" <?php echo $sort === 'payment_amount' ? 'selected' : ''; ?>>Amount</option>
                        <option value="invoice_number" <?php echo $sort === 'invoice_number' ? 'selected' : ''; ?>>Invoice Number</option>
                        <option value="company_name" <?php echo $sort === 'company_name' ? 'selected' : ''; ?>>Client Name</option>
                        <option value="created_at" <?php echo $sort === 'created_at' ? 'selected' : ''; ?>>Created Date</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Order</label>
                    <select name="order" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="DESC" <?php echo $order === 'DESC' ? 'selected' : ''; ?>>Newest First</option>
                        <option value="ASC" <?php echo $order === 'ASC' ? 'selected' : ''; ?>>Oldest First</option>
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
                <a href="<?php echo Helper::baseUrl('modules/payments/'); ?>" class="text-sm text-gray-600 hover:text-gray-900">Clear All</a>
            </div>
        </div>

        <!-- Hidden inputs -->
        <input type="hidden" name="payment_method" id="paymentMethodFilter" value="<?php echo htmlspecialchars($filters['payment_method']); ?>">
    </form>
</div>

<!-- Active Filters Display - Compact -->
<?php if (!empty(array_filter($filters, function($v, $k) { return !empty($v) && !in_array($k, ['sort', 'order']); }, ARRAY_FILTER_USE_BOTH))): ?>
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-4">
        <div class="flex items-center justify-between">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm font-medium text-blue-800">Filters:</span>
                <?php if (!empty($filters['search'])): ?>
                    <span class="inline-flex items-center px-2 py-1 bg-white border border-blue-200 rounded text-xs text-blue-800">
                        "<?php echo htmlspecialchars(substr($filters['search'], 0, 20)) . (strlen($filters['search']) > 20 ? '...' : ''); ?>"
                    </span>
                <?php endif; ?>
                <?php if (!empty($filters['payment_method'])): ?>
                    <span class="inline-flex items-center px-2 py-1 bg-white border border-blue-200 rounded text-xs text-blue-800">
                        <?php echo ucwords(str_replace('_', ' ', $filters['payment_method'])); ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($filters['client'])): ?>
                    <span class="inline-flex items-center px-2 py-1 bg-white border border-blue-200 rounded text-xs text-blue-800">
                        Client Selected
                    </span>
                <?php endif; ?>
            </div>
            <a href="<?php echo Helper::baseUrl('modules/payments/'); ?>" class="text-sm text-blue-600 hover:text-blue-700">Clear</a>
        </div>
    </div>
<?php endif; ?>

<!-- Payments List - THE MAIN CONTENT -->
<div class="space-y-4">
    <?php if (empty($payments)): ?>
        <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
            <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v2a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
            </svg>
            <h3 class="text-lg font-medium text-gray-900 mb-2">No payments found</h3>
            <p class="text-gray-600 mb-6">
                <?php if (!empty(array_filter($filters))): ?>
                    Try adjusting your filters or search terms.
                <?php else: ?>
                    Get started by recording your first payment.
                <?php endif; ?>
            </p>
            <?php if (empty(array_filter($filters))): ?>
                <a href="<?php echo Helper::baseUrl('modules/payments/create.php'); ?>" 
                   class="inline-flex items-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                    </svg>
                    Record First Payment
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <!-- Payments Table/Cards -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="p-4 border-b border-gray-100">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Payment Records</h3>
                        <p class="text-gray-600 text-sm mt-1">
                            Showing <?php echo number_format(count($payments)); ?> of <?php echo number_format($totalPayments); ?> payments
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
                                Date
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Invoice
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Client
                            </th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Amount
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Method
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Reference
                            </th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($payments as $payment): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <!-- Date -->
                                <td class="px-4 py-4">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo Helper::formatDate($payment['payment_date']); ?>
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        <?php echo Helper::formatDate($payment['created_at'], 'M j, Y g:i A'); ?>
                                    </div>
                                </td>

                                <!-- Invoice -->
                                <td class="px-4 py-4">
                                    <a href="<?php echo Helper::baseUrl('modules/invoices/view.php?id=' . Helper::encryptId($payment['invoice_id'])); ?>" 
                                       class="text-blue-600 hover:text-blue-800 font-medium">
                                        <?php echo htmlspecialchars($payment['invoice_number']); ?>
                                    </a>
                                    <?php if ($payment['project_name']): ?>
                                        <div class="text-xs text-gray-500 mt-1">
                                            <?php echo htmlspecialchars($payment['project_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- Client -->
                                <td class="px-4 py-4">
                                    <div>
                                        <a href="<?php echo Helper::baseUrl('modules/clients/view.php?id=' . Helper::encryptId($payment['client_id'])); ?>" 
                                           class="text-gray-900 font-medium hover:text-blue-600">
                                            <?php echo htmlspecialchars($payment['company_name']); ?>
                                        </a>
                                        <?php if ($payment['contact_person']): ?>
                                            <div class="text-xs text-gray-500">
                                                <?php echo htmlspecialchars($payment['contact_person']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Amount -->
                                <td class="px-4 py-4 text-right">
                                    <span class="text-lg font-semibold text-green-600">
                                        <?php echo Helper::formatCurrency($payment['payment_amount']); ?>
                                    </span>
                                </td>

                                <!-- Method -->
                                <td class="px-4 py-4">
                                    <?php
                                    $methodLabels = [
                                        'cash' => ['Cash', 'bg-green-100 text-green-800'],
                                        'bank_transfer' => ['Bank Transfer', 'bg-blue-100 text-blue-800'],
                                        'check' => ['Check', 'bg-yellow-100 text-yellow-800'],
                                        'card' => ['Card', 'bg-purple-100 text-purple-800'],
                                        'online' => ['Online', 'bg-indigo-100 text-indigo-800'],
                                        'other' => ['Other', 'bg-gray-100 text-gray-800']
                                    ];
                                    $method = $methodLabels[$payment['payment_method']] ?? ['Unknown', 'bg-gray-100 text-gray-800'];
                                    ?>
                                    <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full <?php echo $method[1]; ?>">
                                        <?php echo $method[0]; ?>
                                    </span>
                                </td>

                                <!-- Reference -->
                                <td class="px-4 py-4">
                                    <?php if ($payment['payment_reference']): ?>
                                        <span class="text-sm text-gray-900 font-mono">
                                            <?php echo htmlspecialchars($payment['payment_reference']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-400 text-sm">—</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Actions -->
                                <td class="px-4 py-4 text-right">
                                    <div class="flex items-center justify-end space-x-2">
                                        <a href="<?php echo Helper::baseUrl('modules/invoices/view.php?id=' . Helper::encryptId($payment['invoice_id'])); ?>" 
                                           class="inline-flex items-center px-2 py-1 bg-gray-100 text-gray-700 rounded hover:bg-gray-200 transition-colors text-xs"
                                           title="View Invoice">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            </svg>
                                        </a>
                                        <?php if ($_SESSION['user_role'] === 'admin'): ?>
                                            <button onclick="deletePayment(<?php echo $payment['id']; ?>)" 
                                                    class="inline-flex items-center px-2 py-1 bg-red-100 text-red-700 rounded hover:bg-red-200 transition-colors text-xs"
                                                    title="Delete Payment">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                            </button>
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
                <?php foreach ($payments as $payment): ?>
                    <div class="p-4 hover:bg-gray-50 transition-colors">
                        <!-- Payment Header -->
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex-1 min-w-0">
                                <h3 class="text-sm font-semibold text-gray-900">
                                    <a href="<?php echo Helper::baseUrl('modules/invoices/view.php?id=' . Helper::encryptId($payment['invoice_id'])); ?>" 
                                       class="hover:text-blue-600 transition-colors">
                                        <?php echo htmlspecialchars($payment['invoice_number']); ?>
                                    </a>
                                </h3>
                                <p class="text-xs text-gray-500 mt-1">
                                    <?php echo Helper::formatDate($payment['payment_date']); ?>
                                </p>
                            </div>
                            <div class="ml-3 text-right">
                                <span class="text-lg font-semibold text-green-600">
                                    <?php echo Helper::formatCurrency($payment['payment_amount']); ?>
                                </span>
                            </div>
                        </div>

                        <!-- Client & Method -->
                        <div class="grid grid-cols-2 gap-4 mb-3">
                            <div>
                                <p class="text-xs text-gray-500">Client</p>
                                <p class="text-sm font-medium text-gray-900">
                                    <a href="<?php echo Helper::baseUrl('modules/clients/view.php?id=' . Helper::encryptId($payment['client_id'])); ?>" 
                                       class="hover:text-blue-600 transition-colors">
                                        <?php echo htmlspecialchars($payment['company_name']); ?>
                                    </a>
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-xs text-gray-500">Method</p>
                                <?php
                                $methodLabels = [
                                    'cash' => ['Cash', 'bg-green-100 text-green-800'],
                                    'bank_transfer' => ['Bank Transfer', 'bg-blue-100 text-blue-800'],
                                    'check' => ['Check', 'bg-yellow-100 text-yellow-800'],
                                    'card' => ['Card', 'bg-purple-100 text-purple-800'],
                                    'online' => ['Online', 'bg-indigo-100 text-indigo-800'],
                                    'other' => ['Other', 'bg-gray-100 text-gray-800']
                                ];
                                $method = $methodLabels[$payment['payment_method']] ?? ['Unknown', 'bg-gray-100 text-gray-800'];
                                ?>
                                <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full <?php echo $method[1]; ?>">
                                    <?php echo $method[0]; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Reference & Actions -->
                        <div class="flex items-center justify-between">
                            <div>
                                <?php if ($payment['payment_reference']): ?>
                                    <p class="text-xs text-gray-500">Reference</p>
                                    <span class="text-sm text-gray-900 font-mono">
                                        <?php echo htmlspecialchars($payment['payment_reference']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="flex space-x-2">
                                <a href="<?php echo Helper::baseUrl('modules/invoices/view.php?id=' . Helper::encryptId($payment['invoice_id'])); ?>" 
                                   class="inline-flex items-center justify-center px-3 py-2 bg-gray-100 text-gray-700 rounded text-xs font-medium hover:bg-gray-200 transition-colors">
                                    View Invoice
                                </a>
                                <?php if ($_SESSION['user_role'] === 'admin'): ?>
                                    <button onclick="deletePayment(<?php echo $payment['id']; ?>)" 
                                            class="inline-flex items-center justify-center px-3 py-2 bg-red-100 text-red-700 rounded text-xs font-medium hover:bg-red-200 transition-colors">
                                        Delete
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if ($payment['notes']): ?>
                            <div class="mt-3 pt-3 border-t border-gray-100">
                                <p class="text-sm text-gray-600">
                                    <?php echo htmlspecialchars($payment['notes']); ?>
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-4">
                <div class="flex items-center justify-between">
                    <div class="text-sm text-gray-600 hidden sm:block">
                        Showing <?php echo (($page - 1) * $limit) + 1; ?> to <?php echo min($page * $limit, $totalPayments); ?> of <?php echo $totalPayments; ?> payments
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
/* Mobile-First Responsive Design */

/* Method filter optimizations for mobile */
@media (max-width: 640px) {
    .method-filter {
        min-height: 44px; /* Better touch targets */
        font-size: 0.875rem;
        font-weight: 500;
    }
    
    /* Grid layout for method filters on small screens */
    .flex.flex-wrap.gap-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.5rem;
    }
    
    /* "All Methods" button spans full width */
    .method-filter:first-child {
        grid-column: 1 / -1;
    }
}

/* Enhanced mobile card styling */
.md\:hidden > div {
    border-radius: 0.5rem;
    margin: 0.25rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.md\:hidden > div:hover {
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    transform: translateY(-1px);
}

/* Filter toggle improvements */
#mobileFiltersToggle, #desktopFiltersToggle {
    transition: all 0.2s ease;
}

#mobileFiltersToggle:hover, #desktopFiltersToggle:hover {
    transform: translateY(-1px);
}

/* Advanced filters animation */
#advancedFilters {
    transition: all 0.3s ease-in-out;
    overflow: hidden;
}

#advancedFilters.show {
    animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
        max-height: 0;
    }
    to {
        opacity: 1;
        transform: translateY(0);
        max-height: 1000px;
    }
}

/* Improved pagination for mobile */
@media (max-width: 640px) {
    .flex.space-x-2 {
        justify-content: center;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    
    .flex.space-x-2 > * {
        margin: 0; /* Reset space-x margin */
    }
}

/* Enhanced method badges */
.method-badge {
    font-weight: 500;
    letter-spacing: 0.025em;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
}

/* Table hover effects */
tbody tr:hover {
    background-color: #f9fafb;
    transform: translateX(2px);
    transition: all 0.15s ease;
}

/* Better focus states */
input:focus, select:focus {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
}

/* Loading states */
.loading {
    opacity: 0.6;
    pointer-events: none;
    position: relative;
}

.loading::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 20px;
    height: 20px;
    margin: -10px 0 0 -10px;
    border: 2px solid #f3f3f3;
    border-top: 2px solid #3498db;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Accessibility improvements */
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}

/* High contrast mode support */
@media (prefers-contrast: high) {
    .border-gray-200 {
        border-color: #000;
    }
    
    .text-gray-500 {
        color: #000;
    }
    
    .bg-gray-50 {
        background-color: #fff;
    }
}

/* Print styles */
@media print {
    .no-print {
        display: none;
    }
    
    .bg-white {
        background: white !important;
    }
    
    .text-blue-600 {
        color: black !important;
    }
}
</style>

<script>
// Mobile-first JavaScript enhancements

// Mobile filters toggle
document.getElementById('mobileFiltersToggle')?.addEventListener('click', function() {
    const advancedFilters = document.getElementById('advancedFilters');
    const isHidden = advancedFilters.classList.contains('hidden');
    
    if (isHidden) {
        advancedFilters.classList.remove('hidden');
        advancedFilters.classList.add('show');
        this.innerHTML = `
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
            Hide Filters
            <span class="ml-1 bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded-full" id="activeFiltersCount">
                ${document.getElementById('activeFiltersCount').textContent}
            </span>
        `;
    } else {
        advancedFilters.classList.add('hidden');
        advancedFilters.classList.remove('show');
        this.innerHTML = `
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.207A1 1 0 013 6.5V4z"></path>
            </svg>
            More Filters
            <span class="ml-1 bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded-full" id="activeFiltersCount">
                ${document.getElementById('activeFiltersCount').textContent}
            </span>
        `;
    }
});

// Desktop filters toggle
document.getElementById('desktopFiltersToggle')?.addEventListener('click', function() {
    const advancedFilters = document.getElementById('advancedFilters');
    const icon = document.getElementById('desktopFiltersIcon');
    
    if (advancedFilters.classList.contains('hidden')) {
        advancedFilters.classList.remove('hidden');
        icon.style.transform = 'rotate(180deg)';
    } else {
        advancedFilters.classList.add('hidden');
        icon.style.transform = 'rotate(0deg)';
    }
});

// Payment method filter functionality
function setPaymentMethodFilter(method) {
    document.getElementById('paymentMethodFilter').value = method;
    
    // Update button states
    document.querySelectorAll('.method-filter').forEach(btn => {
        btn.classList.remove(
            'bg-gray-800', 'text-white', 'border-gray-800',
            'bg-green-100', 'text-green-800', 'border-green-300',
            'bg-blue-100', 'text-blue-800', 'border-blue-300',
            'bg-purple-100', 'text-purple-800', 'border-purple-300'
        );
        btn.classList.add('bg-white', 'text-gray-700', 'border-gray-300');
    });
    
    // Set active button style
    const activeButton = event.target;
    activeButton.classList.remove('bg-white', 'text-gray-700', 'border-gray-300');
    
    switch(method) {
        case '':
            activeButton.classList.add('bg-gray-800', 'text-white', 'border-gray-800');
            break;
        case 'cash':
            activeButton.classList.add('bg-green-100', 'text-green-800', 'border-green-300');
            break;
        case 'bank_transfer':
            activeButton.classList.add('bg-blue-100', 'text-blue-800', 'border-blue-300');
            break;
        case 'card':
            activeButton.classList.add('bg-purple-100', 'text-purple-800', 'border-purple-300');
            break;
    }
    
    // Auto-submit form
    document.getElementById('filterForm').submit();
}

// Delete payment functionality (admin only)
<?php if ($_SESSION['user_role'] === 'admin'): ?>
function deletePayment(paymentId) {
    if (!confirm('Are you sure you want to delete this payment record?\n\nThis action cannot be undone and will affect the invoice balance.')) {
        return;
    }
    
    // Show loading
    const button = event.target.closest('button');
    const originalContent = button.innerHTML;
    button.innerHTML = '<svg class="w-3 h-3 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>';
    button.disabled = true;
    
    // Send delete request
    fetch('<?php echo Helper::baseUrl('modules/payments/delete.php'); ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ payment_id: paymentId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message and reload
            alert('Payment deleted successfully!');
            window.location.reload();
        } else {
            // Show error message
            alert(data.message || 'Error deleting payment. Please try again.');
            button.innerHTML = originalContent;
            button.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while deleting the payment. Please try again.');
        button.innerHTML = originalContent;
        button.disabled = false;
    });
}
<?php endif; ?>

// Auto-submit search with debounce
let searchTimeout;
document.querySelector('input[name="search"]')?.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        document.getElementById('filterForm').submit();
    }, 500);
});

// Loading states
document.querySelectorAll('a[href*="view.php"], a[href*="create.php"]').forEach(link => {
    link.addEventListener('click', function() {
        this.classList.add('loading');
        setTimeout(() => this.classList.remove('loading'), 3000);
    });
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // 'N' for new payment
    if (e.key.toLowerCase() === 'n' && !e.ctrlKey && !e.metaKey && !e.target.matches('input, textarea, select')) {
        e.preventDefault();
        window.location.href = '<?php echo Helper::baseUrl('modules/payments/create.php'); ?>';
    }
    
    // '/' for search focus
    if (e.key === '/' && !e.target.matches('input, textarea, select')) {
        e.preventDefault();
        document.querySelector('input[name="search"]')?.focus();
    }
    
    // 'Escape' to clear search
    if (e.key === 'Escape' && e.target.matches('input[name="search"]')) {
        e.target.value = '';
        document.getElementById('filterForm').submit();
    }
    
    // 'F' for filters toggle on mobile
    if (e.key.toLowerCase() === 'f' && !e.ctrlKey && !e.metaKey && !e.target.matches('input, textarea, select')) {
        e.preventDefault();
        if (window.innerWidth < 1024) {
            document.getElementById('mobileFiltersToggle')?.click();
        } else {
            document.getElementById('desktopFiltersToggle')?.click();
        }
    }
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Show advanced filters if any are active
    const hasAdvancedFilters = <?php echo json_encode(!empty($filters['client']) || !empty($filters['date_from']) || !empty($filters['date_to'])); ?>;
    if (hasAdvancedFilters) {
        document.getElementById('advancedFilters').classList.remove('hidden');
        const desktopIcon = document.getElementById('desktopFiltersIcon');
        if (desktopIcon) desktopIcon.style.transform = 'rotate(180deg)';
    }
    
    // Intersection observer for fade-in animations
    if ('IntersectionObserver' in window) {
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);
        
        // Observe payment cards
        document.querySelectorAll('.md\\:hidden > div, tbody tr').forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = `opacity 0.6s ease-out ${index * 0.1}s, transform 0.6s ease-out ${index * 0.1}s`;
            observer.observe(card);
        });
    }
    
    // Update active filters counter
    function updateActiveFiltersCount() {
        const count = <?php echo count($activeFilters ?? []); ?>;
        const counter = document.getElementById('activeFiltersCount');
        if (counter) counter.textContent = count;
    }
    
    updateActiveFiltersCount();
});

// Copy payment reference to clipboard
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('font-mono')) {
        const text = e.target.textContent.trim();
        if (text && text !== '—') {
            navigator.clipboard.writeText(text).then(() => {
                // Show brief feedback
                const originalText = e.target.textContent;
                e.target.textContent = 'Copied!';
                setTimeout(() => {
                    e.target.textContent = originalText;
                }, 1000);
            }).catch(err => {
                console.error('Could not copy text: ', err);
            });
        }
    }
});

// Service Worker for offline functionality (optional)
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('/sw.js').catch(function(error) {
            console.log('SW registration failed');
        });
    });
}

// Performance optimizations
// Lazy load images if any
document.addEventListener('DOMContentLoaded', function() {
    if ('IntersectionObserver' in window) {
        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    img.src = img.dataset.src;
                    img.classList.remove('lazy');
                    imageObserver.unobserve(img);
                }
            });
        });
        
        document.querySelectorAll('img[data-src]').forEach(img => {
            imageObserver.observe(img);
        });
    }
});
</script>

<?php include '../../includes/footer.php'; ?>