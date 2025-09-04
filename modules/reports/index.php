<?php
// modules/reports/index.php
session_start();

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

$pageTitle = 'Reports Overview - Invoice Manager';

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Get current year and month for default filtering
$currentYear = date('Y');
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;

try {
    // 1. Get monthly INVOICING ACTIVITY for the selected year
    $monthlyInvoicingQuery = "
        SELECT 
            DATE_FORMAT(i.invoice_date, '%Y-%m') as month_year,
            DATE_FORMAT(i.invoice_date, '%M %Y') as month_name,
            MONTH(i.invoice_date) as month_num,
            COUNT(DISTINCT i.id) as invoices_issued,
            COALESCE(SUM(i.total_amount), 0) as total_invoiced,
            COALESCE(SUM(i.paid_amount), 0) as cumulative_paid_on_invoices,
            COALESCE(SUM(i.balance_amount), 0) as current_outstanding,
            COUNT(DISTINCT pr.id) as projects_invoiced,
            COUNT(DISTINCT c.id) as clients_invoiced,
            COUNT(DISTINCT CASE WHEN i.status = 'paid' THEN i.id END) as paid_invoices,
            COUNT(DISTINCT CASE WHEN i.status = 'partially_paid' THEN i.id END) as partial_invoices,
            COUNT(DISTINCT CASE WHEN i.status = 'overdue' THEN i.id END) as overdue_invoices
        FROM invoices i
        LEFT JOIN projects pr ON i.project_id = pr.id
        LEFT JOIN clients c ON i.client_id = c.id
        WHERE YEAR(i.invoice_date) = :year
        GROUP BY DATE_FORMAT(i.invoice_date, '%Y-%m')
        ORDER BY month_year DESC
    ";
    
    $monthlyInvoicingStmt = $db->prepare($monthlyInvoicingQuery);
    $monthlyInvoicingStmt->bindParam(':year', $selectedYear);
    $monthlyInvoicingStmt->execute();
    $monthlyInvoicing = $monthlyInvoicingStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Get monthly CASH FLOW (actual payments received) for the selected year
    $monthlyCashFlowQuery = "
        SELECT 
            DATE_FORMAT(p.payment_date, '%Y-%m') as month_year,
            DATE_FORMAT(p.payment_date, '%M %Y') as month_name,
            COUNT(DISTINCT p.id) as payments_received,
            COALESCE(SUM(p.payment_amount), 0) as cash_received,
            COUNT(DISTINCT p.invoice_id) as invoices_with_payments,
            COUNT(DISTINCT i.client_id) as clients_who_paid
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.id
        WHERE YEAR(p.payment_date) = :year
        GROUP BY DATE_FORMAT(p.payment_date, '%Y-%m')
        ORDER BY month_year DESC
    ";
    
    $monthlyCashFlowStmt = $db->prepare($monthlyCashFlowQuery);
    $monthlyCashFlowStmt->bindParam(':year', $selectedYear);
    $monthlyCashFlowStmt->execute();
    $monthlyCashFlow = $monthlyCashFlowStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert cash flow to associative array for easy lookup
    $cashFlowByMonth = [];
    foreach ($monthlyCashFlow as $cashFlow) {
        $cashFlowByMonth[$cashFlow['month_year']] = $cashFlow;
    }
    
    // 3. Get yearly INVOICING summary
    $yearlyInvoicingQuery = "
        SELECT 
            COUNT(DISTINCT i.id) as total_invoices,
            COALESCE(SUM(i.total_amount), 0) as total_invoiced,
            COALESCE(SUM(i.paid_amount), 0) as cumulative_paid_on_invoices,
            COALESCE(SUM(i.balance_amount), 0) as current_outstanding,
            COUNT(DISTINCT pr.id) as total_projects,
            COUNT(DISTINCT c.id) as total_clients
        FROM invoices i
        LEFT JOIN projects pr ON i.project_id = pr.id
        LEFT JOIN clients c ON i.client_id = c.id
        WHERE YEAR(i.invoice_date) = :year
    ";
    
    $yearlyInvoicingStmt = $db->prepare($yearlyInvoicingQuery);
    $yearlyInvoicingStmt->bindParam(':year', $selectedYear);
    $yearlyInvoicingStmt->execute();
    $yearlyInvoicing = $yearlyInvoicingStmt->fetch(PDO::FETCH_ASSOC);
    
    // 4. Get yearly CASH FLOW summary
    $yearlyCashFlowQuery = "
        SELECT 
            COUNT(DISTINCT p.id) as total_payments_received,
            COALESCE(SUM(p.payment_amount), 0) as total_cash_received
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.id
        WHERE YEAR(p.payment_date) = :year
    ";
    
    $yearlyCashFlowStmt = $db->prepare($yearlyCashFlowQuery);
    $yearlyCashFlowStmt->bindParam(':year', $selectedYear);
    $yearlyCashFlowStmt->execute();
    $yearlyCashFlow = $yearlyCashFlowStmt->fetch(PDO::FETCH_ASSOC);
    
    // 5. Get monthly expenses for the year
    $monthlyExpensesQuery = "
        SELECT 
            DATE_FORMAT(expense_date, '%Y-%m') as month_year,
            DATE_FORMAT(expense_date, '%M %Y') as month_name,
            COALESCE(SUM(amount), 0) as total_expenses,
            COUNT(*) as expense_count
        FROM expenses
        WHERE YEAR(expense_date) = :year
        GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
        ORDER BY month_year DESC
    ";
    
    $monthlyExpensesStmt = $db->prepare($monthlyExpensesQuery);
    $monthlyExpensesStmt->bindParam(':year', $selectedYear);
    $monthlyExpensesStmt->execute();
    $monthlyExpenses = $monthlyExpensesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert expenses to associative array for easy lookup
    $expensesByMonth = [];
    foreach ($monthlyExpenses as $expense) {
        $expensesByMonth[$expense['month_year']] = $expense;
    }
    
    // 6. Get total yearly expenses
    $yearlyExpensesQuery = "
        SELECT COALESCE(SUM(amount), 0) as total_expenses
        FROM expenses
        WHERE YEAR(expense_date) = :year
    ";
    
    $yearlyExpensesStmt = $db->prepare($yearlyExpensesQuery);
    $yearlyExpensesStmt->bindParam(':year', $selectedYear);
    $yearlyExpensesStmt->execute();
    $yearlyExpenses = $yearlyExpensesStmt->fetch(PDO::FETCH_ASSOC)['total_expenses'];
    
    // 7. Calculate key yearly metrics
    $yearlyNetCashFlow = $yearlyCashFlow['total_cash_received'] - $yearlyExpenses;
    $yearlyCollectionRate = $yearlyInvoicing['total_invoiced'] > 0 
        ? ($yearlyInvoicing['cumulative_paid_on_invoices'] / $yearlyInvoicing['total_invoiced']) * 100 
        : 0;
    
    // 8. Get available years for dropdown
    $yearsQuery = "
        SELECT DISTINCT year_val
        FROM (
            SELECT YEAR(invoice_date) as year_val FROM invoices 
            UNION 
            SELECT YEAR(expense_date) as year_val FROM expenses 
            UNION 
            SELECT YEAR(payment_date) as year_val FROM payments
        ) years
        ORDER BY year_val DESC
    ";
    $yearsStmt = $db->prepare($yearsQuery);
    $yearsStmt->execute();
    $availableYears = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // If no years found, add current year
    if (empty($availableYears)) {
        $availableYears = [$currentYear];
    }
    
} catch (Exception $e) {
    Helper::setMessage('Error loading reports: ' . $e->getMessage(), 'error');
    $monthlyInvoicing = [];
    $yearlyInvoicing = [
        'total_invoices' => 0,
        'total_invoiced' => 0,
        'cumulative_paid_on_invoices' => 0,
        'current_outstanding' => 0,
        'total_projects' => 0,
        'total_clients' => 0
    ];
    $yearlyCashFlow = [
        'total_payments_received' => 0,
        'total_cash_received' => 0
    ];
    $yearlyExpenses = 0;
    $yearlyNetCashFlow = 0;
    $yearlyCollectionRate = 0;
    $availableYears = [$currentYear];
}

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-8">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">Financial Reports Overview</h1>
        <p class="text-gray-600 mt-2">Monthly and yearly business performance analysis</p>
    </div>
    
    <!-- Year Filter -->
    <div class="flex items-center gap-4">
        <form method="GET" class="flex items-center gap-2">
            <label class="text-sm font-medium text-gray-700">Year:</label>
            <select name="year" onchange="this.form.submit()" 
                    class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <?php foreach ($availableYears as $year): ?>
                    <option value="<?php echo $year; ?>" <?php echo $year == $selectedYear ? 'selected' : ''; ?>>
                        <?php echo $year; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<!-- Key Metrics Explanation -->
