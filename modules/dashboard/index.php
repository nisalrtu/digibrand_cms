<?php
// modules/dashboard/index.php
session_start();

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

$pageTitle = 'Dashboard - Invoice Manager';

// Get dashboard stats
$database = new Database();
$db = $database->getConnection();

try {
    // Get incomplete projects (pending + in_progress)
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM projects WHERE status IN ('pending', 'in_progress')");
    $stmt->execute();
    $incompleteProjects = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Get this month's revenue (from payments made this month)
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(payment_amount), 0) as total 
        FROM payments 
        WHERE YEAR(payment_date) = YEAR(CURDATE()) 
        AND MONTH(payment_date) = MONTH(CURDATE())
    ");
    $stmt->execute();
    $thisMonthRevenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get last month's revenue
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(payment_amount), 0) as total 
        FROM payments 
        WHERE YEAR(payment_date) = YEAR(CURDATE() - INTERVAL 1 MONTH) 
        AND MONTH(payment_date) = MONTH(CURDATE() - INTERVAL 1 MONTH)
    ");
    $stmt->execute();
    $lastMonthRevenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Calculate revenue growth percentage
    $revenueGrowth = $lastMonthRevenue > 0 ? (($thisMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue * 100) : 0;
    
    // Get total revenue (all time)
    $stmt = $db->prepare("SELECT COALESCE(SUM(payment_amount), 0) as total FROM payments");
    $stmt->execute();
    $totalRevenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get pending payments (invoices with outstanding balance)
    $stmt = $db->prepare("SELECT COALESCE(SUM(balance_amount), 0) as total FROM invoices WHERE balance_amount > 0");
    $stmt->execute();
    $pendingPayments = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get overdue invoices count
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM invoices WHERE due_date < CURDATE() AND balance_amount > 0");
    $stmt->execute();
    $overdueInvoices = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Get recent invoices (last 5)
    $stmt = $db->prepare("
        SELECT i.*, c.company_name 
        FROM invoices i 
        JOIN clients c ON i.client_id = c.id 
        ORDER BY i.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recentInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent projects (last 5)
    $stmt = $db->prepare("
        SELECT p.*, c.company_name 
        FROM projects p 
        JOIN clients c ON p.client_id = c.id 
        ORDER BY p.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recentProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent payments (last 5)
    $stmt = $db->prepare("
        SELECT p.*, i.invoice_number, c.company_name 
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.id
        JOIN clients c ON i.client_id = c.id 
        ORDER BY p.payment_date DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recentPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get this month's project revenue
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(total_amount), 0) as total 
        FROM projects 
        WHERE YEAR(created_at) = YEAR(CURDATE()) 
        AND MONTH(created_at) = MONTH(CURDATE())
        AND status = 'completed'
    ");
    $stmt->execute();
    $thisMonthProjects = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get this month's outstanding invoices
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(balance_amount), 0) as total 
        FROM invoices 
        WHERE YEAR(created_at) = YEAR(CURDATE()) 
        AND MONTH(created_at) = MONTH(CURDATE())
        AND balance_amount > 0
    ");
    $stmt->execute();
    $thisMonthOutstanding = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get last month's project revenue
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(total_amount), 0) as total 
        FROM projects 
        WHERE YEAR(created_at) = YEAR(CURDATE() - INTERVAL 1 MONTH) 
        AND MONTH(created_at) = MONTH(CURDATE() - INTERVAL 1 MONTH)
        AND status = 'completed'
    ");
    $stmt->execute();
    $lastMonthProjects = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get last month's outstanding invoices
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(balance_amount), 0) as total 
        FROM invoices 
        WHERE YEAR(created_at) = YEAR(CURDATE() - INTERVAL 1 MONTH) 
        AND MONTH(created_at) = MONTH(CURDATE() - INTERVAL 1 MONTH)
        AND balance_amount > 0
    ");
    $stmt->execute();
    $lastMonthOutstanding = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
} catch (Exception $e) {
    $error = "Error loading dashboard data: " . $e->getMessage();
}

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="mb-4 sm:mb-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Dashboard</h1>
            <p class="text-gray-600 mt-1 text-sm sm:text-base">Welcome back, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</p>
        </div>
        <div class="mt-2 sm:mt-0">
            <p class="text-xs sm:text-sm text-gray-500">
                <?php echo date('M j, Y'); ?>
            </p>
        </div>
    </div>
