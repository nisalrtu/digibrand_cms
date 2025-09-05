<?php
// modules/quotations/view.php
session_start();

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

// Get and validate quotation ID
$quotationId = Helper::decryptId($_GET['id'] ?? '');
if (!$quotationId) {
    Helper::setMessage('Invalid quotation ID.', 'error');
    Helper::redirect('modules/quotations/');
}

$pageTitle = 'Quotation Details - Invoice Manager';

// Get database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Get quotation details with client information
    $quotationQuery = "
        SELECT q.*, 
               c.company_name, c.contact_person, c.mobile_number, c.address, c.city,
               u.username as created_by_name
        FROM quotations q 
        LEFT JOIN clients c ON q.client_id = c.id 
        LEFT JOIN users u ON q.created_by = u.id
        WHERE q.id = :quotation_id
    ";
    $quotationStmt = $db->prepare($quotationQuery);
    $quotationStmt->bindParam(':quotation_id', $quotationId);
    $quotationStmt->execute();
    
    $quotation = $quotationStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$quotation) {
        Helper::setMessage('Quotation not found.', 'error');
        Helper::redirect('modules/quotations/');
    }
    
    // Get quotation items
    $quotationItems = [];
    try {
        $itemsQuery = "
            SELECT * FROM quotation_items 
            WHERE quotation_id = :quotation_id 
            ORDER BY id ASC
        ";
        $itemsStmt = $db->prepare($itemsQuery);
        $itemsStmt->bindParam(':quotation_id', $quotationId);
        $itemsStmt->execute();
        $quotationItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // quotation_items table might not exist yet
        $quotationItems = [];
    }
    
    // Get related activities/history
    $activities = [];
    try {
        $activitiesQuery = "
            SELECT qa.*, u.username as performed_by_name
            FROM quotation_activities qa 
            LEFT JOIN users u ON qa.performed_by = u.id
            WHERE qa.quotation_id = :quotation_id 
            ORDER BY qa.activity_date DESC
        ";
        $activitiesStmt = $db->prepare($activitiesQuery);
        $activitiesStmt->bindParam(':quotation_id', $quotationId);
        $activitiesStmt->execute();
        $activities = $activitiesStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // quotation_activities table might not exist yet
        $activities = [];
    }
    
    // Check if quotation has been converted to invoice
    $relatedInvoice = null;
    try {
        // First check if quotation_id column exists in invoices table
        $checkColumnQuery = "SHOW COLUMNS FROM invoices LIKE 'quotation_id'";
        $checkColumnStmt = $db->prepare($checkColumnQuery);
        $checkColumnStmt->execute();
        $columnExists = $checkColumnStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($columnExists) {
            $relatedInvoiceQuery = "
                SELECT id, invoice_number, status, total_amount 
                FROM invoices 
                WHERE quotation_id = :quotation_id
            ";
            $relatedInvoiceStmt = $db->prepare($relatedInvoiceQuery);
            $relatedInvoiceStmt->bindParam(':quotation_id', $quotationId);
            $relatedInvoiceStmt->execute();
            $relatedInvoice = $relatedInvoiceStmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        // quotation_id column doesn't exist in invoices table yet
        $relatedInvoice = null;
    }
    
} catch (Exception $e) {
    Helper::setMessage('Error loading quotation details: ' . $e->getMessage(), 'error');
    Helper::redirect('modules/quotations/');
}

// Check if quotation is expired
$isExpired = strtotime($quotation['expiry_date']) < time();
$daysUntilExpiry = ceil((strtotime($quotation['expiry_date']) - time()) / (60 * 60 * 24));

include '../../includes/header.php';
?>

<!-- Breadcrumb Navigation -->
<nav class="mb-6">
    <div class="flex items-center space-x-2 text-sm">
        <a href="<?php echo Helper::baseUrl('modules/quotations/'); ?>" 
           class="text-gray-500 hover:text-gray-700 transition-colors font-medium">
            Quotations
        </a>
        <svg class="w-4 h-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5l7 7-7 7"></path>
        </svg>
        <span class="text-gray-900 font-semibold">#<?php echo htmlspecialchars($quotation['quotation_number']); ?></span>
    </div>
