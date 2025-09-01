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

<!-- Page Header -->
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Projects</h1>
        <p class="text-gray-600 mt-1">Manage and track your client projects</p>
    </div>
    <div class="flex space-x-3 mt-4 sm:mt-0">
        <a href="<?php echo Helper::baseUrl('modules/projects/add.php'); ?>" 
           class="inline-flex items-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
            </svg>
            New Project
        </a>
    </div>
</div>



<!-- Top Clients Section -->
<?php if (!empty($topClients)): ?>
<div class="bg-white rounded-xl border border-gray-200 p-4 mb-6">
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-base font-medium text-gray-900">Quick Client Filter</h2>
        <a href="<?php echo Helper::baseUrl('modules/clients/'); ?>" class="text-sm text-blue-600 hover:text-blue-700">View All →</a>
    </div>
    <div class="flex flex-wrap gap-2">
        <?php foreach ($topClients as $client): ?>
            <button type="button" 
                    class="client-filter inline-flex items-center px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-blue-100 hover:text-blue-700 transition-colors text-sm border border-gray-200 hover:border-blue-300"
                    data-client-id="<?php echo Helper::encryptId($client['id']); ?>"
                    data-client-name="<?php echo htmlspecialchars($client['company_name']); ?>"
                    title="<?php echo $client['project_count']; ?> projects">
                <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-2m-2 0H5m14 0V9a2 2 0 00-2-2H9a2 2 0 00-2 2v12a2 2 0 002 2h4m0 0V9a2 2 0 012-2h2a2 2 0 012 2v12"></path>
                </svg>
                <span class="font-medium"><?php echo htmlspecialchars($client['company_name']); ?></span>
                <span class="mx-1 text-gray-400">•</span>
                <span class="text-gray-600"><?php echo htmlspecialchars($client['contact_person']); ?></span>
                <span class="ml-1.5 inline-flex items-center justify-center w-5 h-5 bg-blue-100 text-blue-800 text-xs rounded-full">
                    <?php echo $client['project_count']; ?>
                </span>
            </button>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Filters and Search -->
