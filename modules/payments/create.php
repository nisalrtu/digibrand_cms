<?php
// modules/payments/create.php
session_start();

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

$pageTitle = 'Record Payment - Payment Manager';

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Initialize variables
$invoiceId = null;
$invoice = null;
$errors = [];
$successMessage = '';

// Check if invoice ID is provided
if (isset($_GET['invoice_id'])) {
    $invoiceId = Helper::decryptId($_GET['invoice_id']);
    
    if ($invoiceId) {
        try {
            // Get invoice details
            $invoiceQuery = "
                SELECT i.*, 
                       c.company_name, c.contact_person,
                       p.project_name
                FROM invoices i 
                LEFT JOIN clients c ON i.client_id = c.id 
                LEFT JOIN projects p ON i.project_id = p.id
                WHERE i.id = :invoice_id AND i.balance_amount > 0
            ";
            $invoiceStmt = $db->prepare($invoiceQuery);
            $invoiceStmt->bindParam(':invoice_id', $invoiceId);
            $invoiceStmt->execute();
            
            $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$invoice) {
                Helper::setMessage('Invoice not found or already fully paid.', 'error');
                Helper::redirect('modules/invoices/');
            }
        } catch (Exception $e) {
            Helper::setMessage('Error loading invoice: ' . $e->getMessage(), 'error');
            Helper::redirect('modules/invoices/');
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate inputs
        $paymentAmount = floatval($_POST['payment_amount'] ?? 0);
        $paymentDate = $_POST['payment_date'] ?? '';
        $paymentMethod = $_POST['payment_method'] ?? '';
        $paymentReference = trim($_POST['payment_reference'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $selectedInvoiceId = Helper::decryptId($_POST['invoice_id'] ?? '');
        
        // Validation
        if (!$selectedInvoiceId) {
            $errors[] = 'Please select a valid invoice.';
        }
        
        if ($paymentAmount <= 0) {
            $errors[] = 'Payment amount must be greater than zero.';
        }
        
        if (empty($paymentDate)) {
            $errors[] = 'Payment date is required.';
        }
        
        if (empty($paymentMethod)) {
            $errors[] = 'Payment method is required.';
        }
        
        // Get invoice to validate payment amount
        if ($selectedInvoiceId && empty($errors)) {
            $validateQuery = "SELECT balance_amount FROM invoices WHERE id = :invoice_id";
            $validateStmt = $db->prepare($validateQuery);
            $validateStmt->bindParam(':invoice_id', $selectedInvoiceId);
            $validateStmt->execute();
            $invoiceData = $validateStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$invoiceData) {
                $errors[] = 'Selected invoice not found.';
            } elseif ($paymentAmount > $invoiceData['balance_amount']) {
                $errors[] = 'Payment amount cannot exceed the outstanding balance of ' . Helper::formatCurrency($invoiceData['balance_amount']) . '.';
            }
        }
        
        // If no errors, process the payment
        if (empty($errors)) {
            $db->beginTransaction();
            
            try {
                // Insert payment record
                $insertQuery = "
                    INSERT INTO payments (
                        invoice_id, payment_amount, payment_date, payment_method, 
                        payment_reference, notes, created_by
                    ) VALUES (
                        :invoice_id, :payment_amount, :payment_date, :payment_method,
                        :payment_reference, :notes, :created_by
                    )
                ";
                $insertStmt = $db->prepare($insertQuery);
                $insertStmt->bindParam(':invoice_id', $selectedInvoiceId);
                $insertStmt->bindParam(':payment_amount', $paymentAmount);
                $insertStmt->bindParam(':payment_date', $paymentDate);
                $insertStmt->bindParam(':payment_method', $paymentMethod);
                $insertStmt->bindParam(':payment_reference', $paymentReference);
                $insertStmt->bindParam(':notes', $notes);
                $insertStmt->bindParam(':created_by', $_SESSION['user_id']);
                
                $insertStmt->execute();
                $paymentId = $db->lastInsertId();
                
                $db->commit();
                
                Helper::setMessage('Payment recorded successfully!', 'success');
                Helper::redirect('modules/invoices/view.php?id=' . Helper::encryptId($selectedInvoiceId));
                
            } catch (Exception $e) {
                $db->rollBack();
                $errors[] = 'Error recording payment: ' . $e->getMessage();
            }
        }
        
    } catch (Exception $e) {
        $errors[] = 'Error processing payment: ' . $e->getMessage();
    }
}

// Get all unpaid/partially paid invoices for the dropdown
$invoicesQuery = "
    SELECT i.id, i.invoice_number, i.total_amount, i.balance_amount,
           c.company_name, p.project_name
    FROM invoices i 
    LEFT JOIN clients c ON i.client_id = c.id 
    LEFT JOIN projects p ON i.project_id = p.id
    WHERE i.balance_amount > 0 
    ORDER BY i.invoice_date DESC, i.invoice_number ASC
";
$invoicesStmt = $db->prepare($invoicesQuery);
$invoicesStmt->execute();
$availableInvoices = $invoicesStmt->fetchAll(PDO::FETCH_ASSOC);

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
        <span class="text-gray-900 font-medium">Record Payment</span>
    </div>
</nav>

<!-- Page Header -->
<div class="mb-8">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Record Payment</h1>
            <p class="text-gray-600 mt-1">Record a payment for an outstanding invoice</p>
        </div>
        
    </div>
</div>

<!-- Error Messages -->
<?php if (!empty($errors)): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6">
        <div class="flex">
            <svg class="w-5 h-5 text-red-400 mr-2 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
            </svg>
            <div>
                <h3 class="text-sm font-medium">Please correct the following errors:</h3>
                <ul class="mt-2 text-sm list-disc list-inside space-y-1">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Invoice Summary (if selected) -->
<?php if ($invoice): ?>
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-6 mb-6">
        <div class="flex items-start justify-between">
            <div>
                <h3 class="text-lg font-semibold text-blue-900 mb-2">Invoice Details</h3>
                <div class="space-y-2 text-sm text-blue-800">
                    <p><span class="font-medium">Invoice:</span> #<?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
                    <p><span class="font-medium">Client:</span> <?php echo htmlspecialchars($invoice['company_name']); ?></p>
                    <?php if ($invoice['project_name']): ?>
                        <p><span class="font-medium">Project:</span> <?php echo htmlspecialchars($invoice['project_name']); ?></p>
                    <?php endif; ?>
                    <p><span class="font-medium">Due Date:</span> <?php echo Helper::formatDate($invoice['due_date'], 'F j, Y'); ?></p>
                </div>
            </div>
            <div class="text-right">
                <div class="space-y-1 text-sm text-blue-800">
                    <p class="text-xs text-blue-600 uppercase tracking-wide font-medium">Amount Due</p>
                    <p class="text-2xl font-bold text-blue-900"><?php echo Helper::formatCurrency($invoice['balance_amount']); ?></p>
                    <p class="text-xs">of <?php echo Helper::formatCurrency($invoice['total_amount']); ?> total</p>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Payment Form -->
<div class="bg-white rounded-xl border border-gray-200 p-6">
    <form method="POST" action="" class="space-y-6">
        <!-- Invoice Selection -->
        <div>
            <label for="invoice_id" class="block text-sm font-medium text-gray-900 mb-2">
                Select Invoice <span class="text-red-500">*</span>
            </label>
            <select name="invoice_id" id="invoice_id" required 
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                    onchange="updateInvoiceDetails()">
                <option value="">Choose an invoice...</option>
                <?php foreach ($availableInvoices as $inv): ?>
                    <option value="<?php echo Helper::encryptId($inv['id']); ?>" 
                            data-balance="<?php echo $inv['balance_amount']; ?>"
                            data-total="<?php echo $inv['total_amount']; ?>"
                            data-company="<?php echo htmlspecialchars($inv['company_name']); ?>"
                            data-project="<?php echo htmlspecialchars($inv['project_name'] ?? ''); ?>"
                            <?php echo ($invoiceId && $inv['id'] == $invoiceId) ? 'selected' : ''; ?>>
                        #<?php echo htmlspecialchars($inv['invoice_number']); ?> - 
                        <?php echo htmlspecialchars($inv['company_name']); ?>
                        (<?php echo Helper::formatCurrency($inv['balance_amount']); ?> due)
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="text-xs text-gray-500 mt-1">Only invoices with outstanding balances are shown</p>
        </div>

        <!-- Dynamic Invoice Info -->
        <div id="selected-invoice-info" class="hidden bg-gray-50 border border-gray-200 rounded-lg p-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
                <div>
                    <span class="font-medium text-gray-900">Client:</span>
                    <span id="invoice-company" class="block text-gray-600"></span>
                </div>
                <div>
                    <span class="font-medium text-gray-900">Project:</span>
                    <span id="invoice-project" class="block text-gray-600"></span>
                </div>
                <div>
                    <span class="font-medium text-gray-900">Total Amount:</span>
                    <span id="invoice-total" class="block text-gray-600"></span>
                </div>
                <div>
                    <span class="font-medium text-gray-900">Outstanding:</span>
                    <span id="invoice-balance" class="block text-gray-600 font-semibold"></span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Payment Amount -->
            <div>
                <label for="payment_amount" class="block text-sm font-medium text-gray-900 mb-2">
                    Payment Amount <span class="text-red-500">*</span>
                </label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <span class="text-gray-500 sm:text-sm">$</span>
                    </div>
                    <input type="number" name="payment_amount" id="payment_amount" 
                           step="0.01" min="0.01" required
                           value="<?php echo $invoice ? number_format($invoice['balance_amount'], 2, '.', '') : ''; ?>"
                           class="w-full pl-8 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                           placeholder="0.00">
                </div>
                <div class="mt-1 flex items-center justify-between text-xs">
                    <span class="text-gray-500">Enter the payment amount</span>
                    <button type="button" id="pay-full-amount" class="text-blue-600 hover:text-blue-700 font-medium hidden">
                        Pay Full Amount
                    </button>
                </div>
            </div>

            <!-- Payment Date -->
            <div>
                <label for="payment_date" class="block text-sm font-medium text-gray-900 mb-2">
                    Payment Date <span class="text-red-500">*</span>
                </label>
                <input type="date" name="payment_date" id="payment_date" required
                       value="<?php echo date('Y-m-d'); ?>"
                       max="<?php echo date('Y-m-d'); ?>"
                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                <p class="text-xs text-gray-500 mt-1">When was this payment received?</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Payment Method -->
            <div>
                <label for="payment_method" class="block text-sm font-medium text-gray-900 mb-2">
                    Payment Method <span class="text-red-500">*</span>
                </label>
                <select name="payment_method" id="payment_method" required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                    <option value="">Select payment method...</option>
                    <option value="cash">Cash</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="check">Check</option>
                    <option value="card">Credit/Debit Card</option>
                    <option value="online">Online Payment</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <!-- Payment Reference -->
            <div>
                <label for="payment_reference" class="block text-sm font-medium text-gray-900 mb-2">
                    Payment Reference
                </label>
                <input type="text" name="payment_reference" id="payment_reference" 
                       maxlength="100"
                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                       placeholder="Check number, transaction ID, etc.">
                <p class="text-xs text-gray-500 mt-1">Optional: Check number, transaction ID, etc.</p>
            </div>
        </div>

        <!-- Notes -->
        <div>
            <label for="notes" class="block text-sm font-medium text-gray-900 mb-2">
                Notes
            </label>
            <textarea name="notes" id="notes" rows="3"
                      class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors resize-vertical"
                      placeholder="Additional notes about this payment..."></textarea>
            <p class="text-xs text-gray-500 mt-1">Optional: Any additional information about this payment</p>
        </div>

        <!-- Action Buttons -->
        <div class="flex flex-col sm:flex-row gap-4 pt-6 border-t border-gray-200">
            <button type="submit" 
                    class="flex-1 sm:flex-initial inline-flex items-center justify-center px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors font-medium">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Record Payment
            </button>
            
            <a href="<?php echo Helper::baseUrl('modules/invoices/'); ?>" 
               class="flex-1 sm:flex-initial inline-flex items-center justify-center px-6 py-3 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition-colors font-medium">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
                Cancel
            </a>
        </div>
    </form>
</div>

<!-- Quick Payment Tips -->
<div class="mt-6 bg-green-50 border border-green-200 rounded-lg p-4">
    <div class="flex">
        <svg class="w-5 h-5 text-green-400 mr-2 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
        </svg>
        <div>
            <h3 class="text-sm font-medium text-green-800">Payment Tips</h3>
            <ul class="mt-2 text-sm text-green-700 space-y-1">
                <li>• Partial payments are automatically supported</li>
                <li>• Invoice status will update automatically after payment</li>
                <li>• Include payment reference for better tracking</li>
                <li>• Payment date can be backdated if needed</li>
            </ul>
        </div>
    </div>
</div>

<style>
/* Form focus styles */
.form-input:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* Loading state */
.loading {
    opacity: 0.6;
    pointer-events: none;
}

/* Custom select styling */
select {
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
    background-position: right 0.5rem center;
    background-repeat: no-repeat;
    background-size: 1.5em 1.5em;
    padding-right: 2.5rem;
}

/* Animation for invoice info */
#selected-invoice-info {
    transition: all 0.3s ease-in-out;
}