</nav>

<!-- Page Header -->
<div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-6">
    <div class="flex-1">
        <div class="flex items-center gap-3 mb-2">
            <h1 class="text-3xl font-bold text-gray-900">Quotation #<?php echo htmlspecialchars($quotation['quotation_number']); ?></h1>
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium
                <?php 
                switch ($quotation['status']) {
                    case 'draft':
                        echo 'bg-gray-100 text-gray-800';
                        break;
                    case 'sent':
                        echo 'bg-blue-100 text-blue-800';
                        break;
                    case 'approved':
                        echo 'bg-green-100 text-green-800';
                        break;
                    case 'rejected':
                        echo 'bg-red-100 text-red-800';
                        break;
                    case 'expired':
                        echo 'bg-yellow-100 text-yellow-800';
                        break;
                    default:
                        echo 'bg-gray-100 text-gray-800';
                }
                ?>">
                <?php echo ucwords(str_replace('_', ' ', $quotation['status'])); ?>
            </span>
            <?php if ($isExpired && $quotation['status'] !== 'approved' && $quotation['status'] !== 'rejected'): ?>
                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">
                    Expired
                </span>
            <?php elseif ($daysUntilExpiry <= 7 && $daysUntilExpiry > 0 && $quotation['status'] === 'sent'): ?>
                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                    Expires in <?php echo $daysUntilExpiry; ?> day<?php echo $daysUntilExpiry !== 1 ? 's' : ''; ?>
                </span>
            <?php endif; ?>
        </div>
        
        <div class="text-gray-600 space-y-1">
            <p>Client: <span class="font-medium text-gray-900"><?php echo htmlspecialchars($quotation['company_name']); ?></span></p>
            <p>Created: <?php echo Helper::formatDate($quotation['quotation_date'], 'M j, Y'); ?> • 
               Expires: <?php echo Helper::formatDate($quotation['expiry_date'], 'M j, Y'); ?></p>
        </div>
    </div>
    
    <!-- Actions -->
    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 w-full sm:w-auto">
        <?php if ($quotation['status'] === 'draft' || $quotation['status'] === 'sent'): ?>
            <a href="<?php echo Helper::baseUrl('modules/quotations/edit.php?id=' . Helper::encryptId($quotation['id'])); ?>" 
               class="inline-flex items-center justify-center px-4 py-2 bg-white text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                </svg>
                Edit Quotation
            </a>
        <?php endif; ?>
        
        <?php if ($quotation['status'] === 'approved' && !$relatedInvoice): ?>
            <a href="<?php echo Helper::baseUrl('modules/invoices/create.php?quotation_id=' . Helper::encryptId($quotation['id'])); ?>" 
               class="inline-flex items-center justify-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Convert to Invoice
            </a>
        <?php endif; ?>
        
        <a href="<?php echo Helper::baseUrl('modules/quotations/preview_quotation.php?id=' . Helper::encryptId($quotation['id'])); ?>" 
           target="_blank"
           class="inline-flex items-center justify-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-900 transition-colors">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
            </svg>
            Preview
        </a>
        
        <a href="<?php echo Helper::baseUrl('modules/quotations/download_pdf.php?id=' . Helper::encryptId($quotation['id'])); ?>" 
           class="inline-flex items-center justify-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-900 transition-colors">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            Download PDF
        </a>
    </div>
</div>

