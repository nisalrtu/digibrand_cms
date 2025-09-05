<?php
// modules/employees/view.php
session_start();

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// Helper function for time elapsed string
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $weeks = floor($diff->d / 7);
    $days = $diff->d % 7;

    $string = array();
    
    if ($diff->y) $string[] = $diff->y . ' year' . ($diff->y > 1 ? 's' : '');
    if ($diff->m) $string[] = $diff->m . ' month' . ($diff->m > 1 ? 's' : '');
    if ($weeks) $string[] = $weeks . ' week' . ($weeks > 1 ? 's' : '');
    if ($days) $string[] = $days . ' day' . ($days > 1 ? 's' : '');
    if ($diff->h) $string[] = $diff->h . ' hour' . ($diff->h > 1 ? 's' : '');
    if ($diff->i) $string[] = $diff->i . ' minute' . ($diff->i > 1 ? 's' : '');
    if ($diff->s) $string[] = $diff->s . ' second' . ($diff->s > 1 ? 's' : '');

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

// Check if user is admin - only admins can access employee management
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    Helper::setMessage('Access denied. Only administrators can manage employees.', 'error');
    Helper::redirect('modules/dashboard/');
}

// Get and validate employee ID
$employeeId = Helper::decryptId($_GET['id'] ?? '');
if (!$employeeId) {
    Helper::setMessage('Invalid employee ID.', 'error');
    Helper::redirect('modules/employees/');
}

$pageTitle = 'Employee Details - Invoice Manager';

// Get database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Get employee details
    $employeeQuery = "
        SELECT u.*
        FROM users u 
        WHERE u.id = :employee_id
    ";
    $employeeStmt = $db->prepare($employeeQuery);
    $employeeStmt->bindParam(':employee_id', $employeeId);
    $employeeStmt->execute();
    
    $employee = $employeeStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$employee) {
        Helper::setMessage('Employee not found.', 'error');
        Helper::redirect('modules/employees/');
    }
    
    // Get employee statistics
    $statsQuery = "
        SELECT 
            (SELECT COUNT(*) FROM projects WHERE created_by = :employee_id) as total_projects,
            (SELECT COUNT(*) FROM projects WHERE created_by = :employee_id AND status IN ('pending', 'in_progress')) as active_projects,
            (SELECT COUNT(*) FROM projects WHERE created_by = :employee_id AND status = 'completed') as completed_projects,
            (SELECT COUNT(*) FROM employee_payments WHERE employee_id = :employee_id) as total_payments,
            (SELECT COALESCE(SUM(payment_amount), 0) FROM employee_payments WHERE employee_id = :employee_id) as total_earned,
            (SELECT COALESCE(SUM(payment_amount), 0) FROM employee_payments WHERE employee_id = :employee_id AND payment_status = 'paid') as total_paid,
            (SELECT COALESCE(SUM(payment_amount), 0) FROM employee_payments WHERE employee_id = :employee_id AND payment_status = 'pending') as pending_amount,
            (SELECT COUNT(*) FROM employee_payments WHERE employee_id = :employee_id AND project_id IS NOT NULL) as project_payments,
            (SELECT COUNT(*) FROM employee_payments WHERE employee_id = :employee_id AND project_id IS NULL) as non_project_payments
    ";
    
    $statsStmt = $db->prepare($statsQuery);
    $statsStmt->bindParam(':employee_id', $employeeId);
    $statsStmt->execute();
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get recent payments (last 10)
    $recentPaymentsQuery = "
        SELECT ep.*, 
               p.project_name,
               c.company_name,
               cb.full_name as created_by_name
        FROM employee_payments ep 
        LEFT JOIN projects p ON ep.project_id = p.id
        LEFT JOIN clients c ON p.client_id = c.id
        LEFT JOIN users cb ON ep.created_by = cb.id
        WHERE ep.employee_id = :employee_id 
        ORDER BY ep.payment_date DESC, ep.created_at DESC
        LIMIT 10
    ";
    $recentPaymentsStmt = $db->prepare($recentPaymentsQuery);
    $recentPaymentsStmt->bindParam(':employee_id', $employeeId);
    $recentPaymentsStmt->execute();
    $recentPayments = $recentPaymentsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent projects (last 5)
    $recentProjectsQuery = "
        SELECT p.*, c.company_name, c.contact_person,
               (SELECT COUNT(*) FROM employee_payments WHERE project_id = p.id AND employee_id = :employee_id) as payment_count,
               (SELECT COALESCE(SUM(payment_amount), 0) FROM employee_payments WHERE project_id = p.id AND employee_id = :employee_id) as total_earned_from_project
        FROM projects p 
        LEFT JOIN clients c ON p.client_id = c.id
        WHERE p.created_by = :employee_id 
        ORDER BY p.created_at DESC 
        LIMIT 5
    ";
    $recentProjectsStmt = $db->prepare($recentProjectsQuery);
    $recentProjectsStmt->bindParam(':employee_id', $employeeId);
    $recentProjectsStmt->execute();
    $recentProjects = $recentProjectsStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    Helper::setMessage('Error loading employee details: ' . $e->getMessage(), 'error');
    Helper::redirect('modules/employees/');
}

include '../../includes/header.php';
?>

<!-- Enhanced Breadcrumb Navigation -->
<nav class="mb-8">
    <div class="flex items-center space-x-2 text-sm">
        <a href="<?php echo Helper::baseUrl('modules/employees/'); ?>" 
           class="text-gray-500 hover:text-gray-700 transition-colors font-medium">
            Employees
        </a>
        <svg class="w-4 h-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5l7 7-7 7"></path>
        </svg>
        <span class="text-gray-900 font-semibold"><?php echo htmlspecialchars($employee['full_name']); ?></span>
    </div>
</nav>

