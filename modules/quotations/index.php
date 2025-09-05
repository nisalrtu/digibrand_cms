<?php
// modules/quotations/index.php
session_start();

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

$pageTitle = 'Quotations - Invoice Manager';

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
$validSortColumns = ['quotation_number', 'quotation_date', 'expiry_date', 'total_amount', 'status', 'company_name', 'created_at'];
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
        $whereConditions[] = "(q.quotation_number LIKE :search OR c.company_name LIKE :search OR q.notes LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    // Status filter
    if (!empty($statusFilter)) {
        $whereConditions[] = "q.status = :status";
        $params[':status'] = $statusFilter;
    }
    
    // Client filter
    if (!empty($clientFilter)) {
        $clientId = Helper::decryptId($clientFilter);
        if ($clientId) {
            $whereConditions[] = "q.client_id = :client_id";
            $params[':client_id'] = $clientId;
        }
    }
    
    // Date range filter
    if (!empty($dateFrom)) {
        $whereConditions[] = "q.quotation_date >= :date_from";
        $params[':date_from'] = $dateFrom;
    }
    
    if (!empty($dateTo)) {
        $whereConditions[] = "q.quotation_date <= :date_to";
        $params[':date_to'] = $dateTo;
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Get total count
    $countQuery = "
        SELECT COUNT(q.id) as total
        FROM quotations q 
        LEFT JOIN clients c ON q.client_id = c.id 
        $whereClause
    ";
    $countStmt = $db->prepare($countQuery);
    $countStmt->execute($params);
    $totalQuotations = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalQuotations / $perPage);
    
    // Get quotations
    $quotationsQuery = "
        SELECT q.*, 
               c.company_name, c.contact_person,
               u.username as created_by_name,
               (SELECT COUNT(*) FROM quotation_items qi WHERE qi.quotation_id = q.id) as item_count
        FROM quotations q 
        LEFT JOIN clients c ON q.client_id = c.id 
        LEFT JOIN users u ON q.created_by = u.id
        $whereClause
        ORDER BY $sortBy $sortOrder
        LIMIT $perPage OFFSET $offset
    ";
    $quotationsStmt = $db->prepare($quotationsQuery);
    $quotationsStmt->execute($params);
    $quotations = $quotationsStmt->fetchAll(PDO::FETCH_ASSOC);
    
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
    Helper::setMessage('Error loading quotations: ' . $e->getMessage(), 'error');
    $quotations = [];
    $clients = [];
    $totalPages = 0;
    $totalQuotations = 0;
}

include '../../includes/header.php';
?>