<!-- Related Invoice Alert -->
<?php if ($relatedInvoice): ?>
    <div class="bg-green-50 border border-green-200 rounded-xl p-4 mb-6">
        <div class="flex items-center">
            <svg class="w-5 h-5 text-green-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <div>
                <p class="text-sm font-medium text-green-800">
                    This quotation has been converted to Invoice 
                    <a href="<?php echo Helper::baseUrl('modules/invoices/view.php?id=' . Helper::encryptId($relatedInvoice['id'])); ?>" 
                       class="underline hover:no-underline">#<?php echo htmlspecialchars($relatedInvoice['invoice_number']); ?></a>
                </p>
                <p class="text-xs text-green-600 mt-1">
                    Invoice Status: <?php echo ucwords(str_replace('_', ' ', $relatedInvoice['status'])); ?> • 
                    Amount: <?php echo Helper::formatCurrency($relatedInvoice['total_amount']); ?>
                </p>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Expiry Warning -->
<?php if ($isExpired && $quotation['status'] !== 'approved' && $quotation['status'] !== 'rejected'): ?>
    <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6">
        <div class="flex items-center">
            <svg class="w-5 h-5 text-red-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
            </svg>
            <div>
                <p class="text-sm font-medium text-red-800">This quotation has expired</p>
                <p class="text-xs text-red-600 mt-1">
                    Expired on <?php echo Helper::formatDate($quotation['expiry_date'], 'M j, Y'); ?>
                </p>
            </div>
        </div>
    </div>
