<?php
// modules/invoices/view.php
session_start();

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

// Get and validate invoice ID
$invoiceId = Helper::decryptId($_GET['id'] ?? '');
if (!$invoiceId) {
    Helper::setMessage('Invalid invoice ID.', 'error');
    Helper::redirect('modules/invoices/');
}

$pageTitle = 'Invoice Details - Invoice Manager';

// Get database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Get invoice details with client and project information
    $invoiceQuery = "
        SELECT i.*, 
               c.company_name, c.contact_person, c.mobile_number, c.address, c.city,
               p.project_name, p.project_type,
               u.username as created_by_name
        FROM invoices i 
        LEFT JOIN clients c ON i.client_id = c.id 
        LEFT JOIN projects p ON i.project_id = p.id
        LEFT JOIN users u ON i.created_by = u.id
        WHERE i.id = :invoice_id
    ";
    $invoiceStmt = $db->prepare($invoiceQuery);
    $invoiceStmt->bindParam(':invoice_id', $invoiceId);
    $invoiceStmt->execute();
    
    $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        Helper::setMessage('Invoice not found.', 'error');
        Helper::redirect('modules/invoices/');
    }
    
    // Get invoice items if they exist
    $invoiceItems = [];
    try {
        $itemsQuery = "
            SELECT * FROM invoice_items 
            WHERE invoice_id = :invoice_id 
            ORDER BY id ASC
        ";
        $itemsStmt = $db->prepare($itemsQuery);
        $itemsStmt->bindParam(':invoice_id', $invoiceId);
        $itemsStmt->execute();
        $invoiceItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // invoice_items table might not exist yet
        $invoiceItems = [];
    }
    
    // Get related payments
    $paymentsQuery = "
        SELECT * FROM payments 
        WHERE invoice_id = :invoice_id 
        ORDER BY payment_date DESC, created_at DESC
    ";
    $paymentsStmt = $db->prepare($paymentsQuery);
    $paymentsStmt->bindParam(':invoice_id', $invoiceId);
    $paymentsStmt->execute();
    $relatedPayments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    Helper::setMessage('Error loading invoice details: ' . $e->getMessage(), 'error');
    Helper::redirect('modules/invoices/');
}

include '../../includes/header.php';
?>

<!-- Breadcrumb -->
<nav class="mb-6">
    <div class="flex items-center space-x-2 text-sm text-gray-600">
        <a href="<?php echo Helper::baseUrl('modules/invoices/'); ?>" class="hover:text-gray-900 transition-colors">
            Invoices
        </a>
        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
        </svg>
        <?php if ($invoice['project_id']): ?>
            <a href="<?php echo Helper::baseUrl('modules/projects/view.php?id=' . Helper::encryptId($invoice['project_id'])); ?>" 
               class="hover:text-gray-900 transition-colors">
                <?php echo htmlspecialchars($invoice['project_name']); ?>
            </a>
            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
        <?php endif; ?>
        <a href="<?php echo Helper::baseUrl('modules/clients/view.php?id=' . Helper::encryptId($invoice['client_id'])); ?>" 
           class="hover:text-gray-900 transition-colors">
            <?php echo htmlspecialchars($invoice['company_name']); ?>
        </a>
        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
        </svg>
        <span class="text-gray-900 font-medium"><?php echo htmlspecialchars($invoice['invoice_number']); ?></span>
    </div>
</nav>

