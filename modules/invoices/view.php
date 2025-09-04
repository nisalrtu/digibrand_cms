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


<!-- Main Content Grid -->
<div class="grid grid-cols-1 xl:grid-cols-3 gap-4 md:gap-6 lg:gap-8">
    <!-- Left Column (Main Content) -->
    <div class="xl:col-span-2 space-y-4 md:space-y-6">
        
        <!-- Invoice Header Card -->
        <div class="bg-white rounded-xl md:rounded-2xl border border-gray-100 shadow-sm">
            <!-- Header Section -->
            <div class="p-4 md:p-6 border-b border-gray-50">
                <?php if ($invoice['project_id']): ?>
                    <div class="mb-3 md:mb-4">
                        <a href="<?php echo Helper::baseUrl('modules/projects/view.php?id=' . Helper::encryptId($invoice['project_id'])); ?>" 
                           class="inline-flex items-center text-xs md:text-sm text-gray-600 hover:text-gray-800 transition-colors">
                            <svg class="w-3 h-3 md:w-4 md:h-4 mr-1 md:mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                            </svg>
                            Back to Project
                        </a>
                    </div>
                <?php endif; ?>
                
                <div class="flex flex-col gap-3 md:gap-4">
                    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <h1 class="text-xl md:text-2xl lg:text-3xl font-bold text-gray-900 mb-1 md:mb-2 break-words">
                                Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?>
                            </h1>
                            <?php if ($invoice['project_name']): ?>
                                <p class="text-sm md:text-base lg:text-lg text-gray-600 font-medium break-words">
                                    <?php echo htmlspecialchars($invoice['project_name']); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Enhanced Status Badge -->
                        <div class="flex-shrink-0 self-start">
                            <?php 
                            $status = $invoice['status'];
                            $statusConfig = [
                                'draft' => [
                                    'class' => 'bg-gray-50 text-gray-700 border-gray-200',
                                    'icon' => '<svg class="w-3 h-3 md:w-4 md:h-4 mr-1.5 md:mr-2" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4 2a2 2 0 00-2 2v11a2 2 0 002 2h12a2 2 0 002-2V4a2 2 0 00-2-2H4zm0 2h12v11H4V4z" clip-rule="evenodd"></path></svg>',
                                    'label' => 'Draft'
                                ],
                                'sent' => [
                                    'class' => 'bg-blue-50 text-blue-700 border-blue-200',
                                    'icon' => '<svg class="w-3 h-3 md:w-4 md:h-4 mr-1.5 md:mr-2" fill="currentColor" viewBox="0 0 20 20"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"></path><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"></path></svg>',
                                    'label' => 'Sent'
                                ],
                                'paid' => [
                                    'class' => 'bg-green-50 text-green-700 border-green-200',
                                    'icon' => '<svg class="w-3 h-3 md:w-4 md:h-4 mr-1.5 md:mr-2" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg>',
                                    'label' => 'Paid'
                                ],
                                'partially_paid' => [
                                    'class' => 'bg-amber-50 text-amber-700 border-amber-200',
                                    'icon' => '<svg class="w-3 h-3 md:w-4 md:h-4 mr-1.5 md:mr-2" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path></svg>',
                                    'label' => 'Partially Paid'
                                ],
                                'overdue' => [
                                    'class' => 'bg-red-50 text-red-700 border-red-200',
                                    'icon' => '<svg class="w-3 h-3 md:w-4 md:h-4 mr-1.5 md:mr-2" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>',
                                    'label' => 'Overdue'
                                ]
                            ];
                            $config = $statusConfig[$status] ?? $statusConfig['draft'];
                            ?>
                            <div class="inline-flex items-center px-3 py-1.5 md:px-4 md:py-2 rounded-lg md:rounded-xl border font-semibold text-xs md:text-sm <?php echo $config['class']; ?>">
                                <?php echo $config['icon'] . $config['label']; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Invoice Details Grid -->
            <div class="p-4 md:p-6">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6 mb-4 md:mb-6">
                    <div class="space-y-1">
                        <h3 class="text-xs md:text-sm font-medium text-gray-500 uppercase tracking-wide">Invoice Date</h3>
                        <p class="text-sm md:text-base font-semibold text-gray-900"><?php echo Helper::formatDate($invoice['invoice_date'], 'M j, Y'); ?></p>
                    </div>
                    <div class="space-y-1">
                        <h3 class="text-xs md:text-sm font-medium text-gray-500 uppercase tracking-wide">Due Date</h3>
                        <p class="text-sm md:text-base font-semibold <?php echo (strtotime($invoice['due_date']) < time() && $invoice['status'] !== 'paid') ? 'text-red-600' : 'text-gray-900'; ?>">
                            <?php echo Helper::formatDate($invoice['due_date'], 'M j, Y'); ?>
                            <?php if (strtotime($invoice['due_date']) < time() && $invoice['status'] !== 'paid'): ?>
                                <span class="block text-xs text-red-500 font-normal mt-0.5">Overdue</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="space-y-1 sm:col-span-2 lg:col-span-1">
                        <h3 class="text-xs md:text-sm font-medium text-gray-500 uppercase tracking-wide">Created</h3>
                        <p class="text-sm md:text-base font-semibold text-gray-900">
                            <?php echo Helper::formatDate($invoice['created_at'], 'M j, Y'); ?>
                            <?php if ($invoice['created_by_name']): ?>
                                <span class="block text-xs text-gray-500 font-normal mt-0.5">by <?php echo htmlspecialchars($invoice['created_by_name']); ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <!-- Client Information -->
                <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-100 rounded-lg md:rounded-xl p-4 md:p-5">
                    <h3 class="font-semibold text-blue-900 mb-2 md:mb-3 text-xs md:text-sm uppercase tracking-wide">Bill To</h3>
                    <div class="text-blue-800">
                        <p class="font-bold text-base md:text-lg text-blue-900 mb-1 break-words"><?php echo htmlspecialchars($invoice['company_name']); ?></p>
                        <p class="font-medium mb-2 break-words"><?php echo htmlspecialchars($invoice['contact_person']); ?></p>
                        <?php if (!empty($invoice['address'])): ?>
                            <p class="text-sm opacity-90 break-words"><?php echo htmlspecialchars($invoice['address']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($invoice['city'])): ?>
                            <p class="text-sm opacity-90 break-words"><?php echo htmlspecialchars($invoice['city']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($invoice['mobile_number'])): ?>
                            <p class="text-sm font-medium mt-2 break-all">📞 <?php echo htmlspecialchars($invoice['mobile_number']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Invoice Items Card -->
        <div class="bg-white rounded-xl md:rounded-2xl border border-gray-100 shadow-sm">
            <div class="p-4 md:p-6 border-b border-gray-50">
                <h2 class="text-lg md:text-xl font-bold text-gray-900">Invoice Items</h2>
            </div>
            
            <div class="p-4 md:p-6">
                <?php if (!empty($invoiceItems)): ?>
                    <!-- Desktop Table View -->
                    <div class="hidden lg:block">
                        <div class="overflow-hidden rounded-xl border border-gray-100">
                            <table class="min-w-full divide-y divide-gray-100">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="text-left py-4 px-6 text-sm font-semibold text-gray-700 uppercase tracking-wide">Description</th>
                                        <th class="text-center py-4 px-6 text-sm font-semibold text-gray-700 uppercase tracking-wide w-24">Qty</th>
                                        <th class="text-right py-4 px-6 text-sm font-semibold text-gray-700 uppercase tracking-wide w-32">Unit Price</th>
                                        <th class="text-right py-4 px-6 text-sm font-semibold text-gray-700 uppercase tracking-wide w-32">Total</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-50">
                                    <?php foreach ($invoiceItems as $item): ?>
                                        <tr class="hover:bg-gray-25 transition-colors">
                                            <td class="py-3 px-6">
                                                <div class="font-medium text-gray-900 break-words"><?php echo htmlspecialchars($item['description']); ?></div>
                                            </td>
                                            <td class="py-3 px-6 text-center text-gray-600 font-medium">
                                                <?php echo number_format($item['quantity'], 2); ?>
                                            </td>
                                            <td class="py-3 px-6 text-right text-gray-600 font-medium">
                                                <span class="currency-nowrap"><?php echo Helper::formatCurrency($item['unit_price']); ?></span>
                                            </td>
                                            <td class="py-3 px-6 text-right font-bold text-gray-900">
                                                <span class="currency-nowrap"><?php echo Helper::formatCurrency($item['total_amount']); ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Desktop Totals -->
                        <div class="mt-6 flex justify-end">
                            <div class="w-80 space-y-3">
                                <div class="flex justify-between text-gray-600">
                                    <span class="font-medium">Subtotal:</span>
                                    <span class="font-semibold currency-nowrap"><?php echo Helper::formatCurrency($invoice['subtotal']); ?></span>
                                </div>
                                <?php if ($invoice['tax_rate'] > 0): ?>
                                    <div class="flex justify-between text-gray-600">
                                        <span class="font-medium">Tax (<?php echo number_format($invoice['tax_rate'], 2); ?>%):</span>
                                        <span class="font-semibold currency-nowrap"><?php echo Helper::formatCurrency($invoice['tax_amount']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="flex justify-between text-xl font-bold text-gray-900 pt-3 border-t border-gray-200">
                                    <span>Total:</span>
                                    <span class="text-blue-600 currency-nowrap"><?php echo Helper::formatCurrency($invoice['total_amount']); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Mobile/Tablet Card View -->
                    <div class="lg:hidden space-y-3">
                        <?php foreach ($invoiceItems as $item): ?>
                            <div class="border border-gray-100 rounded-xl p-3 md:p-4 bg-gray-50">
                                <div class="font-semibold text-gray-900 mb-2 md:mb-3 text-sm md:text-base break-words"><?php echo htmlspecialchars($item['description']); ?></div>
                                <div class="grid grid-cols-3 gap-3 text-xs md:text-sm">
                                    <div>
                                        <span class="text-gray-500 block mb-1">Quantity</span>
                                        <span class="font-semibold text-gray-900"><?php echo number_format($item['quantity'], 2); ?></span>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 block mb-1">Unit Price</span>
                                        <span class="font-semibold text-gray-900 currency-nowrap break-all"><?php echo Helper::formatCurrency($item['unit_price']); ?></span>
                                    </div>
                                    <div class="text-right">
                                        <span class="text-gray-500 block mb-1">Total</span>
                                        <span class="font-bold text-gray-900 currency-nowrap break-all"><?php echo Helper::formatCurrency($item['total_amount']); ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <!-- Mobile Totals -->
                        <div class="border-t border-gray-200 pt-4 space-y-3">
                            <div class="flex justify-between text-gray-600 text-sm md:text-base">
                                <span class="font-medium">Subtotal:</span>
                                <span class="font-semibold currency-nowrap break-all"><?php echo Helper::formatCurrency($invoice['subtotal']); ?></span>
                            </div>
                            <?php if ($invoice['tax_rate'] > 0): ?>
                                <div class="flex justify-between text-gray-600 text-sm md:text-base">
                                    <span class="font-medium">Tax (<?php echo number_format($invoice['tax_rate'], 2); ?>%):</span>
                                    <span class="font-semibold currency-nowrap break-all"><?php echo Helper::formatCurrency($invoice['tax_amount']); ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="flex justify-between text-lg md:text-xl font-bold text-gray-900 pt-3 border-t border-gray-200">
                                <span>Total:</span>
                                <span class="text-blue-600 currency-nowrap break-all"><?php echo Helper::formatCurrency($invoice['total_amount']); ?></span>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Empty State -->
                    <div class="text-center py-8 md:py-12">
                        <div class="w-12 h-12 md:w-16 md:h-16 mx-auto mb-3 md:mb-4 bg-gray-100 rounded-full flex items-center justify-center">
                            <svg class="w-6 h-6 md:w-8 md:h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                            </svg>
                        </div>
                        <h3 class="text-base md:text-lg font-semibold text-gray-900 mb-2">No invoice items</h3>
                        <p class="text-gray-600 mb-4 md:mb-6 text-sm md:text-base">This invoice doesn't have any line items yet.</p>
                        <a href="<?php echo Helper::baseUrl('modules/invoices/edit.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
                           class="inline-flex items-center px-4 md:px-6 py-2 md:py-3 bg-blue-600 text-white rounded-lg md:rounded-xl hover:bg-blue-700 transition-colors font-medium text-sm md:text-base">
                            <svg class="w-4 h-4 md:w-5 md:h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                            Add Items
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Notes Card -->
        <?php if (!empty($invoice['notes'])): ?>
            <div class="bg-white rounded-xl md:rounded-2xl border border-gray-100 shadow-sm">
                <div class="p-4 md:p-6 border-b border-gray-50">
                    <h2 class="text-lg md:text-xl font-bold text-gray-900">Notes</h2>
                </div>
                <div class="p-4 md:p-6">
                    <div class="text-gray-700 whitespace-pre-line leading-relaxed text-sm md:text-base break-words"><?php echo htmlspecialchars($invoice['notes']); ?></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Payment History Card -->
        <?php if (!empty($relatedPayments)): ?>
            <div class="bg-white rounded-xl md:rounded-2xl border border-gray-100 shadow-sm">
                <div class="p-4 md:p-6 border-b border-gray-50">
                    <h2 class="text-lg md:text-xl font-bold text-gray-900">Payment History</h2>
                </div>
                <div class="p-4 md:p-6">
                    <div class="space-y-3 md:space-y-4">
                        <?php foreach ($relatedPayments as $payment): ?>
                            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between p-4 md:p-5 bg-gradient-to-r from-green-50 to-emerald-50 border border-green-100 rounded-lg md:rounded-xl">
                                <div class="flex-1 min-w-0 mb-3 sm:mb-0">
                                    <div class="flex items-center space-x-2 md:space-x-3 mb-2">
                                        <div class="w-6 h-6 md:w-8 md:h-8 bg-green-100 rounded-full flex items-center justify-center flex-shrink-0">
                                            <svg class="w-3 h-3 md:w-4 md:h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                            </svg>
                                        </div>
                                        <span class="font-semibold text-green-800 text-sm md:text-base">Payment Received</span>
                                    </div>
                                    <div class="text-xs md:text-sm text-green-700 space-y-1 ml-8 md:ml-11">
                                        <p><span class="font-medium">Date:</span> <?php echo Helper::formatDate($payment['payment_date'], 'F j, Y'); ?></p>
                                        <p><span class="font-medium">Method:</span> <?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?></p>
                                        <?php if ($payment['payment_reference']): ?>
                                            <p class="break-words"><span class="font-medium">Reference:</span> <?php echo htmlspecialchars($payment['payment_reference']); ?></p>
                                        <?php endif; ?>
                                        <?php if ($payment['notes']): ?>
                                            <p class="break-words"><span class="font-medium">Notes:</span> <?php echo htmlspecialchars($payment['notes']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="text-right sm:ml-6 flex-shrink-0">
                                    <p class="text-lg md:text-2xl font-bold text-green-700 currency-nowrap break-all">
                                        <?php echo Helper::formatCurrency($payment['payment_amount']); ?>
                                    </p>
                                    <p class="text-xs text-green-600 font-medium">
                                        <?php echo Helper::formatDate($payment['created_at'], 'M j, g:i A'); ?>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Right Column (Sidebar) -->
    <div class="xl:col-span-1 space-y-4 md:space-y-6">
        
        <!-- Payment Summary Card -->
        <div class="bg-white rounded-xl md:rounded-2xl border border-gray-100 shadow-sm xl:sticky xl:top-6">
            <div class="p-4 md:p-6 border-b border-gray-50">
                <h3 class="text-base md:text-lg font-bold text-gray-900">Payment Summary</h3>
            </div>
            <div class="p-4 md:p-6 space-y-3 md:space-y-4">
                <div class="flex justify-between items-center">
                    <span class="text-gray-600 font-medium text-sm md:text-base">Subtotal</span>
                    <span class="font-bold text-gray-900 currency-nowrap text-sm md:text-base break-all"><?php echo Helper::formatCurrency($invoice['subtotal']); ?></span>
                </div>
                <?php if ($invoice['tax_rate'] > 0): ?>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600 font-medium text-sm md:text-base">Tax (<?php echo number_format($invoice['tax_rate'], 2); ?>%)</span>
                        <span class="font-bold text-gray-900 currency-nowrap text-sm md:text-base break-all"><?php echo Helper::formatCurrency($invoice['tax_amount']); ?></span>
                    </div>
                <?php endif; ?>
                <div class="border-t border-gray-100 pt-3 md:pt-4">
                    <div class="flex justify-between items-center mb-3 md:mb-4">
                        <span class="text-base md:text-lg font-bold text-gray-900">Total Amount</span>
                        <span class="text-lg md:text-2xl font-bold text-blue-600 currency-nowrap break-all"><?php echo Helper::formatCurrency($invoice['total_amount']); ?></span>
                    </div>
                    <div class="flex justify-between items-center mb-3 md:mb-4">
                        <span class="text-gray-600 font-medium text-sm md:text-base">Paid Amount</span>
                        <span class="font-bold text-green-600 currency-nowrap text-sm md:text-base break-all"><?php echo Helper::formatCurrency($invoice['paid_amount']); ?></span>
                    </div>
                    <?php if ($invoice['balance_amount'] > 0): ?>
                        <div class="flex justify-between items-center">
                            <span class="text-base md:text-lg font-bold text-red-600">Balance Due</span>
                            <span class="text-lg md:text-xl font-bold text-red-600 currency-nowrap break-all"><?php echo Helper::formatCurrency($invoice['balance_amount']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Payment Progress -->
                <?php if ($invoice['total_amount'] > 0): ?>
                    <div class="pt-3 md:pt-4 border-t border-gray-100">
                        <div class="flex justify-between text-xs md:text-sm text-gray-600 mb-2">
                            <span class="font-medium">Payment Progress</span>
                            <span class="font-semibold"><?php echo round(($invoice['paid_amount'] / $invoice['total_amount']) * 100); ?>%</span>
                        </div>
                        <div class="w-full bg-gray-100 rounded-full h-2 md:h-3">
                            <div class="bg-gradient-to-r from-green-500 to-green-600 h-2 md:h-3 rounded-full transition-all duration-500" 
                                 style="width: <?php echo min(($invoice['paid_amount'] / $invoice['total_amount']) * 100, 100); ?>%"></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Actions Card -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm">
            <div class="p-6 border-b border-gray-50">
                <h3 class="text-lg font-bold text-gray-900">Quick Actions</h3>
            </div>
            <div class="p-6 space-y-3">
                <!-- Primary Action -->
                <?php if ($invoice['balance_amount'] > 0): ?>
                    <a href="<?php echo Helper::baseUrl('modules/payments/create.php?invoice_id=' . Helper::encryptId($invoice['id'])); ?>" 
                       class="flex items-center justify-center w-full px-4 py-3 bg-green-600 text-white rounded-xl hover:bg-green-700 transition-all duration-200 font-semibold group">
                        <svg class="w-5 h-5 mr-2 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v2a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                        Record Payment
                    </a>
                <?php endif; ?>
                
                <!-- Secondary Actions -->
                <a href="<?php echo Helper::baseUrl('modules/invoices/edit.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
                   class="flex items-center justify-center w-full px-4 py-3 bg-gray-800 text-white rounded-xl hover:bg-gray-900 transition-all duration-200 font-medium group">
                    <svg class="w-5 h-5 mr-2 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                    </svg>
                    Edit Invoice
                </a>
                
                <!-- Preview Invoice Button -->
                <button onclick="previewInvoice()" 
                        class="flex items-center justify-center w-full px-4 py-3 bg-purple-600 text-white rounded-xl hover:bg-purple-700 transition-all duration-200 font-medium group">
                    <svg class="w-5 h-5 mr-2 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    </svg>
                    Preview Invoice
                </button>
                
                <!-- More Actions -->
                <div class="pt-3 border-t border-gray-100">
                    <button onclick="emailInvoice()" 
                            class="flex items-center justify-center w-full px-4 py-2.5 text-gray-700 bg-gray-50 rounded-xl hover:bg-gray-100 transition-colors font-medium group">
                        <svg class="w-4 h-4 mr-2 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 7.89a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                        </svg>
                        Email Invoice
                    </button>
                    <button onclick="shareInvoice()" 
                            class="flex items-center justify-center w-full px-4 py-2.5 text-gray-700 bg-gray-50 rounded-xl hover:bg-gray-100 transition-colors font-medium group mt-2">
                        <svg class="w-4 h-4 mr-2 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.367 2.684 3 3 0 00-5.367-2.684z"></path>
                        </svg>
                        Share Invoice
                    </button>
                </div>
            </div>
        </div>

        <!-- Invoice Stats Card -->
        <div class="bg-gradient-to-br from-blue-50 via-indigo-50 to-purple-50 rounded-2xl border border-blue-100 shadow-sm">
            <div class="p-6">
                <h3 class="text-lg font-bold text-gray-900 mb-4">Invoice Stats</h3>
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-2">
                            <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                            <span class="text-sm font-medium text-gray-700">Days Outstanding</span>
                        </div>
                        <span class="font-bold text-gray-900">
                            <?php 
                            $daysOutstanding = floor((time() - strtotime($invoice['invoice_date'])) / (60 * 60 * 24));
                            echo $daysOutstanding;
                            ?>
                        </span>
                    </div>
                    
                    <?php if ($invoice['status'] !== 'paid' && strtotime($invoice['due_date']) < time()): ?>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-2">
                                <div class="w-2 h-2 bg-red-500 rounded-full"></div>
                                <span class="text-sm font-medium text-gray-700">Days Overdue</span>
                            </div>
                            <span class="font-bold text-red-600">
                                <?php 
                                $daysOverdue = floor((time() - strtotime($invoice['due_date'])) / (60 * 60 * 24));
                                echo $daysOverdue;
                                ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-2">
                            <div class="w-2 h-2 bg-green-500 rounded-full"></div>
                            <span class="text-sm font-medium text-gray-700">Payment Count</span>
                        </div>
                        <span class="font-bold text-gray-900"><?php echo count($relatedPayments); ?></span>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Enhanced Styles -->
<style>
/* Enhanced color palette and spacing */
.bg-gray-25 {
    background-color: #fafbfc;
}

/* Currency formatting - prevent line breaks */
.currency-nowrap {
    white-space: nowrap;
}

/* Apply nowrap to all currency spans */
span[class*="font-bold"]:has-text("LKR"),
span[class*="font-semibold"]:has-text("LKR") {
    white-space: nowrap;
}

/* Print styles */
@media print {
    .hidden-print, nav, .lg\:col-span-1 {
        display: none !important;
    }
    
    .lg\:col-span-2 {
        grid-column: span 3 / span 3;
    }
    
    body {
        background: white !important;
    }
    
    .bg-white, .bg-gradient-to-r, .bg-gradient-to-br {
        background: white !important;
        border: 1px solid #e5e7eb !important;
        box-shadow: none !important;
    }
    
    .text-blue-600, .text-green-600 {
        color: #374151 !important;
    }
}

/* Enhanced transitions and animations */
.transition-all {
    transition-property: all;
    transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
    transition-duration: 200ms;
}

/* Status badge animations */
.status-paid {
    animation: pulse-success 3s infinite;
}

.status-overdue {
    animation: pulse-warning 3s infinite;
}

@keyframes pulse-success {
    0%, 100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.3); }
    50% { box-shadow: 0 0 0 6px rgba(34, 197, 94, 0); }
}

@keyframes pulse-warning {
    0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.3); }
    50% { box-shadow: 0 0 0 6px rgba(239, 68, 68, 0); }
}

