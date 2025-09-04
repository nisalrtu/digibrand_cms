<?php
// modules/projects/index.php
session_start();

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

$pageTitle = 'Projects - Invoice Manager';

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Initialize filter variables
$filters = [
    'search' => $_GET['search'] ?? '',
    'status' => $_GET['status'] ?? '',
    'project_type' => $_GET['project_type'] ?? '',
    'client_id' => $_GET['client_id'] ?? '',
    'date_range' => $_GET['date_range'] ?? '',
    'amount_range' => $_GET['amount_range'] ?? '',
    'sort_by' => $_GET['sort_by'] ?? 'created_at',
    'sort_order' => $_GET['sort_order'] ?? 'DESC'
];

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 12; // Projects per page
$offset = ($page - 1) * $limit;

try {
    // Get filter options for dropdowns
    $statusOptions = [
        'pending' => 'Pending',
        'in_progress' => 'In Progress', 
        'completed' => 'Completed',
        'on_hold' => 'On Hold',
        'cancelled' => 'Cancelled'
    ];

    $projectTypeOptions = [];
    $typeQuery = "SELECT DISTINCT project_type FROM projects WHERE project_type IS NOT NULL ORDER BY project_type";
    $typeStmt = $db->prepare($typeQuery);
    $typeStmt->execute();
    while ($row = $typeStmt->fetch(PDO::FETCH_ASSOC)) {
        $projectTypeOptions[$row['project_type']] = ucwords(str_replace('_', ' ', $row['project_type']));
    }

    // Get top clients with project counts
    $topClientsQuery = "
        SELECT c.id, c.company_name, c.contact_person,
               COUNT(p.id) as project_count,
               COALESCE(SUM(p.total_amount), 0) as total_value,
               COALESCE(SUM(CASE WHEN p.status = 'completed' THEN p.total_amount ELSE 0 END), 0) as completed_value,
               MAX(p.created_at) as last_project_date
        FROM clients c
        LEFT JOIN projects p ON c.id = p.client_id
        WHERE c.is_active = 1
        GROUP BY c.id, c.company_name, c.contact_person
        HAVING project_count > 0
        ORDER BY project_count DESC, total_value DESC
        LIMIT 6
    ";
    $topClientsStmt = $db->prepare($topClientsQuery);
    $topClientsStmt->execute();
    $topClients = $topClientsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Build the main projects query with filters
    $whereConditions = ['1=1']; // Always true condition to start
    $params = [];

    // Search filter
    if (!empty($filters['search'])) {
        $whereConditions[] = "(p.project_name LIKE :search OR c.company_name LIKE :search OR p.description LIKE :search)";
        $params[':search'] = '%' . $filters['search'] . '%';
    }

    // Status filter
    if (!empty($filters['status'])) {
        $whereConditions[] = "p.status = :status";
        $params[':status'] = $filters['status'];
    }

    // Project type filter
    if (!empty($filters['project_type'])) {
        $whereConditions[] = "p.project_type = :project_type";
        $params[':project_type'] = $filters['project_type'];
    }

    // Client filter
    if (!empty($filters['client_id'])) {
        $clientFilterId = Helper::decryptId($filters['client_id']);
        if ($clientFilterId) {
            $whereConditions[] = "p.client_id = :client_id";
            $params[':client_id'] = $clientFilterId;
        }
    }

    // Date range filter
    if (!empty($filters['date_range'])) {
        switch ($filters['date_range']) {
            case 'today':
                $whereConditions[] = "DATE(p.created_at) = CURDATE()";
                break;
            case 'week':
                $whereConditions[] = "p.created_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
                break;
            case 'month':
                $whereConditions[] = "p.created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
                break;
            case 'quarter':
                $whereConditions[] = "p.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)";
                break;
            case 'year':
                $whereConditions[] = "p.created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
                break;
        }
    }

    // Amount range filter
    if (!empty($filters['amount_range'])) {
        switch ($filters['amount_range']) {
            case 'under_10k':
                $whereConditions[] = "p.total_amount < 10000";
                break;
            case '10k_50k':
                $whereConditions[] = "p.total_amount >= 10000 AND p.total_amount < 50000";
                break;
            case '50k_100k':
                $whereConditions[] = "p.total_amount >= 50000 AND p.total_amount < 100000";
                break;
            case '100k_500k':
                $whereConditions[] = "p.total_amount >= 100000 AND p.total_amount < 500000";
                break;
            case 'over_500k':
                $whereConditions[] = "p.total_amount >= 500000";
                break;
        }
    }

    // Sorting
    $allowedSortFields = ['created_at', 'project_name', 'total_amount', 'start_date', 'end_date', 'status'];
    $sortBy = in_array($filters['sort_by'], $allowedSortFields) ? $filters['sort_by'] : 'created_at';
    $sortOrder = strtoupper($filters['sort_order']) === 'ASC' ? 'ASC' : 'DESC';

    // Count total projects for pagination
    $countQuery = "
        SELECT COUNT(*) as total
        FROM projects p
        LEFT JOIN clients c ON p.client_id = c.id
        WHERE " . implode(' AND ', $whereConditions);
    
    $countStmt = $db->prepare($countQuery);
    $countStmt->execute($params);
    $totalProjects = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalProjects / $limit);

    // Get projects with pagination
    $projectsQuery = "
        SELECT p.*, c.company_name, c.contact_person, c.mobile_number,
               u.username as created_by_name,
               (SELECT COUNT(*) FROM invoices WHERE project_id = p.id) as invoice_count,
               (SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE project_id = p.id) as invoiced_amount,
               (SELECT COALESCE(SUM(balance_amount), 0) FROM invoices WHERE project_id = p.id AND status IN ('sent', 'partially_paid', 'overdue')) as outstanding_amount
        FROM projects p
        LEFT JOIN clients c ON p.client_id = c.id
        LEFT JOIN users u ON p.created_by = u.id
        WHERE " . implode(' AND ', $whereConditions) . "
        ORDER BY p.{$sortBy} {$sortOrder}
        LIMIT :limit OFFSET :offset
    ";

    $projectsStmt = $db->prepare($projectsQuery);
    
    // Bind filter parameters
    foreach ($params as $key => $value) {
        $projectsStmt->bindValue($key, $value);
    }
    
    // Bind pagination parameters
    $projectsStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $projectsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $projectsStmt->execute();
    $projects = $projectsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get overall statistics
    $statsQuery = "
        SELECT 
            COUNT(*) as total_projects,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_projects,
            COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as active_projects,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_projects,
            COALESCE(SUM(total_amount), 0) as total_value,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END), 0) as completed_value
        FROM projects p
        WHERE " . implode(' AND ', $whereConditions);
    
    $statsStmt = $db->prepare($statsQuery);
    $statsStmt->execute($params);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    Helper::setMessage('Error loading projects: ' . $e->getMessage(), 'error');
    $projects = [];
    $topClients = [];
    $stats = [];
    $totalPages = 1;
}