</div>

<!-- Key Metrics Grid -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-6 sm:mb-8">
    <!-- Incomplete Projects -->
    <div class="bg-white rounded-lg sm:rounded-xl p-3 sm:p-4 border border-gray-200 hover:shadow-md transition-shadow">
        <div class="flex items-center justify-between">
            <div class="w-8 h-8 sm:w-10 sm:h-10 bg-orange-100 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 sm:w-5 sm:h-5 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <?php if ($overdueInvoices > 0): ?>
                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                    <?php echo $overdueInvoices; ?> overdue
                </span>
            <?php endif; ?>
        </div>
        <div class="mt-2 sm:mt-3">
            <p class="text-lg sm:text-2xl font-bold text-gray-900"><?php echo number_format($incompleteProjects); ?></p>
            <p class="text-xs sm:text-sm text-gray-600">Projects In Progress</p>
        </div>
    </div>

    <!-- This Month Revenue -->
    <div class="bg-white rounded-lg sm:rounded-xl p-3 sm:p-4 border border-gray-200 hover:shadow-md transition-shadow">
        <div class="flex items-center justify-between">
            <div class="w-8 h-8 sm:w-10 sm:h-10 bg-green-100 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 sm:w-5 sm:h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                </svg>
            </div>
            <?php if ($revenueGrowth != 0): ?>
                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium <?php echo $revenueGrowth > 0 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                    <?php echo $revenueGrowth > 0 ? '+' : ''; ?><?php echo number_format($revenueGrowth, 1); ?>%
                </span>
            <?php endif; ?>
        </div>
        <div class="mt-2 sm:mt-3">
            <p class="text-sm sm:text-lg font-bold text-gray-900"><?php echo Helper::formatCurrency($thisMonthRevenue); ?></p>
            <p class="text-xs sm:text-sm text-gray-600">This Month</p>
        </div>
    </div>

    <!-- Last Month Revenue -->
    <div class="bg-white rounded-lg sm:rounded-xl p-3 sm:p-4 border border-gray-200 hover:shadow-md transition-shadow">
        <div class="flex items-center">
            <div class="w-8 h-8 sm:w-10 sm:h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 sm:w-5 sm:h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
            </div>
        </div>
        <div class="mt-2 sm:mt-3">
            <p class="text-sm sm:text-lg font-bold text-gray-900"><?php echo Helper::formatCurrency($lastMonthRevenue); ?></p>
            <p class="text-xs sm:text-sm text-gray-600">Last Month</p>
        </div>
    </div>

    <!-- Pending Payments -->
    <div class="bg-white rounded-lg sm:rounded-xl p-3 sm:p-4 border border-gray-200 hover:shadow-md transition-shadow">
        <div class="flex items-center">
            <div class="w-8 h-8 sm:w-10 sm:h-10 bg-amber-100 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 sm:w-5 sm:h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
        </div>
        <div class="mt-2 sm:mt-3">
            <p class="text-sm sm:text-lg font-bold text-gray-900"><?php echo Helper::formatCurrency($pendingPayments); ?></p>
            <p class="text-xs sm:text-sm text-gray-600">Outstanding</p>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6 sm:mb-8">
    <a href="<?php echo Helper::baseUrl('modules/clients/add.php'); ?>" 
       class="bg-white rounded-lg p-3 border border-gray-200 hover:shadow-md hover:border-blue-300 transition-all text-center group">
        <div class="w-8 h-8 bg-blue-50 rounded-lg flex items-center justify-center mx-auto mb-2 group-hover:bg-blue-100 transition-colors">
            <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
            </svg>
        </div>
        <p class="text-xs font-medium text-gray-900 group-hover:text-blue-600 transition-colors">Add Client</p>
    </a>

    <a href="<?php echo Helper::baseUrl('modules/projects/add.php'); ?>" 
       class="bg-white rounded-lg p-3 border border-gray-200 hover:shadow-md hover:border-green-300 transition-all text-center group">
        <div class="w-8 h-8 bg-green-50 rounded-lg flex items-center justify-center mx-auto mb-2 group-hover:bg-green-100 transition-colors">
            <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
            </svg>
        </div>
        <p class="text-xs font-medium text-gray-900 group-hover:text-green-600 transition-colors">New Project</p>
    </a>

    <a href="<?php echo Helper::baseUrl('modules/invoices/create.php'); ?>" 
       class="bg-white rounded-lg p-3 border border-gray-200 hover:shadow-md hover:border-purple-300 transition-all text-center group">
        <div class="w-8 h-8 bg-purple-50 rounded-lg flex items-center justify-center mx-auto mb-2 group-hover:bg-purple-100 transition-colors">
            <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
        </div>
        <p class="text-xs font-medium text-gray-900 group-hover:text-purple-600 transition-colors">Create Invoice</p>
    </a>

    <a href="<?php echo Helper::baseUrl('modules/payments/create.php'); ?>" 
       class="bg-white rounded-lg p-3 border border-gray-200 hover:shadow-md hover:border-emerald-300 transition-all text-center group">
        <div class="w-8 h-8 bg-emerald-50 rounded-lg flex items-center justify-center mx-auto mb-2 group-hover:bg-emerald-100 transition-colors">
            <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v2a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
            </svg>
        </div>
        <p class="text-xs font-medium text-gray-900 group-hover:text-emerald-600 transition-colors">Add Payment</p>
    </a>