<div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-8">
    <div class="flex">
        <div class="flex-shrink-0">
            <svg class="h-5 w-5 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
            </svg>
        </div>
        <div class="ml-3">
            <p class="text-sm text-blue-700">
                <strong>Metrics Explanation:</strong> 
                "Total Invoiced" shows invoices issued in <?php echo $selectedYear; ?>, 
                while "Cash Received" shows actual payments received in <?php echo $selectedYear; ?> 
                (regardless of when invoices were issued). "Net Cash Flow" is your true cash position change.
            </p>
        </div>
    </div>
</div>

<!-- Yearly Summary Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <!-- Total Invoiced -->
    <div class="bg-gradient-to-r from-blue-500 to-blue-600 rounded-xl p-6 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-blue-100 text-sm font-medium">Total Invoiced</p>
                <p class="text-3xl font-bold">LKR <?php echo number_format($yearlyInvoicing['total_invoiced'], 0); ?></p>
                <p class="text-blue-100 text-sm"><?php echo $yearlyInvoicing['total_invoices']; ?> invoices issued</p>
            </div>
            <div class="bg-white bg-opacity-20 rounded-lg p-3">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
            </div>
        </div>
    </div>
    
    <!-- Cash Received -->
    <div class="bg-gradient-to-r from-green-500 to-green-600 rounded-xl p-6 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-green-100 text-sm font-medium">Cash Received</p>
                <p class="text-3xl font-bold">LKR <?php echo number_format($yearlyCashFlow['total_cash_received'], 0); ?></p>
                <p class="text-green-100 text-sm"><?php echo $yearlyCashFlow['total_payments_received']; ?> payments received</p>
            </div>
            <div class="bg-white bg-opacity-20 rounded-lg p-3">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                </svg>
            </div>
        </div>
    </div>
    
    <!-- Outstanding -->
    <div class="bg-gradient-to-r from-orange-500 to-orange-600 rounded-xl p-6 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-orange-100 text-sm font-medium">Outstanding</p>
                <p class="text-3xl font-bold">LKR <?php echo number_format($yearlyInvoicing['current_outstanding'], 0); ?></p>
                <p class="text-orange-100 text-sm">Current unpaid balance</p>
            </div>
            <div class="bg-white bg-opacity-20 rounded-lg p-3">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
        </div>
    </div>
    
    <!-- Net Cash Flow -->
    <div class="bg-gradient-to-r from-<?php echo $yearlyNetCashFlow >= 0 ? 'green' : 'red'; ?>-500 to-<?php echo $yearlyNetCashFlow >= 0 ? 'green' : 'red'; ?>-600 rounded-xl p-6 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-<?php echo $yearlyNetCashFlow >= 0 ? 'green' : 'red'; ?>-100 text-sm font-medium">Net Cash Flow</p>
                <p class="text-3xl font-bold">LKR <?php echo number_format($yearlyNetCashFlow, 0); ?></p>
                <p class="text-<?php echo $yearlyNetCashFlow >= 0 ? 'green' : 'red'; ?>-100 text-sm">Cash in - Expenses out</p>
            </div>
            <div class="bg-white bg-opacity-20 rounded-lg p-3">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2-2V9a2 2 0 002-2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
            </div>
        </div>
    </div>