<!-- Page Header with Mobile-Optimized Layout -->
<div class="mb-4">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Quotations</h1>
            <p class="text-gray-600 mt-1 hidden sm:block">Manage and track all your quotations</p>
        </div>
        <div class="flex flex-col sm:flex-row gap-3">
            <a href="<?php echo Helper::baseUrl('modules/quotations/create.php'); ?>" 
               class="btn-primary inline-flex items-center justify-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Create Quotation
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
                   placeholder="Search quotation #, client, notes..."
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
            <button type="button" onclick="setStatusFilter('draft')"
                    class="status-filter flex-1 sm:flex-none px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo $statusFilter === 'draft' ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'; ?>">
                Draft
            </button>
            <button type="button" onclick="setStatusFilter('sent')"
                    class="status-filter flex-1 sm:flex-none px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo $statusFilter === 'sent' ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'; ?>">
                Sent
            </button>
            <button type="button" onclick="setStatusFilter('approved')"
                    class="status-filter flex-1 sm:flex-none px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo $statusFilter === 'approved' ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'; ?>">
                Approved
            </button>
            <button type="button" onclick="setStatusFilter('rejected')"
                    class="status-filter flex-1 sm:flex-none px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo $statusFilter === 'rejected' ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'; ?>">
                Rejected
            </button>
            <button type="button" onclick="setStatusFilter('expired')"
                    class="status-filter flex-1 sm:flex-none px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo $statusFilter === 'expired' ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'; ?>">
                Expired
            </button>
        </div>

        <!-- Advanced Filters (collapsible) -->
        <div class="collapse-content" id="advancedFilters">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 pt-4 border-t border-gray-200">
                <!-- Client Filter -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                    <select name="client" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">All Clients</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?php echo Helper::encryptId($client['id']); ?>" 
                                    <?php echo $clientFilter === Helper::encryptId($client['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($client['company_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Date From -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">From Date</label>
                    <input type="date" 
                           name="date_from" 
                           value="<?php echo htmlspecialchars($dateFrom); ?>"
                           class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>

                <!-- Date To -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">To Date</label>
                    <input type="date" 
                           name="date_to" 
                           value="<?php echo htmlspecialchars($dateTo); ?>"
                           class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>

                <!-- Sort Options -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sort By</label>
                    <select name="sort" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="created_at" <?php echo $sortBy === 'created_at' ? 'selected' : ''; ?>>Date Created</option>
                        <option value="quotation_date" <?php echo $sortBy === 'quotation_date' ? 'selected' : ''; ?>>Quotation Date</option>
                        <option value="expiry_date" <?php echo $sortBy === 'expiry_date' ? 'selected' : ''; ?>>Expiry Date</option>
                        <option value="total_amount" <?php echo $sortBy === 'total_amount' ? 'selected' : ''; ?>>Total Amount</option>
                        <option value="company_name" <?php echo $sortBy === 'company_name' ? 'selected' : ''; ?>>Client Name</option>
                        <option value="quotation_number" <?php echo $sortBy === 'quotation_number' ? 'selected' : ''; ?>>Quotation Number</option>
                    </select>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-col sm:flex-row gap-3 pt-4">
                <button type="submit" 
                        class="flex-1 sm:flex-none inline-flex items-center justify-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    Apply Filters
                </button>
                <a href="<?php echo Helper::baseUrl('modules/quotations/'); ?>" 
                   class="flex-1 sm:flex-none inline-flex items-center justify-center px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                    Clear All
                </a>
            </div>
        </div>

        <!-- Toggle Advanced Filters -->
        <button type="button" 
                onclick="toggleAdvancedFilters()" 
                class="w-full flex items-center justify-center px-4 py-2 text-sm text-gray-600 hover:text-gray-800 transition-colors">
            <span id="toggleText">Show Advanced Filters</span>
            <svg id="toggleIcon" class="w-4 h-4 ml-2 transform transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
        </button>

        <!-- Hidden inputs to preserve order -->
        <input type="hidden" name="order" value="<?php echo htmlspecialchars($sortOrder); ?>">
    </form>
</div>

<!-- Active Filters Display -->
<?php if (!empty(array_filter([$search, $statusFilter, $clientFilter, $dateFrom, $dateTo]))): ?>
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-4">
        <div class="flex items-center justify-between">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm font-medium text-blue-800">Active Filters:</span>
                <?php if (!empty($search)): ?>
                    <span class="inline-flex items-center px-2 py-1 bg-white border border-blue-200 rounded text-xs text-blue-800">
                        Search: "<?php echo htmlspecialchars(strlen($search) > 20 ? substr($search, 0, 20) . '...' : $search); ?>"
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
            <a href="<?php echo Helper::baseUrl('modules/quotations/'); ?>" class="text-sm text-blue-600 hover:text-blue-700">Clear</a>
        </div>
    </div>
<?php endif; ?>

<!-- Quotations List - THE MAIN CONTENT -->
<div class="space-y-4">
    <?php if (empty($quotations)): ?>
        <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
            <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
            </svg>
            <h3 class="text-lg font-medium text-gray-900 mb-2">No quotations found</h3>
            <p class="text-gray-600 mb-6">
                <?php if (!empty(array_filter([$search, $statusFilter, $clientFilter, $dateFrom, $dateTo]))): ?>
                    Try adjusting your filters or search terms.
                <?php else: ?>
                    Get started by creating your first quotation.
                <?php endif; ?>
            </p>
            <a href="<?php echo Helper::baseUrl('modules/quotations/create.php'); ?>" 
               class="inline-flex items-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Create First Quotation
            </a>
        </div>
    <?php else: ?>
        <!-- Desktop Table View -->
        <div class="hidden lg:block bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left">
                                <button onclick="sortBy('quotation_number')" class="group flex items-center space-x-1 text-xs font-medium text-gray-500 uppercase tracking-wider hover:text-gray-700">
                                    <span>Quotation #</span>
                                    <svg class="w-3 h-3 <?php echo $sortBy === 'quotation_number' ? 'text-gray-700' : 'text-gray-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"></path>
                                    </svg>
                                </button>
                            </th>
                            <th scope="col" class="px-4 py-3 text-left">
                                <button onclick="sortBy('company_name')" class="group flex items-center space-x-1 text-xs font-medium text-gray-500 uppercase tracking-wider hover:text-gray-700">
                                    <span>Client</span>
                                    <svg class="w-3 h-3 <?php echo $sortBy === 'company_name' ? 'text-gray-700' : 'text-gray-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"></path>
                                    </svg>
                                </button>
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Items
                            </th>
                            <th scope="col" class="px-4 py-3 text-right">
                                <button onclick="sortBy('total_amount')" class="group flex items-center space-x-1 text-xs font-medium text-gray-500 uppercase tracking-wider hover:text-gray-700 ml-auto">
                                    <span>Total</span>
                                    <svg class="w-3 h-3 <?php echo $sortBy === 'total_amount' ? 'text-gray-700' : 'text-gray-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"></path>
                                    </svg>
                                </button>
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
                        <?php foreach ($quotations as $quotation): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <!-- Quotation # -->
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900">
                                            <a href="<?php echo Helper::baseUrl('modules/quotations/view.php?id=' . Helper::encryptId($quotation['id'])); ?>" 
                                               class="hover:text-blue-600 transition-colors">
                                                #<?php echo htmlspecialchars($quotation['quotation_number']); ?>
                                            </a>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            Expires: <?php echo Helper::formatDate($quotation['expiry_date'], 'M j, Y'); ?>
                                        </div>
                                    </div>
                                </td>

                                <!-- Client -->
                                <td class="px-4 py-4">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($quotation['company_name']); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($quotation['contact_person']); ?></div>
                                </td>

                                <!-- Items -->
                                <td class="px-4 py-4">
                                    <div class="text-sm text-gray-900">
                                        <?php echo $quotation['item_count']; ?> item<?php echo $quotation['item_count'] !== '1' ? 's' : ''; ?>
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        Created: <?php echo Helper::formatDate($quotation['quotation_date'], 'M j, Y'); ?>
                                    </div>
                                </td>

                                <!-- Total -->
                                <td class="px-4 py-4 text-right">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo Helper::formatCurrency($quotation['total_amount']); ?>
                                    </div>
                                    <?php if ($quotation['tax_amount'] > 0): ?>
                                        <div class="text-xs text-gray-500">
                                            +<?php echo Helper::formatCurrency($quotation['tax_amount']); ?> tax
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- Status -->
                                <td class="px-4 py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                        <?php 
                                        switch ($quotation['status']) {
                                            case 'draft':
                                                echo 'bg-gray-100 text-gray-800';
                                                break;
                                            case 'sent':
                                                echo 'bg-blue-100 text-blue-800';
                                                break;
                                            case 'approved':
                                                echo 'bg-green-100 text-green-800';
                                                break;
                                            case 'rejected':
                                                echo 'bg-red-100 text-red-800';
                                                break;
                                            case 'expired':
                                                echo 'bg-yellow-100 text-yellow-800';
                                                break;
                                            default:
                                                echo 'bg-gray-100 text-gray-800';
                                        }
                                        ?>">
                                        <?php echo ucwords(str_replace('_', ' ', $quotation['status'])); ?>
                                    </span>
                                </td>

                                <!-- Actions -->
                                <td class="px-4 py-4 text-right">
                                    <div class="flex items-center justify-end space-x-2">
                                        <a href="<?php echo Helper::baseUrl('modules/quotations/view.php?id=' . Helper::encryptId($quotation['id'])); ?>" 
                                           class="text-gray-600 hover:text-blue-600 transition-colors" 
                                           title="View Quotation">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                            </svg>
                                        </a>
                                        <?php if ($quotation['status'] === 'draft' || $quotation['status'] === 'sent'): ?>
                                            <a href="<?php echo Helper::baseUrl('modules/quotations/edit.php?id=' . Helper::encryptId($quotation['id'])); ?>" 
                                               class="text-gray-600 hover:text-blue-600 transition-colors" 
                                               title="Edit Quotation">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                </svg>
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($quotation['status'] === 'approved'): ?>
                                            <a href="<?php echo Helper::baseUrl('modules/invoices/create.php?quotation_id=' . Helper::encryptId($quotation['id'])); ?>" 
                                               class="text-gray-600 hover:text-green-600 transition-colors" 
                                               title="Convert to Invoice">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
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
        </div>

        <!-- Mobile Card View -->
        <div class="lg:hidden space-y-4">
            <?php foreach ($quotations as $quotation): ?>
                <div class="bg-white rounded-xl border border-gray-200 p-4">
                    <div class="flex items-start justify-between mb-3">
                        <div class="flex-1">
                            <div class="flex items-center space-x-2 mb-1">
                                <h3 class="text-sm font-medium text-gray-900">
                                    <a href="<?php echo Helper::baseUrl('modules/quotations/view.php?id=' . Helper::encryptId($quotation['id'])); ?>" 
                                       class="hover:text-blue-600 transition-colors">
                                        #<?php echo htmlspecialchars($quotation['quotation_number']); ?>
                                    </a>
                                </h3>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                    <?php 
                                    switch ($quotation['status']) {
                                        case 'draft':
                                            echo 'bg-gray-100 text-gray-800';
                                            break;
                                        case 'sent':
                                            echo 'bg-blue-100 text-blue-800';
                                            break;
                                        case 'approved':
                                            echo 'bg-green-100 text-green-800';
                                            break;
                                        case 'rejected':
                                            echo 'bg-red-100 text-red-800';
                                            break;
                                        case 'expired':
                                            echo 'bg-yellow-100 text-yellow-800';
                                            break;
                                        default:
                                            echo 'bg-gray-100 text-gray-800';
                                    }
                                    ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $quotation['status'])); ?>
                                </span>
                            </div>
                            <p class="text-sm text-gray-600 mb-1"><?php echo htmlspecialchars($quotation['company_name']); ?></p>
                            <p class="text-xs text-gray-500">
                                <?php echo $quotation['item_count']; ?> item<?php echo $quotation['item_count'] !== '1' ? 's' : ''; ?> • 
                                Expires: <?php echo Helper::formatDate($quotation['expiry_date'], 'M j, Y'); ?>
                            </p>
                        </div>
                        <div class="text-right">
                            <div class="text-lg font-semibold text-gray-900"><?php echo Helper::formatCurrency($quotation['total_amount']); ?></div>
                            <?php if ($quotation['tax_amount'] > 0): ?>
                                <div class="text-xs text-gray-500">+<?php echo Helper::formatCurrency($quotation['tax_amount']); ?> tax</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="flex space-x-2">
                        <a href="<?php echo Helper::baseUrl('modules/quotations/view.php?id=' . Helper::encryptId($quotation['id'])); ?>" 
                           class="flex-1 inline-flex items-center justify-center px-3 py-2 bg-gray-100 text-gray-700 rounded text-xs font-medium hover:bg-gray-200 transition-colors">
                            View
                        </a>
                        <?php if ($quotation['status'] === 'draft' || $quotation['status'] === 'sent'): ?>
                            <a href="<?php echo Helper::baseUrl('modules/quotations/edit.php?id=' . Helper::encryptId($quotation['id'])); ?>" 
                               class="flex-1 inline-flex items-center justify-center px-3 py-2 bg-blue-100 text-blue-700 rounded text-xs font-medium hover:bg-blue-200 transition-colors">
                                Edit
                            </a>
                        <?php endif; ?>
                        <?php if ($quotation['status'] === 'approved'): ?>
                            <a href="<?php echo Helper::baseUrl('modules/invoices/create.php?quotation_id=' . Helper::encryptId($quotation['id'])); ?>" 
                               class="flex-1 inline-flex items-center justify-center px-3 py-2 bg-green-100 text-green-700 rounded text-xs font-medium hover:bg-green-200 transition-colors">
                                Convert
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-4">
                <div class="flex items-center justify-between">
                    <div class="text-sm text-gray-600 hidden sm:block">
                        Showing <?php echo (($page - 1) * $perPage) + 1; ?> to <?php echo min($page * $perPage, $totalQuotations); ?> of <?php echo $totalQuotations; ?> quotations
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
                               class="px-3 py-2 rounded-lg transition-colors text-sm <?php echo $i === $page ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
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