include '../../includes/header.php';
?>

<!-- Page Header with Mobile-Optimized Layout -->
<div class="mb-4">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Projects</h1>
            <p class="text-gray-600 mt-1 hidden sm:block">Manage and track your client projects</p>
        </div>
        <div class="flex flex-col sm:flex-row gap-3">
            <a href="<?php echo Helper::baseUrl('modules/projects/add.php'); ?>" 
               class="btn-primary inline-flex items-center justify-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                New Project
            </a>
        </div>
    </div>
</div>

<!-- Mobile-First: Search and Quick Filters Only -->
<div class="bg-white rounded-xl border border-gray-200 p-4 mb-4">
    <form method="GET" class="space-y-4" id="filterForm">
        <!-- Essential Search -->
        <div class="relative">
            <input type="text" 
                   name="search" 
                   value="<?php echo htmlspecialchars($filters['search']); ?>"
                   placeholder="Search projects, clients..."
                   class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            <svg class="w-5 h-5 text-gray-400 absolute left-3 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
            </svg>
        </div>

        <!-- Compact Status Filters - Mobile Optimized -->
        <div class="flex flex-wrap gap-2">
            <button type="button" onclick="setStatusFilter('')" 
                    class="status-filter flex-1 sm:flex-none px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo empty($filters['status']) ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'; ?>">
                All
            </button>
            <button type="button" onclick="setStatusFilter('pending')"
                    class="status-filter flex-1 sm:flex-none px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo $filters['status'] === 'pending' ? 'bg-yellow-100 text-yellow-800 border-yellow-300' : 'bg-white text-gray-700 border-gray-300 hover:bg-yellow-50 hover:text-yellow-700'; ?>">
                Pending
            </button>
            <button type="button" onclick="setStatusFilter('in_progress')"
                    class="status-filter flex-1 sm:flex-none px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo $filters['status'] === 'in_progress' ? 'bg-blue-100 text-blue-800 border-blue-300' : 'bg-white text-gray-700 border-gray-300 hover:bg-blue-50 hover:text-blue-700'; ?>">
                Active
            </button>
            <button type="button" onclick="setStatusFilter('completed')"
                    class="status-filter flex-1 sm:flex-none px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo $filters['status'] === 'completed' ? 'bg-green-100 text-green-800 border-green-300' : 'bg-white text-gray-700 border-gray-300 hover:bg-green-50 hover:text-green-700'; ?>">
                Done
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
                        return !empty($value) && !in_array($key, ['search', 'status', 'sort_by', 'sort_order']); 
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
            <!-- Top Clients Section - Moved Inside Advanced Filters -->
            <?php if (!empty($topClients)): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Quick Client Filter</label>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($topClients as $client): ?>
                            <button type="button" 
                                    class="client-filter inline-flex items-center px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-blue-100 hover:text-blue-700 transition-colors text-sm border border-gray-200 hover:border-blue-300"
                                    data-client-id="<?php echo Helper::encryptId($client['id']); ?>"
                                    data-client-name="<?php echo htmlspecialchars($client['company_name']); ?>"
                                    title="<?php echo $client['project_count']; ?> projects">
                                <?php echo htmlspecialchars($client['company_name']); ?>
                                <span class="ml-1.5 inline-flex items-center justify-center w-5 h-5 bg-blue-100 text-blue-800 text-xs rounded-full">
                                    <?php echo $client['project_count']; ?>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Advanced Filter Options -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <!-- Project Type -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Project Type</label>
                    <select name="project_type" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">All Types</option>
                        <?php foreach ($projectTypeOptions as $type => $label): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $filters['project_type'] === $type ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Date Range -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date Range</label>
                    <select name="date_range" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">All Time</option>
                        <option value="today" <?php echo $filters['date_range'] === 'today' ? 'selected' : ''; ?>>Today</option>
                        <option value="week" <?php echo $filters['date_range'] === 'week' ? 'selected' : ''; ?>>Last Week</option>
                        <option value="month" <?php echo $filters['date_range'] === 'month' ? 'selected' : ''; ?>>Last Month</option>
                        <option value="quarter" <?php echo $filters['date_range'] === 'quarter' ? 'selected' : ''; ?>>Last Quarter</option>
                        <option value="year" <?php echo $filters['date_range'] === 'year' ? 'selected' : ''; ?>>Last Year</option>
                    </select>
                </div>

                <!-- Amount Range -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount Range</label>
                    <select name="amount_range" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">All Amounts</option>
                        <option value="under_10k" <?php echo $filters['amount_range'] === 'under_10k' ? 'selected' : ''; ?>>Under LKR 10,000</option>
                        <option value="10k_50k" <?php echo $filters['amount_range'] === '10k_50k' ? 'selected' : ''; ?>>LKR 10,000 - 50,000</option>
                        <option value="50k_100k" <?php echo $filters['amount_range'] === '50k_100k' ? 'selected' : ''; ?>>LKR 50,000 - 100,000</option>
                        <option value="100k_500k" <?php echo $filters['amount_range'] === '100k_500k' ? 'selected' : ''; ?>>LKR 100,000 - 500,000</option>
                        <option value="over_500k" <?php echo $filters['amount_range'] === 'over_500k' ? 'selected' : ''; ?>>Over LKR 500,000</option>
                    </select>
                </div>
            </div>

            <!-- Sort Options -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sort By</label>
                    <select name="sort_by" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="created_at" <?php echo $filters['sort_by'] === 'created_at' ? 'selected' : ''; ?>>Created Date</option>
                        <option value="project_name" <?php echo $filters['sort_by'] === 'project_name' ? 'selected' : ''; ?>>Project Name</option>
                        <option value="total_amount" <?php echo $filters['sort_by'] === 'total_amount' ? 'selected' : ''; ?>>Amount</option>
                        <option value="start_date" <?php echo $filters['sort_by'] === 'start_date' ? 'selected' : ''; ?>>Start Date</option>
                        <option value="end_date" <?php echo $filters['sort_by'] === 'end_date' ? 'selected' : ''; ?>>End Date</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Order</label>
                    <select name="sort_order" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="DESC" <?php echo $filters['sort_order'] === 'DESC' ? 'selected' : ''; ?>>Newest First</option>
                        <option value="ASC" <?php echo $filters['sort_order'] === 'ASC' ? 'selected' : ''; ?>>Oldest First</option>
                    </select>
                </div>
            </div>

            <!-- Filter Actions -->
            <div class="flex justify-between items-center pt-4 border-t">
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.207A1 1 0 013 6.5V4z"></path>
                    </svg>
                    Apply Filters
                </button>
                <a href="<?php echo Helper::baseUrl('modules/projects/'); ?>" class="text-sm text-gray-600 hover:text-gray-900">Clear All</a>
            </div>
        </div>

        <!-- Hidden inputs -->
        <input type="hidden" name="client_id" id="clientFilter" value="<?php echo htmlspecialchars($filters['client_id']); ?>">
        <input type="hidden" name="status" id="statusFilter" value="<?php echo htmlspecialchars($filters['status']); ?>">
    </form>