</div>

<!-- Financial Overview Charts -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6 mb-6 sm:mb-8">
    <!-- This Month Chart -->
    <div class="bg-white rounded-xl border border-gray-200 p-4 sm:p-6">
        <div class="mb-4">
            <h3 class="text-lg font-semibold text-gray-900">This Month</h3>
            <p class="text-sm text-gray-600"><?php echo date('F Y'); ?></p>
        </div>
        
        <?php 
        $thisMonthTotal = $thisMonthRevenue + $thisMonthProjects + $thisMonthOutstanding;
        $thisMonthHasData = $thisMonthTotal > 0;
        ?>
        
        <?php if (!$thisMonthHasData): ?>
            <div class="flex flex-col items-center justify-center py-8">
                <div class="w-32 h-32 bg-gray-100 rounded-full flex items-center justify-center mb-4">
                    <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                </div>
                <p class="text-gray-500 text-center font-medium">No Data Available</p>
                <p class="text-gray-400 text-sm text-center mt-1">No payments or projects this month</p>
            </div>
        <?php else: ?>
            <div class="flex flex-col sm:flex-row items-center">
                <div class="w-48 h-48 sm:w-40 sm:h-40 lg:w-48 lg:h-48 mb-4 sm:mb-0 sm:mr-6">
                    <canvas id="thisMonthChart" width="192" height="192"></canvas>
                </div>
                <div class="flex-1 space-y-3 w-full">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="w-3 h-3 bg-green-500 rounded-full mr-2"></div>
                            <span class="text-sm text-gray-600">Payments</span>
                        </div>
                        <span class="text-sm font-medium"><?php echo Helper::formatCurrency($thisMonthRevenue); ?></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="w-3 h-3 bg-blue-500 rounded-full mr-2"></div>
                            <span class="text-sm text-gray-600">Projects</span>
                        </div>
                        <span class="text-sm font-medium"><?php echo Helper::formatCurrency($thisMonthProjects); ?></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="w-3 h-3 bg-orange-500 rounded-full mr-2"></div>
                            <span class="text-sm text-gray-600">Outstanding</span>
                        </div>
                        <span class="text-sm font-medium"><?php echo Helper::formatCurrency($thisMonthOutstanding); ?></span>
                    </div>
                    <div class="pt-2 border-t border-gray-100">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium text-gray-900">Total</span>
                            <span class="text-sm font-bold text-gray-900"><?php echo Helper::formatCurrency($thisMonthTotal); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Last Month Chart -->
    <div class="bg-white rounded-xl border border-gray-200 p-4 sm:p-6">
        <div class="mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Last Month</h3>
            <p class="text-sm text-gray-600"><?php echo date('F Y', strtotime('-1 month')); ?></p>
        </div>
        
        <?php 
        $lastMonthTotal = $lastMonthRevenue + $lastMonthProjects + $lastMonthOutstanding;
        $lastMonthHasData = $lastMonthTotal > 0;
        ?>
        
        <?php if (!$lastMonthHasData): ?>
            <div class="flex flex-col items-center justify-center py-8">
                <div class="w-32 h-32 bg-gray-100 rounded-full flex items-center justify-center mb-4">
                    <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                </div>
                <p class="text-gray-500 text-center font-medium">No Data Available</p>
                <p class="text-gray-400 text-sm text-center mt-1">No payments or projects last month</p>
            </div>
        <?php else: ?>
            <div class="flex flex-col sm:flex-row items-center">
                <div class="w-48 h-48 sm:w-40 sm:h-40 lg:w-48 lg:h-48 mb-4 sm:mb-0 sm:mr-6">
                    <canvas id="lastMonthChart" width="192" height="192"></canvas>
                </div>
                <div class="flex-1 space-y-3 w-full">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="w-3 h-3 bg-green-500 rounded-full mr-2"></div>
                            <span class="text-sm text-gray-600">Payments</span>
                        </div>
                        <span class="text-sm font-medium"><?php echo Helper::formatCurrency($lastMonthRevenue); ?></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="w-3 h-3 bg-blue-500 rounded-full mr-2"></div>
                            <span class="text-sm text-gray-600">Projects</span>
                        </div>
                        <span class="text-sm font-medium"><?php echo Helper::formatCurrency($lastMonthProjects); ?></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="w-3 h-3 bg-orange-500 rounded-full mr-2"></div>
                            <span class="text-sm text-gray-600">Outstanding</span>
                        </div>
                        <span class="text-sm font-medium"><?php echo Helper::formatCurrency($lastMonthOutstanding); ?></span>
                    </div>
                    <div class="pt-2 border-t border-gray-100">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium text-gray-900">Total</span>
                            <span class="text-sm font-bold text-gray-900"><?php echo Helper::formatCurrency($lastMonthTotal); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Activity Tabs -->
