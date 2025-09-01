<?php
// modules/invoices/edit.php
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

$pageTitle = 'Edit Invoice - Invoice Manager';

// Initialize variables
$errors = [];
$invoice = null;
$invoiceItems = [];
$relatedPayments = [];

// Get database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Get invoice details with client and project information
    $invoiceQuery = "
        SELECT i.*, 
               c.company_name, c.contact_person, c.mobile_number, c.address, c.city,
               p.project_name, p.project_type
        FROM invoices i 
        LEFT JOIN clients c ON i.client_id = c.id 
        LEFT JOIN projects p ON i.project_id = p.id
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

    // Check if invoice can be edited (paid invoices should not be editable)
    if ($invoice['status'] === 'paid') {
        Helper::setMessage('Cannot edit a paid invoice.', 'error');
        Helper::redirect('modules/invoices/view.php?id=' . Helper::encryptId($invoiceId));
    }

    // Get invoice items
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

    // Get related payments to check if invoice has been partially paid
    $paymentsQuery = "
        SELECT * FROM payments 
        WHERE invoice_id = :invoice_id 
        ORDER BY payment_date DESC, created_at DESC
    ";
    $paymentsStmt = $db->prepare($paymentsQuery);
    $paymentsStmt->bindParam(':invoice_id', $invoiceId);
    $paymentsStmt->execute();
    $relatedPayments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);

    // If invoice has payments, restrict certain edits
    $hasPayments = !empty($relatedPayments);

} catch (Exception $e) {
    Helper::setMessage('Error loading invoice details: ' . $e->getMessage(), 'error');
    Helper::redirect('modules/invoices/');
}