</div>

<!-- Active Filters Display - Compact -->
<?php if (!empty(array_filter($filters, function($v, $k) { return !empty($v) && !in_array($k, ['sort_by', 'sort_order']); }, ARRAY_FILTER_USE_BOTH))): ?>
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-4">
        <div class="flex items-center justify-between">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm font-medium text-blue-800">Filters:</span>
                <?php if (!empty($filters['search'])): ?>
                    <span class="inline-flex items-center px-2 py-1 bg-white border border-blue-200 rounded text-xs text-blue-800">
                        "<?php echo htmlspecialchars(substr($filters['search'], 0, 20)) . (strlen($filters['search']) > 20 ? '...' : ''); ?>"
                    </span>
                <?php endif; ?>
                <?php if (!empty($filters['status'])): ?>
                    <span class="inline-flex items-center px-2 py-1 bg-white border border-blue-200 rounded text-xs text-blue-800">
                        <?php echo ucwords(str_replace('_', ' ', $filters['status'])); ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($filters['client_id'])): ?>
                    <span class="inline-flex items-center px-2 py-1 bg-white border border-blue-200 rounded text-xs text-blue-800">
                        Client Selected
                    </span>
                <?php endif; ?>
            </div>
            <a href="<?php echo Helper::baseUrl('modules/projects/'); ?>" class="text-sm text-blue-600 hover:text-blue-700">Clear</a>
        </div>
    </div>