<div class="bg-white rounded-xl border border-gray-200 mb-6">
    <div class="border-b border-gray-200">
        <nav class="flex space-x-0" role="tablist">
            <button class="flex-1 py-3 px-4 text-sm font-medium text-center border-b-2 border-blue-500 text-blue-600" 
                    id="invoices-tab" role="tab" onclick="switchTab('invoices')">
                Recent Invoices
            </button>
            <button class="flex-1 py-3 px-4 text-sm font-medium text-center border-b-2 border-transparent text-gray-500 hover:text-gray-700" 
                    id="projects-tab" role="tab" onclick="switchTab('projects')">
                Recent Projects
            </button>
            <button class="flex-1 py-3 px-4 text-sm font-medium text-center border-b-2 border-transparent text-gray-500 hover:text-gray-700" 
                    id="payments-tab" role="tab" onclick="switchTab('payments')">
                Recent Payments
            </button>
        </nav>
    </div>
    
    <!-- Invoices Tab -->
    <div id="invoices-content" class="tab-content">
        <div class="p-4">
            <?php if (empty($recentInvoices)): ?>
                <div class="text-center py-6">
                    <svg class="w-10 h-10 text-gray-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <p class="text-gray-500 text-sm">No invoices yet</p>
                    <a href="<?php echo Helper::baseUrl('modules/invoices/create.php'); ?>" 
                       class="text-blue-600 hover:text-blue-700 text-sm font-medium">Create your first invoice</a>
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($recentInvoices as $invoice): ?>
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center space-x-2">
                                    <p class="font-medium text-gray-900 text-sm">
                                        #<?php echo htmlspecialchars($invoice['invoice_number']); ?>
                                    </p>
                                    <?php echo Helper::statusBadge($invoice['status']); ?>
                                </div>
                                <p class="text-xs text-gray-500 truncate">
                                    <?php echo htmlspecialchars($invoice['company_name']); ?>
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-medium text-gray-900">
                                    <?php echo Helper::formatCurrency($invoice['total_amount']); ?>
                                </p>
                                <p class="text-xs text-gray-500">
                                    Due: <?php echo date('M j', strtotime($invoice['due_date'])); ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-4 text-center">
                    <a href="<?php echo Helper::baseUrl('modules/invoices/'); ?>" 
                       class="text-sm text-blue-600 hover:text-blue-700 font-medium">View All Invoices →</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Projects Tab -->
    <div id="projects-content" class="tab-content hidden">
        <div class="p-4">
            <?php if (empty($recentProjects)): ?>
                <div class="text-center py-6">
                    <svg class="w-10 h-10 text-gray-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                    </svg>
                    <p class="text-gray-500 text-sm">No projects yet</p>
                    <a href="<?php echo Helper::baseUrl('modules/projects/add.php'); ?>" 
                       class="text-blue-600 hover:text-blue-700 text-sm font-medium">Create your first project</a>
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($recentProjects as $project): ?>
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center space-x-2">
                                    <p class="font-medium text-gray-900 text-sm truncate">
                                        <?php echo htmlspecialchars($project['project_name']); ?>
                                    </p>
                                    <?php echo Helper::statusBadge($project['status']); ?>
                                </div>
                                <p class="text-xs text-gray-500 truncate">
                                    <?php echo htmlspecialchars($project['company_name']); ?>
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-medium text-gray-900">
                                    <?php echo Helper::formatCurrency($project['total_amount']); ?>
                                </p>
                                <p class="text-xs text-gray-500">
                                    <?php echo ucfirst($project['project_type']); ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-4 text-center">
                    <a href="<?php echo Helper::baseUrl('modules/projects/'); ?>" 
                       class="text-sm text-blue-600 hover:text-blue-700 font-medium">View All Projects →</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Payments Tab -->
    <div id="payments-content" class="tab-content hidden">
        <div class="p-4">
            <?php if (empty($recentPayments)): ?>
                <div class="text-center py-6">
                    <svg class="w-10 h-10 text-gray-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v2a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                    <p class="text-gray-500 text-sm">No payments yet</p>
                    <a href="<?php echo Helper::baseUrl('modules/payments/create.php'); ?>" 
                       class="text-blue-600 hover:text-blue-700 text-sm font-medium">Record your first payment</a>
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($recentPayments as $payment): ?>
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center space-x-2">
                                    <p class="font-medium text-gray-900 text-sm">
                                        #<?php echo htmlspecialchars($payment['invoice_number']); ?>
                                    </p>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                        <?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?>
                                    </span>
                                </div>
                                <p class="text-xs text-gray-500 truncate">
                                    <?php echo htmlspecialchars($payment['company_name']); ?>
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-medium text-gray-900">
                                    <?php echo Helper::formatCurrency($payment['payment_amount']); ?>
                                </p>
                                <p class="text-xs text-gray-500">
                                    <?php echo date('M j, Y', strtotime($payment['payment_date'])); ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-4 text-center">
                    <a href="<?php echo Helper::baseUrl('modules/payments/create.php'); ?>" 
                       class="text-sm text-blue-600 hover:text-blue-700 font-medium">Add New Payment →</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