<div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
    <form method="GET" class="space-y-4" id="filterForm">
        <!-- Search and Quick Filters Row -->
        <div class="flex flex-col sm:flex-row gap-4">
            <!-- Search -->
            <div class="flex-1">
                <div class="relative">
                    <input type="text" 
                           name="search" 
                           value="<?php echo htmlspecialchars($filters['search']); ?>"
                           placeholder="Search projects, clients, or descriptions..."
                           class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <svg class="w-5 h-5 text-gray-400 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </div>
            </div>

            <!-- Quick Status Filters -->
            <div class="flex flex-wrap gap-2">
                <button type="button" onclick="setStatusFilter('')" 
                        class="status-filter px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo empty($filters['status']) ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'; ?>">
                    All
                </button>
                <button type="button" onclick="setStatusFilter('pending')"
                        class="status-filter px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo $filters['status'] === 'pending' ? 'bg-yellow-100 text-yellow-800 border-yellow-300' : 'bg-white text-gray-700 border-gray-300 hover:bg-yellow-50 hover:text-yellow-700'; ?>">
                    <span class="inline-block w-2 h-2 bg-yellow-400 rounded-full mr-2"></span>
                    Pending
                </button>
                <button type="button" onclick="setStatusFilter('in_progress')"
                        class="status-filter px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo $filters['status'] === 'in_progress' ? 'bg-blue-100 text-blue-800 border-blue-300' : 'bg-white text-gray-700 border-gray-300 hover:bg-blue-50 hover:text-blue-700'; ?>">
                    <span class="inline-block w-2 h-2 bg-blue-400 rounded-full mr-2"></span>
                    In Progress
                </button>
                <button type="button" onclick="setStatusFilter('completed')"
                        class="status-filter px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo $filters['status'] === 'completed' ? 'bg-green-100 text-green-800 border-green-300' : 'bg-white text-gray-700 border-gray-300 hover:bg-green-50 hover:text-green-700'; ?>">
                    <span class="inline-block w-2 h-2 bg-green-400 rounded-full mr-2"></span>
                    Completed
                </button>
                <button type="button" onclick="setStatusFilter('on_hold')"
                        class="status-filter px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo $filters['status'] === 'on_hold' ? 'bg-gray-100 text-gray-800 border-gray-300' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'; ?>">
                    <span class="inline-block w-2 h-2 bg-gray-400 rounded-full mr-2"></span>
                    On Hold
                </button>
                <button type="button" onclick="setStatusFilter('cancelled')"
                        class="status-filter px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo $filters['status'] === 'cancelled' ? 'bg-red-100 text-red-800 border-red-300' : 'bg-white text-gray-700 border-gray-300 hover:bg-red-50 hover:text-red-700'; ?>">
                    <span class="inline-block w-2 h-2 bg-red-400 rounded-full mr-2"></span>
                    Cancelled
                </button>
            </div>
        </div>

        <!-- Advanced Filters -->
        <div class="border-t pt-4">
            <button type="button" id="toggleAdvanced" class="flex items-center text-sm text-gray-600 hover:text-gray-900 mb-4">
                <svg class="w-4 h-4 mr-2 transform transition-transform" id="advancedIcon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
                Advanced Filters
            </button>

            <div id="advancedFilters" class="hidden grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
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

                <!-- Sort By -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sort By</label>
                    <div class="flex space-x-2">
                        <select name="sort_by" class="flex-1 border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="created_at" <?php echo $filters['sort_by'] === 'created_at' ? 'selected' : ''; ?>>Created Date</option>
                            <option value="project_name" <?php echo $filters['sort_by'] === 'project_name' ? 'selected' : ''; ?>>Project Name</option>
                            <option value="total_amount" <?php echo $filters['sort_by'] === 'total_amount' ? 'selected' : ''; ?>>Amount</option>
                            <option value="start_date" <?php echo $filters['sort_by'] === 'start_date' ? 'selected' : ''; ?>>Start Date</option>
                            <option value="end_date" <?php echo $filters['sort_by'] === 'end_date' ? 'selected' : ''; ?>>End Date</option>
                        </select>
                        <select name="sort_order" class="border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="DESC" <?php echo $filters['sort_order'] === 'DESC' ? 'selected' : ''; ?>>Desc</option>
                            <option value="ASC" <?php echo $filters['sort_order'] === 'ASC' ? 'selected' : ''; ?>>Asc</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="flex justify-between items-center mt-4">
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.207A1 1 0 013 6.5V4z"></path>
                    </svg>
                    Apply Filters
                </button>
                <a href="<?php echo Helper::baseUrl('modules/projects/'); ?>" class="text-sm text-gray-600 hover:text-gray-900">Clear All Filters</a>
            </div>
        </div>

        <!-- Hidden inputs for client filter -->
        <input type="hidden" name="client_id" id="clientFilter" value="<?php echo htmlspecialchars($filters['client_id']); ?>">
        <input type="hidden" name="status" id="statusFilter" value="<?php echo htmlspecialchars($filters['status']); ?>">
    </form>
</div>

<!-- Projects Grid -->
<div class="space-y-6">
    <!-- Active Filters Display -->
    <?php if (!empty(array_filter($filters))): ?>
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div class="flex items-center justify-between">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-medium text-blue-800">Active Filters:</span>
                    <?php if (!empty($filters['search'])): ?>
                        <span class="inline-flex items-center px-2 py-1 bg-white border border-blue-200 rounded text-sm text-blue-800">
                            Search: "<?php echo htmlspecialchars($filters['search']); ?>"
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($filters['status'])): ?>
                        <span class="inline-flex items-center px-2 py-1 bg-white border border-blue-200 rounded text-sm text-blue-800">
                            Status: <?php echo ucwords(str_replace('_', ' ', $filters['status'])); ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($filters['client_id'])): ?>
                        <span class="inline-flex items-center px-2 py-1 bg-white border border-blue-200 rounded text-sm text-blue-800">
                            Client: <span id="selectedClientName">Selected</span>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($filters['project_type'])): ?>
                        <span class="inline-flex items-center px-2 py-1 bg-white border border-blue-200 rounded text-sm text-blue-800">
                            Type: <?php echo ucwords(str_replace('_', ' ', $filters['project_type'])); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <a href="<?php echo Helper::baseUrl('modules/projects/'); ?>" class="text-sm text-blue-600 hover:text-blue-700">Clear All</a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Projects List -->
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
        <!-- Projects Table -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <!-- Desktop Table -->
            <div class="hidden md:block overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Project
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Client
                            </th>
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
                                    // Enhanced status badges
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

            <!-- Mobile Cards -->
            <div class="md:hidden divide-y divide-gray-200">
                <?php foreach ($projects as $project): ?>
                    <div class="p-4 hover:bg-gray-50 transition-colors">
                        <!-- Project Header -->
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex-1 min-w-0">
                                <h3 class="text-sm font-medium text-gray-900 truncate">
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
                                <p class="text-sm font-medium text-gray-900">
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
                                View Project
                            </a>
                            <a href="<?php echo Helper::baseUrl('modules/invoices/create.php?project_id=' . Helper::encryptId($project['id'])); ?>" 
                               class="flex-1 inline-flex items-center justify-center px-3 py-2 bg-blue-100 text-blue-700 rounded text-xs font-medium hover:bg-blue-200 transition-colors">
                                Create Invoice
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <div class="flex items-center justify-between">
                    <div class="text-sm text-gray-600">
                        Showing <?php echo (($page - 1) * $limit) + 1; ?> to <?php echo min($page * $limit, $totalProjects); ?> of <?php echo $totalProjects; ?> projects
                    </div>
                    
                    <div class="flex space-x-2">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                               class="px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                                Previous
                            </a>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                               class="px-3 py-2 rounded-lg transition-colors <?php echo $i === $page ? 'bg-gray-800 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                               class="px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                                Next
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<style>
/* Custom styles for enhanced interactivity */
.client-filter:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
}

