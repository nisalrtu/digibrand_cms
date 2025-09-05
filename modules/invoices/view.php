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

// Calculate invoice status and aging
$isOverdue = strtotime($invoice['due_date']) < time() && $invoice['status'] !== 'paid';
$daysUntilDue = ceil((strtotime($invoice['due_date']) - time()) / (60 * 60 * 24));

include '../../includes/header.php';
?>

<!-- Breadcrumb Navigation -->
<nav class="mb-6">
    <div class="flex items-center space-x-2 text-sm">
        <a href="<?php echo Helper::baseUrl('modules/invoices/'); ?>" 
           class="text-gray-500 hover:text-gray-700 transition-colors font-medium">
            Invoices
        </a>
        <svg class="w-4 h-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5l7 7-7 7"></path>
        </svg>
        <span class="text-gray-900 font-semibold">#<?php echo htmlspecialchars($invoice['invoice_number']); ?></span>
    </div>
</nav>

<!-- Page Header -->
<div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-6">
    <div class="flex-1">
        <div class="flex items-center gap-3 mb-2">
            <h1 class="text-3xl font-bold text-gray-900">Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?></h1>
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium
                <?php 
                switch ($invoice['status']) {
                    case 'draft':
                        echo 'bg-gray-100 text-gray-800';
                        break;
                    case 'sent':
                        echo 'bg-blue-100 text-blue-800';
                        break;
                    case 'paid':
                        echo 'bg-green-100 text-green-800';
                        break;
                    case 'partially_paid':
                        echo 'bg-yellow-100 text-yellow-800';
                        break;
                    case 'overdue':
                        echo 'bg-red-100 text-red-800';
                        break;
                    default:
                        echo 'bg-gray-100 text-gray-800';
                }
                ?>">
                <?php echo ucwords(str_replace('_', ' ', $invoice['status'])); ?>
            </span>
            <?php if ($isOverdue && $invoice['status'] !== 'paid'): ?>
                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">
                    Overdue
                </span>
            <?php elseif ($daysUntilDue <= 7 && $daysUntilDue > 0 && $invoice['status'] === 'sent'): ?>
                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                    Due in <?php echo $daysUntilDue; ?> day<?php echo $daysUntilDue !== 1 ? 's' : ''; ?>
                </span>
            <?php endif; ?>
        </div>
        
        <div class="text-gray-600 space-y-1">
            <p>Client: <span class="font-medium text-gray-900"><?php echo htmlspecialchars($invoice['company_name']); ?></span></p>
            <p>Issued: <?php echo Helper::formatDate($invoice['invoice_date'], 'M j, Y'); ?> • 
               Due: <?php echo Helper::formatDate($invoice['due_date'], 'M j, Y'); ?></p>
            <?php if ($invoice['project_name']): ?>
                <p>Project: <span class="font-medium text-gray-900"><?php echo htmlspecialchars($invoice['project_name']); ?></span></p>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Actions -->
    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 w-full sm:w-auto">
        <?php if ($invoice['status'] === 'draft' || $invoice['status'] === 'sent'): ?>
            <a href="<?php echo Helper::baseUrl('modules/invoices/edit.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
               class="inline-flex items-center justify-center px-4 py-2 bg-white text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                </svg>
                Edit Invoice
            </a>
        <?php endif; ?>
        
        <?php if ($invoice['balance_amount'] > 0): ?>
            <a href="<?php echo Helper::baseUrl('modules/payments/create.php?invoice_id=' . Helper::encryptId($invoice['id'])); ?>" 
               class="inline-flex items-center justify-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v2a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                </svg>
                Record Payment
            </a>
        <?php endif; ?>
        
        <a href="<?php echo Helper::baseUrl('modules/invoices/preview_invoice.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
           target="_blank"
           class="inline-flex items-center justify-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-900 transition-colors">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
            </svg>
            Preview
        </a>
        
        <a href="<?php echo Helper::baseUrl('modules/invoices/download_pdf.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
           class="inline-flex items-center justify-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-900 transition-colors">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            Download PDF
        </a>
    </div>
</div>

<!-- Payment Status Alert -->
<?php if ($invoice['status'] === 'paid'): ?>
    <div class="bg-green-50 border border-green-200 rounded-xl p-4 mb-6">
        <div class="flex items-center">
            <svg class="w-5 h-5 text-green-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <div>
                <p class="text-sm font-medium text-green-800">
                    This invoice has been fully paid
                </p>
                <p class="text-xs text-green-600 mt-1">
                    Total Amount: <?php echo Helper::formatCurrency($invoice['total_amount']); ?>
                </p>
            </div>
        </div>
    </div>