/* Mobile-first responsive styles */
.tab-content {
    transition: opacity 0.2s ease-in-out;
}

/* Touch-friendly minimum sizes for mobile */
@media (max-width: 640px) {
    .grid.grid-cols-2.lg\:grid-cols-4 > * {
        min-height: 80px;
    }
    
    .bg-white.rounded-lg.p-3 {
        min-height: 44px; /* iOS minimum touch target */
    }
    
    /* Improve readability on small screens */
    .text-xs {
        font-size: 0.75rem;
        line-height: 1rem;
    }
    
    .text-sm {
        font-size: 0.875rem;
        line-height: 1.25rem;
    }
}

/* Enhanced hover states for better user feedback */
.hover\:shadow-md:hover {
    transform: translateY(-1px);
    transition: all 0.2s ease;
}

/* Loading animation for metrics */
.metric-loading {
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: shimmer 1.5s infinite;
    border-radius: 0.25rem;
}

@keyframes shimmer {
    0% { background-position: -200% 0; }
    100% { background-position: 200% 0; }
}

/* Smooth transitions for tab switching */
.tab-content.hidden {
    display: none;
}

/* Improved focus states for accessibility */
button:focus,
a:focus {
    outline: 2px solid #3B82F6;
    outline-offset: 2px;
}