<?php endif; ?>

<!-- Projects List - THE MAIN CONTENT -->
<div class="space-y-4">
    <?php if (empty($projects)): ?>
        <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
            <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-2m-2 0H5m14 0V9a2 2 0 00-2-2H9a2 2 0 00-2 2v12a2 2 0 002 2h4m0 0V9a2 2 0 012-2h2a2 2 0 012 2v12"></path>
            </svg>
            <h3 class="text-lg font-medium text-gray-900 mb-2">No projects found</h3>
            <p class="text-gray-600 mb-6">
                <?php if (!empty(array_filter($filters))): ?>
                    Try adjusting your filters or search terms.
                <?php else: ?>
                    Get started by creating your first project.
                <?php endif; ?>
            </p>
            <?php if (empty(array_filter($filters))): ?>
                <a href="<?php echo Helper::baseUrl('modules/projects/add.php'); ?>" 
                   class="inline-flex items-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                    </svg>
                    Create First Project
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <!-- Projects Table/Cards -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <!-- Desktop Table -->
            <div class="hidden md:block overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Status
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Amount
                            </th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($projects as $project): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <!-- Project Name -->
                                <td class="px-4 py-4">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900">
                                            <a href="<?php echo Helper::baseUrl('modules/projects/view.php?id=' . Helper::encryptId($project['id'])); ?>" 
                                               class="hover:text-blue-600 transition-colors">
                                                <?php echo htmlspecialchars($project['project_name']); ?>
                                            </a>
                                        </div>
                                        <div class="text-xs text-gray-500 mt-1">
                                            <?php echo ucwords(str_replace('_', ' ', $project['project_type'])); ?>
                                        </div>
                                    </div>
                                </td>

                                <!-- Client -->
                                <td class="px-4 py-4">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900">
                                            <a href="<?php echo Helper::baseUrl('modules/clients/view.php?id=' . Helper::encryptId($project['client_id'])); ?>" 
                                               class="hover:text-blue-600 transition-colors">
                                                <?php echo htmlspecialchars($project['company_name']); ?>
                                            </a>
                                        </div>
                                        <div class="text-xs text-gray-500 mt-1">
                                            <?php echo htmlspecialchars($project['contact_person']); ?>
                                        </div>
                                    </div>
                                </td>

                                <!-- Status -->
                                <td class="px-4 py-4">
                                    <?php 
                                    $status = $project['status'];
                                    $statusClasses = [
                                        'pending' => 'bg-yellow-50 text-yellow-700 border border-yellow-200',
                                        'in_progress' => 'bg-blue-50 text-blue-700 border border-blue-200',
                                        'completed' => 'bg-green-50 text-green-700 border border-green-200',
                                        'on_hold' => 'bg-gray-50 text-gray-700 border border-gray-200',
                                        'cancelled' => 'bg-red-50 text-red-700 border border-red-200'
                                    ];
                                    $badgeClass = $statusClasses[$status] ?? 'bg-gray-50 text-gray-700 border border-gray-200';
                                    $statusLabel = ucwords(str_replace('_', ' ', $status));
                                    ?>
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium <?php echo $badgeClass; ?>">
                                        <?php echo $statusLabel; ?>
                                    </span>
                                </td>

                                <!-- Amount -->
                                <td class="px-4 py-4">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo Helper::formatCurrency($project['total_amount']); ?>
                                    </div>
                                    <?php if ($project['invoice_count'] > 0): ?>
                                        <div class="text-xs text-gray-500 mt-1">
                                            <?php echo $project['invoice_count']; ?> invoice<?php echo $project['invoice_count'] != 1 ? 's' : ''; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- Actions -->
                                <td class="px-4 py-4 text-right">
                                    <div class="flex justify-end space-x-2">
                                        <a href="<?php echo Helper::baseUrl('modules/projects/view.php?id=' . Helper::encryptId($project['id'])); ?>" 
                                           class="inline-flex items-center px-2 py-1 bg-gray-100 text-gray-700 rounded hover:bg-gray-200 transition-colors text-xs"
                                           title="View">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            </svg>
                                        </a>
                                        <a href="<?php echo Helper::baseUrl('modules/invoices/create.php?project_id=' . Helper::encryptId($project['id'])); ?>" 
                                           class="inline-flex items-center px-2 py-1 bg-blue-100 text-blue-700 rounded hover:bg-blue-200 transition-colors text-xs"
                                           title="Invoice">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                            </svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Cards - Prioritized Content -->
            <div class="md:hidden divide-y divide-gray-200">
                <?php foreach ($projects as $project): ?>
                    <div class="p-4 hover:bg-gray-50 transition-colors">
                        <!-- Project Header -->
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex-1 min-w-0">
                                <h3 class="text-sm font-semibold text-gray-900 truncate">
                                    <a href="<?php echo Helper::baseUrl('modules/projects/view.php?id=' . Helper::encryptId($project['id'])); ?>" 
                                       class="hover:text-blue-600 transition-colors">
                                        <?php echo htmlspecialchars($project['project_name']); ?>
                                    </a>
                                </h3>
                                <p class="text-xs text-gray-500 mt-1">
                                    <?php echo ucwords(str_replace('_', ' ', $project['project_type'])); ?>
                                </p>
                            </div>
                            <div class="ml-3">
                                <?php 
                                $status = $project['status'];
                                $statusClasses = [
                                    'pending' => 'bg-yellow-50 text-yellow-700 border border-yellow-200',
                                    'in_progress' => 'bg-blue-50 text-blue-700 border border-blue-200',
                                    'completed' => 'bg-green-50 text-green-700 border border-green-200',
                                    'on_hold' => 'bg-gray-50 text-gray-700 border border-gray-200',
                                    'cancelled' => 'bg-red-50 text-red-700 border border-red-200'
                                ];
                                $badgeClass = $statusClasses[$status] ?? 'bg-gray-50 text-gray-700 border border-gray-200';
                                $statusLabel = ucwords(str_replace('_', ' ', $status));
                                ?>
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo $badgeClass; ?>">
                                    <?php echo $statusLabel; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Client & Amount -->
                        <div class="grid grid-cols-2 gap-4 mb-3">
                            <div>
                                <p class="text-xs text-gray-500">Client</p>
                                <p class="text-sm font-medium text-gray-900">
                                    <a href="<?php echo Helper::baseUrl('modules/clients/view.php?id=' . Helper::encryptId($project['client_id'])); ?>" 
                                       class="hover:text-blue-600 transition-colors">
                                        <?php echo htmlspecialchars($project['company_name']); ?>
                                    </a>
                                </p>
                                <p class="text-xs text-gray-500">
                                    <?php echo htmlspecialchars($project['contact_person']); ?>
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-xs text-gray-500">Amount</p>
                                <p class="text-sm font-semibold text-gray-900">
                                    <?php echo Helper::formatCurrency($project['total_amount']); ?>
                                </p>
                                <?php if ($project['invoice_count'] > 0): ?>
                                    <p class="text-xs text-gray-500">
                                        <?php echo $project['invoice_count']; ?> invoice<?php echo $project['invoice_count'] != 1 ? 's' : ''; ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex space-x-2">
                            <a href="<?php echo Helper::baseUrl('modules/projects/view.php?id=' . Helper::encryptId($project['id'])); ?>" 
                               class="flex-1 inline-flex items-center justify-center px-3 py-2 bg-gray-100 text-gray-700 rounded text-xs font-medium hover:bg-gray-200 transition-colors">
                                View
                            </a>
                            <a href="<?php echo Helper::baseUrl('modules/invoices/create.php?project_id=' . Helper::encryptId($project['id'])); ?>" 
                               class="flex-1 inline-flex items-center justify-center px-3 py-2 bg-blue-100 text-blue-700 rounded text-xs font-medium hover:bg-blue-200 transition-colors">
                                Invoice
                            </a>
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
                        Showing <?php echo (($page - 1) * $limit) + 1; ?> to <?php echo min($page * $limit, $totalProjects); ?> of <?php echo $totalProjects; ?> projects
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

