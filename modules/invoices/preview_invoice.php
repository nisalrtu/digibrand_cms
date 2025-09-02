<?php
// modules/invoices/preview_invoice.php
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

// Get database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Get company settings
    $companyQuery = "SELECT * FROM company_settings ORDER BY id DESC LIMIT 1";
    $companyStmt = $db->prepare($companyQuery);
    $companyStmt->execute();
    $companySettings = $companyStmt->fetch(PDO::FETCH_ASSOC);
    
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
    
    // Get invoice items
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
        $invoiceItems = [];
    }
    
    // Get related payments
    $relatedPayments = [];
    try {
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
        $relatedPayments = [];
    }
    
} catch (Exception $e) {
    Helper::setMessage('Error loading invoice: ' . $e->getMessage(), 'error');
    Helper::redirect('modules/invoices/');
}

// Set default company info if not found in database
if (!$companySettings) {
    $companySettings = [
        'company_name' => 'Your Company Name',
        'email' => 'info@yourcompany.com',
        'mobile_number_1' => '+94 77 123 4567',
        'mobile_number_2' => '+94 11 234 5678',
        'address' => '123 Business Street, Business District',
        'city' => 'Colombo',
        'postal_code' => '00100',
        'bank_name' => 'Commercial Bank of Ceylon',
        'bank_branch' => 'Colombo 03',
        'bank_account_number' => '1234567890',
        'bank_account_name' => 'Nisal Sudharaka Weerarathne',
        'tax_number' => 'TIN123456789',
        'website' => 'www.yourcompany.com',
        'logo_path' => 'assets/uploads/logo.png'
    ];
}

$pageTitle = 'Invoice Preview - ' . $invoice['invoice_number'];

// Calculate totals
$subtotal = 0;
foreach ($invoiceItems as $item) {
    $subtotal += ($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0);
}

