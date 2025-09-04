<?php
// modules/employees/index.php
session_start();

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

// Check if user is admin - only admins can access employee management
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    Helper::setMessage('Access denied. Only administrators can manage employees.', 'error');
    Helper::redirect('modules/dashboard/');
}

$pageTitle = 'Employees - Invoice Manager';

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Initialize filter variables
$filters = [
    'search' => $_GET['search'] ?? '',
    'role' => $_GET['role'] ?? '',
    'status' => $_GET['status'] ?? '',
    'sort_by' => $_GET['sort_by'] ?? 'created_at',
    'sort_order' => $_GET['sort_order'] ?? 'DESC'
];

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 12; // Employees per page
$offset = ($page - 1) * $limit;

// Handle employee status toggle
if (isset($_GET['toggle_status'])) {
    $employeeId = Helper::decryptId($_GET['toggle_status']);
    if ($employeeId) {
        try {
            // Get current status
            $statusQuery = "SELECT is_active FROM users WHERE id = :employee_id";
            $statusStmt = $db->prepare($statusQuery);
            $statusStmt->bindParam(':employee_id', $employeeId);
            $statusStmt->execute();
            $currentStatus = $statusStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($currentStatus) {
                $newStatus = $currentStatus['is_active'] ? 0 : 1;
                $updateQuery = "UPDATE users SET is_active = :status WHERE id = :employee_id";
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->bindParam(':status', $newStatus, PDO::PARAM_INT);
                $updateStmt->bindParam(':employee_id', $employeeId);
                
                if ($updateStmt->execute()) {
                    $statusText = $newStatus ? 'activated' : 'deactivated';
                    Helper::setMessage("Employee $statusText successfully!", 'success');
                } else {
                    Helper::setMessage('Error updating employee status.', 'error');
                }
            }
        } catch (Exception $e) {
            Helper::setMessage('Database error: ' . $e->getMessage(), 'error');
        }
        
        Helper::redirect('modules/employees/');
    }
}

try {
    // Get summary statistics
    $summaryQuery = "
        SELECT 
            COUNT(*) as total_employees,
            COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_employees,
            COUNT(CASE WHEN is_active = 0 THEN 1 END) as inactive_employees,
            COUNT(CASE WHEN role = 'admin' THEN 1 END) as admin_count,
            COUNT(CASE WHEN role = 'employee' THEN 1 END) as employee_count
        FROM users
        WHERE 1=1
    ";
    
    $summaryStmt = $db->prepare($summaryQuery);
    $summaryStmt->execute();
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    // Build the main employees query with filters
    $whereConditions = ['1=1'];
    $params = [];

    // Search filter
    if (!empty($filters['search'])) {
        $whereConditions[] = "(full_name LIKE :search OR username LIKE :search OR email LIKE :search)";
        $params[':search'] = '%' . $filters['search'] . '%';
    }

    // Role filter
    if (!empty($filters['role'])) {
        $whereConditions[] = "role = :role";
        $params[':role'] = $filters['role'];
    }

    // Status filter
    if (!empty($filters['status'])) {
        $status = $filters['status'] === 'active' ? 1 : 0;
        $whereConditions[] = "is_active = :status";
        $params[':status'] = $status;
    }

    $whereClause = implode(' AND ', $whereConditions);

    // Count total records for pagination
    $countQuery = "SELECT COUNT(*) as total FROM users WHERE $whereClause";
    $countStmt = $db->prepare($countQuery);
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalRecords / $limit);

    // Main employees query with pagination
    $employeesQuery = "
        SELECT u.*,
               (SELECT COUNT(*) FROM projects WHERE created_by = u.id) as projects_count,
               (SELECT COUNT(*) FROM employee_payments WHERE employee_id = u.id) as payments_count,
               (SELECT COALESCE(SUM(payment_amount), 0) FROM employee_payments WHERE employee_id = u.id) as total_payments
        FROM users u
        WHERE $whereClause
        ORDER BY {$filters['sort_by']} {$filters['sort_order']}
        LIMIT :limit OFFSET :offset
    ";
    
    $employeesStmt = $db->prepare($employeesQuery);
    foreach ($params as $key => $value) {
        $employeesStmt->bindValue($key, $value);
    }
    $employeesStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $employeesStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $employeesStmt->execute();
    
    $employees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    Helper::setMessage('Database error: ' . $e->getMessage(), 'error');
    $employees = [];
    $summary = ['total_employees' => 0, 'active_employees' => 0, 'inactive_employees' => 0, 'admin_count' => 0, 'employee_count' => 0];
    $totalPages = 0;
    $totalRecords = 0;
}