.status-filter.active {
    background-color: #1f2937;
    color: white;
    border-color: #1f2937;
}

/* Status filter buttons */
.status-filter {
    font-weight: 500;
    min-height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.status-filter:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

/* Mobile optimizations for status filters */
@media (max-width: 640px) {
    .status-filter {
        min-width: calc(50% - 0.25rem);
        flex: 1;
        font-size: 0.875rem;
        padding: 0.5rem 0.75rem;
        text-align: center;
    }
    
    /* Stack filters in 2 columns on small screens */
    .flex.flex-wrap.gap-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.5rem;
    }
    
    /* Make "All" button span full width */
    .status-filter:first-child {
        grid-column: 1 / -1;
        min-width: 100%;
    }
}

/* Enhanced status badges */
.status-badge {
    font-weight: 500;
    letter-spacing: 0.025em;
}

/* Table row hover effects */
tbody tr:hover {
    background-color: #f9fafb;
}

/* Mobile card hover effects */
.md\:hidden > div:hover {
    background-color: #f9fafb;
}

/* Loading states */
.loading {
    opacity: 0.6;
    pointer-events: none;
}

/* Smooth transitions */
.transition-all {
    transition-property: all;
    transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
    transition-duration: 200ms;
}

/* Mobile responsive improvements */
@media (max-width: 768px) {
    /* Better touch targets */
    .client-filter {
        min-height: 44px;
        display: flex;
        align-items: center;
    }
    
    /* Improved mobile cards spacing */
    .md\:hidden > div {
        padding: 1rem;
    }
    
    /* Better button spacing on mobile */
    .flex.space-x-2 > * {
        flex: 1;
        text-align: center;
    }
}

/* Status badge responsive behavior */
@media (max-width: 640px) {
    .status-badge {
        font-size: 0.75rem;
        padding: 0.25rem 0.5rem;
    }
}

/* Action buttons styling */
.action-button {
    transition: all 0.15s ease-in-out;
}

.action-button:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

/* Table cell alignment */
td {
    vertical-align: top;
}

/* Minimal design improvements */
.minimal-border {
    border: 1px solid #e5e7eb;
}