#selected-invoice-info.show {
    animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Responsive improvements */
@media (max-width: 640px) {
    .grid {
        grid-template-columns: 1fr !important;
    }
}
</style>

<script>
// Update invoice details when invoice is selected
function updateInvoiceDetails() {
    const select = document.getElementById('invoice_id');
    const selectedOption = select.options[select.selectedIndex];
    const infoDiv = document.getElementById('selected-invoice-info');
    const paymentAmountInput = document.getElementById('payment_amount');
    const payFullButton = document.getElementById('pay-full-amount');
    
    if (selectedOption.value) {
        // Show invoice info
        document.getElementById('invoice-company').textContent = selectedOption.dataset.company || '';
        document.getElementById('invoice-project').textContent = selectedOption.dataset.project || 'No project';
        document.getElementById('invoice-total').textContent = formatCurrency(selectedOption.dataset.total || 0);
        document.getElementById('invoice-balance').textContent = formatCurrency(selectedOption.dataset.balance || 0);
        
        // Set payment amount to balance
        paymentAmountInput.value = parseFloat(selectedOption.dataset.balance || 0).toFixed(2);
        
        // Show the info div with animation
        infoDiv.classList.remove('hidden');
        infoDiv.classList.add('show');
        
        // Show pay full amount button
        payFullButton.classList.remove('hidden');
        
        // Update max attribute for payment amount
        paymentAmountInput.setAttribute('max', selectedOption.dataset.balance || 0);
        
    } else {
        // Hide invoice info
        infoDiv.classList.add('hidden');
        infoDiv.classList.remove('show');
        payFullButton.classList.add('hidden');
        paymentAmountInput.value = '';
        paymentAmountInput.removeAttribute('max');
    }
}