<!-- Invoice Header -->
<div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-6">
        <!-- Left Side - Invoice Info -->
        <div class="flex-1 min-w-0">
            <!-- Back to Project Button (minimal) -->
            <?php if ($invoice['project_id']): ?>
                <div class="mb-3">
                    <a href="<?php echo Helper::baseUrl('modules/projects/view.php?id=' . Helper::encryptId($invoice['project_id'])); ?>" 
                       class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-200 rounded-lg text-xs text-gray-600 hover:text-gray-900 hover:border-gray-300 transition-colors">
                        <svg class="w-3 h-3 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                        </svg>
                        Back to Project
                    </a>
                </div>
            <?php endif; ?>
            
            <!-- Invoice Number and Status -->
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-6">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-2">
                        Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?>
                    </h1>
                    <?php if ($invoice['project_name']): ?>
                        <p class="text-lg text-gray-600">
                            Project: <span class="font-medium"><?php echo htmlspecialchars($invoice['project_name']); ?></span>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="flex-shrink-0">
                    <?php 
                    // Enhanced status badge
                    $status = $invoice['status'];
                    $statusClasses = [
                        'draft' => 'bg-gray-100 text-gray-800 border-gray-200',
                        'sent' => 'bg-blue-100 text-blue-800 border-blue-200',
                        'paid' => 'bg-green-100 text-green-800 border-green-200',
                        'partially_paid' => 'bg-yellow-100 text-yellow-800 border-yellow-200',
                        'overdue' => 'bg-red-100 text-red-800 border-red-200'
                    ];
                    $badgeClass = $statusClasses[$status] ?? 'bg-gray-100 text-gray-800 border-gray-200';
                    
                    $statusIcons = [
                        'draft' => '<svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4 2a2 2 0 00-2 2v11a2 2 0 002 2h12a2 2 0 002-2V4a2 2 0 00-2-2H4zm0 2h12v11H4V4z" clip-rule="evenodd"></path></svg>',
                        'sent' => '<svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"></path><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"></path></svg>',
                        'paid' => '<svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg>',
                        'partially_paid' => '<svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path></svg>',
                        'overdue' => '<svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>'
                    ];
                    $statusIcon = $statusIcons[$status] ?? '';
                    ?>
                    <span class="inline-flex items-center px-3 py-1.5 rounded-lg text-sm font-semibold border <?php echo $badgeClass; ?> shadow-sm">
                        <?php echo $statusIcon; ?>
                        <?php echo ucwords(str_replace('_', ' ', $status)); ?>
                    </span>
                </div>
            </div>

            <!-- Invoice Details Grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div>
                    <h3 class="text-sm font-medium text-gray-900 mb-2">Invoice Date</h3>
                    <p class="text-gray-600"><?php echo Helper::formatDate($invoice['invoice_date'], 'F j, Y'); ?></p>
                </div>
                <div>
                    <h3 class="text-sm font-medium text-gray-900 mb-2">Due Date</h3>
                    <p class="text-gray-600 <?php echo (strtotime($invoice['due_date']) < time() && $invoice['status'] !== 'paid') ? 'text-red-600 font-medium' : ''; ?>">
                        <?php echo Helper::formatDate($invoice['due_date'], 'F j, Y'); ?>
                        <?php if (strtotime($invoice['due_date']) < time() && $invoice['status'] !== 'paid'): ?>
                            <span class="text-xs text-red-500 block">Overdue</span>
                        <?php endif; ?>
                    </p>
                </div>
                <div>
                    <h3 class="text-sm font-medium text-gray-900 mb-2">Created</h3>
                    <p class="text-gray-600">
                        <?php echo Helper::formatDate($invoice['created_at'], 'M j, Y'); ?>
                        <?php if ($invoice['created_by_name']): ?>
                            <span class="text-xs text-gray-500 block">by <?php echo htmlspecialchars($invoice['created_by_name']); ?></span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <!-- Client Information -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <h3 class="font-medium text-blue-900 mb-3">Bill To:</h3>
                <div class="text-sm text-blue-800">
                    <p class="font-semibold text-base"><?php echo htmlspecialchars($invoice['company_name']); ?></p>
                    <p class="mt-1"><?php echo htmlspecialchars($invoice['contact_person']); ?></p>
                    <?php if (!empty($invoice['address'])): ?>
                        <p class="mt-1"><?php echo htmlspecialchars($invoice['address']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($invoice['city'])): ?>
                        <p><?php echo htmlspecialchars($invoice['city']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($invoice['mobile_number'])): ?>
                        <p class="mt-2">Tel: <?php echo htmlspecialchars($invoice['mobile_number']); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Side - Amount Summary -->
        <div class="lg:w-80 bg-gray-50 rounded-lg p-6">
            <h3 class="font-semibold text-gray-900 mb-4">Payment Summary</h3>
            <div class="space-y-3">
                <div class="flex justify-between text-sm">
                    <span class="text-gray-600">Subtotal:</span>
                    <span class="font-medium"><?php echo Helper::formatCurrency($invoice['subtotal']); ?></span>
                </div>
                <?php if ($invoice['tax_rate'] > 0): ?>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Tax (<?php echo number_format($invoice['tax_rate'], 2); ?>%):</span>
                        <span class="font-medium"><?php echo Helper::formatCurrency($invoice['tax_amount']); ?></span>
                    </div>
                <?php endif; ?>
                <div class="flex justify-between text-lg font-semibold border-t pt-3">
                    <span>Total Amount:</span>
                    <span class="text-blue-600"><?php echo Helper::formatCurrency($invoice['total_amount']); ?></span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-600">Paid Amount:</span>
                    <span class="font-medium text-green-600"><?php echo Helper::formatCurrency($invoice['paid_amount']); ?></span>
                </div>
                <?php if ($invoice['balance_amount'] > 0): ?>
                    <div class="flex justify-between text-lg font-semibold">
                        <span class="text-red-600">Balance Due:</span>
                        <span class="text-red-600"><?php echo Helper::formatCurrency($invoice['balance_amount']); ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Payment Progress Bar -->
            <?php if ($invoice['total_amount'] > 0): ?>
                <div class="mt-4">
                    <div class="flex justify-between text-xs text-gray-600 mb-1">
                        <span>Payment Progress</span>
                        <span><?php echo round(($invoice['paid_amount'] / $invoice['total_amount']) * 100); ?>%</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="bg-green-500 h-2 rounded-full transition-all duration-300" 
                             style="width: <?php echo min(($invoice['paid_amount'] / $invoice['total_amount']) * 100, 100); ?>%"></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="mt-6 pt-6 border-t border-gray-200">
        <!-- Desktop Actions -->
        <div class="hidden sm:flex flex-wrap gap-3">
            <a href="<?php echo Helper::baseUrl('modules/invoices/edit.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
               class="inline-flex items-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                </svg>
                Edit Invoice
            </a>
            <?php if ($invoice['balance_amount'] > 0): ?>
                <a href="<?php echo Helper::baseUrl('modules/payments/create.php?invoice_id=' . Helper::encryptId($invoice['id'])); ?>" 
                   class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v2a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                    Record Payment
                </a>
            <?php endif; ?>
            <button onclick="printInvoice()" 
                    class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                </svg>
                Print Invoice
            </button>
            <button onclick="emailInvoice()" 
                    class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 7.89a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                </svg>
                Email Invoice
            </button>
            <button onclick="downloadPDF()" 
                    class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                Download PDF
            </button>
        </div>

        <!-- Mobile Actions -->
        <div class="sm:hidden space-y-3">
            <a href="<?php echo Helper::baseUrl('modules/invoices/edit.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
               class="flex items-center justify-center px-4 py-3 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors text-sm font-medium w-full">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                </svg>
                Edit Invoice
            </a>
            <?php if ($invoice['balance_amount'] > 0): ?>
                <a href="<?php echo Helper::baseUrl('modules/payments/create.php?invoice_id=' . Helper::encryptId($invoice['id'])); ?>" 
                   class="flex items-center justify-center px-4 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors text-sm font-medium w-full">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v2a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                    Record Payment
                </a>
            <?php endif; ?>
            <div class="grid grid-cols-2 gap-3">
                <button onclick="printInvoice()" 
                        class="flex items-center justify-center px-4 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                    </svg>
                    Print
                </button>
                <button onclick="downloadPDF()" 
                        class="flex items-center justify-center px-4 py-3 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors text-sm font-medium">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    PDF
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Invoice Items -->
<div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">Invoice Items</h2>
    
    <?php if (!empty($invoiceItems)): ?>
        <!-- Desktop Table View -->
        <div class="hidden md:block overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr class="border-b border-gray-200">
                        <th class="text-left py-3 px-4 text-sm font-medium text-gray-700">Description</th>
                        <th class="text-center py-3 px-4 text-sm font-medium text-gray-700 w-24">Qty</th>
                        <th class="text-right py-3 px-4 text-sm font-medium text-gray-700 w-32">Unit Price</th>
                        <th class="text-right py-3 px-4 text-sm font-medium text-gray-700 w-32">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoiceItems as $item): ?>
                        <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors">
                            <td class="py-4 px-4">
                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($item['description']); ?></div>
                            </td>
                            <td class="py-4 px-4 text-center text-gray-600">
                                <?php echo number_format($item['quantity'], 2); ?>
                            </td>
                            <td class="py-4 px-4 text-right text-gray-600">
                                <?php echo Helper::formatCurrency($item['unit_price']); ?>
                            </td>
                            <td class="py-4 px-4 text-right font-medium text-gray-900">
                                <?php echo Helper::formatCurrency($item['total_amount']); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-gray-200">
                        <td colspan="3" class="py-4 px-4 text-right font-semibold text-gray-900">Subtotal:</td>
                        <td class="py-4 px-4 text-right font-semibold text-gray-900">
                            <?php echo Helper::formatCurrency($invoice['subtotal']); ?>
                        </td>
                    </tr>
                    <?php if ($invoice['tax_rate'] > 0): ?>
                        <tr>
                            <td colspan="3" class="py-2 px-4 text-right text-gray-600">
                                Tax (<?php echo number_format($invoice['tax_rate'], 2); ?>%):
                            </td>
                            <td class="py-2 px-4 text-right text-gray-600">
                                <?php echo Helper::formatCurrency($invoice['tax_amount']); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <tr class="border-t border-gray-200">
                        <td colspan="3" class="py-4 px-4 text-right text-lg font-bold text-gray-900">Total:</td>
                        <td class="py-4 px-4 text-right text-lg font-bold text-blue-600">
                            <?php echo Helper::formatCurrency($invoice['total_amount']); ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Mobile Card View -->
        <div class="md:hidden space-y-4">
            <?php foreach ($invoiceItems as $item): ?>
                <div class="border border-gray-200 rounded-lg p-4">
                    <div class="font-medium text-gray-900 mb-2"><?php echo htmlspecialchars($item['description']); ?></div>
                    <div class="grid grid-cols-3 gap-2 text-sm">
                        <div>
                            <span class="text-gray-500">Qty:</span>
                            <span class="font-medium"><?php echo number_format($item['quantity'], 2); ?></span>
                        </div>
                        <div>
                            <span class="text-gray-500">Price:</span>
                            <span class="font-medium"><?php echo Helper::formatCurrency($item['unit_price']); ?></span>
                        </div>
                        <div class="text-right">
                            <span class="text-gray-500">Total:</span>
                            <span class="font-medium"><?php echo Helper::formatCurrency($item['total_amount']); ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <!-- Mobile Totals -->
            <div class="border-t border-gray-200 pt-4 space-y-2">
                <div class="flex justify-between">
                    <span class="text-gray-600">Subtotal:</span>
                    <span class="font-medium"><?php echo Helper::formatCurrency($invoice['subtotal']); ?></span>
                </div>
                <?php if ($invoice['tax_rate'] > 0): ?>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Tax (<?php echo number_format($invoice['tax_rate'], 2); ?>%):</span>
                        <span class="font-medium"><?php echo Helper::formatCurrency($invoice['tax_amount']); ?></span>
                    </div>
                <?php endif; ?>
                <div class="flex justify-between text-lg font-bold border-t pt-2">
                    <span>Total:</span>
                    <span class="text-blue-600"><?php echo Helper::formatCurrency($invoice['total_amount']); ?></span>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="text-center py-8">
            <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
            </svg>
            <h3 class="text-lg font-medium text-gray-900 mb-2">No invoice items</h3>
            <p class="text-gray-600 mb-4">This invoice doesn't have any line items yet.</p>
            <a href="<?php echo Helper::baseUrl('modules/invoices/edit.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
               class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                Add Items
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- Notes -->
<?php if (!empty($invoice['notes'])): ?>
    <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Notes</h2>
        <div class="text-gray-700 whitespace-pre-line"><?php echo htmlspecialchars($invoice['notes']); ?></div>
    </div>