.minimal-shadow {
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

/* Responsive grid improvements */
.grid.grid-cols-2 {
    gap: 1rem;
}

@media (max-width: 480px) {
    .grid.grid-cols-2 {
        grid-template-columns: 1fr;
        gap: 0.75rem;
    }
}
</style>

<script>
// Advanced filters toggle
document.getElementById('toggleAdvanced').addEventListener('click', function() {
    const advancedFilters = document.getElementById('advancedFilters');
    const icon = document.getElementById('advancedIcon');
    
    if (advancedFilters.classList.contains('hidden')) {
        advancedFilters.classList.remove('hidden');
        icon.style.transform = 'rotate(180deg)';
    } else {
        advancedFilters.classList.add('hidden');
        icon.style.transform = 'rotate(0deg)';
    }
});

// Status filter buttons
function setStatusFilter(status) {
    document.getElementById('statusFilter').value = status;
    
    // Reset all button states
    document.querySelectorAll('.status-filter').forEach(btn => {
        // Remove all status-specific classes
        btn.classList.remove(
            'bg-gray-800', 'text-white', 'border-gray-800',
            'bg-yellow-100', 'text-yellow-800', 'border-yellow-300',
            'bg-blue-100', 'text-blue-800', 'border-blue-300',
            'bg-green-100', 'text-green-800', 'border-green-300',
            'bg-red-100', 'text-red-800', 'border-red-300'
        );
        // Add default classes
        btn.classList.add('bg-white', 'text-gray-700', 'border-gray-300');
    });
    
    // Set active state for clicked button
    const activeButton = event.target;
    activeButton.classList.remove('bg-white', 'text-gray-700', 'border-gray-300');
    
    // Apply status-specific styling
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
    
    // Submit form
    document.getElementById('filterForm').submit();
}

// Client filter from top clients section
document.querySelectorAll('.client-filter').forEach(client => {
    client.addEventListener('click', function() {
        const clientId = this.dataset.clientId;
        const clientName = this.dataset.clientName;
        
        // Set client filter
        document.getElementById('clientFilter').value = clientId;
        
        // Update display name if exists
        const selectedClientName = document.getElementById('selectedClientName');
        if (selectedClientName) {
            selectedClientName.textContent = clientName;
        }
        
        // Submit form
        document.getElementById('filterForm').submit();
    });
});

// Auto-submit search after typing pause
let searchTimeout;
document.querySelector('input[name="search"]').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        document.getElementById('filterForm').submit();
    }, 500);
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Press 'N' to create new project
    if (e.key.toLowerCase() === 'n' && !e.ctrlKey && !e.metaKey && !e.target.matches('input, textarea, select')) {
        e.preventDefault();
        window.location.href = '<?php echo Helper::baseUrl('modules/projects/add.php'); ?>';
    }
    
    // Press '/' to focus search
    if (e.key === '/' && !e.target.matches('input, textarea, select')) {
        e.preventDefault();
        document.querySelector('input[name="search"]').focus();
    }
    
    // Press 'Escape' to clear search
    if (e.key === 'Escape' && e.target.matches('input[name="search"]')) {
        e.target.value = '';
        document.getElementById('filterForm').submit();
    }
});

// Add loading states to project cards
document.querySelectorAll('a[href*="view.php"], a[href*="create.php"]').forEach(link => {
    link.addEventListener('click', function() {
        this.classList.add('loading');
        
        // Remove loading state after timeout
        setTimeout(() => {
            this.classList.remove('loading');
        }, 3000);
    });
});

// Real-time filter updates
function updateFilters() {
    const form = document.getElementById('filterForm');
    const formData = new FormData(form);
    const params = new URLSearchParams(formData);
    
    // Update URL without reload for better UX
    const newUrl = window.location.pathname + '?' + params.toString();
    history.pushState(null, '', newUrl);
}

// Infinite scroll (optional enhancement)
let isLoading = false;
function loadMoreProjects() {
    if (isLoading) return;
    
    const currentPage = <?php echo $page; ?>;
    const totalPages = <?php echo $totalPages; ?>;
    
    if (currentPage >= totalPages) return;
    
    isLoading = true;
    
    // Implementation would go here for AJAX loading
    console.log('Loading more projects...');
}

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    // Show advanced filters if any advanced filter is active
    const hasAdvancedFilters = '<?php echo !empty($filters['project_type']) || !empty($filters['date_range']) || !empty($filters['amount_range']) ? 'true' : 'false'; ?>';
    if (hasAdvancedFilters === 'true') {
        document.getElementById('advancedFilters').classList.remove('hidden');
        document.getElementById('advancedIcon').style.transform = 'rotate(180deg)';
    }
    
    // Set up intersection observer for animations
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
    document.querySelectorAll('.bg-white.rounded-xl').forEach(card => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        card.style.transition = 'opacity 0.6s ease-out, transform 0.6s ease-out';
        observer.observe(card);
    });
});

// Export projects functionality
function exportProjects() {
    const currentFilters = new URLSearchParams(window.location.search);
    currentFilters.set('export', 'csv');
    
    window.location.href = window.location.pathname + '?' + currentFilters.toString();
}

// Print projects list
function printProjects() {
    window.print();
}
</script>

<?php include '../../includes/footer.php'; ?>
