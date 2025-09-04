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
    // 1. Get incomplete projects (pending + in_progress)
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM projects WHERE status IN ('pending', 'in_progress')");
    $stmt->execute();
    $incompleteProjects = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // 2. Get this month's CASH RECEIVED (payments received this month)
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(payment_amount), 0) as total 
        FROM payments 
        WHERE YEAR(payment_date) = YEAR(CURDATE()) 
        AND MONTH(payment_date) = MONTH(CURDATE())
    ");
    $stmt->execute();
    $thisMonthCashReceived = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 3. Get last month's CASH RECEIVED
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(payment_amount), 0) as total 
        FROM payments 
        WHERE YEAR(payment_date) = YEAR(CURDATE() - INTERVAL 1 MONTH) 
        AND MONTH(payment_date) = MONTH(CURDATE() - INTERVAL 1 MONTH)
    ");
    $stmt->execute();
    $lastMonthCashReceived = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 4. Calculate cash flow growth percentage
    $cashFlowGrowth = $lastMonthCashReceived > 0 ? (($thisMonthCashReceived - $lastMonthCashReceived) / $lastMonthCashReceived * 100) : 0;
    
    // 5. Get total CASH RECEIVED (all time)
    $stmt = $db->prepare("SELECT COALESCE(SUM(payment_amount), 0) as total FROM payments");
    $stmt->execute();
    $totalCashReceived = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 6. Get CURRENT outstanding balance (all unpaid invoices)
    $stmt = $db->prepare("SELECT COALESCE(SUM(balance_amount), 0) as total FROM invoices WHERE balance_amount > 0");
    $stmt->execute();
    $currentOutstanding = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 7. Get overdue invoices count
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM invoices WHERE due_date < CURDATE() AND balance_amount > 0");
    $stmt->execute();
    $overdueInvoices = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // 8. Get this month's INVOICING ACTIVITY
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as invoices_issued,
            COALESCE(SUM(total_amount), 0) as total_invoiced
        FROM invoices 
        WHERE YEAR(invoice_date) = YEAR(CURDATE()) 
        AND MONTH(invoice_date) = MONTH(CURDATE())
    ");
    $stmt->execute();
    $thisMonthInvoicing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 9. Get last month's INVOICING ACTIVITY
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as invoices_issued,
            COALESCE(SUM(total_amount), 0) as total_invoiced
        FROM invoices 
        WHERE YEAR(invoice_date) = YEAR(CURDATE() - INTERVAL 1 MONTH) 
        AND MONTH(invoice_date) = MONTH(CURDATE() - INTERVAL 1 MONTH)
    ");
    $stmt->execute();
    $lastMonthInvoicing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 10. Get this month's EXPENSES
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as expense_count,
            COALESCE(SUM(amount), 0) as total_expenses
        FROM expenses 
        WHERE YEAR(expense_date) = YEAR(CURDATE()) 
        AND MONTH(expense_date) = MONTH(CURDATE())
    ");
    $stmt->execute();
    $thisMonthExpenses = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 11. Get last month's EXPENSES
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as expense_count,
            COALESCE(SUM(amount), 0) as total_expenses
        FROM expenses 
        WHERE YEAR(expense_date) = YEAR(CURDATE() - INTERVAL 1 MONTH) 
        AND MONTH(expense_date) = MONTH(CURDATE() - INTERVAL 1 MONTH)
    ");
    $stmt->execute();
    $lastMonthExpenses = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 12. Calculate NET CASH FLOW
    $thisMonthNetCashFlow = $thisMonthCashReceived - $thisMonthExpenses['total_expenses'];
    $lastMonthNetCashFlow = $lastMonthCashReceived - $lastMonthExpenses['total_expenses'];
    
    // 13. Get recent invoices (last 5)
    $stmt = $db->prepare("
        SELECT i.*, c.company_name 
        FROM invoices i 
        JOIN clients c ON i.client_id = c.id 
        ORDER BY i.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recentInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 14. Get recent projects (last 5)
    $stmt = $db->prepare("
        SELECT p.*, c.company_name 
        FROM projects p 
        JOIN clients c ON p.client_id = c.id 
        ORDER BY p.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recentProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 15. Get recent payments (last 5)
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
    
    // 16. Get key business metrics
    $stmt = $db->prepare("
        SELECT 
            COUNT(DISTINCT c.id) as total_clients,
            COUNT(DISTINCT p.id) as total_projects,
            COUNT(DISTINCT i.id) as total_invoices
        FROM clients c
        LEFT JOIN projects p ON c.id = p.client_id
        LEFT JOIN invoices i ON c.id = i.client_id
        WHERE c.is_active = 1
    ");
    $stmt->execute();
    $businessMetrics = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 17. Get urgent items (overdue invoices, due soon)
    $stmt = $db->prepare("
        SELECT 
            i.invoice_number,
            i.total_amount,
            i.balance_amount,
            i.due_date,
            c.company_name,
            DATEDIFF(CURDATE(), i.due_date) as days_overdue
        FROM invoices i
        JOIN clients c ON i.client_id = c.id
        WHERE i.balance_amount > 0 
        AND (i.due_date < CURDATE() OR DATEDIFF(i.due_date, CURDATE()) <= 7)
        ORDER BY i.due_date ASC
        LIMIT 5
    ");
    $stmt->execute();
    $urgentInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 18. Calculate collection rate
    $stmt = $db->prepare("
        SELECT 
            COALESCE(SUM(total_amount), 0) as total_invoiced,
            COALESCE(SUM(paid_amount), 0) as total_collected
        FROM invoices
    ");
    $stmt->execute();
    $collectionData = $stmt->fetch(PDO::FETCH_ASSOC);
    $collectionRate = $collectionData['total_invoiced'] > 0 
        ? ($collectionData['total_collected'] / $collectionData['total_invoiced']) * 100 
        : 0;
    
} catch (Exception $e) {
    $error = "Error loading dashboard data: " . $e->getMessage();
    // Set default values to prevent errors
    $incompleteProjects = 0;
    $thisMonthCashReceived = 0;
    $lastMonthCashReceived = 0;
    $cashFlowGrowth = 0;
    $totalCashReceived = 0;
    $currentOutstanding = 0;
    $overdueInvoices = 0;
    $thisMonthInvoicing = ['invoices_issued' => 0, 'total_invoiced' => 0];
    $lastMonthInvoicing = ['invoices_issued' => 0, 'total_invoiced' => 0];
    $thisMonthExpenses = ['expense_count' => 0, 'total_expenses' => 0];
    $lastMonthExpenses = ['expense_count' => 0, 'total_expenses' => 0];
    $thisMonthNetCashFlow = 0;
    $lastMonthNetCashFlow = 0;
    $recentInvoices = [];
    $recentProjects = [];
    $recentPayments = [];
    $businessMetrics = ['total_clients' => 0, 'total_projects' => 0, 'total_invoices' => 0];
    $urgentInvoices = [];
    $collectionRate = 0;
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
        <div class="mt-2 sm:mt-0 flex items-center gap-4">
            <div class="text-right">
                <p class="text-xs sm:text-sm text-gray-500">
                    <?php echo date('M j, Y'); ?>
                </p>
                <p class="text-xs text-gray-400">
                    Last updated: <?php echo date('g:i A'); ?>
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Key Performance Indicators -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-6 sm:mb-8">
    <!-- Active Projects -->
    <div class="bg-white rounded-lg sm:rounded-xl p-3 sm:p-4 border border-gray-200 hover:shadow-md transition-shadow">
        <div class="flex items-center justify-between">
            <div class="w-8 h-8 sm:w-10 sm:h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 sm:w-5 sm:h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
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
            <p class="text-xs sm:text-sm text-gray-600">Active Projects</p>
        </div>
    </div>

    <!-- This Month Cash Received -->
    <div class="bg-white rounded-lg sm:rounded-xl p-3 sm:p-4 border border-gray-200 hover:shadow-md transition-shadow">
        <div class="flex items-center justify-between">
            <div class="w-8 h-8 sm:w-10 sm:h-10 bg-green-100 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 sm:w-5 sm:h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                </svg>
            </div>
            <?php if ($cashFlowGrowth != 0): ?>
                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium <?php echo $cashFlowGrowth > 0 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                    <?php echo $cashFlowGrowth > 0 ? '+' : ''; ?><?php echo number_format($cashFlowGrowth, 1); ?>%
                </span>
            <?php endif; ?>
        </div>
        <div class="mt-2 sm:mt-3">
            <p class="text-sm sm:text-lg font-bold text-gray-900">LKR <?php echo number_format($thisMonthCashReceived, 0); ?></p>
            <p class="text-xs sm:text-sm text-gray-600">Cash Received</p>
        </div>
    </div>

    <!-- Net Cash Flow -->
    <div class="bg-white rounded-lg sm:rounded-xl p-3 sm:p-4 border border-gray-200 hover:shadow-md transition-shadow">
        <div class="flex items-center justify-between">
            <div class="w-8 h-8 sm:w-10 sm:h-10 <?php echo $thisMonthNetCashFlow >= 0 ? 'bg-green-100' : 'bg-red-100'; ?> rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 sm:w-5 sm:h-5 <?php echo $thisMonthNetCashFlow >= 0 ? 'text-green-600' : 'text-red-600'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2-2V9a2 2 0 002-2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
            </div>
        </div>
        <div class="mt-2 sm:mt-3">
            <p class="text-sm sm:text-lg font-bold <?php echo $thisMonthNetCashFlow >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                LKR <?php echo number_format($thisMonthNetCashFlow, 0); ?>
            </p>
            <p class="text-xs sm:text-sm text-gray-600">Net Cash Flow</p>
        </div>
    </div>

    <!-- Outstanding Balance -->
    <div class="bg-white rounded-lg sm:rounded-xl p-3 sm:p-4 border border-gray-200 hover:shadow-md transition-shadow">
        <div class="flex items-center justify-between">
            <div class="w-8 h-8 sm:w-10 sm:h-10 bg-orange-100 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 sm:w-5 sm:h-5 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
        </div>
        <div class="mt-2 sm:mt-3">
            <p class="text-sm sm:text-lg font-bold text-gray-900">LKR <?php echo number_format($currentOutstanding, 0); ?></p>
            <p class="text-xs sm:text-sm text-gray-600">Outstanding</p>
        </div>
    </div>
</div>

<!-- Business Overview -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6 mb-6 sm:mb-8">
    <!-- Monthly Comparison -->
    <div class="bg-white rounded-xl border border-gray-200 p-4 sm:p-6 lg:col-span-2">
        <div class="mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Monthly Performance</h3>
            <p class="text-sm text-gray-600">Cash flow comparison</p>
        </div>
        
        <div class="grid grid-cols-2 gap-4">
            <!-- This Month -->
            <div class="text-center">
                <h4 class="text-sm font-medium text-gray-700 mb-2"><?php echo date('F Y'); ?></h4>
                <div class="space-y-2">
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Cash In:</span>
                        <span class="font-medium text-green-600">LKR <?php echo number_format($thisMonthCashReceived, 0); ?></span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Invoiced:</span>
                        <span class="font-medium">LKR <?php echo number_format($thisMonthInvoicing['total_invoiced'], 0); ?></span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Expenses:</span>
                        <span class="font-medium text-red-600">LKR <?php echo number_format($thisMonthExpenses['total_expenses'], 0); ?></span>
                    </div>
                    <div class="pt-2 border-t border-gray-100">
                        <div class="flex justify-between text-sm font-semibold">
                            <span>Net Cash Flow:</span>
                            <span class="<?php echo $thisMonthNetCashFlow >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                LKR <?php echo number_format($thisMonthNetCashFlow, 0); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Last Month -->
            <div class="text-center">
                <h4 class="text-sm font-medium text-gray-700 mb-2"><?php echo date('F Y', strtotime('-1 month')); ?></h4>
                <div class="space-y-2">
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Cash In:</span>
                        <span class="font-medium text-green-600">LKR <?php echo number_format($lastMonthCashReceived, 0); ?></span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Invoiced:</span>
                        <span class="font-medium">LKR <?php echo number_format($lastMonthInvoicing['total_invoiced'], 0); ?></span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Expenses:</span>
                        <span class="font-medium text-red-600">LKR <?php echo number_format($lastMonthExpenses['total_expenses'], 0); ?></span>
                    </div>
                    <div class="pt-2 border-t border-gray-100">
                        <div class="flex justify-between text-sm font-semibold">
                            <span>Net Cash Flow:</span>
                            <span class="<?php echo $lastMonthNetCashFlow >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                LKR <?php echo number_format($lastMonthNetCashFlow, 0); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Key Metrics -->
    <div class="bg-white rounded-xl border border-gray-200 p-4 sm:p-6">
        <div class="mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Key Metrics</h3>
        </div>
        
        <div class="space-y-4">
            <div class="flex justify-between items-center">
                <span class="text-sm text-gray-600">Collection Rate</span>
                <span class="text-sm font-semibold text-blue-600"><?php echo number_format($collectionRate, 1); ?>%</span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-sm text-gray-600">Total Clients</span>
                <span class="text-sm font-semibold"><?php echo number_format($businessMetrics['total_clients']); ?></span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-sm text-gray-600">Total Projects</span>
                <span class="text-sm font-semibold"><?php echo number_format($businessMetrics['total_projects']); ?></span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-sm text-gray-600">Total Invoices</span>
                <span class="text-sm font-semibold"><?php echo number_format($businessMetrics['total_invoices']); ?></span>
            </div>
            <div class="pt-2 border-t border-gray-100">
                <div class="flex justify-between items-center">
                    <span class="text-sm font-medium text-gray-900">Lifetime Revenue</span>
                    <span class="text-sm font-bold text-gray-900">LKR <?php echo number_format($totalCashReceived, 0); ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Urgent Items Alert -->
<?php if (!empty($urgentInvoices)): ?>
<div class="bg-red-50 border-l-4 border-red-400 p-4 mb-6">
    <div class="flex">
        <div class="flex-shrink-0">
            <svg class="h-5 w-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
            </svg>
        </div>
        <div class="ml-3">
            <h3 class="text-sm font-medium text-red-800">Urgent: Overdue Invoices</h3>
            <div class="mt-2 text-sm text-red-700">
                <ul class="list-disc list-inside space-y-1">
                    <?php foreach (array_slice($urgentInvoices, 0, 3) as $urgent): ?>
                        <li>
                            <?php echo htmlspecialchars($urgent['company_name']); ?> - 
                            Invoice #<?php echo htmlspecialchars($urgent['invoice_number']); ?> 
                            (LKR <?php echo number_format($urgent['balance_amount'], 0); ?>) - 
                            <?php if ($urgent['days_overdue'] > 0): ?>
                                <?php echo $urgent['days_overdue']; ?> days overdue
                            <?php else: ?>
                                Due in <?php echo abs($urgent['days_overdue']); ?> days
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (count($urgentInvoices) > 3): ?>
                    <p class="mt-2">And <?php echo count($urgentInvoices) - 3; ?> more urgent items...</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Quick Actions -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6 sm:mb-8">
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
                                    <?php 
                                    $statusClasses = [
                                        'draft' => 'bg-gray-100 text-gray-800',
                                        'sent' => 'bg-blue-100 text-blue-800',
                                        'paid' => 'bg-green-100 text-green-800',
                                        'partially_paid' => 'bg-orange-100 text-orange-800',
                                        'overdue' => 'bg-red-100 text-red-800'
                                    ];
                                    $statusClass = $statusClasses[$invoice['status']] ?? 'bg-gray-100 text-gray-800';
                                    ?>
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium <?php echo $statusClass; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $invoice['status'])); ?>
                                    </span>
                                </div>
                                <p class="text-xs text-gray-500 truncate">
                                    <?php echo htmlspecialchars($invoice['company_name']); ?>
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-medium text-gray-900">
                                    LKR <?php echo number_format($invoice['total_amount'], 0); ?>
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
                                    <?php 
                                    $statusClasses = [
                                        'pending' => 'bg-yellow-100 text-yellow-800',
                                        'in_progress' => 'bg-blue-100 text-blue-800',
                                        'completed' => 'bg-green-100 text-green-800',
                                        'cancelled' => 'bg-red-100 text-red-800'
                                    ];
                                    $statusClass = $statusClasses[$project['status']] ?? 'bg-gray-100 text-gray-800';
                                    ?>
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium <?php echo $statusClass; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $project['status'])); ?>
                                    </span>
                                </div>
                                <p class="text-xs text-gray-500 truncate">
                                    <?php echo htmlspecialchars($project['company_name']); ?>
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-medium text-gray-900">
                                    LKR <?php echo number_format($project['total_amount'], 0); ?>
                                </p>
                                <p class="text-xs text-gray-500">
                                    <?php echo ucfirst(str_replace('_', ' ', $project['project_type'])); ?>
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
                                    LKR <?php echo number_format($payment['payment_amount'], 0); ?>
                                </p>
                                <p class="text-xs text-gray-500">
                                    <?php echo date('M j, Y', strtotime($payment['payment_date'])); ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-4 text-center">
                    <a href="<?php echo Helper::baseUrl('modules/payments/'); ?>" 
                       class="text-sm text-blue-600 hover:text-blue-700 font-medium">View All Payments →</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Performance Insights -->
<?php 
$showInsights = $thisMonthCashReceived > 0 || $lastMonthCashReceived > 0 || $currentOutstanding > 0;
?>
<?php if ($showInsights): ?>
<div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl border border-blue-200 p-6 mb-6">
    <h3 class="text-lg font-semibold text-gray-900 mb-4">Performance Insights</h3>
    
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <!-- Cash Flow Trend -->
        <div class="bg-white rounded-lg p-4 border border-blue-100">
            <div class="flex items-center space-x-2 mb-2">
                <div class="w-2 h-2 <?php echo $cashFlowGrowth >= 0 ? 'bg-green-400' : 'bg-red-400'; ?> rounded-full"></div>
                <h4 class="text-sm font-medium text-gray-900">Cash Flow Trend</h4>
            </div>
            <p class="text-xs text-gray-600">
                <?php if ($cashFlowGrowth > 10): ?>
                    Excellent growth! Your cash receipts increased by <?php echo number_format($cashFlowGrowth, 1); ?>% this month.
                <?php elseif ($cashFlowGrowth > 0): ?>
                    Positive growth of <?php echo number_format($cashFlowGrowth, 1); ?>% in cash receipts.
                <?php elseif ($cashFlowGrowth == 0): ?>
                    Cash receipts remained stable compared to last month.
                <?php else: ?>
                    Cash receipts decreased by <?php echo number_format(abs($cashFlowGrowth), 1); ?>%. Consider following up on outstanding invoices.
                <?php endif; ?>
            </p>
        </div>
        
        <!-- Collection Health -->
        <div class="bg-white rounded-lg p-4 border border-blue-100">
            <div class="flex items-center space-x-2 mb-2">
                <div class="w-2 h-2 <?php echo $collectionRate >= 80 ? 'bg-green-400' : ($collectionRate >= 60 ? 'bg-yellow-400' : 'bg-red-400'); ?> rounded-full"></div>
                <h4 class="text-sm font-medium text-gray-900">Collection Health</h4>
            </div>
            <p class="text-xs text-gray-600">
                <?php if ($collectionRate >= 85): ?>
                    Excellent collection rate of <?php echo number_format($collectionRate, 1); ?>%! Your payment collection is very healthy.
                <?php elseif ($collectionRate >= 70): ?>
                    Good collection rate of <?php echo number_format($collectionRate, 1); ?>%. Some room for improvement.
                <?php elseif ($collectionRate >= 50): ?>
                    Fair collection rate of <?php echo number_format($collectionRate, 1); ?>%. Consider improving follow-up processes.
                <?php else: ?>
                    Low collection rate of <?php echo number_format($collectionRate, 1); ?>%. Focus on payment follow-ups urgently.
                <?php endif; ?>
            </p>
        </div>
        
        <!-- Outstanding Balance -->
        <div class="bg-white rounded-lg p-4 border border-blue-100">
            <div class="flex items-center space-x-2 mb-2">
                <div class="w-2 h-2 <?php echo $overdueInvoices == 0 ? 'bg-green-400' : ($overdueInvoices <= 3 ? 'bg-yellow-400' : 'bg-red-400'); ?> rounded-full"></div>
                <h4 class="text-sm font-medium text-gray-900">Outstanding Status</h4>
            </div>
            <p class="text-xs text-gray-600">
                <?php if ($currentOutstanding == 0): ?>
                    Perfect! No outstanding balances. All invoices are fully paid.
                <?php elseif ($overdueInvoices == 0): ?>
                    LKR <?php echo number_format($currentOutstanding, 0); ?> outstanding, but no overdue invoices. Good management!
                <?php else: ?>
                    LKR <?php echo number_format($currentOutstanding, 0); ?> outstanding with <?php echo $overdueInvoices; ?> overdue invoice(s). Follow up needed.
                <?php endif; ?>
            </p>
        </div>
    </div>
</div>
<?php endif; ?>

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

/* Pulse animation for urgent alerts */
@keyframes pulse-red {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.8; }
}