<!-- Main Content Grid -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
    <!-- Left Column (Main Content) -->
    <div class="lg:col-span-2 space-y-6">
        
        <!-- Employee Header Card -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm">
            <!-- Header Section -->
            <div class="p-6 border-b border-gray-50">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                    <div class="flex items-start space-x-4">
                        <!-- Employee Avatar -->
                        <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-purple-600 rounded-xl flex items-center justify-center text-white font-bold text-xl flex-shrink-0">
                            <?= strtoupper(substr($employee['full_name'], 0, 1)) ?>
                        </div>
                        
                        <!-- Employee Info -->
                        <div class="flex-1 min-w-0">
                            <h1 class="text-2xl font-bold text-gray-900 mb-2">
                                <?php echo htmlspecialchars($employee['full_name']); ?>
                            </h1>
                            
                            <!-- Status and Role Badges -->
                            <div class="flex flex-wrap items-center gap-3 mb-4">
                                <span class="inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-semibold border <?php 
                                    echo $employee['is_active'] 
                                        ? 'bg-green-50 text-green-700 border-green-200' 
                                        : 'bg-red-50 text-red-700 border-red-200'; 
                                ?>">
                                    <i class="fas fa-circle text-xs mr-2"></i>
                                    <?php echo $employee['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                                <span class="inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-semibold border <?php 
                                    echo $employee['role'] === 'admin' 
                                        ? 'bg-purple-50 text-purple-700 border-purple-200' 
                                        : 'bg-blue-50 text-blue-700 border-blue-200'; 
                                ?>">
                                    <i class="fas fa-<?php echo $employee['role'] === 'admin' ? 'user-shield' : 'user'; ?> mr-2"></i>
                                    <?php echo ucfirst($employee['role']); ?>
                                </span>
                            </div>
                            
                            <div class="space-y-2">
                                <div class="flex items-center text-sm text-gray-600">
                                    <i class="fas fa-user-tag w-4 h-4 mr-2 text-gray-400"></i>
                                    <span class="font-medium">Username:</span>
                                    <span class="ml-2"><?php echo htmlspecialchars($employee['username']); ?></span>
                                </div>
                                <div class="flex items-center text-sm text-gray-600">
                                    <i class="fas fa-envelope w-4 h-4 mr-2 text-gray-400"></i>
                                    <span class="font-medium">Email:</span>
                                    <a href="mailto:<?php echo htmlspecialchars($employee['email']); ?>" 
                                       class="ml-2 text-blue-600 hover:text-blue-700 transition-colors">
                                        <?php echo htmlspecialchars($employee['email']); ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="flex flex-col sm:flex-row gap-3">
                        <a href="<?php echo Helper::baseUrl('modules/employee_payments/?employee_id=' . $employee['id']); ?>" 
                           class="inline-flex items-center justify-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium">
                            <i class="fas fa-money-bill-wave mr-2"></i>
                            View All Payments
                        </a>
                        <a href="<?php echo Helper::baseUrl('modules/employees/add.php?employee_id=' . Helper::encryptId($employee['id'])); ?>" 
                           class="inline-flex items-center justify-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors text-sm font-medium">
                            <i class="fas fa-plus mr-2"></i>
                            Add Payment
                        </a>
                        <a href="<?php echo Helper::baseUrl('modules/employees/edit.php?id=' . Helper::encryptId($employee['id'])); ?>" 
                           class="inline-flex items-center justify-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors text-sm font-medium">
                            <i class="fas fa-edit mr-2"></i>
                            Edit Employee
                        </a>
                    </div>
                </div>
            </div>

            <!-- Employee Details Grid -->
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="flex items-start space-x-3">
                        <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900">Member Since</p>
                            <p class="text-gray-600">
                                <?php echo $employee['created_at'] ? 
                                    date('M j, Y', strtotime($employee['created_at'])) : 
                                    'Unknown'; ?>
                            </p>
                        </div>
                    </div>

                    <div class="flex items-start space-x-3">
                        <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900">Last Updated</p>
                            <p class="text-gray-600">
                                <?php echo $employee['updated_at'] ? 
                                    date('M j, Y', strtotime($employee['updated_at'])) : 
                                    'Never'; ?>
                            </p>
                        </div>
                    </div>

                    <?php if (false): // Removed created_by functionality ?>
                    <div class="flex items-start space-x-3">
                        <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900">Created By</p>
                            <p class="text-gray-600">System</p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Payments -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm">
            <div class="p-4 sm:p-6 border-b border-gray-50">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-bold text-gray-900">Recent Payments</h2>
                    <a href="<?php echo Helper::baseUrl('modules/employee_payments/?employee_id=' . $employee['id']); ?>" 
                       class="text-blue-600 hover:text-blue-700 transition-colors text-sm font-medium">
                        View All →
                    </a>
                </div>
            </div>
            
            <div class="p-4 sm:p-6">
                <?php if (empty($recentPayments)): ?>
                    <div class="text-center py-8">
                        <div class="mx-auto w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mb-4">
                            <i class="fas fa-money-bill-wave text-gray-400 text-xl"></i>
                        </div>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">No payments yet</h3>
                        <p class="text-gray-600 mb-4 text-sm">This employee hasn't received any payments yet.</p>
                        <a href="<?php echo Helper::baseUrl('modules/employees/add.php?employee_id=' . Helper::encryptId($employee['id'])); ?>" 
                           class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium">
                            <i class="fas fa-plus mr-2"></i>
                            Add First Payment
                        </a>
                    </div>
                <?php else: ?>
                    <!-- Mobile Filter & Sort -->
                    <div class="mb-4 sm:mb-6">
                        <!-- Mobile Controls -->
                        <div class="sm:hidden">
                            <div class="bg-gray-50 rounded-lg p-3 space-y-3">
                                <div class="flex items-center justify-between">
                                    <select class="text-sm border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 bg-white" 
                                            onchange="filterPayments(this.value)" id="statusFilter">
                                        <option value="all">All Status</option>
                                        <option value="paid">Paid</option>
                                        <option value="pending">Pending</option>
                                        <option value="cancelled">Cancelled</option>
                                    </select>
                                    <button onclick="toggleSortOrder()" 
                                            class="flex items-center text-sm text-gray-600 hover:text-gray-900 transition-colors bg-white px-3 py-2 rounded-lg border border-gray-300"
                                            id="sortButton">
                                        <i class="fas fa-sort mr-2"></i>
                                        <span>Amount ↓</span>
                                    </button>
                                </div>
                                
                                <!-- Search Bar -->
                                <div class="relative">
                                    <input type="text" 
                                           placeholder="Search payments..." 
                                           class="w-full pl-10 pr-4 py-2 text-sm border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                                           oninput="searchPayments(this.value)"
                                           id="searchInput">
                                    <i class="fas fa-search absolute left-3 top-2.5 text-gray-400 text-sm"></i>
                                    <button onclick="clearSearch()" 
                                            class="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600 text-sm hidden"
                                            id="clearSearch">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Desktop Controls -->
                        <div class="hidden sm:flex sm:items-center sm:justify-between">
                            <div class="flex items-center space-x-4">
                                <div class="flex items-center space-x-2">
                                    <label class="text-sm text-gray-700 font-medium">Filter:</label>
                                    <select class="text-sm border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500" 
                                            onchange="filterPayments(this.value)">
                                        <option value="all">All Status</option>
                                        <option value="paid">Paid Only</option>
                                        <option value="pending">Pending Only</option>
                                        <option value="cancelled">Cancelled</option>
                                    </select>
                                </div>
                                
                                <div class="flex items-center space-x-2">
                                    <label class="text-sm text-gray-700 font-medium">Sort:</label>
                                    <button onclick="toggleSortOrder()" 
                                            class="flex items-center text-sm text-gray-600 hover:text-gray-900 transition-colors"
                                            id="sortButtonDesktop">
                                        <i class="fas fa-sort mr-1"></i>
                                        <span>Amount ↓</span>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="relative w-64">
                                <input type="text" 
                                       placeholder="Search payments..." 
                                       class="w-full pl-10 pr-10 py-2 text-sm border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                                       oninput="searchPayments(this.value)">
                                <i class="fas fa-search absolute left-3 top-2.5 text-gray-400 text-sm"></i>
                                <button onclick="clearSearch()" 
                                        class="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600 text-sm hidden"
                                        id="clearSearchDesktop">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Results Summary -->
                        <div class="mt-3 text-sm text-gray-600 hidden" id="searchResults">
                            <span id="resultsCount">0</span> payment(s) found
                        </div>
                    </div>

                    <!-- Payments Grid -->
                    <div class="space-y-3 sm:space-y-4" id="paymentsContainer">
                        <?php foreach ($recentPayments as $payment): ?>
                            <div class="payment-item flex flex-col sm:flex-row sm:items-start p-3 sm:p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors border border-transparent hover:border-gray-200"
                                 data-status="<?php echo $payment['payment_status']; ?>">
                                <!-- Mobile: Stacked Layout -->
                                <div class="flex-1 min-w-0">
                                    <!-- Payment Header -->
                                    <div class="flex items-start justify-between mb-3 sm:mb-2">
                                        <div class="flex flex-col sm:flex-row sm:items-center sm:space-x-3">
                                            <h4 class="font-bold text-lg sm:text-base text-gray-900 mb-1 sm:mb-0">
                                                <?php echo Helper::formatCurrency($payment['payment_amount']); ?>
                                            </h4>
                                            <div class="flex items-center space-x-2">
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php
                                                    switch($payment['payment_status']) {
                                                        case 'paid':
                                                            echo 'bg-green-100 text-green-800 border border-green-200';
                                                            break;
                                                        case 'pending':
                                                            echo 'bg-yellow-100 text-yellow-800 border border-yellow-200';
                                                            break;
                                                        case 'cancelled':
                                                            echo 'bg-red-100 text-red-800 border border-red-200';
                                                            break;
                                                        default:
                                                            echo 'bg-gray-100 text-gray-800 border border-gray-200';
                                                    }
                                                ?>">
                                                    <i class="fas fa-circle text-xs mr-1"></i>
                                                    <?php echo ucfirst($payment['payment_status']); ?>
                                                </span>
                                                <span class="hidden sm:inline-flex items-center text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded-full">
                                                    <i class="fas fa-credit-card mr-1"></i>
                                                    <?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?>
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <!-- Mobile: Created by info -->
                                        <div class="text-right sm:hidden">
                                            <p class="text-xs text-gray-500">
                                                by <?php echo htmlspecialchars($payment['created_by_name'] ?? 'System'); ?>
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <!-- Payment Details -->
                                    <div class="space-y-2">
                                        <!-- Date and Project Info -->
                                        <div class="flex flex-col sm:flex-row sm:items-center sm:space-x-4 space-y-1 sm:space-y-0 text-sm">
                                            <span class="flex items-center text-gray-600">
                                                <i class="fas fa-calendar w-4 h-4 mr-2 text-gray-400"></i>
                                                <span class="font-medium"><?php echo date('M j, Y', strtotime($payment['payment_date'])); ?></span>
                                            </span>
                                            
                                            <?php if ($payment['project_name']): ?>
                                                <span class="flex items-center text-gray-600">
                                                    <i class="fas fa-project-diagram w-4 h-4 mr-2 text-gray-400"></i>
                                                    <span class="truncate">
                                                        <?php echo htmlspecialchars($payment['project_name']); ?>
                                                        <?php if ($payment['company_name']): ?>
                                                            <span class="text-gray-500">- <?php echo htmlspecialchars($payment['company_name']); ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                                </span>
                                            <?php else: ?>
                                                <span class="flex items-center text-gray-400">
                                                    <i class="fas fa-briefcase w-4 h-4 mr-2"></i>
                                                    <span>General Payment</span>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Mobile: Payment Method -->
                                        <div class="flex items-center text-sm text-gray-600 sm:hidden">
                                            <i class="fas fa-credit-card w-4 h-4 mr-2 text-gray-400"></i>
                                            <span><?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?></span>
                                        </div>
                                        
                                        <!-- Work Description -->
                                        <?php if (!empty($payment['work_description'])): ?>
                                            <div class="bg-white rounded-lg p-3 border border-gray-100">
                                                <p class="text-sm text-gray-600 line-clamp-3 sm:line-clamp-2">
                                                    <i class="fas fa-quote-left text-gray-300 mr-1"></i>
                                                    <?php echo htmlspecialchars($payment['work_description']); ?>
                                                </p>
                                                <button onclick="toggleDescription(this)" class="text-blue-600 hover:text-blue-700 text-xs mt-1 sm:hidden">
                                                    Show more
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <!-- Reference Number -->
                                        <?php if (!empty($payment['payment_reference'])): ?>
                                            <div class="flex items-center text-xs text-gray-500">
                                                <i class="fas fa-hashtag w-3 h-3 mr-1"></i>
                                                <span>Ref: <?php echo htmlspecialchars($payment['payment_reference']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Desktop: Right Column -->
                                <div class="hidden sm:flex sm:flex-col sm:items-end sm:ml-4 sm:text-right sm:min-w-0">
                                    <p class="text-xs text-gray-500 mb-1">
                                        by <?php echo htmlspecialchars($payment['created_by_name'] ?? 'System'); ?>
                                    </p>
                                    <p class="text-xs text-gray-400">
                                        <?php echo date('g:i A', strtotime($payment['payment_date'])); ?>
                                    </p>
                                </div>
                                
                                <!-- Mobile: Payment Actions -->
                                <div class="flex items-center justify-between mt-3 pt-3 border-t border-gray-200 sm:hidden">
                                    <div class="flex items-center space-x-3 text-xs text-gray-500">
                                        <span><?php echo date('g:i A', strtotime($payment['payment_date'])); ?></span>
                                        <span>•</span>
                                        <span><?php echo time_elapsed_string($payment['created_at'] ?? $payment['payment_date']); ?></span>
                                    </div>
                                    <button class="text-gray-400 hover:text-gray-600 transition-colors"
                                            onclick="showPaymentDetails('<?php echo $payment['id'] ?? ''; ?>')">
                                        <i class="fas fa-chevron-right"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Load More Button -->
                    <?php if (count($recentPayments) >= 10): ?>
                        <div class="text-center mt-6">
                            <button onclick="loadMorePayments()" 
                                    class="inline-flex items-center px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg transition-colors text-sm font-medium">
                                <i class="fas fa-plus mr-2"></i>
                                Load More Payments
                            </button>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Projects -->
        <?php if (!empty($recentProjects)): ?>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm">
            <div class="p-6 border-b border-gray-50">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-bold text-gray-900">Recent Projects</h2>
                    <a href="<?php echo Helper::baseUrl('modules/projects/?created_by=' . $employee['id']); ?>" 
                       class="text-blue-600 hover:text-blue-700 transition-colors text-sm font-medium">
                        View All →
                    </a>
                </div>
            </div>
            
            <div class="p-6">
                <div class="space-y-4">
                    <?php foreach ($recentProjects as $project): ?>
                        <div class="flex items-start justify-between p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                            <div class="flex-1 min-w-0">
                                <h4 class="font-medium text-gray-900 mb-1">
                                    <a href="<?php echo Helper::baseUrl('modules/projects/view.php?id=' . Helper::encryptId($project['id'])); ?>" 
                                       class="hover:text-blue-600 transition-colors">
                                        <?php echo htmlspecialchars($project['project_name']); ?>
                                    </a>
                                </h4>
                                <p class="text-sm text-gray-600 mb-2">
                                    <?php echo htmlspecialchars($project['company_name']); ?> - 
                                    <?php echo htmlspecialchars($project['contact_person']); ?>
                                </p>
                                <div class="flex items-center space-x-4 text-xs text-gray-500">
                                    <span>
                                        Status: 
                                        <span class="font-medium text-gray-700">
                                            <?php echo ucwords(str_replace('_', ' ', $project['status'])); ?>
                                        </span>
                                    </span>
                                    <span>
                                        Amount: 
                                        <span class="font-medium text-gray-700">
                                            <?php echo Helper::formatCurrency($project['total_amount']); ?>
                                        </span>
                                    </span>
                                    <?php if ($project['payment_count'] > 0): ?>
                                        <span>
                                            Earned: 
                                            <span class="font-medium text-green-600">
                                                <?php echo Helper::formatCurrency($project['total_earned_from_project']); ?>
                                            </span>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="ml-4 text-right">
                                <p class="text-xs text-gray-500">
                                    <?php echo date('M j, Y', strtotime($project['created_at'])); ?>
                                </p>
                                <?php if ($project['payment_count'] > 0): ?>
                                    <p class="text-xs text-gray-400 mt-1">
                                        <?php echo $project['payment_count']; ?> payment<?php echo $project['payment_count'] != 1 ? 's' : ''; ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right Column (Sidebar) -->
    <div class="space-y-6">
        <!-- Quick Stats Card -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <h3 class="text-lg font-bold text-gray-900 mb-4">Quick Stats</h3>
            
            <div class="space-y-4">
                <!-- Total Earned -->
                <div class="flex items-center justify-between p-3 bg-green-50 rounded-lg">
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-dollar-sign text-green-600 text-sm"></i>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-900">Total Earned</p>
                            <p class="text-xs text-gray-600"><?php echo $stats['total_payments']; ?> payments</p>
                        </div>
                    </div>
                    <p class="text-lg font-bold text-green-600">
                        <?php echo Helper::formatCurrency($stats['total_earned']); ?>
                    </p>
                </div>

                <!-- Amount Paid -->
                <div class="flex items-center justify-between p-3 bg-blue-50 rounded-lg">
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-check-circle text-blue-600 text-sm"></i>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-900">Amount Paid</p>
                            <p class="text-xs text-gray-600">Completed payments</p>
                        </div>
                    </div>
                    <p class="text-lg font-bold text-blue-600">
                        <?php echo Helper::formatCurrency($stats['total_paid']); ?>
                    </p>
                </div>

                <!-- Pending Amount -->
                <?php if ($stats['pending_amount'] > 0): ?>
                <div class="flex items-center justify-between p-3 bg-yellow-50 rounded-lg">
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 bg-yellow-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-clock text-yellow-600 text-sm"></i>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-900">Pending</p>
                            <p class="text-xs text-gray-600">Awaiting payment</p>
                        </div>
                    </div>
                    <p class="text-lg font-bold text-yellow-600">
                        <?php echo Helper::formatCurrency($stats['pending_amount']); ?>
                    </p>
                </div>
                <?php endif; ?>

                <!-- Projects -->
                <div class="flex items-center justify-between p-3 bg-purple-50 rounded-lg">
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 bg-purple-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-project-diagram text-purple-600 text-sm"></i>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-900">Projects</p>
                            <p class="text-xs text-gray-600"><?php echo $stats['active_projects']; ?> active</p>
                        </div>
                    </div>
                    <p class="text-lg font-bold text-purple-600">
                        <?php echo number_format($stats['total_projects']); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Payment Breakdown Card -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <h3 class="text-lg font-bold text-gray-900 mb-4">Payment Breakdown</h3>
            
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600">Project-based</span>
                    <span class="text-sm font-medium text-gray-900">
                        <?php echo number_format($stats['project_payments']); ?>
                    </span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600">General payments</span>
                    <span class="text-sm font-medium text-gray-900">
                        <?php echo number_format($stats['non_project_payments']); ?>
                    </span>
                </div>
                <div class="border-t border-gray-100 pt-3">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-gray-900">Total payments</span>
                        <span class="text-sm font-bold text-gray-900">
                            <?php echo number_format($stats['total_payments']); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions Card -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <h3 class="text-lg font-bold text-gray-900 mb-4">Quick Actions</h3>
            
            <div class="space-y-3">
                <a href="<?php echo Helper::baseUrl('modules/employees/add.php?employee_id=' . Helper::encryptId($employee['id'])); ?>" 
                   class="flex items-center justify-between w-full p-3 bg-blue-50 hover:bg-blue-100 rounded-lg transition-colors group">
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 bg-blue-100 group-hover:bg-blue-200 rounded-lg flex items-center justify-center transition-colors">
                            <i class="fas fa-plus text-blue-600 text-sm"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900">Add Payment</span>
                    </div>
                    <i class="fas fa-chevron-right text-gray-400 text-xs group-hover:text-blue-600 transition-colors"></i>
                </a>

                <a href="<?php echo Helper::baseUrl('modules/employee_payments/?employee_id=' . $employee['id']); ?>" 
                   class="flex items-center justify-between w-full p-3 bg-green-50 hover:bg-green-100 rounded-lg transition-colors group">
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 bg-green-100 group-hover:bg-green-200 rounded-lg flex items-center justify-center transition-colors">
                            <i class="fas fa-list text-green-600 text-sm"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900">View All Payments</span>
                    </div>
                    <i class="fas fa-chevron-right text-gray-400 text-xs group-hover:text-green-600 transition-colors"></i>
                </a>

                <a href="<?php echo Helper::baseUrl('modules/projects/add.php?created_by=' . Helper::encryptId($employee['id'])); ?>" 
                   class="flex items-center justify-between w-full p-3 bg-purple-50 hover:bg-purple-100 rounded-lg transition-colors group">
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 bg-purple-100 group-hover:bg-purple-200 rounded-lg flex items-center justify-center transition-colors">
                            <i class="fas fa-project-diagram text-purple-600 text-sm"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900">Assign Project</span>
                    </div>
                    <i class="fas fa-chevron-right text-gray-400 text-xs group-hover:text-purple-600 transition-colors"></i>
                </a>

                <a href="<?php echo Helper::baseUrl('modules/employees/edit.php?id=' . Helper::encryptId($employee['id'])); ?>" 
                   class="flex items-center justify-between w-full p-3 bg-gray-50 hover:bg-gray-100 rounded-lg transition-colors group">
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 bg-gray-100 group-hover:bg-gray-200 rounded-lg flex items-center justify-center transition-colors">
                            <i class="fas fa-edit text-gray-600 text-sm"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900">Edit Employee</span>
                    </div>
                    <i class="fas fa-chevron-right text-gray-400 text-xs group-hover:text-gray-600 transition-colors"></i>
                </a>
            </div>
        </div>

        <!-- Account Status Card -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <h3 class="text-lg font-bold text-gray-900 mb-4">Account Status</h3>
            
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600">Status</span>
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php 
                        echo $employee['is_active'] 
                            ? 'bg-green-100 text-green-800' 
                            : 'bg-red-100 text-red-800'; 
                    ?>">
                        <i class="fas fa-circle text-xs mr-1"></i>
                        <?php echo $employee['is_active'] ? 'Active' : 'Inactive'; ?>
                    </span>
                </div>
                
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600">Role</span>
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php 
                        echo $employee['role'] === 'admin' 
                            ? 'bg-purple-100 text-purple-800' 
                            : 'bg-blue-100 text-blue-800'; 
                    ?>">
                        <i class="fas fa-<?php echo $employee['role'] === 'admin' ? 'user-shield' : 'user'; ?> mr-1"></i>
                        <?php echo ucfirst($employee['role']); ?>
                    </span>
                </div>

                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600">Last Login</span>
                    <span class="text-xs text-gray-500">
                        <?php 
                        // This would require a last_login field in the users table
                        // For now, we'll show a placeholder
                        echo 'Not tracked'; 
                        ?>
                    </span>
                </div>

                <!-- Status Toggle Button (only if current user is not viewing their own profile) -->
                <?php if ($_SESSION['user_id'] != $employee['id']): ?>
                <div class="pt-4 border-t border-gray-100">
                    <a href="<?php echo Helper::baseUrl('modules/employees/?toggle_status=' . Helper::encryptId($employee['id'])); ?>" 
                       class="flex items-center justify-center w-full px-4 py-2 <?php 
                           echo $employee['is_active'] 
                               ? 'bg-red-50 hover:bg-red-100 text-red-700 hover:text-red-800' 
                               : 'bg-green-50 hover:bg-green-100 text-green-700 hover:text-green-800'; 
                       ?> rounded-lg transition-colors text-sm font-medium"
                       onclick="return confirm('Are you sure you want to <?php echo $employee['is_active'] ? 'deactivate' : 'activate'; ?> this employee?')">
                        <i class="fas fa-<?php echo $employee['is_active'] ? 'user-times' : 'user-check'; ?> mr-2"></i>
                        <?php echo $employee['is_active'] ? 'Deactivate Employee' : 'Activate Employee'; ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Additional Mobile-Optimized Styles -->
<style>
/* Mobile-first responsive design */
@media (max-width: 768px) {
    .line-clamp-2 {
        display: -webkit-box;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    
    .line-clamp-3 {
        display: -webkit-box;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    
    .line-clamp-none {
        display: block;
        overflow: visible;
    }
    
    .grid-cols-1 {
        grid-template-columns: repeat(1, minmax(0, 1fr));
    }
    
    .lg\:col-span-2 {
        grid-column: span 1 / span 1;
    }
    
    /* Ensure proper spacing on mobile */
    .space-y-6 > * + * {
        margin-top: 1.5rem;
    }
    
    .space-y-3 > * + * {
        margin-top: 0.75rem;
    }
    
    /* Mobile-friendly button sizes */
    .px-4.py-2 {
        padding: 0.75rem 1rem;
    }
    
    /* Better mobile typography */
    .text-2xl {
        font-size: 1.5rem;
        line-height: 2rem;
    }
    
    .text-xl {
        font-size: 1.25rem;
        line-height: 1.75rem;
    }
    
    /* Payment card mobile optimizations */
    .payment-item {
        border: 1px solid transparent;
        transition: all 0.2s ease-in-out;
    }
    
    .payment-item:hover {
        border-color: #e5e7eb;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }
    
    /* Mobile payment card layout */
    .payment-item .truncate {
        max-width: 200px;
    }
    
    /* Mobile filter controls */
    .mobile-filter-controls {
        background: linear-gradient(to right, #f9fafb, #f3f4f6);
        border-radius: 0.5rem;
        padding: 0.75rem;
        margin-bottom: 1rem;
    }
    
    /* Swipe indicator */
    .swipe-indicator {
        position: relative;
    }
    
    .swipe-indicator::after {
        content: '';
        position: absolute;
        right: -8px;
        top: 50%;
        transform: translateY(-50%);
        width: 4px;
        height: 20px;
        background: linear-gradient(to bottom, transparent, #3b82f6, transparent);
        border-radius: 2px;
        opacity: 0.3;
    }
}

/* Tablet optimizations */
@media (min-width: 769px) and (max-width: 1024px) {
    .payment-item {
        padding: 1rem;
    }
    
    .lg\:col-span-2 {
        grid-column: span 2 / span 2;
    }
    
    /* Tablet-specific adjustments */
    .grid-cols-1.lg\\:grid-cols-3 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

/* Animation enhancements */
@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes slideInLeft {
    from {
        opacity: 0;
        transform: translateX(-20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes slideInRight {
    from {
        opacity: 0;
        transform: translateX(20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes pulse {
    0%, 100% {
        opacity: 1;
    }
    50% {
        opacity: 0.7;
    }
}

.fade-in {
    animation: fadeIn 0.3s ease-in-out;
}

.slide-in-left {
    animation: slideInLeft 0.3s ease-in-out;
}

.slide-in-right {
    animation: slideInRight 0.3s ease-in-out;
}

.pulse {
    animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

/* Enhanced status badges */
.status-badge {
    position: relative;
    overflow: hidden;
}

.status-badge::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(
        90deg,
        transparent,
        rgba(255, 255, 255, 0.2),
        transparent
    );
    transition: left 0.5s;
}

.status-badge:hover::before {
    left: 100%;
}

/* Payment amount emphasis */
.payment-amount {
    font-variant-numeric: tabular-nums;
    font-feature-settings: "tnum";
}

/* Enhanced loading states */
.loading {
    opacity: 0.7;
    pointer-events: none;
    position: relative;
}

.loading::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(255, 255, 255, 0.8);
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: inherit;
}

/* Focus states for accessibility */
a:focus,
button:focus,
select:focus,
.payment-item:focus {
    outline: 2px solid #3b82f6;
    outline-offset: 2px;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* Enhanced hover effects */
.hover-lift {
    transition: all 0.2s ease-in-out;
}

.hover-lift:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 15px -3px rgba(0, 0, 0, 0.1);
}

/* Improved button styles */
.btn-primary {
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    box-shadow: 0 4px 14px 0 rgba(59, 130, 246, 0.39);
    transition: all 0.2s ease-in-out;
}

.btn-primary:hover {
    background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
    box-shadow: 0 6px 20px 0 rgba(59, 130, 246, 0.5);
    transform: translateY(-1px);
}

.btn-secondary {
    background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
    box-shadow: 0 4px 14px 0 rgba(107, 114, 128, 0.39);
    transition: all 0.2s ease-in-out;
}

.btn-secondary:hover {
    background: linear-gradient(135deg, #4b5563 0%, #374151 100%);
    box-shadow: 0 6px 20px 0 rgba(107, 114, 128, 0.5);
    transform: translateY(-1px);
}

/* High contrast mode support */
@media (prefers-contrast: high) {
    .payment-item {
        border: 2px solid;
    }
    
    .status-badge {
        border: 1px solid;
        font-weight: bold;
    }
    
    .btn-primary,
    .btn-secondary {
        border: 2px solid;
        font-weight: bold;
    }
}

/* Reduced motion support */
@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
        scroll-behavior: auto !important;
    }
}

/* Print optimizations */
@media print {
    .no-print,
    .mobile-filter-controls,
    button,
    .btn-primary,
    .btn-secondary {
        display: none !important;
    }
    
    body {
        background: white !important;
        color: black !important;
    }
    
    .bg-white,
    .bg-gray-50,
    .bg-blue-50,
    .bg-green-50,
    .bg-purple-50,
    .bg-yellow-50,
    .bg-red-50 {
        background: white !important;
        border: 1px solid #e5e7eb !important;
    }
    
    .text-white {
        color: black !important;
    }
    
    .payment-item {
        break-inside: avoid;
        margin-bottom: 1rem;
    }
    
    .text-blue-600,
    .text-green-600,
    .text-purple-600,
    .text-yellow-600,
    .text-red-600 {
        color: black !important;
        font-weight: bold;
    }
}

/* Performance optimizations */
.will-change-transform {
    will-change: transform;
}

.gpu-accelerated {
    transform: translateZ(0);
    backface-visibility: hidden;
    perspective: 1000px;
}

/* Custom scrollbar for webkit browsers */
.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}

.custom-scrollbar::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

/* Toast notification styles */
.toast {
    position: fixed;
    top: 1rem;
    right: 1rem;
    background: white;
    border-radius: 0.5rem;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
    padding: 1rem;
    z-index: 1000;
    opacity: 0;
    transform: translateX(100%);
    transition: all 0.3s ease-in-out;
}

.toast.show {
    opacity: 1;
    transform: translateX(0);
}

.toast.success {
    border-left: 4px solid #10b981;
}

.toast.error {
    border-left: 4px solid #ef4444;
}

.toast.warning {
    border-left: 4px solid #f59e0b;
}

.toast.info {
    border-left: 4px solid #3b82f6;
}

/* Skeleton loading animation */
.skeleton {
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 37%, #f0f0f0 63%);
    background-size: 400% 100%;
    animation: skeleton-loading 1.4s ease infinite;
}

@keyframes skeleton-loading {
    0% {
        background-position: 100% 50%;
    }
    100% {
        background-position: 0% 50%;
    }
}

/* Improved focus ring */
.focus-ring:focus {
    outline: none;
    box-shadow: 
        0 0 0 2px white,
        0 0 0 4px #3b82f6,
        0 0 0 6px rgba(59, 130, 246, 0.2);
}

/* Enhanced text selection */
::selection {
    background-color: #3b82f6;
    color: white;
}

::-moz-selection {
    background-color: #3b82f6;
    color: white;
}
</style>

<!-- JavaScript for enhanced interactions -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Add loading states to buttons
    const actionButtons = document.querySelectorAll('a[href*="add.php"], a[href*="edit.php"]');
    actionButtons.forEach(button => {
        button.addEventListener('click', function() {
            this.classList.add('loading');
            const icon = this.querySelector('i');
            if (icon) {
                icon.className = 'fas fa-spinner fa-spin mr-2';
            }
        });
    });

    // Payment filtering functionality
    window.filterPayments = function(status) {
        const payments = document.querySelectorAll('.payment-item');
        let visibleCount = 0;
        
        payments.forEach(payment => {
            const matchesStatus = status === 'all' || payment.dataset.status === status;
            const searchTerm = document.querySelector('#searchInput')?.value.toLowerCase() || 
                              document.querySelector('input[placeholder="Search payments..."]')?.value.toLowerCase() || '';
            const matchesSearch = !searchTerm || payment.textContent.toLowerCase().includes(searchTerm);
            
            if (matchesStatus && matchesSearch) {
                payment.style.display = 'flex';
                payment.classList.add('fade-in');
                visibleCount++;
            } else {
                payment.style.display = 'none';
                payment.classList.remove('fade-in');
            }
        });
        
        updateResultsCount(visibleCount);
    };

    // Sort functionality
    let sortAscending = false; // Start with descending (highest first)
    window.toggleSortOrder = function() {
        const container = document.getElementById('paymentsContainer');
        const payments = Array.from(container.querySelectorAll('.payment-item'));
        
        payments.sort((a, b) => {
            const amountA = parseFloat(a.querySelector('h4').textContent.replace(/[^\d.-]/g, ''));
            const amountB = parseFloat(b.querySelector('h4').textContent.replace(/[^\d.-]/g, ''));
            return sortAscending ? amountA - amountB : amountB - amountA;
        });
        
        sortAscending = !sortAscending;
        
        // Clear container and re-append sorted items with animation
        container.innerHTML = '';
        payments.forEach((payment, index) => {
            setTimeout(() => {
                payment.classList.add('slide-in-left');
                container.appendChild(payment);
            }, index * 50); // Staggered animation
        });
        
        // Update sort button text
        const sortButtons = document.querySelectorAll('#sortButton, #sortButtonDesktop');
        sortButtons.forEach(button => {
            const span = button.querySelector('span');
            if (span) {
                span.textContent = `Amount ${sortAscending ? '↑' : '↓'}`;
            }
        });
    };

    // Toggle work description
    window.toggleDescription = function(button) {
        const description = button.previousElementSibling;
        const isExpanded = description.classList.contains('line-clamp-3');
        
        if (isExpanded) {
            description.classList.remove('line-clamp-3');
            description.classList.add('line-clamp-none');
            button.textContent = 'Show less';
        } else {
            description.classList.add('line-clamp-3');
            description.classList.remove('line-clamp-none');
            button.textContent = 'Show more';
        }
    };

    // Show payment details (could open modal or redirect)
    window.showPaymentDetails = function(paymentId) {
        if (paymentId) {
            // You can implement a modal or redirect to payment details
            console.log('Showing details for payment:', paymentId);
            // Example: window.location.href = `payment_details.php?id=${paymentId}`;
        }
    };

    // Load more payments functionality
    window.loadMorePayments = function() {
        const button = event.target;
        const originalText = button.innerHTML;
        
        // Show loading state
        button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Loading...';
        button.disabled = true;
        
        // Simulate API call (replace with actual AJAX call)
        setTimeout(() => {
            // Here you would typically fetch more payments via AJAX
            // For now, just hide the button
            button.style.display = 'none';
            
            // Show success message
            const message = document.createElement('div');
            message.className = 'text-center text-sm text-gray-500 mt-4';
            message.textContent = 'All payments loaded';
            button.parentNode.appendChild(message);
        }, 1000);
    };

    // Auto-refresh stats (optional - for real-time updates)
    let autoRefreshEnabled = false;
    
    // Toggle auto-refresh functionality
    function toggleAutoRefresh() {
        autoRefreshEnabled = !autoRefreshEnabled;
        
        if (autoRefreshEnabled) {
            setInterval(() => {
                // Only refresh if no form inputs are focused
                if (!document.querySelector('input:focus, select:focus, textarea:focus')) {
                    // Here you could implement AJAX to refresh stats
                    console.log('Auto-refresh would update stats here');
                }
            }, 30000); // Refresh every 30 seconds
        }
    }

    // Smooth scroll for anchor links
    const anchorLinks = document.querySelectorAll('a[href^="#"]');
    anchorLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ 
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });

    // Enhanced mobile menu handling
    function handleMobileInteractions() {
        // Add touch-friendly hover effects for mobile
        const touchDevice = 'ontouchstart' in window;
        
        if (touchDevice) {
            const hoverElements = document.querySelectorAll('.hover\\:bg-gray-100, .hover\\:bg-blue-100, .hover\\:bg-green-100');
            hoverElements.forEach(element => {
                element.addEventListener('touchstart', function() {
                    this.classList.add('bg-gray-100');
                });
                
                element.addEventListener('touchend', function() {
                    setTimeout(() => {
                        this.classList.remove('bg-gray-100');
                    }, 150);
                });
            });
        }
    }

    handleMobileInteractions();

    // Swipe gesture support for mobile payment cards
    function addSwipeSupport() {
        const paymentItems = document.querySelectorAll('.payment-item');
        
        paymentItems.forEach(item => {
            let startX = 0;
            let currentX = 0;
            let isDragging = false;
            
            item.addEventListener('touchstart', (e) => {
                startX = e.touches[0].clientX;
                isDragging = true;
                item.style.transition = 'none';
            });
            
            item.addEventListener('touchmove', (e) => {
                if (!isDragging) return;
                currentX = e.touches[0].clientX;
                const deltaX = currentX - startX;
                
                // Limit swipe distance
                const maxSwipe = 100;
                const clampedDelta = Math.max(-maxSwipe, Math.min(maxSwipe, deltaX));
                item.style.transform = `translateX(${clampedDelta}px)`;
            });
            
            item.addEventListener('touchend', () => {
                if (!isDragging) return;
                isDragging = false;
                
                const deltaX = currentX - startX;
                item.style.transition = 'transform 0.3s ease';
                
                if (Math.abs(deltaX) > 50) {
                    // Show action buttons or trigger action
                    console.log('Swipe action triggered');
                }
                
                // Reset position
                item.style.transform = 'translateX(0)';
                startX = 0;
                currentX = 0;
            });
        });
    }
    
    addSwipeSupport();

    // Performance monitoring
    if ('performance' in window) {
        window.addEventListener('load', function() {
            const loadTime = performance.timing.loadEventEnd - performance.timing.navigationStart;
            console.log('Employee view page loaded in:', loadTime + 'ms');
        });
    }

    // Connection status handling
    function handleConnectionStatus() {
        const showStatus = (message, type) => {
            const statusDiv = document.createElement('div');
            statusDiv.className = `fixed top-4 right-4 px-4 py-2 rounded-lg text-white text-sm font-medium z-50 ${
                type === 'offline' ? 'bg-red-500' : 'bg-green-500'
            }`;
            statusDiv.textContent = message;
            document.body.appendChild(statusDiv);
            
            setTimeout(() => {
                statusDiv.remove();
            }, 3000);
        };
        
        window.addEventListener('online', () => {
            showStatus('Connection restored', 'online');
        });
        
        window.addEventListener('offline', () => {
            showStatus('Working offline', 'offline');
        });
    }
    
    handleConnectionStatus();

    // Enhanced payment card interactions
    function enhancePaymentCards() {
        const paymentCards = document.querySelectorAll('.payment-item');
        
        paymentCards.forEach(card => {
            // Add keyboard navigation
            card.setAttribute('tabindex', '0');
            
            card.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    const button = card.querySelector('[onclick*="showPaymentDetails"]');
                    if (button) button.click();
                }
            });
            
            // Add focus indicators
            card.addEventListener('focus', () => {
                card.style.outline = '2px solid #3B82F6';
                card.style.outlineOffset = '2px';
            });
            
            card.addEventListener('blur', () => {
                card.style.outline = 'none';
            });
        });
    }
    
    enhancePaymentCards();
});

// Utility functions
function formatCurrency(amount) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2
    }).format(amount);
}

function formatDate(dateString) {
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

// Search functionality for payments
function searchPayments(query) {
    const payments = document.querySelectorAll('.payment-item');
    const searchTerm = query.toLowerCase();
    
    payments.forEach(payment => {
        const text = payment.textContent.toLowerCase();
        if (text.includes(searchTerm)) {
            payment.style.display = 'flex';
            // Highlight matching text
            highlightText(payment, query);
        } else {
            payment.style.display = 'none';
        }
    });
}

function highlightText(element, query) {
    if (!query) return;
    
    const walker = document.createTreeWalker(
        element,
        NodeFilter.SHOW_TEXT,
        null,
        false
    );
    
    const textNodes = [];
    let node;
    
    while (node = walker.nextNode()) {
        textNodes.push(node);
    }
    
    textNodes.forEach(textNode => {
        const text = textNode.textContent;
        const regex = new RegExp(`(${query})`, 'gi');
        
        if (regex.test(text)) {
            const highlightedText = text.replace(regex, '<mark class="bg-yellow-200">$1</mark>');
            const span = document.createElement('span');
            span.innerHTML = highlightedText;
            textNode.parentNode.replaceChild(span, textNode);
        }
    });
}
</script>

<?php include '../../includes/footer.php'; ?>