</div>

<!-- Additional Metrics Row -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600">Total Expenses</p>
                <p class="text-2xl font-bold text-red-600">LKR <?php echo number_format($yearlyExpenses, 0); ?></p>
                <p class="text-xs text-gray-500">Money spent in <?php echo $selectedYear; ?></p>
            </div>
            <div class="bg-red-100 rounded-lg p-3">
                <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"></path>
                </svg>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600">Collection Rate</p>
                <p class="text-2xl font-bold text-blue-600"><?php echo number_format($yearlyCollectionRate, 1); ?>%</p>
                <p class="text-xs text-gray-500">Of invoiced amount collected</p>
            </div>
            <div class="bg-blue-100 rounded-lg p-3">
                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2-2V9a2 2 0 002-2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600">Business Activity</p>
                <p class="text-2xl font-bold text-gray-900"><?php echo $yearlyInvoicing['total_projects']; ?></p>
                <p class="text-xs text-gray-500"><?php echo $yearlyInvoicing['total_clients']; ?> clients served</p>
            </div>
            <div class="bg-gray-100 rounded-lg p-3">
                <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2-2v2m8 0V2a2 2 0 00-2-2H8a2 2 0 00-2 2v2m8 0V4a2 2 0 00-2-2H8a2 2 0 00-2 2v2m8 0h2a2 2 0 012 2v8a2 2 0 01-2 2h-8a2 2 0 01-2-2V8a2 2 0 012-2z"></path>
                </svg>
            </div>
        </div>
    </div>