.pulse-red {
    animation: pulse-red 2s infinite;
}
</style>

<script>
// Enhanced dashboard JavaScript with improved functionality
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
    function animateNumber(element, start, end, duration = 1200) {
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
            if (element.textContent.includes('LKR')) {
                element.textContent = 'LKR ' + Math.floor(current).toLocaleString();
            } else {
                element.textContent = Math.floor(current).toLocaleString();
            }
        }, 16);
    }
    
    // Initialize number animations with staggered delays
    const numberElements = document.querySelectorAll('.font-bold');
    numberElements.forEach((el, index) => {
        const text = el.textContent.trim();
        const numberMatch = text.match(/[\d,]+/);
        if (numberMatch) {
            const finalNumber = parseInt(numberMatch[0].replace(/,/g, ''));
            if (!isNaN(finalNumber) && finalNumber > 0) {
                const prefix = text.includes('LKR') ? 'LKR ' : '';
                el.textContent = prefix + '0';
                setTimeout(() => animateNumber(el, 0, finalNumber), 300 + (index * 100));
            }
        }
    });
    
    // Add pulse animation to urgent items
    const urgentAlert = document.querySelector('.bg-red-50');
    if (urgentAlert) {
        urgentAlert.classList.add('pulse-red');
    }
    
    // Enhanced touch feedback for mobile devices
    function addTouchFeedback() {
        const touchElements = document.querySelectorAll('a, button, [onclick]');
        
        touchElements.forEach(element => {
            element.addEventListener('touchstart', function(e) {
                this.style.transform = 'scale(0.98)';
                this.style.opacity = '0.9';
                this.style.transition = 'all 0.1s ease';
            }, { passive: true });
            
            element.addEventListener('touchend', function() {
                this.style.transform = '';
                this.style.opacity = '';
            }, { passive: true });
            
            element.addEventListener('touchcancel', function() {
                this.style.transform = '';
                this.style.opacity = '';
            }, { passive: true });
        });
    }
    
    // Add swipe gestures for tab navigation on mobile
    function addSwipeGestures() {
        const tabContainer = document.querySelector('.bg-white.rounded-xl.border');
        if (!tabContainer) return;
        
        let startX = 0;
        let startY = 0;
        let isSwipeMove = false;
        const threshold = 50;
        
        tabContainer.addEventListener('touchstart', function(e) {
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
            isSwipeMove = false;
        }, { passive: true });
        
        tabContainer.addEventListener('touchmove', function(e) {
            if (!isSwipeMove) {
                const diffX = Math.abs(e.touches[0].clientX - startX);
                const diffY = Math.abs(e.touches[0].clientY - startY);
                
                if (diffX > diffY && diffX > 10) {
                    isSwipeMove = true;
                }
            }
            
            if (isSwipeMove) {
                e.preventDefault(); // Prevent scrolling while swiping
            }
        }, { passive: false });
        
        tabContainer.addEventListener('touchend', function(e) {
            if (!isSwipeMove) return;
            
            const endX = e.changedTouches[0].clientX;
            const diffX = startX - endX;
            
            if (Math.abs(diffX) > threshold) {
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
        }, { passive: true });
    }
    
    // Initialize mobile enhancements
    if (window.innerWidth <= 768) {
        addTouchFeedback();
        addSwipeGestures();
    }
    
    // Real-time updates (placeholder for WebSocket implementation)
    function initializeRealTimeUpdates() {
        // This would connect to a WebSocket server in production
        console.log('Real-time updates initialized');
        
        // Example: Update metrics every 30 seconds
        setInterval(function() {
            if (!document.hidden) {
                // In production, this would fetch updated data via AJAX/WebSocket
                updateLastUpdatedTime();
            }
        }, 30000);
    }
    
    function updateLastUpdatedTime() {
        const timeElement = document.querySelector('.text-gray-400');
        if (timeElement && timeElement.textContent.includes('Last updated:')) {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { 
                hour: 'numeric', 
                minute: '2-digit',
                hour12: true 
            });
            timeElement.textContent = `Last updated: ${timeString}`;
        }
    }
    
    // Initialize enhanced features
    initializeRealTimeUpdates();
    
    // Add loading states for better user experience
    function addLoadingStates() {
        const cards = document.querySelectorAll('.bg-white.rounded-lg, .bg-white.rounded-xl');
        cards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            
            setTimeout(() => {
                card.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, index * 50);
        });
    }
    
    addLoadingStates();
    
    // Enhanced error handling and offline support
    function handleConnectionStatus() {
        const showConnectionStatus = (message, type) => {
            const existingStatus = document.querySelector('.connection-status');
            if (existingStatus) existingStatus.remove();
            
            const statusDiv = document.createElement('div');
            statusDiv.className = `connection-status fixed top-4 right-4 px-4 py-2 rounded-lg text-white text-sm z-50 shadow-lg ${
                type === 'offline' ? 'bg-red-500' : 'bg-green-500'
            }`;
            statusDiv.textContent = message;
            document.body.appendChild(statusDiv);
            
            setTimeout(() => {
                statusDiv.remove();
            }, 5000);
        };
        
        window.addEventListener('online', () => {
            showConnectionStatus('Connection restored', 'online');
            updateLastUpdatedTime();
        });
        
        window.addEventListener('offline', () => {
            showConnectionStatus('Working offline', 'offline');
        });
    }
    
    handleConnectionStatus();
    
    // Advanced dashboard features
    function initializeDashboardFeatures() {
        // Add click-to-refresh for metrics
        const metricCards = document.querySelectorAll('[class*="grid-cols-2"] > div');
        metricCards.forEach(card => {
            card.addEventListener('dblclick', function() {
                // In production, this would refresh the specific metric
                this.classList.add('metric-loading');
                setTimeout(() => {
                    this.classList.remove('metric-loading');
                    updateLastUpdatedTime();
                }, 1000);
            });
        });
        
        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Alt/Option + number keys for tab switching
            if (e.altKey && !e.ctrlKey && !e.metaKey) {
                switch(e.code) {
                    case 'Digit1':
                        e.preventDefault();
                        switchTab('invoices');
                        break;
                    case 'Digit2':
                        e.preventDefault();
                        switchTab('projects');
                        break;
                    case 'Digit3':
                        e.preventDefault();
                        switchTab('payments');
                        break;
                    case 'KeyR':
                        e.preventDefault();
                        location.reload();
                        break;
                }
            }
        });
        
        // Add smooth scroll to urgent items
        const urgentSection = document.querySelector('.bg-red-50');
        if (urgentSection) {
            urgentSection.addEventListener('click', function() {
                // Smooth scroll to recent activity
                document.querySelector('.bg-white.rounded-xl.border').scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            });
        }
    }
    
    initializeDashboardFeatures();
    
    // Performance monitoring and optimization
    function initializePerformanceMonitoring() {
        // Monitor page load performance
        if ('performance' in window) {
            window.addEventListener('load', function() {
                const loadTime = performance.timing.loadEventEnd - performance.timing.navigationStart;
                console.log('Dashboard loaded in:', loadTime + 'ms');
                
                // Log performance metrics for optimization
                const navigation = performance.getEntriesByType('navigation')[0];
                if (navigation) {
                    console.log('Performance metrics:', {
                        loadTime: loadTime,
                        domContentLoaded: navigation.domContentLoadedEventEnd - navigation.domContentLoadedEventStart,
                        firstPaint: performance.getEntriesByName('first-paint')[0]?.startTime || 0
                    });
                }
            });
        }
        
        // Monitor memory usage (if supported)
        if ('memory' in performance) {
            setInterval(() => {
                const memory = performance.memory;
                if (memory.usedJSHeapSize > 50 * 1024 * 1024) { // 50MB threshold
                    console.warn('High memory usage detected:', memory.usedJSHeapSize / 1024 / 1024 + 'MB');
                }
            }, 60000); // Check every minute
        }
    }
    
    initializePerformanceMonitoring();
    
    // Advanced UI enhancements
    function initializeAdvancedUI() {
        // Add progress indicators for actions
        const actionLinks = document.querySelectorAll('a[href*="create"], a[href*="add"]');
        actionLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                if (!this.dataset.loading) {
                    this.dataset.loading = 'true';
                    const originalText = this.textContent;
                    this.style.opacity = '0.7';
                    this.style.pointerEvents = 'none';
                    
                    // Reset after navigation or timeout
                    setTimeout(() => {
                        this.style.opacity = '';
                        this.style.pointerEvents = '';
                        delete this.dataset.loading;
                    }, 3000);
                }
            });
        });
        
        // Add contextual tooltips
        const elements = document.querySelectorAll('[title]');
        elements.forEach(element => {
            let tooltip = null;
            
            element.addEventListener('mouseenter', function() {
                const title = this.getAttribute('title');
                if (!title) return;
                
                tooltip = document.createElement('div');
                tooltip.className = 'fixed bg-gray-900 text-white text-xs rounded py-1 px-2 z-50 pointer-events-none';
                tooltip.textContent = title;
                document.body.appendChild(tooltip);
                
                // Position tooltip
                const rect = this.getBoundingClientRect();
                tooltip.style.left = rect.left + 'px';
                tooltip.style.top = (rect.bottom + 5) + 'px';
            });
            
            element.addEventListener('mouseleave', function() {
                if (tooltip) {
                    tooltip.remove();
                    tooltip = null;
                }
            });
        });
    }
    
    initializeAdvancedUI();
    
    // Dashboard data refresh functionality
    function setupDataRefresh() {
        let refreshInterval;
        
        // Auto-refresh every 5 minutes when page is visible
        function startAutoRefresh() {
            refreshInterval = setInterval(() => {
                if (!document.hidden) {
                    refreshDashboardData();
                }
            }, 300000); // 5 minutes
        }
        
        function stopAutoRefresh() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
                refreshInterval = null;
            }
        }
        
        // Handle visibility changes
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                stopAutoRefresh();
            } else {
                startAutoRefresh();
                // Refresh immediately when tab becomes visible again
                setTimeout(refreshDashboardData, 1000);
            }
        });
        
        // Start auto-refresh
        startAutoRefresh();
    }
    
    function refreshDashboardData() {
        // In production, this would make AJAX calls to update dashboard data
        console.log('Refreshing dashboard data...');
        
        // Example implementation:
        /*
        fetch(window.location.href + '?ajax=1', {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            updateDashboardMetrics(data);
            updateLastUpdatedTime();
        })
        .catch(error => {
            console.error('Dashboard refresh failed:', error);
        });
        */
        
        // For now, just update the timestamp
        updateLastUpdatedTime();
    }
    
    function updateDashboardMetrics(data) {
        // Update KPI cards
        if (data.thisMonthCashReceived !== undefined) {
            const element = document.querySelector('[data-metric="cash-received"] .font-bold');
            if (element) {
                const current = parseInt(element.textContent.replace(/[^\d]/g, ''));
                animateNumber(element, current, data.thisMonthCashReceived);
            }
        }
        
        // Update other metrics similarly...
        // This would be implemented based on the specific data structure returned by the server
    }
    
    setupDataRefresh();
    
    // Accessibility improvements
    function enhanceAccessibility() {
        // Add ARIA labels for better screen reader support
        const metricCards = document.querySelectorAll('.grid.grid-cols-2 > div');
        metricCards.forEach((card, index) => {
            const title = card.querySelector('.text-xs.sm\\:text-sm')?.textContent || '';
            const value = card.querySelector('.font-bold')?.textContent || '';
            card.setAttribute('aria-label', `${title}: ${value}`);
        });
        
        // Improve tab navigation
        const tabButtons = document.querySelectorAll('[role="tab"]');
        tabButtons.forEach((button, index) => {
            button.setAttribute('tabindex', index === 0 ? '0' : '-1');
            button.addEventListener('focus', function() {
                // Update tabindex for keyboard navigation
                tabButtons.forEach(b => b.setAttribute('tabindex', '-1'));
                this.setAttribute('tabindex', '0');
            });
        });
        
        // Add keyboard navigation for tabs
        document.addEventListener('keydown', function(e) {
            const activeTab = document.querySelector('[role="tab"][tabindex="0"]');
            if (!activeTab) return;
            
            let targetTab = null;
            if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
                e.preventDefault();
                const tabs = Array.from(tabButtons);
                const currentIndex = tabs.indexOf(activeTab);
                
                if (e.key === 'ArrowRight') {
                    targetTab = tabs[currentIndex + 1] || tabs[0];
                } else {
                    targetTab = tabs[currentIndex - 1] || tabs[tabs.length - 1];
                }
                
                if (targetTab) {
                    targetTab.click();
                    targetTab.focus();
                }
            }
        });
    }
    
    enhanceAccessibility();
});

// Utility functions
function formatCurrency(amount) {
    return new Intl.NumberFormat('en-LK', {
        style: 'currency',
        currency: 'LKR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(amount);
}

function showNotification(message, type = 'info', duration = 5000) {
    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 px-4 py-3 rounded-lg shadow-lg z-50 transition-all duration-300 ${
        type === 'success' ? 'bg-green-500 text-white' :
        type === 'error' ? 'bg-red-500 text-white' :
        type === 'warning' ? 'bg-yellow-500 text-black' :
        'bg-blue-500 text-white'
    }`;
    
    notification.innerHTML = `
        <div class="flex items-center space-x-2">
            <span>${message}</span>
            <button onclick="this.parentElement.parentElement.remove()" class="ml-2 text-current opacity-70 hover:opacity-100">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    // Auto-remove after duration
    setTimeout(() => {
        notification.style.opacity = '0';
        notification.style.transform = 'translateX(100%)';
        setTimeout(() => notification.remove(), 300);
    }, duration);
}

// Export functions for use in other scripts
window.dashboardUtils = {
    switchTab: window.switchTab,
    formatCurrency,
    showNotification,
    refreshDashboardData: () => refreshDashboardData()
};
</script>

<?php include '../../includes/footer.php'; ?>