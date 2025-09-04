<?php
// modules/reports/detailed.php
session_start();

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

// Get and validate month parameter
$selectedMonth = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    Helper::setMessage('Invalid month parameter.', 'error');
    Helper::redirect('modules/reports/');
}

$monthParts = explode('-', $selectedMonth);
$year = (int)$monthParts[0];
$month = (int)$monthParts[1];
$monthName = date('F Y', mktime(0, 0, 0, $month, 1, $year));

$pageTitle = 'Detailed Report - ' . $monthName . ' - Invoice Manager';

// Get database connection
$database = new Database();
$db = $database->getConnection();

try {
    // 1. INVOICING ACTIVITY - Invoices issued this month
    $invoiceActivityQuery = "
        SELECT 
            COUNT(DISTINCT i.id) as invoices_issued,
            COALESCE(SUM(i.total_amount), 0) as total_invoiced,
            COALESCE(SUM(i.paid_amount), 0) as cumulative_paid_on_invoices,
            COALESCE(SUM(i.balance_amount), 0) as current_outstanding,
            COUNT(DISTINCT pr.id) as projects_invoiced,
            COUNT(DISTINCT c.id) as clients_invoiced,
            COUNT(DISTINCT CASE WHEN i.status = 'paid' THEN i.id END) as paid_invoices,
            COUNT(DISTINCT CASE WHEN i.status = 'partially_paid' THEN i.id END) as partial_invoices,
            COUNT(DISTINCT CASE WHEN i.status = 'overdue' THEN i.id END) as overdue_invoices,
            COUNT(DISTINCT CASE WHEN i.status = 'draft' THEN i.id END) as draft_invoices,
            COUNT(DISTINCT CASE WHEN i.status = 'sent' THEN i.id END) as sent_invoices
        FROM invoices i
        LEFT JOIN projects pr ON i.project_id = pr.id
        LEFT JOIN clients c ON i.client_id = c.id
        WHERE YEAR(i.invoice_date) = :year AND MONTH(i.invoice_date) = :month
    ";
    
    $invoiceActivityStmt = $db->prepare($invoiceActivityQuery);
    $invoiceActivityStmt->bindParam(':year', $year);
    $invoiceActivityStmt->bindParam(':month', $month);
    $invoiceActivityStmt->execute();
    $invoiceActivity = $invoiceActivityStmt->fetch(PDO::FETCH_ASSOC);
    
    // 2. CASH FLOW - Actual money received this month
    $cashFlowQuery = "
        SELECT 
            COUNT(DISTINCT p.id) as payments_received_count,
            COALESCE(SUM(p.payment_amount), 0) as total_payments_received,
            COUNT(DISTINCT p.invoice_id) as invoices_with_payments,
            COUNT(DISTINCT i.client_id) as clients_who_paid
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.id
        WHERE YEAR(p.payment_date) = :year AND MONTH(p.payment_date) = :month
    ";
    
    $cashFlowStmt = $db->prepare($cashFlowQuery);
    $cashFlowStmt->bindParam(':year', $year);
    $cashFlowStmt->bindParam(':month', $month);
    $cashFlowStmt->execute();
    $cashFlow = $cashFlowStmt->fetch(PDO::FETCH_ASSOC);
    
    // 3. EXPENSES - Money spent this month
    $expenseActivityQuery = "
        SELECT 
            COALESCE(SUM(amount), 0) as total_expenses,
            COUNT(*) as total_expense_items,
            COUNT(CASE WHEN payment_status = 'paid' THEN 1 END) as paid_expenses,
            COUNT(CASE WHEN payment_status = 'pending' THEN 1 END) as pending_expenses,
            COUNT(CASE WHEN payment_status = 'overdue' THEN 1 END) as overdue_expenses
        FROM expenses
        WHERE YEAR(expense_date) = :year AND MONTH(expense_date) = :month
    ";
    
    $expenseActivityStmt = $db->prepare($expenseActivityQuery);
    $expenseActivityStmt->bindParam(':year', $year);
    $expenseActivityStmt->bindParam(':month', $month);
    $expenseActivityStmt->execute();
    $expenseActivity = $expenseActivityStmt->fetch(PDO::FETCH_ASSOC);
    
    // 4. Calculate key metrics
    $netCashFlow = $cashFlow['total_payments_received'] - $expenseActivity['total_expenses'];
    $collectionRate = $invoiceActivity['total_invoiced'] > 0 
        ? ($invoiceActivity['cumulative_paid_on_invoices'] / $invoiceActivity['total_invoiced']) * 100 
        : 0;
    $avgInvoiceValue = $invoiceActivity['invoices_issued'] > 0 
        ? $invoiceActivity['total_invoiced'] / $invoiceActivity['invoices_issued'] 
        : 0;
    
    // 5. Get detailed invoice list for this month
    $invoicesQuery = "
        SELECT i.*, c.company_name, pr.project_name
        FROM invoices i
        LEFT JOIN clients c ON i.client_id = c.id
        LEFT JOIN projects pr ON i.project_id = pr.id
        WHERE YEAR(i.invoice_date) = :year AND MONTH(i.invoice_date) = :month
        ORDER BY i.invoice_date DESC, i.created_at DESC
    ";
    
    $invoicesStmt = $db->prepare($invoicesQuery);
    $invoicesStmt->bindParam(':year', $year);
    $invoicesStmt->bindParam(':month', $month);
    $invoicesStmt->execute();
    $invoices = $invoicesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 6. Get all payments received this month (regardless of invoice date)
    $paymentsQuery = "
        SELECT p.*, i.invoice_number, i.invoice_date, c.company_name
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.id
        JOIN clients c ON i.client_id = c.id
        WHERE YEAR(p.payment_date) = :year AND MONTH(p.payment_date) = :month
        ORDER BY p.payment_date DESC, p.created_at DESC
    ";
    
    $paymentsStmt = $db->prepare($paymentsQuery);
    $paymentsStmt->bindParam(':year', $year);
    $paymentsStmt->bindParam(':month', $month);
    $paymentsStmt->execute();
    $payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 7. Get expenses for the month
    $expensesQuery = "
        SELECT *
        FROM expenses
        WHERE YEAR(expense_date) = :year AND MONTH(expense_date) = :month
        ORDER BY expense_date DESC, created_at DESC
    ";
    
    $expensesStmt = $db->prepare($expensesQuery);
    $expensesStmt->bindParam(':year', $year);
    $expensesStmt->bindParam(':month', $month);
    $expensesStmt->execute();
    $expenses = $expensesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 8. Get top clients by invoicing activity this month
    $topClientsQuery = "
        SELECT 
            c.company_name,
            c.contact_person,
            COUNT(DISTINCT i.id) as invoice_count,
            COALESCE(SUM(i.total_amount), 0) as total_invoiced,
            COALESCE(SUM(i.paid_amount), 0) as total_paid,
            COALESCE(SUM(i.balance_amount), 0) as outstanding_balance
        FROM invoices i
        JOIN clients c ON i.client_id = c.id
        WHERE YEAR(i.invoice_date) = :year AND MONTH(i.invoice_date) = :month
        GROUP BY c.id, c.company_name, c.contact_person
        ORDER BY total_invoiced DESC
        LIMIT 10
    ";
    
    $topClientsStmt = $db->prepare($topClientsQuery);
    $topClientsStmt->bindParam(':year', $year);
    $topClientsStmt->bindParam(':month', $month);
    $topClientsStmt->execute();
    $topClients = $topClientsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 9. Get expense breakdown by category
    $expenseByCategoryQuery = "
        SELECT 
            category,
            COUNT(*) as expense_count,
            COALESCE(SUM(amount), 0) as total_amount,
            COALESCE(AVG(amount), 0) as avg_amount
        FROM expenses
        WHERE YEAR(expense_date) = :year AND MONTH(expense_date) = :month
        GROUP BY category
        ORDER BY total_amount DESC
    ";
    
    $expenseByCategoryStmt = $db->prepare($expenseByCategoryQuery);
    $expenseByCategoryStmt->bindParam(':year', $year);
    $expenseByCategoryStmt->bindParam(':month', $month);
    $expenseByCategoryStmt->execute();
    $expensesByCategory = $expenseByCategoryStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    Helper::setMessage('Error loading detailed report: ' . $e->getMessage(), 'error');
    Helper::redirect('modules/reports/');
}