/* Enhanced hover effects */
.group:hover .group-hover\:scale-110 {
    transform: scale(1.1);
}

/* Better mobile spacing */
@media (max-width: 1024px) {
    .grid.grid-cols-1.lg\:grid-cols-3 {
        gap: 1.5rem;
    }
    
    .sticky {
        position: static;
    }
}

/* Smooth scrolling for anchor links */
html {
    scroll-behavior: smooth;
}

/* Enhanced focus states */
button:focus, a:focus {
    outline: 2px solid #3b82f6;
    outline-offset: 2px;
}

/* Loading states */
.loading {
    opacity: 0.6;
    pointer-events: none;
}

/* Improved table styling */
table {
    border-collapse: separate;
    border-spacing: 0;
}

/* Custom scrollbar for webkit browsers */
::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}

::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 3px;
}

::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
}

::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* Better mobile touch targets */
@media (max-width: 768px) {
    button, a {
        min-height: 44px;
        min-width: 44px;
    }
}
</style>

<script>
// Enhanced JavaScript functionality
document.addEventListener('DOMContentLoaded', function() {
    initializeInvoiceView();
});

function initializeInvoiceView() {
    // Add status-specific styling
    addStatusStyling();
    
    // Initialize keyboard shortcuts
    initializeKeyboardShortcuts();
    
    // Add smooth hover effects
    addHoverEffects();
    
    // Initialize intersection observer for animations
    initializeAnimations();
}

