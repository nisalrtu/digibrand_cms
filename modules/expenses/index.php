<?php
// modules/expenses/index.php
session_start();

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

$pageTitle = 'Expenses - Invoice Manager';

// Initialize variables
$expenses = [];
$upcomingPayments = [];
$stats = [];
$filters = [
    'category' => $_GET['category'] ?? '',
    'payment_status' => $_GET['payment_status'] ?? '',
    'is_recurring' => $_GET['is_recurring'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? ''
];

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// Get database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Build WHERE conditions for filtering
    $whereConditions = ['1=1']; // Always true condition
    $params = [];

    if (!empty($filters['category'])) {
        $whereConditions[] = 'category = :category';
        $params['category'] = $filters['category'];
    }

    if (!empty($filters['payment_status'])) {
        $whereConditions[] = 'payment_status = :payment_status';
        $params['payment_status'] = $filters['payment_status'];
    }

    if (!empty($filters['is_recurring'])) {
        $whereConditions[] = 'is_recurring = :is_recurring';
        $params['is_recurring'] = $filters['is_recurring'] === '1' ? 1 : 0;
    }

    if (!empty($filters['date_from'])) {
        $whereConditions[] = 'expense_date >= :date_from';
        $params['date_from'] = $filters['date_from'];
    }

    if (!empty($filters['date_to'])) {
        $whereConditions[] = 'expense_date <= :date_to';
        $params['date_to'] = $filters['date_to'];
    }

    $whereClause = implode(' AND ', $whereConditions);

    // Get total count for pagination
    $countQuery = "SELECT COUNT(*) as total FROM expenses WHERE {$whereClause}";
    $countStmt = $db->prepare($countQuery);
    $countStmt->execute($params);
    $totalExpenses = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalExpenses / $limit);

    // Get expenses with pagination
    $expenseQuery = "
        SELECT * FROM expenses 
        WHERE {$whereClause}
        ORDER BY expense_date DESC, created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ";
    $expenseStmt = $db->prepare($expenseQuery);
    $expenseStmt->execute($params);
    $expenses = $expenseStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get upcoming recurring payments (next 30 days) - including paid recurring expenses with future due dates
    $upcomingQuery = "
        SELECT * FROM expenses 
        WHERE is_recurring = 1 
        AND next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        AND next_due_date IS NOT NULL
        ORDER BY next_due_date ASC
        LIMIT 10
    ";
    $upcomingStmt = $db->prepare($upcomingQuery);
    $upcomingStmt->execute();
    $upcomingPayments = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get current month expenses total
    $currentMonth = date('Y-m');
    $monthQuery = "
        SELECT SUM(amount) as monthly_total
        FROM expenses 
        WHERE DATE_FORMAT(expense_date, '%Y-%m') = :current_month
    ";
    $monthStmt = $db->prepare($monthQuery);
    $monthStmt->bindParam(':current_month', $currentMonth);
    $monthStmt->execute();
    $monthlyTotal = $monthStmt->fetch(PDO::FETCH_ASSOC)['monthly_total'] ?? 0;

} catch (Exception $e) {
    Helper::setMessage('Error loading expenses: ' . $e->getMessage(), 'error');
    $expenses = [];
    $upcomingPayments = [];
    $monthlyTotal = 0;
    $totalPages = 1;
}

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Expenses</h1>
        <p class="text-gray-600 mt-1">Track and manage company expenses</p>
    </div>
    <div class="flex space-x-3 mt-4 sm:mt-0">
        <a href="<?php echo Helper::baseUrl('modules/expenses/add.php'); ?>" 
           class="inline-flex items-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
            </svg>
            Add Expense
        </a>
    </div>
</div>

<!-- Current Month Total -->
<div class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
    <div class="flex items-center">
        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
        </div>
        <div class="ml-4">
            <p class="text-sm font-medium text-gray-600"><?php echo date('F Y'); ?> Expenses</p>
            <p class="text-2xl font-bold text-gray-900">Rs. <?php echo number_format($monthlyTotal, 2); ?></p>
        </div>
    </div>
</div>