// Get all active projects and clients for dropdowns
try {
    $projectsQuery = "
        SELECT p.id, p.project_name, p.total_amount, c.company_name 
        FROM projects p 
        LEFT JOIN clients c ON p.client_id = c.id 
        WHERE p.status NOT IN ('cancelled', 'completed')
        ORDER BY p.created_at DESC
    ";
    $projectsStmt = $db->prepare($projectsQuery);
    $projectsStmt->execute();
    $availableProjects = $projectsStmt->fetchAll(PDO::FETCH_ASSOC);

    $clientsQuery = "SELECT id, company_name, contact_person FROM clients WHERE is_active = 1 ORDER BY company_name ASC";
    $clientsStmt = $db->prepare($clientsQuery);
    $clientsStmt->execute();
    $availableClients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $availableProjects = [];
    $availableClients = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!Helper::verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        // Get and sanitize form data
        $selectedProjectId = Helper::decryptId($_POST['project_id'] ?? '');
        $selectedClientId = Helper::decryptId($_POST['client_id'] ?? '');
        $formData = [
            'invoice_date' => Helper::sanitize($_POST['invoice_date'] ?? ''),
            'due_date' => Helper::sanitize($_POST['due_date'] ?? ''),
            'tax_rate' => floatval($_POST['tax_rate'] ?? 0),
            'notes' => Helper::sanitize($_POST['notes'] ?? ''),
            'status' => Helper::sanitize($_POST['status'] ?? 'draft')
        ];

        // Get invoice items from form
        $newInvoiceItems = [];
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                if (!empty($item['description']) && !empty($item['quantity']) && !empty($item['unit_price'])) {
                    $newInvoiceItems[] = [
                        'description' => Helper::sanitize($item['description']),
                        'quantity' => floatval($item['quantity']),
                        'unit_price' => floatval($item['unit_price']),
                        'total' => floatval($item['quantity']) * floatval($item['unit_price'])
                    ];
                }
            }
        }

        // Validate required fields
        if (empty($selectedProjectId) && empty($selectedClientId)) {
            $errors[] = 'Please select either a project or a client.';
        }
        
        if (empty($formData['invoice_date'])) {
            $errors[] = 'Invoice date is required.';
        } elseif (!strtotime($formData['invoice_date'])) {
            $errors[] = 'Please enter a valid invoice date.';
        }
        
        if (empty($formData['due_date'])) {
            $errors[] = 'Due date is required.';
        } elseif (!strtotime($formData['due_date'])) {
            $errors[] = 'Please enter a valid due date.';
        } elseif (strtotime($formData['due_date']) < strtotime($formData['invoice_date'])) {
            $errors[] = 'Due date must be after invoice date.';
        }

        if ($formData['tax_rate'] < 0 || $formData['tax_rate'] > 100) {
            $errors[] = 'Tax rate must be between 0% and 100%.';
        }

        if (empty($newInvoiceItems)) {
            $errors[] = 'Please add at least one invoice item.';
        }

        // Additional validation for invoices with payments
        if ($hasPayments) {
            $newSubtotal = array_sum(array_column($newInvoiceItems, 'total'));
            $newTaxAmount = ($newSubtotal * $formData['tax_rate']) / 100;
            $newTotalAmount = $newSubtotal + $newTaxAmount;
            
            // Cannot reduce total amount below already paid amount
            if ($newTotalAmount < $invoice['paid_amount']) {
                $errors[] = 'Cannot reduce invoice total below the already paid amount of ' . Helper::formatCurrency($invoice['paid_amount']) . '.';
            }
        }

        // If no errors, update the invoice
        if (empty($errors)) {
            try {
                $db->beginTransaction();

                // Calculate totals
                $subtotal = array_sum(array_column($newInvoiceItems, 'total'));
                $taxAmount = ($subtotal * $formData['tax_rate']) / 100;
                $totalAmount = $subtotal + $taxAmount;

                // Calculate new balance
                $paidAmount = $invoice['paid_amount'];
                $balanceAmount = $totalAmount - $paidAmount;

                // Determine new status based on payments
                $newStatus = $formData['status'];
                if ($paidAmount > 0) {
                    if ($balanceAmount <= 0) {
                        $newStatus = 'paid';
                    } else {
                        $newStatus = 'partially_paid';
                    }
                } elseif ($newStatus === 'paid' || $newStatus === 'partially_paid') {
                    // Reset to sent if no payments but status was paid/partially_paid
                    $newStatus = 'sent';
                }

                // Use project's client if project is selected, otherwise use selected client
                $finalClientId = $selectedProjectId ? 
                    (($invoice['project_id'] && $selectedProjectId == $invoice['project_id']) ? $invoice['client_id'] : $selectedClientId) : 
                    $selectedClientId;

                // Update invoice
                $updateInvoiceQuery = "
                    UPDATE invoices SET 
                        project_id = :project_id, 
                        client_id = :client_id, 
                        invoice_date = :invoice_date, 
                        due_date = :due_date,
                        subtotal = :subtotal, 
                        tax_rate = :tax_rate, 
                        tax_amount = :tax_amount, 
                        total_amount = :total_amount, 
                        balance_amount = :balance_amount,
                        status = :status, 
                        notes = :notes,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :invoice_id
                ";
                
                $updateStmt = $db->prepare($updateInvoiceQuery);
                $updateStmt->bindParam(':project_id', $selectedProjectId);
                $updateStmt->bindParam(':client_id', $finalClientId);
                $updateStmt->bindParam(':invoice_date', $formData['invoice_date']);
                $updateStmt->bindParam(':due_date', $formData['due_date']);
                $updateStmt->bindParam(':subtotal', $subtotal);
                $updateStmt->bindParam(':tax_rate', $formData['tax_rate']);
                $updateStmt->bindParam(':tax_amount', $taxAmount);
                $updateStmt->bindParam(':total_amount', $totalAmount);
                $updateStmt->bindParam(':balance_amount', $balanceAmount);
                $updateStmt->bindParam(':status', $newStatus);
                $updateStmt->bindParam(':notes', $formData['notes']);
                $updateStmt->bindParam(':invoice_id', $invoiceId);
                
                $updateStmt->execute();

                // Delete existing invoice items and insert new ones
                try {
                    $deleteItemsQuery = "DELETE FROM invoice_items WHERE invoice_id = :invoice_id";
                    $deleteStmt = $db->prepare($deleteItemsQuery);
                    $deleteStmt->bindParam(':invoice_id', $invoiceId);
                    $deleteStmt->execute();

                    // Insert new invoice items
                    foreach ($newInvoiceItems as $item) {
                        $itemQuery = "
                            INSERT INTO invoice_items (
                                invoice_id, description, quantity, unit_price, total_amount
                            ) VALUES (
                                :invoice_id, :description, :quantity, :unit_price, :total_amount
                            )
                        ";
                        $itemStmt = $db->prepare($itemQuery);
                        $itemStmt->bindParam(':invoice_id', $invoiceId);
                        $itemStmt->bindParam(':description', $item['description']);
                        $itemStmt->bindParam(':quantity', $item['quantity']);
                        $itemStmt->bindParam(':unit_price', $item['unit_price']);
                        $itemStmt->bindParam(':total_amount', $item['total']);
                        $itemStmt->execute();
                    }
                } catch (Exception $e) {
                    // invoice_items table might not exist - you may want to create it
                }

                $db->commit();

                Helper::setMessage('Invoice updated successfully!', 'success');
                Helper::redirect('modules/invoices/view.php?id=' . Helper::encryptId($invoiceId));

            } catch (Exception $e) {
                $db->rollBack();
                $errors[] = 'Error updating invoice: ' . $e->getMessage();
            }
        }
    }
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
        <a href="<?php echo Helper::baseUrl('modules/invoices/view.php?id=' . Helper::encryptId($invoiceId)); ?>" 
           class="hover:text-gray-900 transition-colors">
            #<?php echo htmlspecialchars($invoice['invoice_number']); ?>
        </a>
        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
        </svg>
        <span class="text-gray-900 font-medium">Edit</span>
    </div>