function addStatusStyling() {
    const statusBadge = document.querySelector('[class*="bg-"][class*="border-"]');
    const status = '<?php echo $invoice['status']; ?>';
    
    if (statusBadge) {
        statusBadge.classList.add(`status-${status.replace('_', '-')}`);
    }
}

function initializeKeyboardShortcuts() {
    document.addEventListener('keydown', function(e) {
        // Prevent shortcuts when typing in inputs
        if (e.target.matches('input, textarea')) return;
        
        switch(e.key.toLowerCase()) {
            case 'p':
                e.preventDefault();
                previewInvoice();
                break;
            case 'e':
                e.preventDefault();
                window.location.href = '<?php echo Helper::baseUrl('modules/invoices/edit.php?id=' . Helper::encryptId($invoice['id'])); ?>';
                break;
            <?php if ($invoice['balance_amount'] > 0): ?>
            case 'r':
                e.preventDefault();
                window.location.href = '<?php echo Helper::baseUrl('modules/payments/create.php?invoice_id=' . Helper::encryptId($invoice['id'])); ?>';
                break;
            <?php endif; ?>
            case 'b':
                e.preventDefault();
                window.location.href = '<?php echo Helper::baseUrl('modules/invoices/'); ?>';
                break;
        }
    });
}

function addHoverEffects() {
    // Add subtle lift effect to cards
    document.querySelectorAll('.bg-white.rounded-2xl').forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
            this.style.boxShadow = '0 10px 25px rgba(0, 0, 0, 0.1)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = '';
            this.style.boxShadow = '';
        });
    });
}