include '../../includes/header.php';
?>

<!-- Page Header with Mobile-Optimized Layout -->
<div class="mb-4">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Employees</h1>
            <p class="text-gray-600 mt-1 hidden sm:block">Manage employee accounts and permissions</p>
        </div>
        <div class="flex flex-col sm:flex-row gap-3">
            <a href="<?php echo Helper::baseUrl('modules/employees/add.php'); ?>" 
               class="btn-primary inline-flex items-center justify-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Add Employee
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
                   placeholder="Search employees by name, username, email..."
                   class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            <svg class="w-5 h-5 text-gray-400 absolute left-3 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
            </svg>
        </div>

        <!-- Quick Status Filters -->
        <div class="flex flex-wrap gap-2">
            <button type="button" onclick="setStatusFilter('')" 
                    class="status-filter flex-1 sm:flex-none px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo empty($filters['status']) ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'; ?>">
                All Status
            </button>
            <button type="button" onclick="setStatusFilter('active')"
                    class="status-filter flex-1 sm:flex-none px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo $filters['status'] === 'active' ? 'bg-green-100 text-green-800 border-green-300' : 'bg-white text-gray-700 border-gray-300 hover:bg-green-50 hover:text-green-700'; ?>">
                Active
            </button>
            <button type="button" onclick="setStatusFilter('inactive')"
                    class="status-filter flex-1 sm:flex-none px-3 py-2 text-sm rounded-lg border transition-colors whitespace-nowrap <?php echo $filters['status'] === 'inactive' ? 'bg-red-100 text-red-800 border-red-300' : 'bg-white text-gray-700 border-gray-300 hover:bg-red-50 hover:text-red-700'; ?>">
                Inactive
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
            <!-- Role Filter -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                    <select name="role" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">All Roles</option>
                        <option value="admin" <?php echo $filters['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="employee" <?php echo $filters['role'] === 'employee' ? 'selected' : ''; ?>>Employee</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $filters['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $filters['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <!-- Sort Options -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sort By</label>
                    <select name="sort_by" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="created_at" <?php echo $filters['sort_by'] === 'created_at' ? 'selected' : ''; ?>>Created Date</option>
                        <option value="full_name" <?php echo $filters['sort_by'] === 'full_name' ? 'selected' : ''; ?>>Name</option>
                        <option value="username" <?php echo $filters['sort_by'] === 'username' ? 'selected' : ''; ?>>Username</option>
                        <option value="role" <?php echo $filters['sort_by'] === 'role' ? 'selected' : ''; ?>>Role</option>
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
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    Apply Filters
                </button>
                <a href="<?php echo Helper::baseUrl('modules/employees/'); ?>" class="text-sm text-gray-600 hover:text-gray-900">Clear All</a>
            </div>
        </div>

        <!-- Hidden inputs -->
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
                <?php if (!empty($filters['role'])): ?>
                    <span class="inline-flex items-center px-2 py-1 bg-white border border-blue-200 rounded text-xs text-blue-800">
                        <?php echo ucwords($filters['role']); ?>
                    </span>
                <?php endif; ?>
            </div>
            <a href="<?php echo Helper::baseUrl('modules/employees/'); ?>" class="text-sm text-blue-600 hover:text-blue-700">Clear</a>
        </div>
    </div>
<?php endif; ?>

<!-- Employees List - THE MAIN CONTENT -->
<div class="space-y-4">
    <?php if (empty($employees)): ?>
        <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
            <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
            </svg>
            <h3 class="text-lg font-medium text-gray-900 mb-2">No employees found</h3>
            <p class="text-gray-600 mb-6">
                <?php if (!empty(array_filter($filters))): ?>
                    Try adjusting your filters or search terms.
                <?php else: ?>
                    Get started by adding your first employee.
                <?php endif; ?>
            </p>
            <?php if (empty(array_filter($filters))): ?>
                <a href="<?php echo Helper::baseUrl('modules/employees/add.php'); ?>" 
                   class="inline-flex items-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                    </svg>
                    Add First Employee
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <!-- Employees Table/Cards -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="p-4 border-b border-gray-100">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Employee Records</h3>
                        <p class="text-gray-600 text-sm mt-1">
                            Showing <?php echo number_format(count($employees)); ?> of <?php echo number_format($totalRecords); ?> employees
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
                                    Employee
                                </th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Role
                                </th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Status
                                </th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Projects
                                </th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Total Payments
                                </th>
                                <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($employees as $employee): ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <!-- Employee Info -->
                                    <td class="px-4 py-4">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold">
                                                <?= strtoupper(substr($employee['full_name'], 0, 1)) ?>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <a href="<?php echo Helper::baseUrl('modules/employees/view.php?id=' . Helper::encryptId($employee['id'])); ?>" 
                                                       class="hover:text-blue-600 transition-colors">
                                                        <?= htmlspecialchars($employee['full_name']) ?>
                                                    </a>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    <?= htmlspecialchars($employee['username']) ?>
                                                </div>
                                                <div class="text-xs text-gray-400">
                                                    <?= htmlspecialchars($employee['email']) ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Role -->
                                    <td class="px-4 py-4">
                                        <span class="role-badge <?= $employee['role'] === 'admin' ? 'role-admin' : 'role-employee' ?>">
                                            <i class="fas fa-<?= $employee['role'] === 'admin' ? 'user-shield' : 'user' ?> mr-1"></i>
                                            <?= ucfirst($employee['role']) ?>
                                        </span>
                                    </td>

                                    <!-- Status -->
                                    <td class="px-4 py-4">
                                        <span class="status-badge <?= $employee['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                            <i class="fas fa-circle text-xs mr-1"></i>
                                            <?= $employee['is_active'] ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>

                                    <!-- Projects Count -->
                                    <td class="px-4 py-4">
                                        <div class="text-sm text-gray-900"><?= number_format($employee['projects_count']) ?></div>
                                        <div class="text-xs text-gray-500">
                                            <?= $employee['projects_count'] == 1 ? 'project' : 'projects' ?>
                                        </div>
                                    </td>

                                    <!-- Total Payments -->
                                    <td class="px-4 py-4">
                                        <div class="text-sm font-medium text-gray-900">
                                            LKR <?= number_format($employee['total_payments'], 2) ?>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            <?= number_format($employee['payments_count']) ?> payments
                                        </div>
                                    </td>

                                    <!-- Actions -->
                                    <td class="px-4 py-4 text-right text-sm font-medium space-x-2">
                                        <a href="<?php echo Helper::baseUrl('modules/employees/view.php?id=' . Helper::encryptId($employee['id'])); ?>" 
                                           class="text-blue-600 hover:text-blue-900 transition-colors" 
                                           title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="<?php echo Helper::baseUrl('modules/employees/edit.php?id=' . Helper::encryptId($employee['id'])); ?>" 
                                           class="text-green-600 hover:text-green-900 transition-colors" 
                                           title="Edit Employee">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="?toggle_status=<?= Helper::encryptId($employee['id']) ?>" 
                                           class="text-<?= $employee['is_active'] ? 'red' : 'green' ?>-600 hover:text-<?= $employee['is_active'] ? 'red' : 'green' ?>-900 transition-colors" 
                                           title="<?= $employee['is_active'] ? 'Deactivate' : 'Activate' ?> Employee"
                                           onclick="return confirm('Are you sure you want to <?= $employee['is_active'] ? 'deactivate' : 'activate' ?> this employee?')">
                                            <i class="fas fa-<?= $employee['is_active'] ? 'user-times' : 'user-check' ?>"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                        <!-- Mobile Cards - Prioritized Content -->
            <div class="md:hidden divide-y divide-gray-200">
                <?php foreach ($employees as $employee): ?>
                    <div class="p-4 hover:bg-gray-50 transition-colors">
                        <!-- Employee Header -->
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex items-center">
                                <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold">
                                    <?php echo strtoupper(substr($employee['full_name'], 0, 1)); ?>
                                </div>
                                <div class="ml-3">
                                    <h3 class="text-sm font-semibold text-gray-900">
                                        <a href="<?php echo Helper::baseUrl('modules/employees/view.php?id=' . Helper::encryptId($employee['id'])); ?>" 
                                           class="hover:text-blue-600 transition-colors">
                                            <?php echo htmlspecialchars($employee['full_name']); ?>
                                        </a>
                                    </h3>
                                    <p class="text-xs text-gray-500">
                                        <?php echo htmlspecialchars($employee['username']); ?>
                                    </p>
                                </div>
                            </div>
                            <div class="flex space-x-2">
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo $employee['role'] === 'admin' ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-800'; ?>">
                                    <?php echo ucfirst($employee['role']); ?>
                                </span>
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo $employee['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                    <?php echo $employee['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Employee Info -->
                        <div class="grid grid-cols-2 gap-4 mb-3">
                            <div>
                                <p class="text-xs text-gray-500">Email</p>
                                <p class="text-sm text-gray-900">
                                    <?php echo htmlspecialchars($employee['email']); ?>
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-xs text-gray-500">Projects</p>
                                <p class="text-sm font-medium text-gray-900">
                                    <?php echo number_format($employee['projects_count']); ?>
                                </p>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex space-x-2">
                            <a href="<?php echo Helper::baseUrl('modules/employees/view.php?id=' . Helper::encryptId($employee['id'])); ?>" 
                               class="flex-1 inline-flex items-center justify-center px-3 py-2 bg-gray-100 text-gray-700 rounded text-xs font-medium hover:bg-gray-200 transition-colors">
                                View
                            </a>
                            <a href="<?php echo Helper::baseUrl('modules/employees/edit.php?id=' . Helper::encryptId($employee['id'])); ?>" 
                               class="flex-1 inline-flex items-center justify-center px-3 py-2 bg-blue-100 text-blue-700 rounded text-xs font-medium hover:bg-blue-200 transition-colors">
                                Edit
                            </a>
                            <a href="?toggle_status=<?php echo Helper::encryptId($employee['id']); ?>" 
                               class="flex-1 inline-flex items-center justify-center px-3 py-2 bg-<?php echo $employee['is_active'] ? 'red' : 'green'; ?>-100 text-<?php echo $employee['is_active'] ? 'red' : 'green'; ?>-700 rounded text-xs font-medium hover:bg-<?php echo $employee['is_active'] ? 'red' : 'green'; ?>-200 transition-colors"
                               onclick="return confirm('Are you sure you want to <?php echo $employee['is_active'] ? 'deactivate' : 'activate'; ?> this employee?')">
                                <?php echo $employee['is_active'] ? 'Deactivate' : 'Activate'; ?>
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
                        Showing <?php echo (($page - 1) * $limit) + 1; ?> to <?php echo min($page * $limit, $totalRecords); ?> of <?php echo $totalRecords; ?> employees
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
    
    /* "All Status" button spans full width */
    .status-filter:first-child {
        grid-column: 1 / -1;
    }
}

/* Enhanced mobile card styling */
.md\:hidden > div {
    border-radius: 0.5rem;
    margin: 0.25rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    transition: all 0.15s ease;
    cursor: pointer;
}

.md\:hidden > div:hover {
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    transform: translateY(-1px);
    background-color: #f9fafb;
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

/* Table hover effects */
tbody tr:hover {
    background-color: #f9fafb;
    transform: translateX(2px);
    transition: all 0.15s ease;
    cursor: pointer;
}

/* Clickable row indicator */
tbody tr {
    transition: all 0.15s ease;
}

/* Mobile card hover effects with clickable indication */
.md\:hidden > div {
    transition: all 0.15s ease;
    cursor: pointer;
}

.md\:hidden > div:hover {
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    transform: translateY(-1px);
}

/* Better focus states */
input:focus, select:focus {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
}

/* Enhanced status badges */
.status-badge {
    font-weight: 500;
    letter-spacing: 0.025em;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
}

/* Role badge styles */
.role-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.5rem;
    border-radius: 0.375rem;
    font-size: 0.75rem;
    font-weight: 500;
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
        case 'active':
            activeButton.classList.add('bg-green-100', 'text-green-800', 'border-green-300');
            break;
        case 'inactive':
            activeButton.classList.add('bg-red-100', 'text-red-800', 'border-red-300');
            break;
    }
    
    // Auto-submit form
    document.getElementById('filterForm').submit();
}

// Auto-submit search with debounce
let searchTimeout;
document.querySelector('input[name="search"]')?.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        document.getElementById('filterForm').submit();
    }, 500);
});