<!-- Minimal Upcoming Payments Notification -->
<?php if (!empty($upcomingPayments)): ?>
<div class="bg-orange-50 border border-orange-200 rounded-lg p-4 mb-6">
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-medium text-orange-800">
            Upcoming Payments (<?php echo count($upcomingPayments); ?>)
        </h3>
        <span class="text-xs text-orange-600">Next 30 days</span>
    </div>
    
    <div class="space-y-2">
        <?php foreach ($upcomingPayments as $payment): ?>
            <?php 
            $dueDate = new DateTime($payment['next_due_date']);
            $today = new DateTime();
            $daysUntilDue = $today->diff($dueDate)->days;
            $isOverdue = $dueDate < $today;
            $isDueSoon = $daysUntilDue <= 7;
            ?>
            <div class="flex items-center justify-between p-2 bg-white rounded border border-orange-100">
                <div class="flex-1">
                    <span class="text-sm font-medium text-gray-900">
                        <?php echo htmlspecialchars($payment['expense_name']); ?>
                    </span>
                </div>
                
                <div class="flex items-center space-x-4 text-sm">
                    <span class="<?php echo $payment['payment_status'] === 'paid' ? 'text-green-600' : ($isOverdue ? 'text-red-600' : ($isDueSoon ? 'text-orange-600' : 'text-gray-600')); ?>">
                        <?php echo date('M j', strtotime($payment['next_due_date'])); ?>
                    </span>
                    <span class="font-medium text-gray-900 min-w-[80px] text-right">
                        Rs. <?php echo number_format($payment['amount'], 0); ?>
                    </span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
    <form method="GET" action="" class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
            <!-- Category Filter -->
            <div>
                <label for="category" class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                <select name="category" id="category" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Categories</option>
                    <option value="software" <?php echo $filters['category'] === 'software' ? 'selected' : ''; ?>>Software</option>
                    <option value="marketing" <?php echo $filters['category'] === 'marketing' ? 'selected' : ''; ?>>Marketing</option>
                    <option value="office" <?php echo $filters['category'] === 'office' ? 'selected' : ''; ?>>Office</option>
                    <option value="utilities" <?php echo $filters['category'] === 'utilities' ? 'selected' : ''; ?>>Utilities</option>
                    <option value="travel" <?php echo $filters['category'] === 'travel' ? 'selected' : ''; ?>>Travel</option>
                    <option value="equipment" <?php echo $filters['category'] === 'equipment' ? 'selected' : ''; ?>>Equipment</option>
                    <option value="subscription" <?php echo $filters['category'] === 'subscription' ? 'selected' : ''; ?>>Subscription</option>
                    <option value="other" <?php echo $filters['category'] === 'other' ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>

            <!-- Payment Status Filter -->
            <div>
                <label for="payment_status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select name="payment_status" id="payment_status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Status</option>
                    <option value="paid" <?php echo $filters['payment_status'] === 'paid' ? 'selected' : ''; ?>>Paid</option>
                    <option value="pending" <?php echo $filters['payment_status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="overdue" <?php echo $filters['payment_status'] === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                </select>
            </div>

            <!-- Recurring Filter -->
            <div>
                <label for="is_recurring" class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                <select name="is_recurring" id="is_recurring" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Types</option>
                    <option value="1" <?php echo $filters['is_recurring'] === '1' ? 'selected' : ''; ?>>Recurring</option>
                    <option value="0" <?php echo $filters['is_recurring'] === '0' ? 'selected' : ''; ?>>One-time</option>
                </select>
            </div>

            <!-- Date From -->
            <div>
                <label for="date_from" class="block text-sm font-medium text-gray-700 mb-1">From Date</label>
                <input type="date" name="date_from" id="date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>

            <!-- Date To -->
            <div>
                <label for="date_to" class="block text-sm font-medium text-gray-700 mb-1">To Date</label>
                <input type="date" name="date_to" id="date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
        </div>

        <div class="flex flex-col sm:flex-row gap-3">
            <button type="submit" 
                    class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.414A1 1 0 013 6.707V4z"></path>
                </svg>
                Apply Filters
            </button>
            
            <a href="<?php echo Helper::baseUrl('modules/expenses/'); ?>" 
               class="inline-flex items-center px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                </svg>
                Clear Filters
            </a>
        </div>
    </form>
</div>

<!-- Expenses List -->
<?php if (empty($expenses)): ?>
    <!-- Empty State -->
    <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
        </div>
        <h3 class="text-lg font-medium text-gray-900 mb-2">No expenses found</h3>
        <p class="text-gray-500 mb-6">
            <?php if (!empty(array_filter($filters))): ?>
                No expenses match your current filters. Try adjusting your search criteria.
            <?php else: ?>
                Get started by adding your first expense.
            <?php endif; ?>
        </p>
        <?php if (empty(array_filter($filters))): ?>
            <a href="<?php echo Helper::baseUrl('modules/expenses/add.php'); ?>" 
               class="inline-flex items-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Add First Expense
            </a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <!-- Expenses Table -->
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <!-- Desktop Table -->
        <div class="hidden lg:block overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Expense
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Category
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Amount
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Date
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Status
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Type
                        </th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($expenses as $expense): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <!-- Expense Name -->
                            <td class="px-6 py-4">
                                <div>
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($expense['expense_name']); ?>
                                    </div>
                                    <?php if (!empty($expense['vendor_name'])): ?>
                                        <div class="text-sm text-gray-500">
                                            <?php echo htmlspecialchars($expense['vendor_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($expense['is_recurring'] && !empty($expense['next_due_date'])): ?>
                                        <div class="text-xs text-blue-600 mt-1">
                                            Next: <?php echo date('M j, Y', strtotime($expense['next_due_date'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- Category -->
                            <td class="px-6 py-4">
                                <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-800">
                                    <?php echo ucfirst(str_replace('_', ' ', $expense['category'])); ?>
                                </span>
                            </td>

                            <!-- Amount -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">
                                    Rs. <?php echo number_format($expense['amount'], 2); ?>
                                </div>
                            </td>

                            <!-- Date -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">
                                    <?php echo date('M j, Y', strtotime($expense['expense_date'])); ?>
                                </div>
                            </td>

                            <!-- Payment Status -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php
                                $statusColors = [
                                    'paid' => 'bg-green-100 text-green-800',
                                    'pending' => 'bg-yellow-100 text-yellow-800',
                                    'overdue' => 'bg-red-100 text-red-800'
                                ];
                                $statusColor = $statusColors[$expense['payment_status']] ?? 'bg-gray-100 text-gray-800';
                                ?>
                                <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full <?php echo $statusColor; ?>">
                                    <?php echo ucfirst($expense['payment_status']); ?>
                                </span>
                            </td>

                            <!-- Type -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if ($expense['is_recurring']): ?>
                                    <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full bg-purple-100 text-purple-800">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                        </svg>
                                        <?php echo ucfirst($expense['recurring_type']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-800">
                                        One-time
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Actions -->
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex items-center justify-end space-x-2">
                                    <a href="<?php echo Helper::baseUrl('modules/expenses/view.php?id=' . Helper::encryptId($expense['id'])); ?>" 
                                       class="inline-flex items-center px-3 py-1.5 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                        </svg>
                                        View
                                    </a>
                                    <a href="<?php echo Helper::baseUrl('modules/expenses/edit.php?id=' . Helper::encryptId($expense['id'])); ?>" 
                                       class="inline-flex items-center px-3 py-1.5 bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200 transition-colors">
                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                        </svg>
                                        Edit
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Cards -->
        <div class="lg:hidden">
            <div class="divide-y divide-gray-200">
                <?php foreach ($expenses as $expense): ?>
                    <div class="p-4 hover:bg-gray-50 transition-colors">
                        <!-- Header -->
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex-1 min-w-0">
                                <h3 class="text-sm font-medium text-gray-900 truncate">
                                    <?php echo htmlspecialchars($expense['expense_name']); ?>
                                </h3>
                                <?php if (!empty($expense['vendor_name'])): ?>
                                    <p class="text-sm text-gray-500 truncate">
                                        <?php echo htmlspecialchars($expense['vendor_name']); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <div class="text-right ml-3">
                                <p class="text-sm font-medium text-gray-900">
                                    Rs. <?php echo number_format($expense['amount'], 2); ?>
                                </p>
                                <p class="text-xs text-gray-500">
                                    <?php echo date('M j, Y', strtotime($expense['expense_date'])); ?>
                                </p>
                            </div>
                        </div>

                        <!-- Details -->
                        <div class="grid grid-cols-2 gap-3 mb-3">
                            <div>
                                <p class="text-xs text-gray-500 mb-1">Category</p>
                                <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-800">
                                    <?php echo ucfirst(str_replace('_', ' ', $expense['category'])); ?>
                                </span>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 mb-1">Status</p>
                                <?php
                                $statusColors = [
                                    'paid' => 'bg-green-100 text-green-800',
                                    'pending' => 'bg-yellow-100 text-yellow-800',
                                    'overdue' => 'bg-red-100 text-red-800'
                                ];
                                $statusColor = $statusColors[$expense['payment_status']] ?? 'bg-gray-100 text-gray-800';
                                ?>
                                <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full <?php echo $statusColor; ?>">
                                    <?php echo ucfirst($expense['payment_status']); ?>
                                </span>
                            </div>
                        </div>

                        <!-- Type and Next Due -->
                        <div class="flex items-center justify-between mb-3">
                            <div>
                                <?php if ($expense['is_recurring']): ?>
                                    <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full bg-purple-100 text-purple-800">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                        </svg>
                                        <?php echo ucfirst($expense['recurring_type']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-800">
                                        One-time
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if ($expense['is_recurring'] && !empty($expense['next_due_date'])): ?>
                                <div class="text-xs text-blue-600">
                                    Next: <?php echo date('M j, Y', strtotime($expense['next_due_date'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Actions -->
                        <div class="flex space-x-2">
                            <a href="<?php echo Helper::baseUrl('modules/expenses/view.php?id=' . Helper::encryptId($expense['id'])); ?>" 
                               class="flex-1 inline-flex items-center justify-center px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                </svg>
                                View
                            </a>
                            <a href="<?php echo Helper::baseUrl('modules/expenses/edit.php?id=' . Helper::encryptId($expense['id'])); ?>" 
                               class="flex-1 inline-flex items-center justify-center px-3 py-2 bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200 transition-colors">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                </svg>
                                Edit
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="bg-white rounded-xl border border-gray-200 p-6 mt-6">
            <div class="flex items-center justify-between">
                <div class="text-sm text-gray-600">
                    Showing <?php echo (($page - 1) * $limit) + 1; ?> to <?php echo min($page * $limit, $totalExpenses); ?> of <?php echo $totalExpenses; ?> expenses
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

<?php include '../../includes/footer.php'; ?>