function initializeAnimations() {
    // Fade in animation for cards
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, { threshold: 0.1 });

    document.querySelectorAll('.bg-white.rounded-2xl').forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        card.style.transition = `all 0.6s ease-out ${index * 0.1}s`;
        observer.observe(card);
    });
}

// Preview Invoice functionality
function previewInvoice() {
    // Redirect to preview_invoice.php with the invoice ID
    window.location.href = '<?php echo Helper::baseUrl('modules/invoices/preview_invoice.php?id=' . Helper::encryptId($invoice['id'])); ?>';
}

// Enhanced email functionality
function emailInvoice() {
    showNotification('Email functionality would integrate with your email service provider', 'info');
}

// Enhanced share functionality
function shareInvoice() {
    if (navigator.share) {
        navigator.share({
            title: 'Invoice #<?php echo $invoice['invoice_number']; ?>',
            text: 'Invoice for <?php echo addslashes($invoice['company_name']); ?> - <?php echo Helper::formatCurrency($invoice['total_amount']); ?>',
            url: window.location.href
        }).catch(console.error);
    } else {
        navigator.clipboard.writeText(window.location.href).then(() => {
            showNotification('Invoice link copied to clipboard!', 'success');
        }).catch(() => {
            showNotification('Unable to copy link. Please copy the URL manually.', 'error');
        });
    }
}