<?php endif; ?>

<!-- Payment History -->
<?php if (!empty($relatedPayments)): ?>
    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Payment History</h2>
        <div class="space-y-4">
            <?php foreach ($relatedPayments as $payment): ?>
                <div class="flex items-center justify-between p-4 bg-green-50 border border-green-200 rounded-lg">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center space-x-2 mb-1">
                            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <span class="font-medium text-green-800">Payment Received</span>
                        </div>
                        <div class="text-sm text-green-700">
                            <p>Date: <?php echo Helper::formatDate($payment['payment_date'], 'F j, Y'); ?></p>
                            <p>Method: <?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?></p>
                            <?php if ($payment['payment_reference']): ?>
                                <p>Reference: <?php echo htmlspecialchars($payment['payment_reference']); ?></p>
                            <?php endif; ?>
                            <?php if ($payment['notes']): ?>
                                <p class="mt-1">Notes: <?php echo htmlspecialchars($payment['notes']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-right ml-4">
                        <p class="text-lg font-bold text-green-600">
                            <?php echo Helper::formatCurrency($payment['payment_amount']); ?>
                        </p>
                        <p class="text-xs text-green-600">
                            <?php echo Helper::formatDate($payment['created_at'], 'M j, g:i A'); ?>
                        </p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<style>
/* Print styles */
@media print {
    .hidden-print {
        display: none !important;
    }
    
    body {
        background: white !important;
    }
    
    .bg-white {
        background: white !important;
        border: none !important;
        box-shadow: none !important;
    }
    
    .bg-blue-50 {
        background: #f8fafc !important;
        border: 1px solid #e2e8f0 !important;
    }
    
    .text-blue-600 {
        color: #1d4ed8 !important;
    }
    
    nav, .hidden-print {
        display: none !important;
    }
}

/* Status-specific styling */
.status-paid {
    animation: pulse-green 2s infinite;
}

.status-overdue {
    animation: pulse-red 2s infinite;
}

@keyframes pulse-green {
    0%, 100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.4); }
    50% { box-shadow: 0 0 0 8px rgba(34, 197, 94, 0); }
}