/* Performance optimization for animations */
.transition-all,
.transition-colors,
.transition-shadow {
    will-change: transform;
}
</style>

<script>
// Enhanced dashboard JavaScript with mobile optimizations
document.addEventListener('DOMContentLoaded', function() {
    // Tab switching functionality
    window.switchTab = function(tabName) {
        // Hide all tab contents
        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.add('hidden');
        });
        
        // Remove active classes from all tabs
        document.querySelectorAll('[role="tab"]').forEach(tab => {
            tab.classList.remove('border-blue-500', 'text-blue-600');
            tab.classList.add('border-transparent', 'text-gray-500');
        });
        
        // Show selected tab content
        document.getElementById(tabName + '-content').classList.remove('hidden');
        
        // Add active class to selected tab
        const activeTab = document.getElementById(tabName + '-tab');
        activeTab.classList.remove('border-transparent', 'text-gray-500');
        activeTab.classList.add('border-blue-500', 'text-blue-600');
        
        // Store active tab in localStorage for persistence
        localStorage.setItem('activeTab', tabName);
    };
    
    // Restore active tab from localStorage
    const savedTab = localStorage.getItem('activeTab');
    if (savedTab && document.getElementById(savedTab + '-tab')) {
        switchTab(savedTab);
    }
    
    // Add number animation for metrics
    function animateNumber(element, start, end, duration = 1000) {
        const range = end - start;
        const increment = range / (duration / 16);
        let current = start;
        
        const timer = setInterval(() => {
            current += increment;
            if ((increment > 0 && current >= end) || (increment < 0 && current <= end)) {
                current = end;
                clearInterval(timer);
            }
            
            // Format based on element content type
            if (element.textContent.includes('$')) {
                element.textContent = '$' + Math.floor(current).toLocaleString();
            } else {
                element.textContent = Math.floor(current).toLocaleString();
            }
        }, 16);
    }
    
    // Initialize number animations
    const numberElements = document.querySelectorAll('.text-lg, .text-2xl');
    numberElements.forEach(el => {
        const text = el.textContent.trim();
        if (/^[\$\d,]+$/.test(text.replace(/\$|,/g, ''))) {
            const finalNumber = parseInt(text.replace(/[\$,]/g, ''));
            if (!isNaN(finalNumber) && finalNumber > 0) {
                el.textContent = text.includes('$') ? '$0' : '0';
                setTimeout(() => animateNumber(el, 0, finalNumber), 300);
            }
        }
    });
    
    // Add touch feedback for mobile devices
    function addTouchFeedback() {
        const touchElements = document.querySelectorAll('a, button, [onclick]');
        
        touchElements.forEach(element => {
            element.addEventListener('touchstart', function() {
                this.style.transform = 'scale(0.98)';
                this.style.opacity = '0.8';
            });
            
            element.addEventListener('touchend', function() {
                this.style.transform = '';
                this.style.opacity = '';
            });
            
            element.addEventListener('touchcancel', function() {
                this.style.transform = '';
                this.style.opacity = '';
            });
        });
    }
    
    // Add swipe gestures for tab navigation on mobile
    function addSwipeGestures() {
        const tabContainer = document.querySelector('.bg-white.rounded-xl.border');
        if (!tabContainer) return;
        
        let startX = 0;
        let startY = 0;
        const threshold = 50;
        
        tabContainer.addEventListener('touchstart', function(e) {
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
        });
        
        tabContainer.addEventListener('touchmove', function(e) {
            e.preventDefault(); // Prevent scrolling while swiping
        });
        
        tabContainer.addEventListener('touchend', function(e) {
            const endX = e.changedTouches[0].clientX;
            const endY = e.changedTouches[0].clientY;
            const diffX = startX - endX;
            const diffY = startY - endY;
            
            // Only process horizontal swipes
            if (Math.abs(diffX) > Math.abs(diffY) && Math.abs(diffX) > threshold) {
                const tabs = ['invoices', 'projects', 'payments'];
                const currentTab = localStorage.getItem('activeTab') || 'invoices';
                const currentIndex = tabs.indexOf(currentTab);
                
                if (diffX > 0 && currentIndex < tabs.length - 1) {
                    // Swipe left - next tab
                    switchTab(tabs[currentIndex + 1]);
                } else if (diffX < 0 && currentIndex > 0) {
                    // Swipe right - previous tab
                    switchTab(tabs[currentIndex - 1]);
                }
            }
        });
    }
    
    // Initialize mobile enhancements
    if (window.innerWidth <= 768) {
        addTouchFeedback();
        addSwipeGestures();
    }
    
    // Auto-refresh dashboard data every 5 minutes
    setInterval(function() {
        // Only refresh if the page is visible
        if (!document.hidden) {
            // In production, you would make an AJAX call here
            console.log('Auto-refreshing dashboard data...');
            
            // Example AJAX refresh (uncomment in production):
            /*
            fetch(window.location.href, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                // Update metrics without page reload
                updateDashboardMetrics(data);
            })
            .catch(error => console.log('Refresh failed:', error));
            */
        }
    }, 300000); // 5 minutes
    
    // Add loading states for better user experience
    function addLoadingStates() {
        const cards = document.querySelectorAll('.bg-white.rounded-lg, .bg-white.rounded-xl');
        cards.forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(10px)';
        });
        
        // Animate cards in with stagger effect
        cards.forEach((card, index) => {
            setTimeout(() => {
                card.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, index * 100);
        });
    }
    
    // Initialize loading animations
    addLoadingStates();
    
    // Handle connection status for offline experience
    function handleConnectionStatus() {
        const showStatus = (message, type) => {
            const statusDiv = document.createElement('div');
            statusDiv.className = `fixed top-4 right-4 px-4 py-2 rounded-lg text-white text-sm z-50 ${
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
    
    // Performance monitoring
    if ('performance' in window) {
        window.addEventListener('load', function() {
            const loadTime = performance.timing.loadEventEnd - performance.timing.navigationStart;
            console.log('Dashboard loaded in:', loadTime + 'ms');
        });
    }
});

// Utility function for formatting currency on the client side
function formatCurrency(amount) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0
    }).format(amount);
}

// Function to update metrics via AJAX (for future use)
function updateDashboardMetrics(data) {
    // Update incomplete projects
    const incompleteElement = document.querySelector('[data-metric="incomplete-projects"]');
    if (incompleteElement && data.incompleteProjects !== undefined) {
        animateNumber(incompleteElement, 
            parseInt(incompleteElement.textContent.replace(/,/g, '')), 
            data.incompleteProjects
        );
    }
    
    // Update revenue metrics
    const thisMonthElement = document.querySelector('[data-metric="this-month-revenue"]');
    if (thisMonthElement && data.thisMonthRevenue !== undefined) {
        const current = parseInt(thisMonthElement.textContent.replace(/[\$,]/g, ''));
        thisMonthElement.textContent = '$0';
        animateNumber(thisMonthElement, current, data.thisMonthRevenue);
    }
    
    // Update other metrics similarly...
}

// Service Worker registration for offline functionality (optional)
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('/sw.js')
            .then(function(registration) {
                console.log('SW registered: ', registration);
            })
            .catch(function(registrationError) {
                console.log('SW registration failed: ', registrationError);
            });
    });
}
</script>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

<script>
// Pie Chart Configuration
function createPieChart(canvasId, data, labels, colors) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;
    
    new Chart(ctx, {
        type: 'pie',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: colors,
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: false // We show custom legend
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = formatCurrency(context.parsed);
                            const percentage = ((context.parsed / context.dataset.data.reduce((a, b) => a + b, 0)) * 100).toFixed(1);
                            return `${label}: ${value} (${percentage}%)`;
                        }
                    }
                }
            },
            elements: {
                arc: {
                    borderWidth: 2
                }
            }
        }
    });
}

// Initialize charts when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // This Month Chart
    <?php if ($thisMonthHasData): ?>
    const thisMonthData = [
        <?php echo $thisMonthRevenue; ?>,
        <?php echo $thisMonthProjects; ?>,
        <?php echo $thisMonthOutstanding; ?>
    ].filter(value => value > 0);
    
    const thisMonthLabels = [];
    const thisMonthColors = [];
    
    <?php if ($thisMonthRevenue > 0): ?>
    thisMonthLabels.push('Payments');
    thisMonthColors.push('#10b981');
    <?php endif; ?>
    
    <?php if ($thisMonthProjects > 0): ?>
    thisMonthLabels.push('Projects');
    thisMonthColors.push('#3b82f6');
    <?php endif; ?>
    
    <?php if ($thisMonthOutstanding > 0): ?>
    thisMonthLabels.push('Outstanding');
    thisMonthColors.push('#f59e0b');
    <?php endif; ?>
    
    if (thisMonthData.length > 0) {
        createPieChart('thisMonthChart', thisMonthData, thisMonthLabels, thisMonthColors);
    }
    <?php endif; ?>
    
    // Last Month Chart
    <?php if ($lastMonthHasData): ?>
    const lastMonthData = [
        <?php echo $lastMonthRevenue; ?>,
        <?php echo $lastMonthProjects; ?>,
        <?php echo $lastMonthOutstanding; ?>
    ].filter(value => value > 0);
    
    const lastMonthLabels = [];
    const lastMonthColors = [];
    
    <?php if ($lastMonthRevenue > 0): ?>
    lastMonthLabels.push('Payments');
    lastMonthColors.push('#10b981');
    <?php endif; ?>
    
    <?php if ($lastMonthProjects > 0): ?>
    lastMonthLabels.push('Projects');
    lastMonthColors.push('#3b82f6');
    <?php endif; ?>
    
    <?php if ($lastMonthOutstanding > 0): ?>
    lastMonthLabels.push('Outstanding');
    lastMonthColors.push('#f59e0b');
    <?php endif; ?>
    
    if (lastMonthData.length > 0) {
        createPieChart('lastMonthChart', lastMonthData, lastMonthLabels, lastMonthColors);
    }
    <?php endif; ?>
});
</script>

<?php include '../../includes/footer.php'; ?>