include '../../includes/header.php';
?>

<!-- Breadcrumb Navigation -->
<nav class="mb-8">
    <div class="flex items-center space-x-2 text-sm">
        <a href="<?php echo Helper::baseUrl('modules/reports/'); ?>" 
           class="text-gray-500 hover:text-gray-700 transition-colors font-medium">
            Reports
        </a>
        <svg class="w-4 h-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5l7 7-7 7"></path>
        </svg>
        <span class="text-gray-900 font-semibold"><?php echo htmlspecialchars($monthName); ?> Detailed Report</span>
    </div>
</nav>

<!-- Page Header -->
<div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-8">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">Detailed Financial Report</h1>
        <p class="text-gray-600 mt-2"><?php echo htmlspecialchars($monthName); ?> - Complete business activity breakdown</p>
    </div>
    
    <!-- Actions -->
    <div class="flex items-center gap-3">
        <button onclick="window.print()" 
                class="inline-flex items-center px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v2a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
            </svg>
            Print Report
        </button>
        <a href="<?php echo Helper::baseUrl('modules/reports/'); ?>" 
           class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Back to Reports
        </a>
    </div>
</div>

<!-- Key Performance Indicators -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <!-- Total Invoiced -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <div class="flex items-center">
            <div class="bg-blue-100 rounded-lg p-3 mr-4">
                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
            </div>
            <div>
                <p class="text-sm text-gray-600">Invoiced This Month</p>
                <p class="text-2xl font-bold text-gray-900">LKR <?php echo number_format($invoiceActivity['total_invoiced'], 2); ?></p>
                <p class="text-xs text-gray-500"><?php echo $invoiceActivity['invoices_issued']; ?> invoices issued</p>
            </div>
        </div>
    </div>
    
    <!-- Cash Received -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <div class="flex items-center">
            <div class="bg-green-100 rounded-lg p-3 mr-4">
                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                </svg>
            </div>
            <div>
                <p class="text-sm text-gray-600">Cash Received</p>
                <p class="text-2xl font-bold text-gray-900">LKR <?php echo number_format($cashFlow['total_payments_received'], 2); ?></p>
                <p class="text-xs text-gray-500"><?php echo $cashFlow['payments_received_count']; ?> payments</p>
            </div>
        </div>
    </div>
    
    <!-- Expenses -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <div class="flex items-center">
            <div class="bg-red-100 rounded-lg p-3 mr-4">
                <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"></path>
                </svg>
            </div>
            <div>
                <p class="text-sm text-gray-600">Expenses</p>
                <p class="text-2xl font-bold text-gray-900">LKR <?php echo number_format($expenseActivity['total_expenses'], 2); ?></p>
                <p class="text-xs text-gray-500"><?php echo $expenseActivity['total_expense_items']; ?> expenses</p>
            </div>
        </div>
    </div>
    
    <!-- Net Cash Flow -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <div class="flex items-center">
            <div class="<?php echo $netCashFlow >= 0 ? 'bg-green-100' : 'bg-red-100'; ?> rounded-lg p-3 mr-4">
                <svg class="w-6 h-6 <?php echo $netCashFlow >= 0 ? 'text-green-600' : 'text-red-600'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2-2V9a2 2 0 002-2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
            </div>
            <div>
                <p class="text-sm text-gray-600">Net Cash Flow</p>
                <p class="text-2xl font-bold <?php echo $netCashFlow >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                    LKR <?php echo number_format($netCashFlow, 2); ?>
                </p>
                <p class="text-xs text-gray-500">Cash in - Cash out</p>
            </div>
        </div>
    </div>