// Pay full amount button
document.getElementById('pay-full-amount').addEventListener('click', function() {
    const select = document.getElementById('invoice_id');
    const selectedOption = select.options[select.selectedIndex];
    const paymentAmountInput = document.getElementById('payment_amount');
    
    if (selectedOption.value) {
        paymentAmountInput.value = parseFloat(selectedOption.dataset.balance || 0).toFixed(2);
        paymentAmountInput.focus();
    }
});

// Format currency helper
function formatCurrency(amount) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD'
    }).format(amount);
}

// Validate payment amount
document.getElementById('payment_amount').addEventListener('input', function() {
    const input = this;
    const select = document.getElementById('invoice_id');
    const selectedOption = select.options[select.selectedIndex];
    
    if (selectedOption.value) {
        const balance = parseFloat(selectedOption.dataset.balance || 0);
        const amount = parseFloat(input.value || 0);
        
        if (amount > balance) {
            input.setCustomValidity(`Payment amount cannot exceed the outstanding balance of ${formatCurrency(balance)}`);
        } else {
            input.setCustomValidity('');
        }
    }
});

// Auto-fill payment reference based on method
document.getElementById('payment_method').addEventListener('change', function() {
    const method = this.value;
    const referenceInput = document.getElementById('payment_reference');
    
    // Clear previous placeholder
    referenceInput.placeholder = '';
    
    switch (method) {
        case 'check':
            referenceInput.placeholder = 'Check number';
            break;
        case 'bank_transfer':
            referenceInput.placeholder = 'Transfer reference or confirmation number';
            break;
        case 'card':
            referenceInput.placeholder = 'Last 4 digits or authorization code';
            break;
        case 'online':
            referenceInput.placeholder = 'Transaction ID or reference';
            break;
        case 'cash':
            referenceInput.placeholder = 'Receipt number (optional)';
            break;
        default:
            referenceInput.placeholder = 'Reference number or note';
    }
});