<?php elseif ($daysUntilExpiry <= 7 && $daysUntilExpiry > 0 && $quotation['status'] === 'sent'): ?>
    <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 mb-6">
        <div class="flex items-center">
            <svg class="w-5 h-5 text-yellow-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <div>
                <p class="text-sm font-medium text-yellow-800">
                    This quotation expires in <?php echo $daysUntilExpiry; ?> day<?php echo $daysUntilExpiry !== 1 ? 's' : ''; ?>
                </p>
                <p class="text-xs text-yellow-600 mt-1">
                    Expires on <?php echo Helper::formatDate($quotation['expiry_date'], 'M j, Y'); ?>
                </p>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Main Content -->
    <div class="lg:col-span-2 flex flex-col">
        <!-- Quotation Details Card -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6 order-2 lg:order-1">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900">Quotation Details</h2>
            </div>
            
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <!-- Basic Info -->
                    <div>
                        <h3 class="text-sm font-medium text-gray-900 mb-3">Quotation Information</h3>
                        <div class="space-y-2">
                            <div class="flex justify-between">
                                <span class="text-sm text-gray-600">Quotation #:</span>
                                <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($quotation['quotation_number']); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-sm text-gray-600">Date:</span>
                                <span class="text-sm font-medium text-gray-900"><?php echo Helper::formatDate($quotation['quotation_date'], 'M j, Y'); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-sm text-gray-600">Valid Until:</span>
                                <span class="text-sm font-medium text-gray-900"><?php echo Helper::formatDate($quotation['expiry_date'], 'M j, Y'); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-sm text-gray-600">Created By:</span>
                                <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($quotation['created_by_name'] ?? 'Unknown'); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Client Info -->
                    <div>
                        <h3 class="text-sm font-medium text-gray-900 mb-3">Client Information</h3>
                        <div class="space-y-2">
                            <div>
                                <span class="text-sm text-gray-600">Company:</span>
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($quotation['company_name']); ?></div>
                            </div>
                            <div>
                                <span class="text-sm text-gray-600">Contact Person:</span>
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($quotation['contact_person']); ?></div>
                            </div>
                            <?php if (!empty($quotation['mobile_number'])): ?>
                                <div>
                                    <span class="text-sm text-gray-600">Phone:</span>
                                    <div class="text-sm font-medium text-gray-900">
                                        <a href="tel:<?php echo htmlspecialchars($quotation['mobile_number']); ?>" 
                                           class="text-blue-600 hover:text-blue-700">
                                            <?php echo htmlspecialchars($quotation['mobile_number']); ?>
                                        </a>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($quotation['address'])): ?>
                                <div>
                                    <span class="text-sm text-gray-600">Address:</span>
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($quotation['address']); ?>
                                        <?php if (!empty($quotation['city'])): ?>
                                            <br><?php echo htmlspecialchars($quotation['city']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Notes -->
                <?php if (!empty($quotation['notes'])): ?>
                    <div class="border-t border-gray-200 pt-4">
                        <h3 class="text-sm font-medium text-gray-900 mb-2">Notes</h3>
                        <div class="text-sm text-gray-600 bg-gray-50 rounded-lg p-3">
                            <?php echo nl2br(htmlspecialchars($quotation['notes'])); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Terms & Conditions -->
                <?php if (!empty($quotation['terms_conditions'])): ?>
                    <div class="border-t border-gray-200 pt-4 mt-4">
                        <h3 class="text-sm font-medium text-gray-900 mb-2">Terms & Conditions</h3>
                        <div class="text-sm text-gray-600 bg-gray-50 rounded-lg p-3">
                            <?php echo nl2br(htmlspecialchars($quotation['terms_conditions'])); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quotation Items (Mobile First Order) -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6 order-1 lg:order-2">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900">Items</h2>
            </div>
            
            <?php if (empty($quotationItems)): ?>
                <div class="p-6 text-center">
                    <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                    </svg>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No items found</h3>
                    <p class="text-gray-600">This quotation doesn't have any line items yet.</p>
                </div>
            <?php else: ?>
                <!-- Desktop Table View -->
                <div class="hidden md:block overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Unit Price</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($quotationItems as $item): ?>
                                <tr>
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                        <?php if (!empty($item['description'])): ?>
                                            <div class="text-sm text-gray-500 mt-1"><?php echo htmlspecialchars($item['description']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                            <?php echo ucwords(str_replace('_', ' ', $item['category'])); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-right text-sm text-gray-900">
                                        <?php echo number_format($item['quantity'], 2); ?>
                                    </td>
                                    <td class="px-6 py-4 text-right text-sm text-gray-900">
                                        <?php echo Helper::formatCurrency($item['unit_price']); ?>
                                    </td>
                                    <td class="px-6 py-4 text-right text-sm font-medium text-gray-900">
                                        <?php echo Helper::formatCurrency($item['total_price']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Mobile Card View -->
                <div class="md:hidden divide-y divide-gray-200">
                    <?php foreach ($quotationItems as $item): ?>
                        <div class="p-4">
                            <div class="flex justify-between items-start mb-2">
                                <div class="flex-1">
                                    <h4 class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['item_name']); ?></h4>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 mt-1">
                                        <?php echo ucwords(str_replace('_', ' ', $item['category'])); ?>
                                    </span>
                                </div>
                                <div class="text-right ml-4">
                                    <div class="text-sm font-medium text-gray-900"><?php echo Helper::formatCurrency($item['total_price']); ?></div>
                                    <div class="text-xs text-gray-500">
                                        <?php echo number_format($item['quantity'], 2); ?> × <?php echo Helper::formatCurrency($item['unit_price']); ?>
                                    </div>
                                </div>
                            </div>
                            <?php if (!empty($item['description'])): ?>
                                <p class="text-sm text-gray-600 mt-2"><?php echo htmlspecialchars($item['description']); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Totals Section -->
                <div class="border-t border-gray-200 bg-gray-50 px-6 py-4">
                    <div class="flex justify-end">
                        <div class="w-64">
                            <div class="flex justify-between py-2">
                                <span class="text-sm text-gray-600">Subtotal:</span>
                                <span class="text-sm font-medium text-gray-900"><?php echo Helper::formatCurrency($quotation['subtotal']); ?></span>
                            </div>
                            <?php if ($quotation['tax_rate'] > 0): ?>
                                <div class="flex justify-between py-2">
                                    <span class="text-sm text-gray-600">Tax (<?php echo number_format($quotation['tax_rate'], 1); ?>%):</span>
                                    <span class="text-sm font-medium text-gray-900"><?php echo Helper::formatCurrency($quotation['tax_amount']); ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="flex justify-between py-2 border-t border-gray-200">
                                <span class="text-base font-semibold text-gray-900">Total:</span>
                                <span class="text-base font-bold text-gray-900"><?php echo Helper::formatCurrency($quotation['total_amount']); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Activity Timeline -->
        <?php if (!empty($activities)): ?>
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm order-3">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-900">Activity Timeline</h2>
                </div>
                
                <div class="p-6">
                    <div class="flow-root">
                        <ul class="-mb-8">
                            <?php foreach ($activities as $index => $activity): ?>
                                <li>
                                    <div class="relative pb-8">
                                        <?php if ($index < count($activities) - 1): ?>
                                            <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
                                        <?php endif; ?>
                                        <div class="relative flex space-x-3">
                                            <div>
                                                <span class="h-8 w-8 rounded-full flex items-center justify-center ring-8 ring-white
                                                    <?php 
                                                    switch ($activity['activity_type']) {
                                                        case 'created':
                                                            echo 'bg-gray-400';
                                                            break;
                                                        case 'updated':
                                                            echo 'bg-blue-400';
                                                            break;
                                                        case 'sent':
                                                            echo 'bg-green-400';
                                                            break;
                                                        case 'viewed':
                                                            echo 'bg-purple-400';
                                                            break;
                                                        case 'approved':
                                                            echo 'bg-green-600';
                                                            break;
                                                        case 'rejected':
                                                            echo 'bg-red-600';
                                                            break;
                                                        case 'expired':
                                                            echo 'bg-yellow-600';
                                                            break;
                                                        default:
                                                            echo 'bg-gray-400';
                                                    }
                                                    ?>">
                                                    <?php 
                                                    switch ($activity['activity_type']) {
                                                        case 'created':
                                                            echo '<svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>';
                                                            break;
                                                        case 'updated':
                                                            echo '<svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>';
                                                            break;
                                                        case 'sent':
                                                            echo '<svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path></svg>';
                                                            break;
                                                        case 'viewed':
                                                            echo '<svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>';
                                                            break;
                                                        case 'approved':
                                                            echo '<svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';
                                                            break;
                                                        case 'rejected':
                                                            echo '<svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>';
                                                            break;
                                                        case 'expired':
                                                            echo '<svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';
                                                            break;
                                                        default:
                                                            echo '<svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';
                                                    }
                                                    ?>
                                                </span>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div>
                                                    <div class="text-sm">
                                                        <span class="font-medium text-gray-900">
                                                            <?php echo ucwords(str_replace('_', ' ', $activity['activity_type'])); ?>
                                                        </span>
                                                        <?php if (!empty($activity['performed_by_name'])): ?>
                                                            <span class="text-gray-600">by <?php echo htmlspecialchars($activity['performed_by_name']); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="mt-1 text-sm text-gray-600">
                                                        <time datetime="<?php echo $activity['activity_date']; ?>">
                                                            <?php echo Helper::formatDate($activity['activity_date'], 'M j, Y \a\t g:i A'); ?>
                                                        </time>
                                                    </div>
                                                </div>
                                                <?php if (!empty($activity['activity_description'])): ?>
                                                    <div class="mt-2 text-sm text-gray-700">
                                                        <?php echo htmlspecialchars($activity['activity_description']); ?>
                                                    </div>
                                                <?php endif; ?>
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
                <h3 class="text-lg font-semibold text-gray-900">Quick Stats</h3>
            </div>
            <div class="p-6 space-y-4">
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-600">Total Items:</span>
                    <span class="text-sm font-medium text-gray-900"><?php echo count($quotationItems); ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-600">Subtotal:</span>
                    <span class="text-sm font-medium text-gray-900"><?php echo Helper::formatCurrency($quotation['subtotal']); ?></span>
                </div>
                <?php if ($quotation['tax_rate'] > 0): ?>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">Tax Rate:</span>
                        <span class="text-sm font-medium text-gray-900"><?php echo number_format($quotation['tax_rate'], 1); ?>%</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">Tax Amount:</span>
                        <span class="text-sm font-medium text-gray-900"><?php echo Helper::formatCurrency($quotation['tax_amount']); ?></span>
                    </div>
                <?php endif; ?>
                <div class="flex justify-between items-center pt-2 border-t border-gray-200">
                    <span class="text-sm font-semibold text-gray-900">Total Amount:</span>
                    <span class="text-sm font-bold text-gray-900"><?php echo Helper::formatCurrency($quotation['total_amount']); ?></span>
                </div>
                
                <div class="pt-2 border-t border-gray-200">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm text-gray-600">Days to Expiry:</span>
                        <span class="text-sm font-medium <?php echo $isExpired ? 'text-red-600' : ($daysUntilExpiry <= 7 ? 'text-yellow-600' : 'text-gray-900'); ?>">
                            <?php 
                            if ($isExpired) {
                                echo 'Expired';
                            } elseif ($daysUntilExpiry == 0) {
                                echo 'Expires today';
                            } else {
                                echo $daysUntilExpiry . ' day' . ($daysUntilExpiry !== 1 ? 's' : '');
                            }
                            ?>
                        </span>
                    </div>
                    <?php if (!$isExpired && $daysUntilExpiry <= 30): ?>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="bg-<?php echo $daysUntilExpiry <= 7 ? 'red' : ($daysUntilExpiry <= 14 ? 'yellow' : 'green'); ?>-500 h-2 rounded-full" 
                                 style="width: <?php echo min(100, ($daysUntilExpiry / 30) * 100); ?>%"></div>
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
                <?php if ($quotation['status'] === 'draft'): ?>
                    <button onclick="updateQuotationStatus('sent')" 
                            class="w-full inline-flex items-center justify-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                        </svg>
                        Mark as Sent
                    </button>
                <?php endif; ?>
                
                <?php if ($quotation['status'] === 'sent' && !$isExpired): ?>
                    <button onclick="updateQuotationStatus('approved')" 
                            class="w-full inline-flex items-center justify-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        Mark as Approved
                    </button>
                    
                    <button onclick="updateQuotationStatus('rejected')" 
                            class="w-full inline-flex items-center justify-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                        Mark as Rejected
                    </button>
                <?php endif; ?>
                
                <a href="<?php echo Helper::baseUrl('modules/quotations/duplicate.php?id=' . Helper::encryptId($quotation['id'])); ?>" 
                   class="w-full inline-flex items-center justify-center px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                    </svg>
                    Duplicate Quotation
                </a>
                
                <a href="<?php echo Helper::baseUrl('modules/clients/view.php?id=' . Helper::encryptId($quotation['client_id'])); ?>" 
                   class="w-full inline-flex items-center justify-center px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                    </svg>
                    View Client
                </a>
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
                        <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($quotation['company_name']); ?></p>
                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($quotation['contact_person']); ?></p>
                    </div>
                    
                    <?php if (!empty($quotation['mobile_number'])): ?>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Phone</p>
                            <a href="tel:<?php echo htmlspecialchars($quotation['mobile_number']); ?>" 
                               class="text-sm text-blue-600 hover:text-blue-700">
                                <?php echo htmlspecialchars($quotation['mobile_number']); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($quotation['address'])): ?>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Address</p>
                            <p class="text-sm text-gray-700">
                                <?php echo htmlspecialchars($quotation['address']); ?>
                                <?php if (!empty($quotation['city'])): ?>
                                    <br><?php echo htmlspecialchars($quotation['city']); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Status Update Modal -->
<div id="statusModal" class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50 hidden">
    <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Update Quotation Status</h3>
        <p class="text-sm text-gray-600 mb-6" id="statusConfirmText"></p>
        
        <div class="flex space-x-3">
            <button onclick="confirmStatusUpdate()" 
                    class="flex-1 inline-flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                Confirm
            </button>
            <button onclick="closeStatusModal()" 
                    class="flex-1 inline-flex justify-center py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                Cancel
            </button>
        </div>
    </div>
</div>

<script>
let pendingStatusUpdate = null;

function updateQuotationStatus(newStatus) {
    pendingStatusUpdate = newStatus;
    
    const statusTexts = {
        'sent': 'Are you sure you want to mark this quotation as sent?',
        'approved': 'Are you sure you want to mark this quotation as approved?',
        'rejected': 'Are you sure you want to mark this quotation as rejected?'
    };
    
    document.getElementById('statusConfirmText').textContent = statusTexts[newStatus] || 'Are you sure you want to update this quotation status?';
    document.getElementById('statusModal').classList.remove('hidden');
}

function confirmStatusUpdate() {
    if (!pendingStatusUpdate) return;
    
    // Show loading state
    const confirmBtn = document.querySelector('#statusModal button[onclick="confirmStatusUpdate()"]');
    const originalText = confirmBtn.textContent;
    confirmBtn.textContent = 'Updating...';
    confirmBtn.disabled = true;
    
    // Create form and submit
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?php echo Helper::baseUrl('modules/quotations/update_status.php'); ?>';
    
    const quotationIdInput = document.createElement('input');
    quotationIdInput.type = 'hidden';
    quotationIdInput.name = 'quotation_id';
    quotationIdInput.value = '<?php echo Helper::encryptId($quotation['id']); ?>';
    
    const statusInput = document.createElement('input');
    statusInput.type = 'hidden';
    statusInput.name = 'status';
    statusInput.value = pendingStatusUpdate;
    
    form.appendChild(quotationIdInput);
    form.appendChild(statusInput);
    document.body.appendChild(form);
    form.submit();
}

function closeStatusModal() {
    document.getElementById('statusModal').classList.add('hidden');
    pendingStatusUpdate = null;
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.target.matches('input, textarea, select')) return;
    
    switch(e.key.toLowerCase()) {
        case 'e':
            if (!e.ctrlKey && !e.metaKey) {
                e.preventDefault();
                <?php if ($quotation['status'] === 'draft' || $quotation['status'] === 'sent'): ?>
                    window.location.href = '<?php echo Helper::baseUrl('modules/quotations/edit.php?id=' . Helper::encryptId($quotation['id'])); ?>';
                <?php endif; ?>
            }
            break;
        case 'p':
            if (!e.ctrlKey && !e.metaKey) {
                e.preventDefault();
                window.open('<?php echo Helper::baseUrl('modules/quotations/preview.php?id=' . Helper::encryptId($quotation['id'])); ?>', '_blank');
            }
            break;
        case 'd':
            if (!e.ctrlKey && !e.metaKey) {
                e.preventDefault();
                window.location.href = '<?php echo Helper::baseUrl('modules/quotations/download_pdf.php?id=' . Helper::encryptId($quotation['id'])); ?>';
            }
            break;
        case 'escape':
            closeStatusModal();
            break;
    }
});

// Auto-update expired status
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($quotation['status'] === 'sent' && $isExpired): ?>
        // Automatically mark as expired if past expiry date
        setTimeout(() => {
            updateQuotationStatus('expired');
        }, 2000);
    <?php endif; ?>
    
    // Add tooltips for action buttons
    const actionButtons = document.querySelectorAll('[title]');
    actionButtons.forEach(button => {
        button.addEventListener('mouseenter', function() {
            // Add tooltip functionality if needed
        });
    });
    
    // Smooth scroll to sections
    const sectionLinks = document.querySelectorAll('a[href^="#"]');
    sectionLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });
    
    // Print functionality
    window.addEventListener('beforeprint', function() {
        document.title = 'Quotation #<?php echo htmlspecialchars($quotation['quotation_number']); ?> - <?php echo htmlspecialchars($quotation['company_name']); ?>';
    });
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
    .no-print, nav, .fixed, #statusModal {
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

/* Loading animation */
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.loading::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 16px;
    height: 16px;
    margin: -8px 0 0 -8px;
    border: 2px solid transparent;
    border-top: 2px solid #3b82f6;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

/* Status-specific animations */
.status-expired {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.8; }
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