</nav>

<!-- Page Header -->
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Edit Invoice</h1>
        <p class="text-gray-600 mt-1">
            Editing invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?>
            <?php if ($invoice['project_name']): ?>
                for project: <span class="font-medium"><?php echo htmlspecialchars($invoice['project_name']); ?></span>
            <?php endif; ?>
        </p>
    </div>
    <div class="mt-4 sm:mt-0">
        <?php 
        $status = $invoice['status'];
        $statusClasses = [
            'draft' => 'bg-gray-100 text-gray-800',
            'sent' => 'bg-blue-100 text-blue-800',
            'paid' => 'bg-green-100 text-green-800',
            'partially_paid' => 'bg-yellow-100 text-yellow-800',
            'overdue' => 'bg-red-100 text-red-800'
        ];
        $badgeClass = $statusClasses[$status] ?? 'bg-gray-100 text-gray-800';
        ?>
        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $badgeClass; ?>">
            <?php echo ucwords(str_replace('_', ' ', $status)); ?>
        </span>
    </div>
</div>

<!-- Warning Messages -->
<?php if ($hasPayments): ?>
    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
        <div class="flex">
            <svg class="w-5 h-5 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.464 0L3.35 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
            </svg>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-yellow-800">Payment Alert</h3>
                <p class="mt-1 text-sm text-yellow-700">
                    This invoice has received <?php echo Helper::formatCurrency($invoice['paid_amount']); ?> in payments. 
                    You cannot reduce the total amount below this paid amount.
                </p>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Error Messages -->
<?php if (!empty($errors)): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
        <div class="flex">
            <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-red-800">Please correct the following errors:</h3>
                <ul class="mt-2 text-sm text-red-700 list-disc list-inside">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Invoice Form -->