@keyframes pulse-red {
    0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
    50% { box-shadow: 0 0 0 8px rgba(239, 68, 68, 0); }
}

/* Smooth transitions */
.transition-colors {
    transition-property: color, background-color, border-color;
    transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
    transition-duration: 150ms;
}

/* Hover effects */
.hover-lift:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}
</style>

<script>
// Print invoice
function printInvoice() {
    // Hide action buttons and navigation for printing
    const elementsToHide = document.querySelectorAll('nav, .hidden-print');
    elementsToHide.forEach(el => el.style.display = 'none');
    
    window.print();
    
    // Restore elements after printing
    setTimeout(() => {
        elementsToHide.forEach(el => el.style.display = '');
    }, 1000);
}

// Email invoice
function emailInvoice() {
    // This would typically open an email modal or redirect to email functionality
    alert('Email functionality would be implemented here.\n\nTypically this would:\n- Generate a PDF\n- Open email compose window\n- Pre-fill recipient and subject\n- Attach the invoice PDF');
}

// Download PDF
function downloadPDF() {
    // This would typically generate and download a PDF
    alert('PDF download functionality would be implemented here.\n\nTypically this would:\n- Generate a PDF using a library like mPDF or TCPDF\n- Include company branding\n- Format the invoice professionally\n- Trigger automatic download');
}