<?php elseif ($invoice['status'] === 'partially_paid'): ?>
    <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 mb-6">
        <div class="flex items-center">
            <svg class="w-5 h-5 text-yellow-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <div>
                <p class="text-sm font-medium text-yellow-800">
                    This invoice is partially paid
                </p>
                <p class="text-xs text-yellow-600 mt-1">
                    Paid: <?php echo Helper::formatCurrency($invoice['paid_amount']); ?> • 
                    Balance: <?php echo Helper::formatCurrency($invoice['balance_amount']); ?>
                </p>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Overdue Warning -->
<?php if ($isOverdue && $invoice['status'] !== 'paid'): ?>
    <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6">
        <div class="flex items-center">
            <svg class="w-5 h-5 text-red-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
            </svg>
            <div>
                <p class="text-sm font-medium text-red-800">This invoice is overdue</p>
                <p class="text-xs text-red-600 mt-1">
                    Due date was <?php echo Helper::formatDate($invoice['due_date'], 'M j, Y'); ?>
                </p>
            </div>
        </div>
    </div>
<?php elseif ($daysUntilDue <= 7 && $daysUntilDue > 0 && $invoice['status'] === 'sent'): ?>
    <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 mb-6">
        <div class="flex items-center">
            <svg class="w-5 h-5 text-yellow-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <div>
                <p class="text-sm font-medium text-yellow-800">
                    This invoice is due in <?php echo $daysUntilDue; ?> day<?php echo $daysUntilDue !== 1 ? 's' : ''; ?>
                </p>
                <p class="text-xs text-yellow-600 mt-1">
                    Due on <?php echo Helper::formatDate($invoice['due_date'], 'M j, Y'); ?>
                </p>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Main Content -->
    <div class="lg:col-span-2 flex flex-col">
        <!-- Invoice Details Card -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6 order-2 lg:order-1">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900">Invoice Details</h2>
            </div>
            
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <!-- Basic Info -->
                    <div>
                        <h3 class="text-sm font-medium text-gray-900 mb-3">Invoice Information</h3>
                        <div class="space-y-2">
                            <div class="flex justify-between">
                                <span class="text-sm text-gray-600">Invoice #:</span>
                                <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($invoice['invoice_number']); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-sm text-gray-600">Date:</span>
                                <span class="text-sm font-medium text-gray-900"><?php echo Helper::formatDate($invoice['invoice_date'], 'M j, Y'); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-sm text-gray-600">Due Date:</span>
                                <span class="text-sm font-medium text-gray-900"><?php echo Helper::formatDate($invoice['due_date'], 'M j, Y'); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-sm text-gray-600">Created By:</span>
                                <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($invoice['created_by_name'] ?? 'Unknown'); ?></span>
                            </div>
                            <?php if ($invoice['project_name']): ?>
                                <div class="flex justify-between">
                                    <span class="text-sm text-gray-600">Project:</span>
                                    <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($invoice['project_name']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Client Info -->
                    <div>
                        <h3 class="text-sm font-medium text-gray-900 mb-3">Client Information</h3>
                        <div class="space-y-2">
                            <div>
                                <span class="text-sm text-gray-600">Company:</span>
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($invoice['company_name']); ?></div>
                            </div>
                            <div>
                                <span class="text-sm text-gray-600">Contact Person:</span>
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($invoice['contact_person']); ?></div>
                            </div>
                            <?php if (!empty($invoice['mobile_number'])): ?>
                                <div>
                                    <span class="text-sm text-gray-600">Phone:</span>
                                    <div class="text-sm font-medium text-gray-900">
                                        <a href="tel:<?php echo htmlspecialchars($invoice['mobile_number']); ?>" 
                                           class="text-blue-600 hover:text-blue-700">
                                            <?php echo htmlspecialchars($invoice['mobile_number']); ?>
                                        </a>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($invoice['address'])): ?>
                                <div>
                                    <span class="text-sm text-gray-600">Address:</span>
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($invoice['address']); ?>
                                        <?php if (!empty($invoice['city'])): ?>
                                            <br><?php echo htmlspecialchars($invoice['city']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Notes -->
                <?php if (!empty($invoice['notes'])): ?>
                    <div class="border-t border-gray-200 pt-4">
                        <h3 class="text-sm font-medium text-gray-900 mb-2">Notes</h3>
                        <div class="text-sm text-gray-600 bg-gray-50 rounded-lg p-3">
                            <?php echo nl2br(htmlspecialchars($invoice['notes'])); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Invoice Items (Mobile First Order) -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6 order-1 lg:order-2">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900">Items</h2>
            </div>
            
            <?php if (empty($invoiceItems)): ?>
                <div class="p-6 text-center">
                    <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                    </svg>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No items found</h3>
                    <p class="text-gray-600">This invoice doesn't have any line items yet.</p>
                </div>
            <?php else: ?>
                <!-- Desktop Table View -->
                <div class="hidden md:block overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Unit Price</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($invoiceItems as $item): ?>
                                <tr>
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['description']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 text-right text-sm text-gray-900">
                                        <?php echo number_format($item['quantity'], 2); ?>
                                    </td>
                                    <td class="px-6 py-4 text-right text-sm text-gray-900">
                                        <?php echo Helper::formatCurrency($item['unit_price']); ?>
                                    </td>
                                    <td class="px-6 py-4 text-right text-sm font-medium text-gray-900">
                                        <?php echo Helper::formatCurrency($item['total_amount']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Mobile Card View -->
                <div class="md:hidden divide-y divide-gray-200">
                    <?php foreach ($invoiceItems as $item): ?>
                        <div class="p-4">
                            <div class="flex justify-between items-start mb-2">
                                <div class="flex-1">
                                    <h4 class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['description']); ?></h4>
                                </div>
                                <div class="text-right ml-4">
                                    <div class="text-sm font-medium text-gray-900"><?php echo Helper::formatCurrency($item['total_amount']); ?></div>
                                    <div class="text-xs text-gray-500">
                                        <?php echo number_format($item['quantity'], 2); ?> × <?php echo Helper::formatCurrency($item['unit_price']); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Totals Section -->
                <div class="border-t border-gray-200 bg-gray-50 px-6 py-4">
                    <div class="flex justify-end">
                        <div class="w-64">
                            <div class="flex justify-between py-2">
                                <span class="text-sm text-gray-600">Subtotal:</span>
                                <span class="text-sm font-medium text-gray-900"><?php echo Helper::formatCurrency($invoice['subtotal']); ?></span>
                            </div>
                            <?php if ($invoice['tax_rate'] > 0): ?>
                                <div class="flex justify-between py-2">
                                    <span class="text-sm text-gray-600">Tax (<?php echo number_format($invoice['tax_rate'], 1); ?>%):</span>
                                    <span class="text-sm font-medium text-gray-900"><?php echo Helper::formatCurrency($invoice['tax_amount']); ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="flex justify-between py-2 border-t border-gray-200">
                                <span class="text-base font-semibold text-gray-900">Total:</span>
                                <span class="text-base font-bold text-gray-900"><?php echo Helper::formatCurrency($invoice['total_amount']); ?></span>
                            </div>
                            <?php if ($invoice['paid_amount'] > 0): ?>
                                <div class="flex justify-between py-2">
                                    <span class="text-sm text-gray-600">Paid:</span>
                                    <span class="text-sm font-medium text-green-600"><?php echo Helper::formatCurrency($invoice['paid_amount']); ?></span>
                                </div>
                                <div class="flex justify-between py-2 border-t border-gray-200">
                                    <span class="text-base font-semibold text-gray-900">Balance:</span>
                                    <span class="text-base font-bold text-red-600"><?php echo Helper::formatCurrency($invoice['balance_amount']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Payment History -->
        <?php if (!empty($relatedPayments)): ?>
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm order-3">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-900">Payment History</h2>
                </div>
                
                <div class="p-6">
                    <div class="flow-root">
                        <ul class="-mb-8">
                            <?php foreach ($relatedPayments as $index => $payment): ?>
                                <li>
                                    <div class="relative pb-8">
                                        <?php if ($index < count($relatedPayments) - 1): ?>
                                            <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
                                        <?php endif; ?>
                                        <div class="relative flex space-x-3">
                                            <div>
                                                <span class="h-8 w-8 rounded-full bg-green-500 flex items-center justify-center ring-8 ring-white">
                                                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v2a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                                    </svg>
                                                </span>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div>
                                                    <div class="text-sm">
                                                        <span class="font-medium text-gray-900">Payment Received</span>
                                                    </div>
                                                    <div class="mt-1 text-sm text-gray-600">
                                                        <time datetime="<?php echo $payment['payment_date']; ?>">
                                                            <?php echo Helper::formatDate($payment['payment_date'], 'M j, Y \a\t g:i A'); ?>
                                                        </time>
                                                    </div>
                                                </div>
                                                <div class="mt-2 text-sm text-gray-700 space-y-1">
                                                    <p><span class="font-medium">Amount:</span> <?php echo Helper::formatCurrency($payment['payment_amount']); ?></p>
                                                    <p><span class="font-medium">Method:</span> <?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?></p>
                                                    <?php if ($payment['payment_reference']): ?>
                                                        <p><span class="font-medium">Reference:</span> <?php echo htmlspecialchars($payment['payment_reference']); ?></p>
                                                    <?php endif; ?>
                                                    <?php if ($payment['notes']): ?>
                                                        <p><span class="font-medium">Notes:</span> <?php echo htmlspecialchars($payment['notes']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Sidebar -->
    <div class="lg:col-span-1">
        <!-- Quick Stats -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Payment Summary</h3>
            </div>
            <div class="p-6 space-y-4">
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-600">Subtotal:</span>
                    <span class="text-sm font-medium text-gray-900"><?php echo Helper::formatCurrency($invoice['subtotal']); ?></span>
                </div>
                <?php if ($invoice['tax_rate'] > 0): ?>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">Tax Rate:</span>
                        <span class="text-sm font-medium text-gray-900"><?php echo number_format($invoice['tax_rate'], 1); ?>%</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">Tax Amount:</span>
                        <span class="text-sm font-medium text-gray-900"><?php echo Helper::formatCurrency($invoice['tax_amount']); ?></span>
                    </div>
                <?php endif; ?>
                <div class="flex justify-between items-center pt-2 border-t border-gray-200">
                    <span class="text-sm font-semibold text-gray-900">Total Amount:</span>
                    <span class="text-sm font-bold text-gray-900"><?php echo Helper::formatCurrency($invoice['total_amount']); ?></span>
                </div>
                
                <?php if ($invoice['paid_amount'] > 0): ?>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">Paid Amount:</span>
                        <span class="text-sm font-medium text-green-600"><?php echo Helper::formatCurrency($invoice['paid_amount']); ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if ($invoice['balance_amount'] > 0): ?>
                    <div class="flex justify-between items-center pt-2 border-t border-gray-200">
                        <span class="text-sm font-semibold text-red-600">Balance Due:</span>
                        <span class="text-sm font-bold text-red-600"><?php echo Helper::formatCurrency($invoice['balance_amount']); ?></span>
                    </div>
                <?php endif; ?>
                
                <div class="pt-2 border-t border-gray-200">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm text-gray-600">Payment Progress:</span>
                        <span class="text-sm font-medium text-gray-900"><?php echo $invoice['total_amount'] > 0 ? round(($invoice['paid_amount'] / $invoice['total_amount']) * 100) : 0; ?>%</span>
                    </div>
                    <?php if ($invoice['total_amount'] > 0): ?>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="bg-green-500 h-2 rounded-full" 
                                 style="width: <?php echo min(($invoice['paid_amount'] / $invoice['total_amount']) * 100, 100); ?>%"></div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="pt-2 border-t border-gray-200">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm text-gray-600">Days Since Issue:</span>
                        <span class="text-sm font-medium text-gray-900">
                            <?php echo floor((time() - strtotime($invoice['invoice_date'])) / (60 * 60 * 24)); ?> days
                        </span>
                    </div>
                    <?php if ($invoice['status'] !== 'paid' && $isOverdue): ?>
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-600">Days Overdue:</span>
                            <span class="text-sm font-medium text-red-600">
                                <?php echo floor((time() - strtotime($invoice['due_date'])) / (60 * 60 * 24)); ?> days
                            </span>
                        </div>
                    <?php elseif ($daysUntilDue > 0 && $invoice['status'] !== 'paid'): ?>
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-600">Days Until Due:</span>
                            <span class="text-sm font-medium <?php echo $daysUntilDue <= 7 ? 'text-yellow-600' : 'text-gray-900'; ?>">
                                <?php echo $daysUntilDue; ?> days
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Quick Actions</h3>
            </div>
            <div class="p-6 space-y-3">
                <?php if ($invoice['balance_amount'] > 0): ?>
                    <a href="<?php echo Helper::baseUrl('modules/payments/create.php?invoice_id=' . Helper::encryptId($invoice['id'])); ?>" 
                       class="w-full inline-flex items-center justify-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v2a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                        Record Payment
                    </a>
                <?php endif; ?>
                
                <a href="<?php echo Helper::baseUrl('modules/invoices/duplicate.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
                   class="w-full inline-flex items-center justify-center px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                    </svg>
                    Duplicate Invoice
                </a>
                
                <a href="<?php echo Helper::baseUrl('modules/clients/view.php?id=' . Helper::encryptId($invoice['client_id'])); ?>" 
                   class="w-full inline-flex items-center justify-center px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                    </svg>
                    View Client
                </a>
                
                <?php if ($invoice['project_id']): ?>
                    <a href="<?php echo Helper::baseUrl('modules/projects/view.php?id=' . Helper::encryptId($invoice['project_id'])); ?>" 
                       class="w-full inline-flex items-center justify-center px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                        </svg>
                        View Project
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Client Quick Info -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Client Info</h3>
            </div>
            <div class="p-6">
                <div class="space-y-3">
                    <div>
                        <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($invoice['company_name']); ?></p>
                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($invoice['contact_person']); ?></p>
                    </div>
                    
                    <?php if (!empty($invoice['mobile_number'])): ?>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Phone</p>
                            <a href="tel:<?php echo htmlspecialchars($invoice['mobile_number']); ?>" 
                               class="text-sm text-blue-600 hover:text-blue-700">
                                <?php echo htmlspecialchars($invoice['mobile_number']); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($invoice['address'])): ?>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Address</p>
                            <p class="text-sm text-gray-700">
                                <?php echo htmlspecialchars($invoice['address']); ?>
                                <?php if (!empty($invoice['city'])): ?>
                                    <br><?php echo htmlspecialchars($invoice['city']); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($invoice['project_name']): ?>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Project</p>
                            <p class="text-sm text-gray-700"><?php echo htmlspecialchars($invoice['project_name']); ?></p>
                            <?php if ($invoice['project_type']): ?>
                                <p class="text-xs text-gray-500"><?php echo ucwords(str_replace('_', ' ', $invoice['project_type'])); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.target.matches('input, textarea, select')) return;
    
    switch(e.key.toLowerCase()) {
        case 'e':
            if (!e.ctrlKey && !e.metaKey) {
                e.preventDefault();
                <?php if ($invoice['status'] === 'draft' || $invoice['status'] === 'sent'): ?>
                    window.location.href = '<?php echo Helper::baseUrl('modules/invoices/edit.php?id=' . Helper::encryptId($invoice['id'])); ?>';
                <?php endif; ?>
            }
            break;
        case 'p':
            if (!e.ctrlKey && !e.metaKey) {
                e.preventDefault();
                window.open('<?php echo Helper::baseUrl('modules/invoices/preview.php?id=' . Helper::encryptId($invoice['id'])); ?>', '_blank');
            }
            break;
        case 'd':
            if (!e.ctrlKey && !e.metaKey) {
                e.preventDefault();
                window.location.href = '<?php echo Helper::baseUrl('modules/invoices/download_pdf.php?id=' . Helper::encryptId($invoice['id'])); ?>';
            }
            break;
        <?php if ($invoice['balance_amount'] > 0): ?>
        case 'r':
            if (!e.ctrlKey && !e.metaKey) {
                e.preventDefault();
                window.location.href = '<?php echo Helper::baseUrl('modules/payments/create.php?invoice_id=' . Helper::encryptId($invoice['id'])); ?>';
            }
            break;
        <?php endif; ?>
    }
});

