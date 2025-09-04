<?php
// modules/employees/view.php
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
                        <a href="<?php echo Helper::baseUrl('modules/employee_payments/add.php?employee_id=' . Helper::encryptId($employee['id'])); ?>" 
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
            <div class="p-6 border-b border-gray-50">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-bold text-gray-900">Recent Payments</h2>
                    <a href="<?php echo Helper::baseUrl('modules/employee_payments/?employee_id=' . $employee['id']); ?>" 
                       class="text-blue-600 hover:text-blue-700 transition-colors text-sm font-medium">
                        View All →
                    </a>
                </div>
            </div>
            
            <div class="p-6">
                <?php if (empty($recentPayments)): ?>
                    <div class="text-center py-8">
                        <div class="mx-auto w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mb-4">
                            <i class="fas fa-money-bill-wave text-gray-400 text-xl"></i>
                        </div>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">No payments yet</h3>
                        <p class="text-gray-600 mb-4">This employee hasn't received any payments yet.</p>
                        <a href="<?php echo Helper::baseUrl('modules/employee_payments/add.php?employee_id=' . Helper::encryptId($employee['id'])); ?>" 
                           class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                            <i class="fas fa-plus mr-2"></i>
                            Add First Payment
                        </a>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($recentPayments as $payment): ?>
                            <div class="flex items-start justify-between p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center space-x-3 mb-2">
                                        <h4 class="font-medium text-gray-900">
                                            $<?php echo number_format($payment['payment_amount'], 2); ?>
                                        </h4>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php
                                            switch($payment['payment_status']) {
                                                case 'paid':
                                                    echo 'bg-green-100 text-green-800';
                                                    break;
                                                case 'pending':
                                                    echo 'bg-yellow-100 text-yellow-800';
                                                    break;
                                                case 'cancelled':
                                                    echo 'bg-red-100 text-red-800';
                                                    break;
                                                default:
                                                    echo 'bg-gray-100 text-gray-800';
                                            }
                                        ?>">
                                            <?php echo ucfirst($payment['payment_status']); ?>
                                        </span>
                                        <span class="text-xs text-gray-500">
                                            <?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="flex flex-col sm:flex-row sm:items-center sm:space-x-4 space-y-1 sm:space-y-0 text-sm text-gray-600">
                                        <span>
                                            <i class="fas fa-calendar mr-1"></i>
                                            <?php echo date('M j, Y', strtotime($payment['payment_date'])); ?>
                                        </span>
                                        <?php if ($payment['project_name']): ?>
                                            <span>
                                                <i class="fas fa-project-diagram mr-1"></i>
                                                <?php echo htmlspecialchars($payment['project_name']); ?>
                                                <?php if ($payment['company_name']): ?>
                                                    - <?php echo htmlspecialchars($payment['company_name']); ?>
                                                <?php endif; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-gray-400">
                                                <i class="fas fa-briefcase mr-1"></i>
                                                General Payment
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if (!empty($payment['work_description'])): ?>
                                        <p class="text-sm text-gray-500 mt-2 line-clamp-2">
                                            <?php echo htmlspecialchars($payment['work_description']); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="ml-4 text-right">
                                    <p class="text-xs text-gray-500">
                                        by <?php echo htmlspecialchars($payment['created_by_name'] ?? 'System'); ?>
                                    </p>
                                    <?php if (!empty($payment['payment_reference'])): ?>
                                        <p class="text-xs text-gray-400 mt-1">
                                            Ref: <?php echo htmlspecialchars($payment['payment_reference']); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
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
                                            $<?php echo number_format($project['total_amount'], 2); ?>
                                        </span>
                                    </span>
                                    <?php if ($project['payment_count'] > 0): ?>
                                        <span>
                                            Earned: 
                                            <span class="font-medium text-green-600">
                                                $<?php echo number_format($project['total_earned_from_project'], 2); ?>
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
                        $<?php echo number_format($stats['total_earned'], 2); ?>
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
                        $<?php echo number_format($stats['total_paid'], 2); ?>
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
                        $<?php echo number_format($stats['pending_amount'], 2); ?>
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
                <a href="<?php echo Helper::baseUrl('modules/employee_payments/add.php?employee_id=' . Helper::encryptId($employee['id'])); ?>" 
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
@media (max-width: 768px) {
    .line-clamp-2 {
        display: -webkit-box;
        -webkit-box-orient: vertical;
        overflow: hidden;
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
}

/* Custom animations for better UX */
.transition-colors {
    transition-property: color, background-color, border-color;
    transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
    transition-duration: 200ms;
}

.group:hover .group-hover\:bg-blue-200 {
    background-color: rgb(191 219 254);
}

.group:hover .group-hover\:bg-green-200 {
    background-color: rgb(187 247 208);
}

.group:hover .group-hover\:bg-purple-200 {
    background-color: rgb(221 214 254);
}

.group:hover .group-hover\:bg-gray-200 {
    background-color: rgb(229 231 235);
}

.group:hover .group-hover\:text-blue-600 {
    color: rgb(37 99 235);
}

.group:hover .group-hover\:text-green-600 {
    color: rgb(22 163 74);
}

.group:hover .group-hover\:text-purple-600 {
    color: rgb(147 51 234);
}

.group:hover .group-hover\:text-gray-600 {
    color: rgb(75 85 99);
}

/* Loading states for better UX */
.loading {
    opacity: 0.7;
    pointer-events: none;
}

/* Focus states for accessibility */
a:focus,
button:focus {
    outline: 2px solid rgb(59 130 246);
    outline-offset: 2px;
}

/* Print styles */
@media print {
    .no-print {
        display: none !important;
    }
    
    body {
        background: white !important;
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
</script>

<?php include '../../includes/footer.php'; ?>