// Form submission validation
document.querySelector('form').addEventListener('submit', function(e) {
    const paymentAmount = parseFloat(document.getElementById('payment_amount').value || 0);
    const select = document.getElementById('invoice_id');
    const selectedOption = select.options[select.selectedIndex];
    
    if (selectedOption.value) {
        const balance = parseFloat(selectedOption.dataset.balance || 0);
        
        if (paymentAmount <= 0) {
            e.preventDefault();
            alert('Payment amount must be greater than zero.');
            return;
        }
        
        if (paymentAmount > balance) {
            e.preventDefault();
            alert(`Payment amount cannot exceed the outstanding balance of ${formatCurrency(balance)}.`);
            return;
        }
    }
    
    // Show loading state
    const submitButton = e.target.querySelector('button[type="submit"]');
    const originalText = submitButton.innerHTML;
    submitButton.innerHTML = '<svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Processing...';
    submitButton.disabled = true;
    
    // Re-enable after 10 seconds as fallback
    setTimeout(() => {
        submitButton.innerHTML = originalText;
        submitButton.disabled = false;
    }, 10000);
});

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    // Update invoice details if pre-selected
    updateInvoiceDetails();
    
    // Auto-focus on invoice selection if no invoice is pre-selected
    const invoiceSelect = document.getElementById('invoice_id');
    if (!invoiceSelect.value) {
        invoiceSelect.focus();
    } else {
        // Focus on payment amount if invoice is pre-selected
        document.getElementById('payment_amount').focus();
    }
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl/Cmd + Enter to submit
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        document.querySelector('form').requestSubmit();
    }
    
    // Escape to cancel
    if (e.key === 'Escape') {
        if (confirm('Are you sure you want to cancel? Any unsaved changes will be lost.')) {
            window.location.href = '<?php echo Helper::baseUrl('modules/invoices/'); ?>';
        }
    }
});