<script>
// Filter and Sort Functions
function setStatusFilter(status) {
    const form = document.getElementById('filterForm');
    const statusInput = document.createElement('input');
    statusInput.type = 'hidden';
    statusInput.name = 'status';
    statusInput.value = status;
    
    // Remove existing status input
    const existingStatus = form.querySelector('input[name="status"]');
    if (existingStatus) {
        existingStatus.remove();
    }
    
    form.appendChild(statusInput);
    form.submit();
}

function sortBy(column) {
    const urlParams = new URLSearchParams(window.location.search);
    const currentSort = urlParams.get('sort');
    const currentOrder = urlParams.get('order');
    
    let newOrder = 'DESC';
    if (currentSort === column && currentOrder === 'DESC') {
        newOrder = 'ASC';
    }
    
    urlParams.set('sort', column);
    urlParams.set('order', newOrder);
    
    window.location.search = urlParams.toString();
}

function toggleAdvancedFilters() {
    const advancedFilters = document.getElementById('advancedFilters');
    const toggleText = document.getElementById('toggleText');
    const toggleIcon = document.getElementById('toggleIcon');
    
    if (advancedFilters.style.display === 'none' || advancedFilters.style.display === '') {
        advancedFilters.style.display = 'block';
        toggleText.textContent = 'Hide Advanced Filters';
        toggleIcon.style.transform = 'rotate(180deg)';
    } else {
        advancedFilters.style.display = 'none';
        toggleText.textContent = 'Show Advanced Filters';
        toggleIcon.style.transform = 'rotate(0deg)';
    }
}

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    // Hide advanced filters by default
    const advancedFilters = document.getElementById('advancedFilters');
    const hasAdvancedFilters = <?php echo json_encode(!empty($clientFilter) || !empty($dateFrom) || !empty($dateTo)); ?>;
    
    if (!hasAdvancedFilters) {
        advancedFilters.style.display = 'none';
    } else {
        document.getElementById('toggleText').textContent = 'Hide Advanced Filters';
        document.getElementById('toggleIcon').style.transform = 'rotate(180deg)';
    }
    
    // Auto-submit form on filter changes
    const autoSubmitElements = document.querySelectorAll('select[name="client"], input[name="date_from"], input[name="date_to"], select[name="sort"]');
    autoSubmitElements.forEach(element => {
        element.addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });
    });
    
    // Search input auto-submit with debounce
    const searchInput = document.querySelector('input[name="search"]');
    let searchTimeout;
    
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            if (this.value.length >= 3 || this.value.length === 0) {
                document.getElementById('filterForm').submit();
            }
        }, 500);
    });
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.target.matches('input, textarea, select')) return;
        
        switch(e.key.toLowerCase()) {
            case 'n':
                if (!e.ctrlKey && !e.metaKey) {
                    e.preventDefault();
                    window.location.href = '<?php echo Helper::baseUrl('modules/quotations/create.php'); ?>';
                }
                break;
            case 'f':
                if (!e.ctrlKey && !e.metaKey) {
                    e.preventDefault();
                    document.querySelector('input[name="search"]').focus();
                }
                break;
            case 'escape':
                // Clear search
                const searchField = document.querySelector('input[name="search"]');
                if (searchField.value) {
                    searchField.value = '';
                    document.getElementById('filterForm').submit();
                }
                break;
        }
    });
    
    // Add loading states for better UX
    const actionLinks = document.querySelectorAll('a[href*="view.php"], a[href*="edit.php"], a[href*="create.php"]');
    actionLinks.forEach(link => {
        link.addEventListener('click', function() {
            this.style.opacity = '0.6';
            this.style.pointerEvents = 'none';
            
            // Reset after timeout
            setTimeout(() => {
                this.style.opacity = '';
                this.style.pointerEvents = '';
            }, 3000);
        });
    });
    
    // Touch-friendly interactions for mobile
    if ('ontouchstart' in window) {
        const touchableElements = document.querySelectorAll('.status-filter, .bg-white.rounded-xl');
        touchableElements.forEach(element => {
            element.addEventListener('touchstart', function() {
                this.style.transform = 'scale(0.98)';
            }, { passive: true });
            
            element.addEventListener('touchend', function() {
                this.style.transform = '';
            }, { passive: true });
        });
    }
    
    // Check for expired quotations and highlight them
    const today = new Date();
    const quotationRows = document.querySelectorAll('tbody tr, .lg\\:hidden > div');
    
    quotationRows.forEach(row => {
        const expiryText = row.querySelector('[data-expiry]')?.textContent || 
                          row.textContent.match(/Expires: (.+?)(?:\s|$)/)?.[1];
        
        if (expiryText) {
            const expiryDate = new Date(expiryText);
            const daysUntilExpiry = Math.ceil((expiryDate - today) / (1000 * 60 * 60 * 24));
            
            if (daysUntilExpiry <= 7 && daysUntilExpiry > 0) {
                row.style.backgroundColor = '#fef3cd'; // Warning yellow
                row.style.borderLeftColor = '#f59e0b';
                row.style.borderLeftWidth = '4px';
            } else if (daysUntilExpiry <= 0) {
                row.style.backgroundColor = '#fee2e2'; // Error red
                row.style.borderLeftColor = '#ef4444';
                row.style.borderLeftWidth = '4px';
            }
        }
    });
});