// Mobile-friendly interactions
if ('ontouchstart' in window) {
    const touchableElements = document.querySelectorAll('button, .bg-white.rounded-xl');
    touchableElements.forEach(element => {
        element.addEventListener('touchstart', function() {
            this.style.transform = 'scale(0.98)';
        }, { passive: true });
        
        element.addEventListener('touchend', function() {
            this.style.transform = '';
        }, { passive: true });
    });
}
</script>

<style>
/* Print styles */
@media print {
    .no-print, nav, .fixed {
        display: none !important;
    }
    
    .bg-white {
        box-shadow: none !important;
    }
    
    body {
        font-size: 12px;
    }
    
    .text-3xl {
        font-size: 1.5rem !important;
    }
    
    .text-xl {
        font-size: 1.25rem !important;
    }
}

/* Custom scrollbar for timeline */
.flow-root {
    max-height: 400px;
    overflow-y: auto;
}

.flow-root::-webkit-scrollbar {
    width: 6px;
}

.flow-root::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.flow-root::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

.flow-root::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

/* Responsive table improvements */
@media (max-width: 768px) {
    .overflow-x-auto {
        -webkit-overflow-scrolling: touch;
    }
    
    table {
        min-width: 600px;
    }
}
</style>

<?php include '../../includes/footer.php'; ?>