$taxAmount = $subtotal * (($invoice['tax_percentage'] ?? 0) / 100);
$discountAmount = $subtotal * (($invoice['discount_percentage'] ?? 0) / 100);
$total = $subtotal + $taxAmount - $discountAmount;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <!-- Header with back button and print button -->
    <div class="no-print bg-white shadow-sm border-b sticky top-0 z-10">
        <div class="max-w-4xl mx-auto px-4 py-2 flex justify-between items-center">
            <a href="index.php" class="inline-flex items-center text-sm text-blue-600 hover:text-blue-800 transition-colors">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Back to Invoices
            </a>
            <div class="flex space-x-2">
                <a href="download_pdf.php?id=<?php echo $_GET['id']; ?>" class="bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded text-sm transition-colors inline-flex items-center">
                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Download PDF
                </a>
                <button onclick="window.print()" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded text-sm transition-colors inline-flex items-center">
                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                    </svg>
                    Print Invoice
                </button>
            </div>
        </div>
    </div>

    <!-- Invoice Content -->
    <div class="max-w-4xl mx-auto p-4">
        <div class="bg-white shadow-lg rounded-lg overflow-hidden">
            <!-- Invoice Header -->
            <div class="p-5 border-b border-gray-200">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Company Logo and Info -->
                    <div>
                        <div class="mb-3">
                            <img src="../../<?php echo htmlspecialchars($companySettings['logo_path']); ?>" 
                                 alt="Company Logo" 
                                 class="h-12 w-auto object-contain">
                        </div>
                        <div class="space-y-0.5">
                            <h2 class="text-lg font-bold text-gray-900">
                                <?php echo htmlspecialchars($companySettings['company_name']); ?>
                            </h2>
                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($companySettings['address']); ?></p>
                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($companySettings['city'] . ' ' . ($companySettings['postal_code'] ?? '')); ?></p>
                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($companySettings['email']); ?></p>
                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($companySettings['mobile_number_1']); ?></p>
                            <?php if (!empty($companySettings['website'])): ?>
                                <p class="text-sm text-gray-600"><?php echo htmlspecialchars($companySettings['website']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Invoice Details -->
                    <div class="text-right">
                        <h1 class="text-2xl font-bold text-gray-900 mb-4">INVOICE</h1>
                        <?php if (!empty($invoice['project_name'])): ?>
                            <p class="font-semibold text-sm text-gray-900 mb-3"><?php echo htmlspecialchars($invoice['project_name']); ?></p>
                        <?php endif; ?>
                        <div class="space-y-1">
                            <div class="grid grid-cols-2 gap-2 text-sm">
                                <span class="text-gray-600 font-medium">Invoice #:</span>
                                <span class="text-gray-900 font-semibold"><?php echo htmlspecialchars($invoice['invoice_number']); ?></span>
                            </div>
                            <div class="grid grid-cols-2 gap-2 text-sm">
                                <span class="text-gray-600 font-medium">Date:</span>
                                <span class="text-gray-900"><?php echo date('M d, Y', strtotime($invoice['invoice_date'])); ?></span>
                            </div>
                            <div class="grid grid-cols-2 gap-2 text-sm">
                                <span class="text-gray-600 font-medium">Due Date:</span>
                                <span class="text-gray-900"><?php echo date('M d, Y', strtotime($invoice['due_date'])); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Client and Project Information -->
            <div class="p-5 border-b border-gray-200">
                <div class="grid grid-cols-1 md:grid-cols-1 gap-6">
                    <!-- Bill To -->
                    <div>
                        <h3 class="text-base font-semibold text-gray-900 mb-2">Bill To:</h3>
                        <div class="space-y-0.5">
                            <p class="font-semibold text-sm text-gray-900"><?php echo htmlspecialchars($invoice['company_name']); ?></p>
                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($invoice['contact_person']); ?></p>
                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($invoice['address']); ?></p>
                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($invoice['city']); ?></p>
                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($invoice['mobile_number']); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Invoice Items -->
            <div class="p-5">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b-2 border-gray-200">
                                <th class="text-left py-2 px-1 font-semibold text-sm text-gray-900">Description</th>
                                <th class="text-center py-2 px-1 font-semibold text-sm text-gray-900 w-16">Qty</th>
                                <th class="text-right py-2 px-1 font-semibold text-sm text-gray-900 w-28">Rate</th>
                                <th class="text-right py-2 px-1 font-semibold text-sm text-gray-900 w-32">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($invoiceItems)): ?>
                                <?php foreach ($invoiceItems as $item): ?>
                                    <tr class="border-b border-gray-100">
                                        <td class="py-2 px-1">
                                            <div class="font-medium text-sm text-gray-900"><?php echo htmlspecialchars($item['description'] ?? ''); ?></div>
                                            <?php if (!empty($item['details'])): ?>
                                                <div class="text-xs text-gray-600 mt-0.5"><?php echo htmlspecialchars($item['details']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-2 px-1 text-center text-sm text-gray-900 whitespace-nowrap"><?php echo number_format($item['quantity'] ?? 0); ?></td>
                                        <td class="py-2 px-1 text-right text-sm text-gray-900 whitespace-nowrap">LKR <?php echo number_format($item['unit_price'] ?? 0, 2); ?></td>
                                        <td class="py-2 px-1 text-right font-medium text-sm text-gray-900 whitespace-nowrap">LKR <?php echo number_format(($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0), 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="py-6 text-center text-sm text-gray-500">No items found for this invoice.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Invoice Totals -->
                <div class="mt-6 flex justify-end">
                    <div class="w-full max-w-sm">
                        <div class="space-y-1">
                            <div class="flex justify-between py-1">
                                <span class="text-sm text-gray-600">Subtotal:</span>
                                <span class="text-sm text-gray-900 font-medium">LKR <?php echo number_format($subtotal, 2); ?></span>
                            </div>

                            <?php if (($invoice['discount_percentage'] ?? 0) > 0): ?>
                                <div class="flex justify-between py-1">
                                    <span class="text-sm text-gray-600">Discount (<?php echo number_format($invoice['discount_percentage'], 1); ?>%):</span>
                                    <span class="text-sm text-gray-900 font-medium">-LKR <?php echo number_format($discountAmount, 2); ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if (($invoice['tax_percentage'] ?? 0) > 0): ?>
                                <div class="flex justify-between py-1">
                                    <span class="text-sm text-gray-600">Tax (<?php echo number_format($invoice['tax_percentage'], 1); ?>%):</span>
                                    <span class="text-sm text-gray-900 font-medium">LKR <?php echo number_format($taxAmount, 2); ?></span>
                                </div>
                            <?php endif; ?>

                            <div class="border-t border-gray-200 pt-1">
                                <div class="flex justify-between py-1">
                                    <span class="text-base font-semibold text-gray-900">Total:</span>
                                    <span class="text-base font-bold text-gray-900">LKR <?php echo number_format($total, 2); ?></span>
                                </div>
                            </div>

                            <?php if (!empty($relatedPayments)): ?>
                                <div class="border-t border-gray-200 pt-1 mt-2">
                                    <div class="flex justify-between py-1">
                                        <span class="text-sm font-semibold text-green-700">Paid Amount:</span>
                                        <span class="text-sm font-bold text-green-700">LKR <?php echo number_format($invoice['paid_amount'] ?? 0, 2); ?></span>
                                    </div>
                                    <?php if (($invoice['balance_amount'] ?? 0) > 0): ?>
                                        <div class="flex justify-between py-1">
                                            <span class="text-base font-semibold text-red-700">Balance Due:</span>
                                            <span class="text-base font-bold text-red-700">LKR <?php echo number_format($invoice['balance_amount'], 2); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Notes -->
                <?php if (!empty($invoice['notes'])): ?>
                    <div class="mt-6 pt-4 border-t border-gray-200">
                        <h4 class="text-base font-semibold text-gray-900 mb-2">Notes:</h4>
                        <p class="text-sm text-gray-600 whitespace-pre-line"><?php echo htmlspecialchars($invoice['notes']); ?></p>
                    </div>
                <?php endif; ?>

                <!-- Payment Information -->
                <div class="mt-6 pt-4 border-t border-gray-200">
                    <h4 class="text-base font-semibold text-gray-900 mb-2">Payment Information:</h4>
                    <div class="text-sm space-y-1">
                        <p><span class="text-gray-600">Bank:</span> <span class="font-medium text-gray-900"><?php echo htmlspecialchars($companySettings['bank_name']); ?> - <?php echo htmlspecialchars($companySettings['bank_branch']); ?></span></p>
                        <p><span class="text-gray-600">Account:</span> <span class="font-medium text-gray-900"><?php echo htmlspecialchars($companySettings['bank_account_number']); ?></span></p>
                        <p><span class="text-gray-600">Name:</span> <span class="font-medium text-gray-900">Nisal Sudharaka Weerarathne</span></p>
                    </div>
                </div>

                <!-- Payment History -->
                <?php if (!empty($relatedPayments)): ?>
                    <div class="mt-6 pt-4 border-t border-gray-200">
                        <h4 class="text-base font-semibold text-gray-900 mb-3">Payment History:</h4>
                        <div class="space-y-3">
                            <?php foreach ($relatedPayments as $payment): ?>
                                <div class="flex items-center justify-between p-3 bg-gradient-to-r from-green-50 to-emerald-50 border border-green-100 rounded-lg">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center space-x-2 mb-1">
                                            <div class="w-2 h-2 bg-green-500 rounded-full"></div>
                                            <span class="font-medium text-green-800 text-sm">Payment Received</span>
                                        </div>
                                        <div class="text-xs text-green-700 space-y-0.5">
                                            <p><span class="font-medium">Date:</span> <?php echo date('M j, Y', strtotime($payment['payment_date'])); ?></p>
                                            <p><span class="font-medium">Method:</span> <?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?></p>
                                            <?php if (!empty($payment['payment_reference'])): ?>
                                                <p><span class="font-medium">Reference:</span> <?php echo htmlspecialchars($payment['payment_reference']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="text-right ml-4">
                                        <p class="text-lg font-bold text-green-700">
                                            LKR <?php echo number_format($payment['payment_amount'], 2); ?>
                                        </p>
                                        <p class="text-xs text-green-600">
                                            <?php echo date('M j, g:i A', strtotime($payment['created_at'])); ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Footer -->
                <div class="mt-6 pt-4 border-t border-gray-200 text-center">
                    <p class="text-xs text-gray-500">
                        Thank you for your business! Please make payment within the due date.
                    </p>
                    <?php if (!empty($companySettings['tax_number'])): ?>
                        <p class="text-xs text-gray-400 mt-1">
                            Tax ID: <?php echo htmlspecialchars($companySettings['tax_number']); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