</div>

<!-- Monthly Reports Table -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm">
    <div class="px-6 py-4 border-b border-gray-200">
        <h2 class="text-xl font-semibold text-gray-900">Monthly Business Activity - <?php echo $selectedYear; ?></h2>
        <p class="text-gray-600 text-sm mt-1">Click "Detailed Report" to view complete monthly breakdown</p>
    </div>
    
    <!-- Desktop Table View -->
    <div class="hidden lg:block overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Month</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Invoices Issued</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount Invoiced</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Cash Received</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Expenses</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Net Cash Flow</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Outstanding</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($monthlyInvoicing)): ?>
                    <tr>
                        <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                            <div class="flex flex-col items-center">
                                <svg class="w-12 h-12 text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2-2V9a2 2 0 002-2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                </svg>
                                <h3 class="text-lg font-medium text-gray-900 mb-2">No business activity found</h3>
                                <p class="text-gray-500">No invoices found for <?php echo $selectedYear; ?>. Create some invoices to see reports.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($monthlyInvoicing as $report): ?>
                        <?php 
                        // Get corresponding cash flow and expenses for this month
                        $monthCashFlow = isset($cashFlowByMonth[$report['month_year']]) ? $cashFlowByMonth[$report['month_year']]['cash_received'] : 0;
                        $monthExpenses = isset($expensesByMonth[$report['month_year']]) ? $expensesByMonth[$report['month_year']]['total_expenses'] : 0;
                        $monthNetCashFlow = $monthCashFlow - $monthExpenses;
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($report['month_name']); ?></div>
                                <div class="text-sm text-gray-500">
                                    <?php echo $report['projects_invoiced']; ?> projects, <?php echo $report['clients_invoiced']; ?> clients
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <div class="text-sm font-medium text-gray-900"><?php echo $report['invoices_issued']; ?></div>
                                <div class="text-xs text-gray-500">
                                    <?php echo $report['paid_invoices']; ?> paid, <?php echo $report['partial_invoices']; ?> partial
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <div class="text-sm font-medium text-gray-900">LKR <?php echo number_format($report['total_invoiced'], 0); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <div class="text-sm font-medium text-green-600">LKR <?php echo number_format($monthCashFlow, 0); ?></div>
                                <?php if (isset($cashFlowByMonth[$report['month_year']])): ?>
                                    <div class="text-xs text-gray-500"><?php echo $cashFlowByMonth[$report['month_year']]['payments_received']; ?> payments</div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <div class="text-sm font-medium text-red-600">LKR <?php echo number_format($monthExpenses, 0); ?></div>
                                <?php if (isset($expensesByMonth[$report['month_year']])): ?>
                                    <div class="text-xs text-gray-500"><?php echo $expensesByMonth[$report['month_year']]['expense_count']; ?> expenses</div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <div class="text-sm font-medium <?php echo $monthNetCashFlow >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                    LKR <?php echo number_format($monthNetCashFlow, 0); ?>
                                </div>
                                <div class="text-xs text-gray-500">
                                    <?php echo $monthNetCashFlow >= 0 ? 'Profit' : 'Loss'; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <div class="text-sm font-medium <?php echo $report['current_outstanding'] > 0 ? 'text-orange-600' : 'text-gray-500'; ?>">
                                    LKR <?php echo number_format($report['current_outstanding'], 0); ?>
                                </div>
                                <?php if ($report['overdue_invoices'] > 0): ?>
                                    <div class="text-xs text-red-500"><?php echo $report['overdue_invoices']; ?> overdue</div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <a href="<?php echo Helper::baseUrl('modules/reports/detailed.php?month=' . $report['month_year']); ?>" 
                                   class="inline-flex items-center px-3 py-1.5 bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200 transition-colors text-sm font-medium">
                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2-2V9a2 2 0 002-2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                    </svg>
                                    View Details
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile Card View -->
    <div class="lg:hidden">
        <?php if (empty($monthlyInvoicing)): ?>
            <div class="px-6 py-12 text-center text-gray-500">
                <div class="flex flex-col items-center">
                    <svg class="w-12 h-12 text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2-2V9a2 2 0 002-2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No business activity found</h3>
                    <p class="text-gray-500 text-center">No invoices found for <?php echo $selectedYear; ?>. Create some invoices to see reports.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="divide-y divide-gray-200">
                <?php foreach ($monthlyInvoicing as $report): ?>
                    <?php 
                    // Get corresponding cash flow and expenses for this month
                    $monthCashFlow = isset($cashFlowByMonth[$report['month_year']]) ? $cashFlowByMonth[$report['month_year']]['cash_received'] : 0;
                    $monthExpenses = isset($expensesByMonth[$report['month_year']]) ? $expensesByMonth[$report['month_year']]['total_expenses'] : 0;
                    $monthNetCashFlow = $monthCashFlow - $monthExpenses;
                    ?>
                    <div class="p-4 hover:bg-gray-50 transition-colors">
                        <!-- Month Header -->
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($report['month_name']); ?></h3>
                                <p class="text-sm text-gray-500">
                                    <?php echo $report['projects_invoiced']; ?> projects • <?php echo $report['clients_invoiced']; ?> clients
                                </p>
                            </div>
                            <a href="<?php echo Helper::baseUrl('modules/reports/detailed.php?month=' . $report['month_year']); ?>" 
                               class="inline-flex items-center px-3 py-1.5 bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200 transition-colors text-sm font-medium">
                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2-2V9a2 2 0 002-2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                </svg>
                                Details
                            </a>
                        </div>

                        <!-- Financial Metrics Grid -->
                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <!-- Invoices Issued -->
                            <div class="bg-blue-50 rounded-lg p-3">
                                <p class="text-xs font-medium text-blue-600 uppercase tracking-wider">Invoices</p>
                                <p class="text-lg font-bold text-blue-900"><?php echo $report['invoices_issued']; ?></p>
                                <p class="text-xs text-blue-700">
                                    <?php echo $report['paid_invoices']; ?> paid, <?php echo $report['partial_invoices']; ?> partial
                                </p>
                            </div>

                            <!-- Amount Invoiced -->
                            <div class="bg-gray-50 rounded-lg p-3">
                                <p class="text-xs font-medium text-gray-600 uppercase tracking-wider">Invoiced</p>
                                <p class="text-lg font-bold text-gray-900">LKR <?php echo number_format($report['total_invoiced'], 0); ?></p>
                            </div>

                            <!-- Cash Received -->
                            <div class="bg-green-50 rounded-lg p-3">
                                <p class="text-xs font-medium text-green-600 uppercase tracking-wider">Cash In</p>
                                <p class="text-lg font-bold text-green-900">LKR <?php echo number_format($monthCashFlow, 0); ?></p>
                                <?php if (isset($cashFlowByMonth[$report['month_year']])): ?>
                                    <p class="text-xs text-green-700"><?php echo $cashFlowByMonth[$report['month_year']]['payments_received']; ?> payments</p>
                                <?php endif; ?>
                            </div>

                            <!-- Expenses -->
                            <div class="bg-red-50 rounded-lg p-3">
                                <p class="text-xs font-medium text-red-600 uppercase tracking-wider">Expenses</p>
                                <p class="text-lg font-bold text-red-900">LKR <?php echo number_format($monthExpenses, 0); ?></p>
                                <?php if (isset($expensesByMonth[$report['month_year']])): ?>
                                    <p class="text-xs text-red-700"><?php echo $expensesByMonth[$report['month_year']]['expense_count']; ?> expenses</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Bottom Summary Row -->
                        <div class="grid grid-cols-2 gap-4">
                            <!-- Net Cash Flow -->
                            <div class="bg-<?php echo $monthNetCashFlow >= 0 ? 'green' : 'red'; ?>-50 rounded-lg p-3">
                                <p class="text-xs font-medium text-<?php echo $monthNetCashFlow >= 0 ? 'green' : 'red'; ?>-600 uppercase tracking-wider">Net Flow</p>
                                <p class="text-lg font-bold text-<?php echo $monthNetCashFlow >= 0 ? 'green' : 'red'; ?>-900">
                                    LKR <?php echo number_format($monthNetCashFlow, 0); ?>
                                </p>
                                <p class="text-xs text-<?php echo $monthNetCashFlow >= 0 ? 'green' : 'red'; ?>-700">
                                    <?php echo $monthNetCashFlow >= 0 ? 'Profit' : 'Loss'; ?>
                                </p>
                            </div>

                            <!-- Outstanding -->
                            <div class="bg-orange-50 rounded-lg p-3">
                                <p class="text-xs font-medium text-orange-600 uppercase tracking-wider">Outstanding</p>
                                <p class="text-lg font-bold text-orange-900">LKR <?php echo number_format($report['current_outstanding'], 0); ?></p>
                                <?php if ($report['overdue_invoices'] > 0): ?>
                                    <p class="text-xs text-red-600"><?php echo $report['overdue_invoices']; ?> overdue</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Performance Summary -->