// Update invoice status
function updateInvoiceStatus(newStatus) {
    if (confirm(`Are you sure you want to change the invoice status to "${newStatus.replace('_', ' ')}"?`)) {
        // This would typically be an AJAX call to update the status
        console.log('Updating invoice status to:', newStatus);
        
        // Show loading state
        const statusBadge = document.querySelector('.inline-flex.items-center.px-3.py-1\\.5');
        if (statusBadge) {
            statusBadge.style.opacity = '0.5';
            statusBadge.textContent = 'Updating...';
            
            // Simulate API call
            setTimeout(() => {
                location.reload();
            }, 1000);
        }
    }
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Press 'P' to print
    if (e.key.toLowerCase() === 'p' && !e.ctrlKey && !e.metaKey && !e.target.matches('input, textarea')) {
        e.preventDefault();
        printInvoice();
    }
    
    // Press 'E' to edit
    if (e.key.toLowerCase() === 'e' && !e.ctrlKey && !e.metaKey && !e.target.matches('input, textarea')) {
        e.preventDefault();
        window.location.href = '<?php echo Helper::baseUrl('modules/invoices/edit.php?id=' . Helper::encryptId($invoice['id'])); ?>';
    }
    
    // Press 'R' to record payment (if balance exists)
    <?php if ($invoice['balance_amount'] > 0): ?>
    if (e.key.toLowerCase() === 'r' && !e.ctrlKey && !e.metaKey && !e.target.matches('input, textarea')) {
        e.preventDefault();
        window.location.href = '<?php echo Helper::baseUrl('modules/payments/create.php?invoice_id=' . Helper::encryptId($invoice['id'])); ?>';
    }
    <?php endif; ?>
    
    // Press 'B' to go back
    if (e.key.toLowerCase() === 'b' && !e.ctrlKey && !e.metaKey && !e.target.matches('input, textarea')) {
        e.preventDefault();
        window.location.href = '<?php echo Helper::baseUrl('modules/invoices/'); ?>';
    }
});