</div>

<!-- Important Note -->
<div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-8">
    <div class="flex">
        <div class="flex-shrink-0">
            <svg class="h-5 w-5 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
            </svg>
        </div>
        <div class="ml-3">
            <p class="text-sm text-blue-700">
                <strong>Report Explanation:</strong> 
                "Invoiced This Month" shows invoices issued in <?php echo htmlspecialchars($monthName); ?>, 
                while "Cash Received" shows actual payments received in <?php echo htmlspecialchars($monthName); ?> 
                (which may be for invoices from previous months). "Net Cash Flow" represents your actual 
                cash position change for the month.
            </p>
        </div>
    </div>
</div>

<!-- Business Metrics Grid -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
    <!-- Invoice Status Breakdown -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Invoice Status (<?php echo htmlspecialchars($monthName); ?>)</h3>
        <div class="space-y-3">
            <div class="flex justify-between items-center">
                <span class="text-sm text-gray-600">Paid</span>
                <span class="px-2 py-1 bg-green-100 text-green-800 rounded-full text-xs font-medium">
                    <?php echo $invoiceActivity['paid_invoices']; ?>
                </span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-sm text-gray-600">Partially Paid</span>
                <span class="px-2 py-1 bg-orange-100 text-orange-800 rounded-full text-xs font-medium">
                    <?php echo $invoiceActivity['partial_invoices']; ?>
                </span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-sm text-gray-600">Sent (Unpaid)</span>
                <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded-full text-xs font-medium">
                    <?php echo $invoiceActivity['sent_invoices']; ?>
                </span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-sm text-gray-600">Overdue</span>
                <span class="px-2 py-1 bg-red-100 text-red-800 rounded-full text-xs font-medium">
                    <?php echo $invoiceActivity['overdue_invoices']; ?>
                </span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-sm text-gray-600">Draft</span>
                <span class="px-2 py-1 bg-gray-100 text-gray-800 rounded-full text-xs font-medium">
                    <?php echo $invoiceActivity['draft_invoices']; ?>
                </span>
            </div>
            <div class="pt-2 border-t border-gray-100">
                <div class="flex justify-between items-center">
                    <span class="text-sm font-medium text-gray-900">Outstanding</span>
                    <span class="text-sm font-semibold text-orange-600">
                        LKR <?php echo number_format($invoiceActivity['current_outstanding'], 2); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Expense Status -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Expense Status</h3>
        <div class="space-y-3">
            <div class="flex justify-between items-center">
                <span class="text-sm text-gray-600">Paid</span>
                <span class="px-2 py-1 bg-green-100 text-green-800 rounded-full text-xs font-medium">
                    <?php echo $expenseActivity['paid_expenses']; ?>
                </span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-sm text-gray-600">Pending</span>
                <span class="px-2 py-1 bg-orange-100 text-orange-800 rounded-full text-xs font-medium">
                    <?php echo $expenseActivity['pending_expenses']; ?>
                </span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-sm text-gray-600">Overdue</span>
                <span class="px-2 py-1 bg-red-100 text-red-800 rounded-full text-xs font-medium">
                    <?php echo $expenseActivity['overdue_expenses']; ?>
                </span>
            </div>
            
            <!-- Expense Category Breakdown -->
            <?php if (!empty($expensesByCategory)): ?>
                <div class="pt-2 border-t border-gray-100">
                    <h4 class="text-sm font-medium text-gray-900 mb-2">Top Categories</h4>
                    <?php foreach (array_slice($expensesByCategory, 0, 3) as $category): ?>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-gray-600"><?php echo ucfirst(str_replace('_', ' ', $category['category'])); ?></span>
                            <span class="text-gray-900 font-medium">
                                LKR <?php echo number_format($category['total_amount'], 0); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Key Metrics -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Key Metrics</h3>
        <div class="space-y-3">
            <div class="flex justify-between items-center">
                <span class="text-sm text-gray-600">Active Projects</span>
                <span class="text-sm font-medium text-gray-900"><?php echo $invoiceActivity['projects_invoiced']; ?></span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-sm text-gray-600">Active Clients</span>
                <span class="text-sm font-medium text-gray-900"><?php echo $invoiceActivity['clients_invoiced']; ?></span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-sm text-gray-600">Avg. Invoice Value</span>
                <span class="text-sm font-medium text-gray-900">
                    LKR <?php echo number_format($avgInvoiceValue, 0); ?>
                </span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-sm text-gray-600">Collection Rate</span>
                <span class="text-sm font-medium text-gray-900">
                    <?php echo number_format($collectionRate, 1); ?>%
                </span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-sm text-gray-600">Payment Sources</span>
                <span class="text-sm font-medium text-gray-900">
                    <?php echo $cashFlow['invoices_with_payments']; ?> invoices
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Top Clients -->
<?php if (!empty($topClients)): ?>
<div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-8">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-semibold text-gray-900">Top Clients by Invoicing - <?php echo htmlspecialchars($monthName); ?></h3>
        <p class="text-sm text-gray-600 mt-1">Based on invoices issued this month</p>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Client</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Invoices</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total Invoiced</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Paid Amount</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Outstanding</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Collection %</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($topClients as $client): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div>
                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($client['company_name']); ?></div>
                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($client['contact_person']); ?></div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900">
                            <?php echo $client['invoice_count']; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-gray-900">
                            LKR <?php echo number_format($client['total_invoiced'], 2); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-green-600">
                            LKR <?php echo number_format($client['total_paid'], 2); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-orange-600">
                            LKR <?php echo number_format($client['outstanding_balance'], 2); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <span class="text-sm font-medium <?php echo $client['total_invoiced'] > 0 && ($client['total_paid'] / $client['total_invoiced']) >= 0.8 ? 'text-green-600' : 'text-orange-600'; ?>">
                                <?php echo $client['total_invoiced'] > 0 ? number_format(($client['total_paid'] / $client['total_invoiced']) * 100, 1) : '0'; ?>%
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Detailed Tables -->
<div class="space-y-8">
    <!-- Invoices Table -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">Invoices Issued - <?php echo htmlspecialchars($monthName); ?></h3>
            <p class="text-sm text-gray-600 mt-1"><?php echo count($invoices); ?> invoices issued this month</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Invoice</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Client</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Project</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Issue Date</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Due Date</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Paid</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($invoices)): ?>
                        <tr>
                            <td colspan="9" class="px-6 py-12 text-center text-gray-500">
                                <div class="flex flex-col items-center">
                                    <svg class="w-12 h-12 text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                    <h4 class="text-lg font-medium text-gray-900 mb-2">No invoices found</h4>
                                    <p class="text-gray-500">No invoices were issued in <?php echo htmlspecialchars($monthName); ?>.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($invoices as $invoice): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="font-medium text-blue-600">
                                        <a href="<?php echo Helper::baseUrl('modules/invoices/view.php?id=' . Helper::encryptId($invoice['id'])); ?>">
                                            <?php echo htmlspecialchars($invoice['invoice_number']); ?>
                                        </a>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($invoice['company_name']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($invoice['project_name'] ?? 'N/A'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                    <?php echo date('M d, Y', strtotime($invoice['invoice_date'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                    <?php echo date('M d, Y', strtotime($invoice['due_date'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-gray-900">
                                    LKR <?php echo number_format($invoice['total_amount'], 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-green-600">
                                    LKR <?php echo number_format($invoice['paid_amount'], 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-orange-600">
                                    LKR <?php echo number_format($invoice['balance_amount'], 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
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
                                    <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo $statusClass; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $invoice['status'])); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Payments Table -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">Payments Received - <?php echo htmlspecialchars($monthName); ?></h3>
            <p class="text-sm text-gray-600 mt-1"><?php echo count($payments); ?> payments received this month (may be for invoices from any period)</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Payment Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Invoice</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Invoice Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Client</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Payment Amount</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Method</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reference</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($payments)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                <div class="flex flex-col items-center">
                                    <svg class="w-12 h-12 text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v2a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                    </svg>
                                    <h4 class="text-lg font-medium text-gray-900 mb-2">No payments found</h4>
                                    <p class="text-gray-500">No payments were received in <?php echo htmlspecialchars($monthName); ?>.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($payments as $payment): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo date('M d, Y', strtotime($payment['payment_date'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <a href="<?php echo Helper::baseUrl('modules/invoices/view.php?id=' . Helper::encryptId($payment['invoice_id'])); ?>" 
                                       class="text-blue-600 hover:text-blue-800 font-medium">
                                        <?php echo htmlspecialchars($payment['invoice_number']); ?>
                                    </a>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-500">
                                    <?php echo date('M d, Y', strtotime($payment['invoice_date'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($payment['company_name']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-green-600">
                                    LKR <?php echo number_format($payment['payment_amount'], 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <span class="px-2 py-1 bg-gray-100 text-gray-800 rounded-full text-xs font-medium">
                                        <?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($payment['payment_reference'] ?? 'N/A'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Expenses Table -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">Expenses - <?php echo htmlspecialchars($monthName); ?></h3>
            <p class="text-sm text-gray-600 mt-1"><?php echo count($expenses); ?> expenses recorded this month</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Expense</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Payment Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vendor</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($expenses)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                                <div class="flex flex-col items-center">
                                    <svg class="w-12 h-12 text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"></path>
                                    </svg>
                                    <h4 class="text-lg font-medium text-gray-900 mb-2">No expenses found</h4>
                                    <p class="text-gray-500">No expenses were recorded in <?php echo htmlspecialchars($monthName); ?>.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($expenses as $expense): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo date('M d, Y', strtotime($expense['expense_date'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="font-medium text-gray-900"><?php echo htmlspecialchars($expense['expense_name']); ?></div>
                                    <?php if (!empty($expense['description'])): ?>
                                        <div class="text-sm text-gray-500 truncate max-w-xs">
                                            <?php echo htmlspecialchars(substr($expense['description'], 0, 50)) . (strlen($expense['description']) > 50 ? '...' : ''); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 bg-gray-100 text-gray-800 rounded-full text-xs font-medium">
                                        <?php echo ucfirst(str_replace('_', ' ', $expense['category'])); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-red-600">
                                    LKR <?php echo number_format($expense['amount'], 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <?php
                                    $statusClasses = [
                                        'paid' => 'bg-green-100 text-green-800',
                                        'pending' => 'bg-orange-100 text-orange-800',
                                        'overdue' => 'bg-red-100 text-red-800'
                                    ];
                                    $statusClass = $statusClasses[$expense['payment_status']] ?? 'bg-gray-100 text-gray-800';
                                    ?>
                                    <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo $statusClass; ?>">
                                        <?php echo ucfirst($expense['payment_status']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($expense['vendor_name'] ?? 'N/A'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Print Styles -->
<style>
@media print {
    .no-print {
        display: none !important;
    }
    
    body {
        background: white !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    .bg-gradient-to-r {
        background: #f3f4f6 !important;
        color: #111827 !important;
    }
    
    .bg-white {
        background: white !important;
        border: 1px solid #e5e7eb !important;
        box-shadow: none !important;
    }
    
    .text-white {
        color: #111827 !important;
    }
    
    table {
        page-break-inside: auto;
    }
    
    tr {
        page-break-inside: avoid;
        page-break-after: auto;
    }
    
    thead {
        display: table-header-group;
    }
    
    .rounded-xl,
    .rounded-lg {
        border-radius: 0 !important;
    }
    
    .shadow-sm {
        box-shadow: none !important;
    }
    
    /* Hide navigation and unnecessary elements */
    nav,
    .no-print,
    button {
        display: none !important;
    }
    
    /* Adjust spacing for print */
    .mb-8 {
        margin-bottom: 1rem !important;
    }
    
    .p-6 {
        padding: 1rem !important;
    }
    
    /* Ensure good page breaks */
    .grid {
        display: block !important;
    }
    
    .grid > div {
        display: block !important;
        margin-bottom: 1rem !important;
        page-break-inside: avoid;
    }
}

@page {
    margin: 1in;
    size: A4;
}
</style>

<script>
// Print functionality
function printReport() {
    window.print();
}

// Add keyboard shortcut for printing
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
        e.preventDefault();
        printReport();
    }
});

// Format currency values consistently
function formatCurrency(amount) {
    return 'LKR ' + parseFloat(amount).toLocaleString('en-LK', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

// Add table sorting functionality
function sortTable(table, column, direction = 'asc') {
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    
    // Skip if table is empty or only has empty state row
    if (rows.length === 0 || (rows.length === 1 && rows[0].cells.length > 6)) {
        return;
    }
    
    rows.sort((a, b) => {
        const aValue = a.cells[column].textContent.trim();
        const bValue = b.cells[column].textContent.trim();
        
        // Handle numeric values (currency)
        if (aValue.includes('LKR') && bValue.includes('LKR')) {
            const aNum = parseFloat(aValue.replace(/[^0-9.-]+/g, ''));
            const bNum = parseFloat(bValue.replace(/[^0-9.-]+/g, ''));
            return direction === 'asc' ? aNum - bNum : bNum - aNum;
        }
        
        // Handle date values
        if (aValue.match(/\w+ \d{1,2}, \d{4}/)) {
            const aDate = new Date(aValue);
            const bDate = new Date(bValue);
            return direction === 'asc' ? aDate - bDate : bDate - aDate;
        }
        
        // Handle text values
        return direction === 'asc' ? 
            aValue.localeCompare(bValue) : 
            bValue.localeCompare(aValue);
    });
    
    // Re-append sorted rows
    rows.forEach(row => tbody.appendChild(row));
}

// Initialize tooltips and interactive elements
document.addEventListener('DOMContentLoaded', function() {
    // Add hover effects to metric cards
    const metricCards = document.querySelectorAll('.bg-white.rounded-xl');
    metricCards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
            this.style.transition = 'transform 0.2s ease';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
    
    // Add click-to-copy functionality for amounts
    const amountElements = document.querySelectorAll('[class*="text-green-600"], [class*="text-red-600"], [class*="text-orange-600"]');
    amountElements.forEach(element => {
        if (element.textContent.includes('LKR')) {
            element.style.cursor = 'pointer';
            element.title = 'Click to copy amount';
            
            element.addEventListener('click', function() {
                const amount = this.textContent.replace('LKR ', '').trim();
                navigator.clipboard.writeText(amount).then(() => {
                    // Show brief feedback
                    const originalText = this.textContent;
                    this.textContent = 'Copied!';
                    setTimeout(() => {
                        this.textContent = originalText;
                    }, 1000);
                });
            });
        }
    });
    
    // Add sortable headers to tables
    const tables = document.querySelectorAll('table');
    tables.forEach(table => {
        const headers = table.querySelectorAll('th');
        headers.forEach((header, index) => {
            if (header.textContent.trim() && !header.textContent.includes('Status') && !header.textContent.includes('Method')) {
                header.style.cursor = 'pointer';
                header.title = 'Click to sort';
                
                let sortDirection = 'asc';
                header.addEventListener('click', function() {
                    sortTable(table, index, sortDirection);
                    sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
                    
                    // Visual feedback
                    headers.forEach(h => h.classList.remove('bg-blue-100'));
                    this.classList.add('bg-blue-100');
                });
            }
        });
    });
});

// Export functionality placeholder
function exportToCSV(tableId, filename) {
    // This could be implemented to export table data to CSV
    console.log(`Export ${tableId} to CSV as ${filename} - to be implemented`);
}

// Quick summary calculator
function calculateQuickStats() {
    const invoicedAmount = <?php echo $invoiceActivity['total_invoiced']; ?>;
    const paymentsReceived = <?php echo $cashFlow['total_payments_received']; ?>;
    const expenses = <?php echo $expenseActivity['total_expenses']; ?>;
    
    console.log('Quick Financial Summary:');
    console.log(`Invoiced this month: LKR ${invoicedAmount.toLocaleString()}`);
    console.log(`Payments received: LKR ${paymentsReceived.toLocaleString()}`);
    console.log(`Expenses: LKR ${expenses.toLocaleString()}`);
    console.log(`Net cash flow: LKR ${(paymentsReceived - expenses).toLocaleString()}`);
    console.log(`Collection efficiency: ${invoicedAmount > 0 ? ((paymentsReceived / invoicedAmount) * 100).toFixed(1) : 0}%`);
}

// Call on page load for debugging
calculateQuickStats();
</script>

<?php include '../../includes/footer.php'; ?>