/* Status filter optimizations for mobile */
@media (max-width: 640px) {
    .status-filter {
        min-height: 44px; /* Better touch targets */
        font-size: 0.875rem;
        font-weight: 500;
    }
    
    /* Two-column layout for status filters on very small screens */
    .flex.flex-wrap.gap-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.5rem;
    }
    
    /* "All" button spans full width */
    .status-filter:first-child {
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

/* Client filter buttons */
.client-filter {
    transition: all 0.15s ease;
}

.client-filter:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
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

/* Enhanced status badges */
.status-badge {
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

// Status filter functionality
function setStatusFilter(status) {
    document.getElementById('statusFilter').value = status;
    
    // Update button states
    document.querySelectorAll('.status-filter').forEach(btn => {
        btn.classList.remove(
            'bg-gray-800', 'text-white', 'border-gray-800',
            'bg-yellow-100', 'text-yellow-800', 'border-yellow-300',
            'bg-blue-100', 'text-blue-800', 'border-blue-300',
            'bg-green-100', 'text-green-800', 'border-green-300',
            'bg-red-100', 'text-red-800', 'border-red-300'
        );
        btn.classList.add('bg-white', 'text-gray-700', 'border-gray-300');
    });
    
    // Set active button style
    const activeButton = event.target;
    activeButton.classList.remove('bg-white', 'text-gray-700', 'border-gray-300');
    
    switch(status) {
        case '':
            activeButton.classList.add('bg-gray-800', 'text-white', 'border-gray-800');
            break;
        case 'pending':
            activeButton.classList.add('bg-yellow-100', 'text-yellow-800', 'border-yellow-300');
            break;
        case 'in_progress':
            activeButton.classList.add('bg-blue-100', 'text-blue-800', 'border-blue-300');
            break;
        case 'completed':
            activeButton.classList.add('bg-green-100', 'text-green-800', 'border-green-300');
            break;
        case 'on_hold':
            activeButton.classList.add('bg-gray-100', 'text-gray-800', 'border-gray-300');
            break;
        case 'cancelled':
            activeButton.classList.add('bg-red-100', 'text-red-800', 'border-red-300');
            break;
    }
    
    // Auto-submit form
    document.getElementById('filterForm').submit();
}

// Client filter functionality
document.querySelectorAll('.client-filter').forEach(client => {
    client.addEventListener('click', function() {
        const clientId = this.dataset.clientId;
        document.getElementById('clientFilter').value = clientId;
        document.getElementById('filterForm').submit();
    });
});

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
    // 'N' for new project
    if (e.key.toLowerCase() === 'n' && !e.ctrlKey && !e.metaKey && !e.target.matches('input, textarea, select')) {
        e.preventDefault();
        window.location.href = '<?php echo Helper::baseUrl('modules/projects/add.php'); ?>';
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
    const hasAdvancedFilters = <?php echo json_encode(!empty($filters['project_type']) || !empty($filters['date_range']) || !empty($filters['amount_range'])); ?>;
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
        
        // Observe project cards
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
                            