// Loading states
document.querySelectorAll('a[href*="view.php"], a[href*="edit.php"]').forEach(link => {
    link.addEventListener('click', function() {
        this.style.opacity = '0.7';
        this.style.pointerEvents = 'none';
        setTimeout(() => {
            this.style.opacity = '';
            this.style.pointerEvents = '';
        }, 3000);
    });
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // 'N' for new employee
    if (e.key.toLowerCase() === 'n' && !e.ctrlKey && !e.metaKey && !e.target.matches('input, textarea, select')) {
        e.preventDefault();
        window.location.href = '<?php echo Helper::baseUrl('modules/employees/add.php'); ?>';
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
    const hasAdvancedFilters = <?php echo json_encode(!empty($filters['role']) || $filters['sort_by'] !== 'created_at'); ?>;
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
        
        // Observe employee cards
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
    
    // Enhanced table row interactions - make entire row clickable
    document.querySelectorAll('tbody tr').forEach(row => {
        // Click anywhere on row to view employee (except action buttons)
        row.addEventListener('click', function(e) {
            if (!e.target.closest('a') && !e.target.closest('button')) {
                const viewLink = this.querySelector('a[href*="view.php"]');
                if (viewLink) {
                    window.location.href = viewLink.href;
                }
            }
        });
        
        // Add hover cursor and title
        row.style.cursor = 'pointer';
        const employeeName = row.querySelector('td:first-child .text-sm.font-medium a');
        if (employeeName) {
            row.title = 'Click to view ' + employeeName.textContent.trim() + ' details';
        }
    });
    
    // Enhanced mobile card interactions
    document.querySelectorAll('.md\\:hidden > div').forEach(card => {
        // Skip if it's not an employee card
        if (!card.querySelector('h3 a[href*="view.php"]')) return;
        
        card.addEventListener('click', function(e) {
            if (!e.target.closest('a') && !e.target.closest('button')) {
                const viewLink = this.querySelector('h3 a[href*="view.php"]');
                if (viewLink) {
                    window.location.href = viewLink.href;
                }
            }
        });
        
        card.style.cursor = 'pointer';
        const employeeName = card.querySelector('h3 a');
        if (employeeName) {
            card.title = 'Click to view ' + employeeName.textContent.trim() + ' details';
        }
    });
});
</script>

<?php include '../../includes/footer.php'; ?>