// Export/Print functionality
function exportQuotations() {
    const currentUrl = new URL(window.location);
    currentUrl.pathname = currentUrl.pathname.replace('index.php', 'export.php');
    window.open(currentUrl.toString(), '_blank');
}

function printQuotationsList() {
    window.print();
}

// Bulk actions (placeholder for future implementation)
function initializeBulkActions() {
    // This would be implemented when bulk actions are needed
    // e.g., bulk delete, bulk status change, etc.
}
</script>

<style>
/* Additional styles for better mobile experience */
@media (max-width: 640px) {
    .status-filter {
        font-size: 0.75rem;
        padding: 0.5rem 0.75rem;
    }
    
    .collapse-content {
        transition: all 0.3s ease;
    }
}

/* Print styles */
@media print {
    .no-print {
        display: none !important;
    }
    
    .bg-white {
        box-shadow: none !important;
    }
    
    .hover\:bg-gray-50:hover {
        background-color: transparent !important;
    }
}

/* Loading state styles */
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
    width: 16px;
    height: 16px;
    margin: -8px 0 0 -8px;
    border: 2px solid transparent;
    border-top: 2px solid #3b82f6;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Accessibility improvements */
.focus\:ring-2:focus {
    outline: none;
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.5);
}

/* Status-specific styling */
.status-expired {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.8; }
}
</style>

<?php include '../../includes/footer.php'; ?>