// Enhanced notification system
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    const colors = {
        success: 'bg-green-500',
        error: 'bg-red-500',
        info: 'bg-blue-500',
        warning: 'bg-yellow-500'
    };
    
    notification.className = `fixed top-4 right-4 ${colors[type]} text-white px-6 py-3 rounded-xl shadow-lg z-50 transform translate-x-full transition-transform duration-300`;
    notification.textContent = message;
    
    document.body.appendChild(notification);
    
    // Slide in
    setTimeout(() => {
        notification.classList.remove('translate-x-full');
    }, 100);
    
    // Slide out and remove
    setTimeout(() => {
        notification.classList.add('translate-x-full');
        setTimeout(() => {
            document.body.removeChild(notification);
        }, 300);
    }, 3000);
}

// Auto-save scroll position
window.addEventListener('beforeunload', () => {
    sessionStorage.setItem('invoiceScrollPosition', window.scrollY);
});

window.addEventListener('load', () => {
    const scrollPosition = sessionStorage.getItem('invoiceScrollPosition');
    if (scrollPosition) {
        window.scrollTo(0, parseInt(scrollPosition));
        sessionStorage.removeItem('invoiceScrollPosition');
    }
});

// Enhanced responsive behavior
function handleResize() {
    if (window.innerWidth >= 1024) {
        // Desktop behavior
        document.querySelectorAll('.sticky').forEach(el => {
            el.style.position = 'sticky';
        });
    } else {
        // Mobile behavior
        document.querySelectorAll('.sticky').forEach(el => {
            el.style.position = 'static';
        });
    }
}

window.addEventListener('resize', handleResize);
handleResize();

// Performance: Lazy load payment history if many payments
if (document.querySelectorAll('.payment-item').length > 10) {
    // Implement virtual scrolling or pagination for large payment lists
    console.log('Consider implementing pagination for payment history');
}
</script>

<?php include '../../includes/footer.php'; ?>