<?php if (!empty($monthlyInvoicing)): ?>
<div class="mt-8 grid grid-cols-1 lg:grid-cols-2 gap-8">
    <!-- Monthly Performance Chart Placeholder -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Monthly Performance Overview</h3>
        <div class="space-y-4">
            <?php
            $bestMonth = null;
            $worstMonth = null;
            $bestCashFlow = PHP_FLOAT_MIN;
            $worstCashFlow = PHP_FLOAT_MAX;
            
            foreach ($monthlyInvoicing as $report) {
                $monthCashFlow = isset($cashFlowByMonth[$report['month_year']]) ? $cashFlowByMonth[$report['month_year']]['cash_received'] : 0;
                $monthExpenses = isset($expensesByMonth[$report['month_year']]) ? $expensesByMonth[$report['month_year']]['total_expenses'] : 0;
                $netCashFlow = $monthCashFlow - $monthExpenses;
                
                if ($netCashFlow > $bestCashFlow) {
                    $bestCashFlow = $netCashFlow;
                    $bestMonth = $report;
                }
                if ($netCashFlow < $worstCashFlow) {
                    $worstCashFlow = $netCashFlow;
                    $worstMonth = $report;
                }
            }
            ?>
            
            <?php if ($bestMonth): ?>
                <div class="flex items-center justify-between p-3 bg-green-50 rounded-lg">
                    <div>
                        <p class="text-sm font-medium text-green-800">Best Month</p>
                        <p class="text-lg font-bold text-green-600"><?php echo $bestMonth['month_name']; ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-green-600">Net Cash Flow</p>
                        <p class="text-lg font-bold text-green-800">LKR <?php echo number_format($bestCashFlow, 0); ?></p>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($worstMonth && $worstCashFlow < 0): ?>
                <div class="flex items-center justify-between p-3 bg-red-50 rounded-lg">
                    <div>
                        <p class="text-sm font-medium text-red-800">Challenging Month</p>
                        <p class="text-lg font-bold text-red-600"><?php echo $worstMonth['month_name']; ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-red-600">Net Cash Flow</p>
                        <p class="text-lg font-bold text-red-800">LKR <?php echo number_format($worstCashFlow, 0); ?></p>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="pt-3 border-t border-gray-200">
                <div class="grid grid-cols-2 gap-4 text-center">
                    <div>
                        <p class="text-2xl font-bold text-blue-600"><?php echo count($monthlyInvoicing); ?></p>
                        <p class="text-sm text-gray-600">Active Months</p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-green-600"><?php echo number_format($yearlyInvoicing['total_invoices'] > 0 ? $yearlyInvoicing['total_invoiced'] / $yearlyInvoicing['total_invoices'] : 0, 0); ?></p>
                        <p class="text-sm text-gray-600">Avg Invoice</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Quick Insights -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Quick Insights</h3>
        <div class="space-y-4">
            <div class="flex items-start space-x-3">
                <div class="flex-shrink-0">
                    <div class="w-2 h-2 bg-blue-400 rounded-full mt-2"></div>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-900">Collection Performance</p>
                    <p class="text-sm text-gray-600">
                        Your collection rate is <?php echo number_format($yearlyCollectionRate, 1); ?>%. 
                        <?php if ($yearlyCollectionRate >= 85): ?>
                            Excellent collection performance!
                        <?php elseif ($yearlyCollectionRate >= 70): ?>
                            Good collection rate, some room for improvement.
                        <?php else: ?>
                            Consider improving collection processes.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            
            <div class="flex items-start space-x-3">
                <div class="flex-shrink-0">
                    <div class="w-2 h-2 bg-green-400 rounded-full mt-2"></div>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-900">Cash Flow Status</p>
                    <p class="text-sm text-gray-600">
                        Your net cash flow for <?php echo $selectedYear; ?> is 
                        <?php if ($yearlyNetCashFlow > 0): ?>
                            positive by LKR <?php echo number_format($yearlyNetCashFlow, 0); ?>. Great job!
                        <?php elseif ($yearlyNetCashFlow == 0): ?>
                            balanced. You broke even this year.
                        <?php else: ?>
                            negative by LKR <?php echo number_format(abs($yearlyNetCashFlow), 0); ?>. Focus on cost management.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            
            <div class="flex items-start space-x-3">
                <div class="flex-shrink-0">
                    <div class="w-2 h-2 bg-orange-400 rounded-full mt-2"></div>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-900">Outstanding Balance</p>
                    <p class="text-sm text-gray-600">
                        You have LKR <?php echo number_format($yearlyInvoicing['current_outstanding'], 0); ?> in outstanding invoices. 
                        <?php 
                        $outstandingPercent = $yearlyInvoicing['total_invoiced'] > 0 ? ($yearlyInvoicing['current_outstanding'] / $yearlyInvoicing['total_invoiced']) * 100 : 0;
                        if ($outstandingPercent <= 10): ?>
                            Very low outstanding balance.
                        <?php elseif ($outstandingPercent <= 25): ?>
                            Manageable outstanding balance.
                        <?php else: ?>
                            Consider following up on overdue invoices.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            
            <?php if ($yearlyExpenses > 0): ?>
                <div class="flex items-start space-x-3">
                    <div class="flex-shrink-0">
                        <div class="w-2 h-2 bg-red-400 rounded-full mt-2"></div>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900">Expense Management</p>
                        <p class="text-sm text-gray-600">
                            Your expenses are 
                            <?php 
                            $expenseRatio = $yearlyCashFlow['total_cash_received'] > 0 ? ($yearlyExpenses / $yearlyCashFlow['total_cash_received']) * 100 : 0;
                            echo number_format($expenseRatio, 1); ?>% of your cash received.
                            <?php if ($expenseRatio <= 30): ?>
                                Excellent expense control!
                            <?php elseif ($expenseRatio <= 50): ?>
                                Good expense management.
                            <?php else: ?>
                                Consider reviewing your expense structure.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Enhanced functionality for reports overview