// Auto-refresh for real-time updates (optional)
let autoRefreshInterval;

function startAutoRefresh() {
    autoRefreshInterval = setInterval(() => {
        // In a real application, you might want to check for status updates via AJAX
        console.log('Auto-refresh check would happen here');
    }, 30000); // Check every 30 seconds
}

function stopAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
    }
}

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    // Add status-specific classes for animations
    const statusBadge = document.querySelector('.inline-flex.items-center.px-3.py-1\\.5');
    const status = '<?php echo $invoice['status']; ?>';
    
    if (statusBadge) {
        if (status === 'paid') {
            statusBadge.classList.add('status-paid');
        } else if (status === 'overdue') {
            statusBadge.classList.add('status-overdue');
        }
    }
    
    // Add hover effects to action buttons
    document.querySelectorAll('a[href], button').forEach(element => {
        if (!element.classList.contains('hover-lift')) {
            element.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-1px)';
            });
            
            element.addEventListener('mouseleave', function() {
                this.style.transform = '';
            });
        }
    });
    
    // Start auto-refresh if needed
    // startAutoRefresh();
});

// Clean up on page unload
window.addEventListener('beforeunload', function() {
    stopAutoRefresh();
});

// Share invoice functionality
function shareInvoice() {
    if (navigator.share) {
        navigator.share({
            title: 'Invoice #<?php echo $invoice['invoice_number']; ?>',
            text: 'Invoice for <?php echo addslashes($invoice['company_name']); ?>',
            url: window.location.href
        }).catch(console.error);
    } else {
        // Fallback: copy link to clipboard
        navigator.clipboard.writeText(window.location.href).then(() => {
            alert('Invoice link copied to clipboard!');
        }).catch(() => {
            alert('Unable to copy link. Please copy the URL manually.');
        });
    }
}
</script>

<?php include '../../includes/footer.php'; ?>