<form method="POST" class="space-y-6" id="invoiceForm">
    <input type="hidden" name="csrf_token" value="<?php echo Helper::generateCSRF(); ?>">
    
    <!-- Invoice Details -->
    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Invoice Details</h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Project Selection -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Project</label>
                <select name="project_id" id="projectSelect" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">Select a project (optional)</option>
                    <?php foreach ($availableProjects as $availableProject): ?>
                        <option value="<?php echo Helper::encryptId($availableProject['id']); ?>" 
                                <?php echo ($invoice['project_id'] && $invoice['project_id'] == $availableProject['id']) ? 'selected' : ''; ?>
                                data-client="<?php echo htmlspecialchars($availableProject['company_name']); ?>">
                            <?php echo htmlspecialchars($availableProject['project_name']); ?> - 
                            <?php echo htmlspecialchars($availableProject['company_name']); ?> 
                            (<?php echo Helper::formatCurrency($availableProject['total_amount']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-500 mt-1">Select a project to auto-fill client details</p>
            </div>

            <!-- Client Selection -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Client</label>
                <select name="client_id" id="clientSelect" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">Select a client</option>
                    <?php foreach ($availableClients as $availableClient): ?>
                        <option value="<?php echo Helper::encryptId($availableClient['id']); ?>"
                                <?php echo ($invoice['client_id'] == $availableClient['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($availableClient['company_name']); ?> - 
                            <?php echo htmlspecialchars($availableClient['contact_person']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-500 mt-1">Required if no project selected</p>
            </div>

            <!-- Invoice Date -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Invoice Date <span class="text-red-500">*</span></label>
                <input type="date" 
                       name="invoice_date" 
                       value="<?php echo htmlspecialchars($invoice['invoice_date']); ?>"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                       required>
            </div>

            <!-- Due Date -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Due Date <span class="text-red-500">*</span></label>
                <input type="date" 
                       name="due_date" 
                       value="<?php echo htmlspecialchars($invoice['due_date']); ?>"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                       required>
            </div>

            <!-- Tax Rate -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Tax Rate (%)</label>
                <input type="number" 
                       name="tax_rate" 
                       value="<?php echo htmlspecialchars($invoice['tax_rate']); ?>"
                       min="0" 
                       max="100" 
                       step="0.01"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                       id="taxRate">
            </div>

            <!-- Status -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                <select name="status" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
                        <?php echo $hasPayments ? 'disabled' : ''; ?>>
                    <option value="draft" <?php echo $invoice['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="sent" <?php echo $invoice['status'] === 'sent' ? 'selected' : ''; ?>>Sent</option>
                    <?php if ($hasPayments): ?>
                        <option value="partially_paid" <?php echo $invoice['status'] === 'partially_paid' ? 'selected' : ''; ?>>Partially Paid</option>
                        <option value="paid" <?php echo $invoice['status'] === 'paid' ? 'selected' : ''; ?>>Paid</option>
                        <option value="overdue" <?php echo $invoice['status'] === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                    <?php endif; ?>
                </select>
                <?php if ($hasPayments): ?>
                    <p class="text-xs text-gray-500 mt-1">Status is auto-managed based on payments</p>
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($invoice['status']); ?>">
                <?php endif; ?>
            </div>
        </div>

        <!-- Notes -->
        <div class="mt-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
            <textarea name="notes" 
                      rows="3" 
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                      placeholder="Additional notes or terms..."><?php echo htmlspecialchars($invoice['notes']); ?></textarea>
        </div>
    </div>

    <!-- Client Info Preview -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
        <h3 class="font-medium text-blue-900 mb-2">Invoice To:</h3>
        <div class="text-sm text-blue-800">
            <p class="font-medium"><?php echo htmlspecialchars($invoice['company_name']); ?></p>
            <p><?php echo htmlspecialchars($invoice['contact_person']); ?></p>
            <?php if (!empty($invoice['address'])): ?>
                <p><?php echo htmlspecialchars($invoice['address']); ?></p>
            <?php endif; ?>
            <?php if (!empty($invoice['city'])): ?>
                <p><?php echo htmlspecialchars($invoice['city']); ?></p>
            <?php endif; ?>
            <?php if (!empty($invoice['mobile_number'])): ?>
                <p>Tel: <?php echo htmlspecialchars($invoice['mobile_number']); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Invoice Items -->
    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold text-gray-900">Invoice Items</h2>
            <button type="button" 
                    onclick="addInvoiceItem()" 
                    class="inline-flex items-center px-3 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Add Item
            </button>
        </div>

        <!-- Items Table -->
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr class="border-b border-gray-200">
                        <th class="text-left py-2 px-2 text-sm font-medium text-gray-700">Description</th>
                        <th class="text-center py-2 px-2 text-sm font-medium text-gray-700 w-24">Qty</th>
                        <th class="text-center py-2 px-2 text-sm font-medium text-gray-700 w-32">Unit Price</th>
                        <th class="text-center py-2 px-2 text-sm font-medium text-gray-700 w-32">Total</th>
                        <th class="text-center py-2 px-2 text-sm font-medium text-gray-700 w-16">Action</th>
                    </tr>
                </thead>
                <tbody id="invoiceItems">
                    <?php if (!empty($invoiceItems)): ?>
                        <?php foreach ($invoiceItems as $index => $item): ?>
                            <tr class="invoice-item border-b border-gray-100">
                                <td class="py-2 px-2">
                                    <input type="text" 
                                           name="items[<?php echo $index; ?>][description]" 
                                           value="<?php echo htmlspecialchars($item['description']); ?>"
                                           class="w-full border border-gray-300 rounded px-2 py-1 text-sm focus:ring-1 focus:ring-blue-500 focus:border-transparent"
                                           placeholder="Item description">
                                </td>
                                <td class="py-2 px-2">
                                    <input type="number" 
                                           name="items[<?php echo $index; ?>][quantity]" 
                                           value="<?php echo $item['quantity']; ?>"
                                           min="0.01" 
                                           step="0.01"
                                           class="w-full border border-gray-300 rounded px-2 py-1 text-sm text-center focus:ring-1 focus:ring-blue-500 focus:border-transparent quantity-input"
                                           onchange="calculateItemTotal(this)">
                                </td>
                                <td class="py-2 px-2">
                                    <input type="number" 
                                           name="items[<?php echo $index; ?>][unit_price]" 
                                           value="<?php echo $item['unit_price']; ?>"
                                           min="0.01" 
                                           step="0.01"
                                           class="w-full border border-gray-300 rounded px-2 py-1 text-sm text-center focus:ring-1 focus:ring-blue-500 focus:border-transparent unit-price-input"
                                           onchange="calculateItemTotal(this)">
                                </td>
                                <td class="py-2 px-2">
                                    <input type="text" 
                                           class="w-full border border-gray-200 rounded px-2 py-1 text-sm text-center bg-gray-50 item-total"
                                           value="<?php echo Helper::formatCurrency($item['total_amount']); ?>"
                                           readonly>
                                </td>
                                <td class="py-2 px-2 text-center">
                                    <button type="button" 
                                            onclick="removeInvoiceItem(this)" 
                                            class="text-red-600 hover:text-red-800 transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                        </svg>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <!-- Add one empty row if no items -->
                        <tr class="invoice-item border-b border-gray-100">
                            <td class="py-2 px-2">
                                <input type="text" 
                                       name="items[0][description]" 
                                       class="w-full border border-gray-300 rounded px-2 py-1 text-sm focus:ring-1 focus:ring-blue-500 focus:border-transparent"
                                       placeholder="Item description">
                            </td>
                            <td class="py-2 px-2">
                                <input type="number" 
                                       name="items[0][quantity]" 
                                       value="1"
                                       min="0.01" 
                                       step="0.01"
                                       class="w-full border border-gray-300 rounded px-2 py-1 text-sm text-center focus:ring-1 focus:ring-blue-500 focus:border-transparent quantity-input"
                                       onchange="calculateItemTotal(this)">
                            </td>
                            <td class="py-2 px-2">
                                <input type="number" 
                                       name="items[0][unit_price]" 
                                       value="0.00"
                                       min="0.01" 
                                       step="0.01"
                                       class="w-full border border-gray-300 rounded px-2 py-1 text-sm text-center focus:ring-1 focus:ring-blue-500 focus:border-transparent unit-price-input"
                                       onchange="calculateItemTotal(this)">
                            </td>
                            <td class="py-2 px-2">
                                <input type="text" 
                                       class="w-full border border-gray-200 rounded px-2 py-1 text-sm text-center bg-gray-50 item-total"
                                       value="LKR 0.00"
                                       readonly>
                            </td>
                            <td class="py-2 px-2 text-center">
                                <button type="button" 
                                        onclick="removeInvoiceItem(this)" 
                                        class="text-red-600 hover:text-red-800 transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                </button>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Totals -->
        <div class="mt-6 flex justify-end">
            <div class="w-64 space-y-2">
                <div class="flex justify-between text-sm">
                    <span class="text-gray-600">Subtotal:</span>
                    <span id="subtotalDisplay" class="font-medium">LKR 0.00</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-600">Tax (<span id="taxRateDisplay">0</span>%):</span>
                    <span id="taxDisplay" class="font-medium">LKR 0.00</span>
                </div>
                <div class="flex justify-between text-lg font-semibold border-t pt-2">
                    <span>Total:</span>
                    <span id="totalDisplay" class="text-blue-600">LKR 0.00</span>
                </div>
                <?php if ($hasPayments): ?>
                    <div class="flex justify-between text-sm border-t pt-2">
                        <span class="text-gray-600">Paid Amount:</span>
                        <span class="font-medium text-green-600"><?php echo Helper::formatCurrency($invoice['paid_amount']); ?></span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Balance:</span>
                        <span id="balanceDisplay" class="font-medium text-red-600">LKR 0.00</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Payment History (if any) -->
    <?php if (!empty($relatedPayments)): ?>
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Payment History</h2>
            <div class="space-y-3">
                <?php foreach ($relatedPayments as $payment): ?>
                    <div class="flex items-center justify-between p-3 bg-green-50 border border-green-200 rounded-lg">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center space-x-2 mb-1">
                                <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <span class="font-medium text-green-800 text-sm">Payment Received</span>
                            </div>
                            <div class="text-xs text-green-700">
                                <p>Date: <?php echo Helper::formatDate($payment['payment_date'], 'M j, Y'); ?></p>
                                <p>Method: <?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?></p>
                                <?php if ($payment['payment_reference']): ?>
                                    <p>Ref: <?php echo htmlspecialchars($payment['payment_reference']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="text-right ml-4">
                            <p class="font-bold text-green-600 text-sm">
                                <?php echo Helper::formatCurrency($payment['payment_amount']); ?>
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Form Actions -->
    <div class="flex flex-col sm:flex-row gap-4 sm:justify-end">
        <a href="<?php echo Helper::baseUrl('modules/invoices/view.php?id=' . Helper::encryptId($invoiceId)); ?>" 
           class="px-6 py-3 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors text-center">
            Cancel
        </a>
        <button type="submit" 
                class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
            Update Invoice
        </button>
    </div>
</form>

<style>
/* Custom styles for the invoice form */
.invoice-item input:focus {
    outline: none;
}

/* Responsive table */
@media (max-width: 768px) {
    .overflow-x-auto {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    table {
        min-width: 600px;
    }
}

/* Smooth transitions */
.transition-colors {
    transition-property: color, background-color, border-color;
    transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
    transition-duration: 150ms;
}

/* Loading state for form submission */
.form-submitting {
    opacity: 0.6;
    pointer-events: none;
}

/* Better focus states */
input:focus, select:focus, textarea:focus {
    outline: none;
    box-shadow: 0 0 0 2px #3b82f6;
    border-color: transparent;
}

/* Disabled state styling */
select:disabled {
    background-color: #f9fafb;
    color: #6b7280;
    cursor: not-allowed;
}

/* Warning highlight for minimum total */
.minimum-total-warning {
    border-color: #f59e0b !important;
    background-color: #fef3c7 !important;
}
</style>

<script>
let itemIndex = <?php echo !empty($invoiceItems) ? count($invoiceItems) : 1; ?>;
const hasPayments = <?php echo $hasPayments ? 'true' : 'false'; ?>;
const paidAmount = <?php echo $invoice['paid_amount']; ?>;

// Add new invoice item
function addInvoiceItem() {
    const tbody = document.getElementById('invoiceItems');
    const newRow = document.createElement('tr');
    newRow.className = 'invoice-item border-b border-gray-100';
    
    newRow.innerHTML = `
        <td class="py-2 px-2">
            <input type="text" 
                   name="items[${itemIndex}][description]" 
                   class="w-full border border-gray-300 rounded px-2 py-1 text-sm focus:ring-1 focus:ring-blue-500 focus:border-transparent"
                   placeholder="Item description">
        </td>
        <td class="py-2 px-2">
            <input type="number" 
                   name="items[${itemIndex}][quantity]" 
                   value="1"
                   min="0.01" 
                   step="0.01"
                   class="w-full border border-gray-300 rounded px-2 py-1 text-sm text-center focus:ring-1 focus:ring-blue-500 focus:border-transparent quantity-input"
                   onchange="calculateItemTotal(this)">
        </td>
        <td class="py-2 px-2">
            <input type="number" 
                   name="items[${itemIndex}][unit_price]" 
                   value="0.00"
                   min="0.01" 
                   step="0.01"
                   class="w-full border border-gray-300 rounded px-2 py-1 text-sm text-center focus:ring-1 focus:ring-blue-500 focus:border-transparent unit-price-input"
                   onchange="calculateItemTotal(this)">
        </td>
        <td class="py-2 px-2">
            <input type="text" 
                   class="w-full border border-gray-200 rounded px-2 py-1 text-sm text-center bg-gray-50 item-total"
                   value="LKR 0.00"
                   readonly>
        </td>
        <td class="py-2 px-2 text-center">
            <button type="button" 
                    onclick="removeInvoiceItem(this)" 
                    class="text-red-600 hover:text-red-800 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                </svg>
            </button>
        </td>
    `;
    
    tbody.appendChild(newRow);
    itemIndex++;
    
    // Focus on the description field
    newRow.querySelector('input[type="text"]').focus();
}

// Remove invoice item
function removeInvoiceItem(button) {
    const row = button.closest('tr');
    const tbody = document.getElementById('invoiceItems');
    
    // Don't remove if it's the last row
    if (tbody.children.length > 1) {
        row.remove();
        updateItemIndices();
        calculateTotal();
    }
}

// Update item indices after removal
function updateItemIndices() {
    const rows = document.querySelectorAll('.invoice-item');
    rows.forEach((row, index) => {
        const inputs = row.querySelectorAll('input[name*="items"]');
        inputs.forEach(input => {
            const name = input.getAttribute('name');
            const newName = name.replace(/items\[\d+\]/, `items[${index}]`);
            input.setAttribute('name', newName);
        });
    });
}

// Calculate item total
function calculateItemTotal(input) {
    const row = input.closest('tr');
    const quantity = parseFloat(row.querySelector('.quantity-input').value) || 0;
    const unitPrice = parseFloat(row.querySelector('.unit-price-input').value) || 0;
    const total = quantity * unitPrice;
    
    row.querySelector('.item-total').value = formatCurrency(total);
    calculateTotal();
}

// Calculate invoice total
function calculateTotal() {
    let subtotal = 0;
    
    document.querySelectorAll('.invoice-item').forEach(row => {
        const quantity = parseFloat(row.querySelector('.quantity-input').value) || 0;
        const unitPrice = parseFloat(row.querySelector('.unit-price-input').value) || 0;
        subtotal += quantity * unitPrice;
    });
    
    const taxRate = parseFloat(document.getElementById('taxRate').value) || 0;
    const taxAmount = (subtotal * taxRate) / 100;
    const total = subtotal + taxAmount;
    
    document.getElementById('subtotalDisplay').textContent = formatCurrency(subtotal);
    document.getElementById('taxRateDisplay').textContent = taxRate.toFixed(2);
    document.getElementById('taxDisplay').textContent = formatCurrency(taxAmount);
    document.getElementById('totalDisplay').textContent = formatCurrency(total);
    
    // If there are payments, show balance
    if (hasPayments) {
        const balance = total - paidAmount;
        document.getElementById('balanceDisplay').textContent = formatCurrency(balance);
        
        // Highlight if total is below paid amount
        const totalDisplayElement = document.getElementById('totalDisplay');
        if (total < paidAmount) {
            totalDisplayElement.classList.add('minimum-total-warning');
            totalDisplayElement.parentElement.classList.add('minimum-total-warning');
        } else {
            totalDisplayElement.classList.remove('minimum-total-warning');
            totalDisplayElement.parentElement.classList.remove('minimum-total-warning');
        }
    }
}

// Format currency
function formatCurrency(amount) {
    return 'LKR ' + amount.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

// Project selection change handler
document.getElementById('projectSelect').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    if (selectedOption.value) {
        const clientName = selectedOption.getAttribute('data-client');
        if (clientName) {
            // Find and select the corresponding client
            const clientSelect = document.getElementById('clientSelect');
            for (let option of clientSelect.options) {
                if (option.text.includes(clientName)) {
                    option.selected = true;
                    break;
                }
            }
        }
    }
});

// Tax rate change handler
document.getElementById('taxRate').addEventListener('change', calculateTotal);

// Form submission handler
document.getElementById('invoiceForm').addEventListener('submit', function(e) {
    // Check if total is below paid amount when there are payments
    if (hasPayments) {
        let subtotal = 0;
        document.querySelectorAll('.invoice-item').forEach(row => {
            const quantity = parseFloat(row.querySelector('.quantity-input').value) || 0;
            const unitPrice = parseFloat(row.querySelector('.unit-price-input').value) || 0;
            subtotal += quantity * unitPrice;
        });
        
        const taxRate = parseFloat(document.getElementById('taxRate').value) || 0;
        const taxAmount = (subtotal * taxRate) / 100;
        const total = subtotal + taxAmount;
        
        if (total < paidAmount) {
            e.preventDefault();
            alert(`Cannot update invoice. The new total (${formatCurrency(total)}) cannot be less than the already paid amount (${formatCurrency(paidAmount)}).`);
            return false;
        }
    }
    
    // Add loading state
    this.classList.add('form-submitting');
    
    // Disable submit button to prevent double submission
    const submitButton = this.querySelector('button[type="submit"]');
    submitButton.disabled = true;
    submitButton.textContent = 'Updating...';
});

// Initialize calculations on page load
document.addEventListener('DOMContentLoaded', function() {
    calculateTotal();
    
    // Auto-calculate when values change
    document.querySelectorAll('.quantity-input, .unit-price-input').forEach(input => {
        input.addEventListener('change', function() {
            calculateItemTotal(this);
        });
        input.addEventListener('input', function() {
            calculateItemTotal(this);
        });
    });
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl+Enter to save
    if (e.ctrlKey && e.key === 'Enter') {
        e.preventDefault();
        document.querySelector('button[type="submit"]').click();
    }
    
    // Ctrl+Plus to add item
    if (e.ctrlKey && e.key === '=') {
        e.preventDefault();
        addInvoiceItem();
    }
    
    // Escape to cancel
    if (e.key === 'Escape') {
        e.preventDefault();
        window.location.href = '<?php echo Helper::baseUrl('modules/invoices/view.php?id=' . Helper::encryptId($invoiceId)); ?>';
    }
});

// Auto-save functionality (optional)
let autoSaveTimeout;
function autoSave() {
    clearTimeout(autoSaveTimeout);
    autoSaveTimeout = setTimeout(() => {
        // Save draft to localStorage
        const formData = new FormData(document.getElementById('invoiceForm'));
        const data = {};
        for (let [key, value] of formData.entries()) {
            data[key] = value;
        }
        localStorage.setItem('invoice_edit_draft_<?php echo $invoiceId; ?>', JSON.stringify(data));
        console.log('Draft saved locally');
    }, 2000);
}

// Attach auto-save to form inputs
document.querySelectorAll('input, select, textarea').forEach(input => {
    input.addEventListener('input', autoSave);
    input.addEventListener('change', autoSave);
});

// Load draft on page load
window.addEventListener('load', function() {
    const savedDraft = localStorage.getItem('invoice_edit_draft_<?php echo $invoiceId; ?>');
    if (savedDraft) {
        // You could implement draft restoration here
        console.log('Draft available in localStorage');
    }
});

// Clear draft on successful submission
document.getElementById('invoiceForm').addEventListener('submit', function() {
    localStorage.removeItem('invoice_edit_draft_<?php echo $invoiceId; ?>');
});

// Warn about unsaved changes
let formChanged = false;
document.querySelectorAll('input, select, textarea').forEach(input => {
    input.addEventListener('change', function() {
        formChanged = true;
    });
});

window.addEventListener('beforeunload', function(e) {
    if (formChanged) {
        e.preventDefault();
        e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
        return e.returnValue;
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