document.addEventListener('DOMContentLoaded', function() {
    // Add hover effects to cards
    const cards = document.querySelectorAll('.bg-gradient-to-r, .bg-white.rounded-xl');
    cards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
            this.style.transition = 'transform 0.2s ease';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
    
    // Add click-to-copy functionality for financial amounts
    const amounts = document.querySelectorAll('[class*="font-bold"]:not(.text-gray-900)');
    amounts.forEach(amount => {
        if (amount.textContent.includes('LKR')) {
            amount.style.cursor = 'pointer';
            amount.title = 'Click to copy amount';
            
            amount.addEventListener('click', function() {
                const amountText = this.textContent.replace('LKR ', '').trim();
                navigator.clipboard.writeText(amountText).then(() => {
                    const originalText = this.textContent;
                    this.textContent = 'Copied!';
                    setTimeout(() => {
                        this.textContent = originalText;
                    }, 1000);
                });
            });
        }
    });
    
    // Table row highlighting and interaction
    const tableRows = document.querySelectorAll('tbody tr:not(:first-child)');
    tableRows.forEach(row => {
        row.addEventListener('click', function(e) {
            // Don't trigger if clicking on the action button
            if (!e.target.closest('a')) {
                const detailButton = this.querySelector('a');
                if (detailButton) {
                    window.location.href = detailButton.href;
                }
            }
        });
    });
    
    // Mobile card interactions
    const mobileCards = document.querySelectorAll('.lg\\:hidden > div > div');
    mobileCards.forEach(card => {
        card.addEventListener('click', function(e) {
            // Don't trigger if clicking on the action button
            if (!e.target.closest('a')) {
                const detailButton = this.querySelector('a');
                if (detailButton) {
                    window.location.href = detailButton.href;
                }
            }
        });
        
        // Add cursor pointer for mobile cards
        card.style.cursor = 'pointer';
    });
    
    // Enhanced touch interactions for mobile
    if ('ontouchstart' in window) {
        const touchElements = document.querySelectorAll('.grid.grid-cols-2.gap-4 > div');
        touchElements.forEach(element => {
            element.addEventListener('touchstart', function() {
                this.style.transform = 'scale(0.98)';
                this.style.transition = 'transform 0.1s ease';
            });
            
            element.addEventListener('touchend', function() {
                this.style.transform = 'scale(1)';
            });
        });
    }
});