// Auto-save draft (optional)
let autoSaveTimeout;
function autoSaveDraft() {
    clearTimeout(autoSaveTimeout);
    autoSaveTimeout = setTimeout(() => {
        // In a real application, you might want to save draft to localStorage
        const formData = new FormData(document.querySelector('form'));
        const draftData = Object.fromEntries(formData.entries());
        localStorage.setItem('payment_draft', JSON.stringify(draftData));
    }, 2000);
}

// Add auto-save listeners
document.querySelectorAll('input, select, textarea').forEach(input => {
    input.addEventListener('input', autoSaveDraft);
    input.addEventListener('change', autoSaveDraft);
});

// Load draft on page load
window.addEventListener('load', function() {
    const draft = localStorage.getItem('payment_draft');
    if (draft && !document.getElementById('invoice_id').value) {
        try {
            const draftData = JSON.parse(draft);
            Object.keys(draftData).forEach(key => {
                const input = document.querySelector(`[name="${key}"]`);
                if (input && input.value === '') {
                    input.value = draftData[key];
                }
            });
            updateInvoiceDetails();
        } catch (e) {
            // Ignore draft restore errors
        }
    }
});

// Clear draft on successful submission
if (window.location.search.includes('success=1')) {
    localStorage.removeItem('payment_draft');
}
</script>

<?php include '../../includes/footer.php'; ?>