// Quick financial summary function
function showQuickSummary() {
    const totalInvoiced = <?php echo $yearlyInvoicing['total_invoiced']; ?>;
    const totalReceived = <?php echo $yearlyCashFlow['total_cash_received']; ?>;
    const totalExpenses = <?php echo $yearlyExpenses; ?>;
    const netCashFlow = <?php echo $yearlyNetCashFlow; ?>;
    
    console.log('=== <?php echo $selectedYear; ?> Financial Summary ===');
    console.log(`Total Invoiced: LKR ${totalInvoiced.toLocaleString()}`);
    console.log(`Cash Received: LKR ${totalReceived.toLocaleString()}`);
    console.log(`Total Expenses: LKR ${totalExpenses.toLocaleString()}`);
    console.log(`Net Cash Flow: LKR ${netCashFlow.toLocaleString()}`);
    console.log(`Collection Rate: <?php echo number_format($yearlyCollectionRate, 1); ?>%`);
}

// Call on page load for debugging
showQuickSummary();

// Auto-refresh functionality (disabled by default)
let autoRefreshEnabled = false;
let autoRefreshInterval;

function toggleAutoRefresh() {
    autoRefreshEnabled = !autoRefreshEnabled;
    
    if (autoRefreshEnabled) {
        autoRefreshInterval = setInterval(() => {
            // Only refresh if no dropdown is focused
            if (!document.querySelector('select:focus')) {
                window.location.reload();
            }
        }, 300000); // Refresh every 5 minutes
        console.log('Auto-refresh enabled for reports');
    } else {
        clearInterval(autoRefreshInterval);
        console.log('Auto-refresh disabled');
    }
}

// Export functionality placeholder
function exportYearlyReport(format) {
    const year = <?php echo $selectedYear; ?>;
    console.log(`Export ${year} yearly report to ${format} - to be implemented`);
    
    // Future implementation:
    // - CSV export of monthly data
    // - PDF summary report
    // - Excel workbook with multiple sheets
}

// Print functionality
function printYearlyReport() {
    window.print();
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl/Cmd + P for print
    if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
        e.preventDefault();
        printYearlyReport();
    }
    
    // Ctrl/Cmd + R for refresh (if auto-refresh is disabled)
    if ((e.ctrlKey || e.metaKey) && e.key === 'r' && !autoRefreshEnabled) {
        e.preventDefault();
        window.location.reload();
    }
});
</script>

<?php include '../../includes/footer.php'; ?>