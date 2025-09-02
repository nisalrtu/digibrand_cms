<?php
// modules/expenses/add.php
require_once '../../config/config.php';
require_once '../../core/database.php';
require_once '../../core/Helper.php';

$pageTitle = 'Add New Expense';
$message = '';
$messageType = '';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

// Database connection
$database = new Database();
$db = $database->getConnection();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get form data
        $expense_name = Helper::sanitize($_POST['expense_name']);
        $description = Helper::sanitize($_POST['description']);
        $amount = floatval($_POST['amount']);
        $expense_date = $_POST['expense_date'];
        $category = $_POST['category'];
        $vendor_name = Helper::sanitize($_POST['vendor_name']);
        $vendor_contact = Helper::sanitize($_POST['vendor_contact']);
        $payment_status = $_POST['payment_status'];
        $payment_method = $_POST['payment_method'] ?? null;
        $payment_date = $_POST['payment_date'] ?? null;
        $payment_reference = Helper::sanitize($_POST['payment_reference']);
        $notes = Helper::sanitize($_POST['notes']);
        $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
        $recurring_type = $_POST['recurring_type'] ?? null;
        $next_due_date = null;
        
        // Calculate next due date if recurring
        if ($is_recurring && $recurring_type) {
            $expense_date_obj = new DateTime($expense_date);
            switch ($recurring_type) {
                case 'weekly':
                    $expense_date_obj->add(new DateInterval('P1W'));
                    break;
                case 'monthly':
                    $expense_date_obj->add(new DateInterval('P1M'));
                    break;
                case 'quarterly':
                    $expense_date_obj->add(new DateInterval('P3M'));
                    break;
                case 'yearly':
                    $expense_date_obj->add(new DateInterval('P1Y'));
                    break;
            }
            $next_due_date = $expense_date_obj->format('Y-m-d');
        }
        
        // Handle file upload for receipt
        $receipt_path = null;
        if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../../assets/uploads/receipts/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION);
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
            
            if (in_array(strtolower($file_extension), $allowed_extensions)) {
                $filename = 'receipt_' . time() . '_' . rand(1000, 9999) . '.' . $file_extension;
                $upload_path = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['receipt']['tmp_name'], $upload_path)) {
                    $receipt_path = 'assets/uploads/receipts/' . $filename;
                }
            }
        }
        
        // Prepare SQL query
        $query = "INSERT INTO expenses (
            expense_name, description, amount, expense_date, category,
            is_recurring, recurring_type, next_due_date,
            payment_status, payment_date, payment_method, payment_reference,
            vendor_name, vendor_contact, receipt_path, notes, created_by
        ) VALUES (
            :expense_name, :description, :amount, :expense_date, :category,
            :is_recurring, :recurring_type, :next_due_date,
            :payment_status, :payment_date, :payment_method, :payment_reference,
            :vendor_name, :vendor_contact, :receipt_path, :notes, :created_by
        )";
        
        $stmt = $db->prepare($query);
        
        // Bind parameters
        $stmt->bindParam(':expense_name', $expense_name);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':amount', $amount);
        $stmt->bindParam(':expense_date', $expense_date);
        $stmt->bindParam(':category', $category);
        $stmt->bindParam(':is_recurring', $is_recurring);
        $stmt->bindParam(':recurring_type', $recurring_type);
        $stmt->bindParam(':next_due_date', $next_due_date);
        $stmt->bindParam(':payment_status', $payment_status);
        $stmt->bindParam(':payment_date', $payment_date);
        $stmt->bindParam(':payment_method', $payment_method);
        $stmt->bindParam(':payment_reference', $payment_reference);
        $stmt->bindParam(':vendor_name', $vendor_name);
        $stmt->bindParam(':vendor_contact', $vendor_contact);
        $stmt->bindParam(':receipt_path', $receipt_path);
        $stmt->bindParam(':notes', $notes);
        $stmt->bindParam(':created_by', $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            Helper::setMessage('Expense added successfully!', 'success');
            Helper::redirect('modules/expenses/');
        } else {
            $message = 'Error adding expense. Please try again.';
            $messageType = 'error';
        }
        
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Include header
include '../../includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <!-- Page Header -->
    <div class="mb-8">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Add New Expense</h1>
                <p class="mt-2 text-gray-600">Create a new expense record with optional recurring settings</p>
            </div>
            <div class="mt-4 sm:mt-0">
                <a href="<?php echo Helper::baseUrl('modules/expenses/'); ?>" 
                   class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                    Back to Expenses
                </a>
            </div>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php 
    $flash = Helper::getFlashMessage();
    if ($flash): 
    ?>
        <div class="mb-6 p-4 rounded-lg <?php echo $flash['type'] === 'success' ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200'; ?>">
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="mb-6 p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200'; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- Expense Form -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
        <form method="POST" enctype="multipart/form-data" class="p-6 space-y-6">
            <!-- Basic Information -->
            <div>
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Basic Information</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Expense Name -->
                    <div>
                        <label for="expense_name" class="block text-sm font-medium text-gray-700 mb-2">
                            Expense Name <span class="text-red-500">*</span>
                        </label>
                        <input type="text" 
                               id="expense_name" 
                               name="expense_name" 
                               required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="e.g., Office 365 Subscription">
                    </div>

                    <!-- Amount -->
                    <div>
                        <label for="amount" class="block text-sm font-medium text-gray-700 mb-2">
                            Amount (LKR) <span class="text-red-500">*</span>
                        </label>
                        <input type="number" 
                               id="amount" 
                               name="amount" 
                               step="0.01" 
                               min="0" 
                               required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="0.00">
                    </div>

                    <!-- Expense Date -->
                    <div>
                        <label for="expense_date" class="block text-sm font-medium text-gray-700 mb-2">
                            Expense Date <span class="text-red-500">*</span>
                        </label>
                        <input type="date" 
                               id="expense_date" 
                               name="expense_date" 
                               required
                               value="<?php echo date('Y-m-d'); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <!-- Category -->
                    <div>
                        <label for="category" class="block text-sm font-medium text-gray-700 mb-2">
                            Category <span class="text-red-500">*</span>
                        </label>
                        <select id="category" 
                                name="category" 
                                required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Select Category</option>
                            <option value="software">Software</option>
                            <option value="marketing">Marketing</option>
                            <option value="office">Office</option>
                            <option value="utilities">Utilities</option>
                            <option value="travel">Travel</option>
                            <option value="equipment">Equipment</option>
                            <option value="subscription">Subscription</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>

                <!-- Description -->
                <div class="mt-6">
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
                        Description
                    </label>
                    <textarea id="description" 
                              name="description" 
                              rows="3"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                              placeholder="Additional details about this expense..."></textarea>
                </div>
            </div>

            <!-- Recurring Settings -->
            <div class="border-t border-gray-200 pt-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Recurring Settings</h3>
                
                <!-- Is Recurring Checkbox -->
                <div class="mb-4">
                    <label class="flex items-center">
                        <input type="checkbox" 
                               id="is_recurring" 
                               name="is_recurring" 
                               value="1"
                               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                        <span class="ml-2 text-sm font-medium text-gray-700">This is a recurring expense</span>
                    </label>
                </div>

                <!-- Recurring Type and Next Due Date -->
                <div id="recurring_options" class="hidden grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="recurring_type" class="block text-sm font-medium text-gray-700 mb-2">
                            Recurring Type
                        </label>
                        <select id="recurring_type" 
                                name="recurring_type"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Select Type</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly (3 months)</option>
                            <option value="yearly">Yearly</option>
                        </select>
                    </div>

                    <div>
                        <label for="next_due_preview" class="block text-sm font-medium text-gray-700 mb-2">
                            Next Due Date (Preview)
                        </label>
                        <input type="text" 
                               id="next_due_preview" 
                               readonly
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 text-gray-600"
                               placeholder="Select recurring type and expense date">
                    </div>
                </div>
            </div>

            <!-- Vendor Information -->
            <div class="border-t border-gray-200 pt-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Vendor Information</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="vendor_name" class="block text-sm font-medium text-gray-700 mb-2">
                            Vendor Name
                        </label>
                        <input type="text" 
                               id="vendor_name" 
                               name="vendor_name"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="e.g., Microsoft, Adobe, etc.">
                    </div>

                    <div>
                        <label for="vendor_contact" class="block text-sm font-medium text-gray-700 mb-2">
                            Vendor Contact
                        </label>
                        <input type="text" 
                               id="vendor_contact" 
                               name="vendor_contact"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="Phone, email, or website">
                    </div>
                </div>
            </div>

            <!-- Payment Information -->
            <div class="border-t border-gray-200 pt-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Payment Information</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <!-- Payment Status -->
                    <div>
                        <label for="payment_status" class="block text-sm font-medium text-gray-700 mb-2">
                            Payment Status <span class="text-red-500">*</span>
                        </label>
                        <select id="payment_status" 
                                name="payment_status" 
                                required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="pending">Pending</option>
                            <option value="paid">Paid</option>
                            <option value="overdue">Overdue</option>
                        </select>
                    </div>

                    <!-- Payment Method -->
                    <div id="payment_method_div" class="hidden">
                        <label for="payment_method" class="block text-sm font-medium text-gray-700 mb-2">
                            Payment Method
                        </label>
                        <select id="payment_method" 
                                name="payment_method"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Select Method</option>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="check">Check</option>
                            <option value="card">Card</option>
                            <option value="online">Online Payment</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <!-- Payment Date -->
                    <div id="payment_date_div" class="hidden">
                        <label for="payment_date" class="block text-sm font-medium text-gray-700 mb-2">
                            Payment Date
                        </label>
                        <input type="date" 
                               id="payment_date" 
                               name="payment_date"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>

                <!-- Payment Reference -->
                <div id="payment_reference_div" class="hidden mt-6">
                    <label for="payment_reference" class="block text-sm font-medium text-gray-700 mb-2">
                        Payment Reference
                    </label>
                    <input type="text" 
                           id="payment_reference" 
                           name="payment_reference"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="Transaction ID, check number, etc.">
                </div>
            </div>

            <!-- Receipt Upload -->
            <div class="border-t border-gray-200 pt-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Receipt/Invoice</h3>
                <div>
                    <label for="receipt" class="block text-sm font-medium text-gray-700 mb-2">
                        Upload Receipt/Invoice
                    </label>
                    <input type="file" 
                           id="receipt" 
                           name="receipt"
                           accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <p class="mt-1 text-sm text-gray-500">
                        Supported formats: JPG, PNG, GIF, PDF, DOC, DOCX (Max 5MB)
                    </p>
                </div>
            </div>

            <!-- Notes -->
            <div class="border-t border-gray-200 pt-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Additional Notes</h3>
                <div>
                    <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">
                        Notes
                    </label>
                    <textarea id="notes" 
                              name="notes" 
                              rows="4"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                              placeholder="Any additional notes or comments..."></textarea>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="border-t border-gray-200 pt-6">
                <div class="flex flex-col sm:flex-row sm:justify-end sm:space-x-4 space-y-3 sm:space-y-0">
                    <a href="<?php echo Helper::baseUrl('modules/expenses/'); ?>" 
                       class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors text-center">
                        Cancel
                    </a>
                    <button type="submit" 
                            class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Add Expense
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const isRecurringCheckbox = document.getElementById('is_recurring');
    const recurringOptions = document.getElementById('recurring_options');
    const recurringTypeSelect = document.getElementById('recurring_type');
    const expenseDateInput = document.getElementById('expense_date');
    const nextDuePreview = document.getElementById('next_due_preview');
    const paymentStatusSelect = document.getElementById('payment_status');
    const paymentMethodDiv = document.getElementById('payment_method_div');
    const paymentDateDiv = document.getElementById('payment_date_div');
    const paymentReferenceDiv = document.getElementById('payment_reference_div');

    // Toggle recurring options
    isRecurringCheckbox.addEventListener('change', function() {
        if (this.checked) {
            recurringOptions.classList.remove('hidden');
        } else {
            recurringOptions.classList.add('hidden');
            recurringTypeSelect.value = '';
            nextDuePreview.value = '';
        }
    });

    // Calculate next due date
    function calculateNextDueDate() {
        const expenseDate = expenseDateInput.value;
        const recurringType = recurringTypeSelect.value;
        
        if (expenseDate && recurringType) {
            const date = new Date(expenseDate);
            
            switch (recurringType) {
                case 'weekly':
                    date.setDate(date.getDate() + 7);
                    break;
                case 'monthly':
                    date.setMonth(date.getMonth() + 1);
                    break;
                case 'quarterly':
                    date.setMonth(date.getMonth() + 3);
                    break;
                case 'yearly':
                    date.setFullYear(date.getFullYear() + 1);
                    break;
            }
            
            const nextDue = date.toISOString().split('T')[0];
            nextDuePreview.value = nextDue;
        } else {
            nextDuePreview.value = '';
        }
    }

    // Event listeners for due date calculation
    recurringTypeSelect.addEventListener('change', calculateNextDueDate);
    expenseDateInput.addEventListener('change', calculateNextDueDate);

    // Toggle payment fields based on status
    paymentStatusSelect.addEventListener('change', function() {
        const status = this.value;
        
        if (status === 'paid') {
            paymentMethodDiv.classList.remove('hidden');
            paymentDateDiv.classList.remove('hidden');
            paymentReferenceDiv.classList.remove('hidden');
            
            // Set payment date to today if not set
            const paymentDateInput = document.getElementById('payment_date');
            if (!paymentDateInput.value) {
                paymentDateInput.value = new Date().toISOString().split('T')[0];
            }
        } else {
            paymentMethodDiv.classList.add('hidden');
            paymentDateDiv.classList.add('hidden');
            paymentReferenceDiv.classList.add('hidden');
            
            // Clear payment fields
            document.getElementById('payment_method').value = '';
            document.getElementById('payment_date').value = '';
            document.getElementById('payment_reference').value = '';
        }
    });

    // Format amount input
    const amountInput = document.getElementById('amount');
    amountInput.addEventListener('input', function() {
        let value = this.value;
        // Remove any non-numeric characters except decimal point
        value = value.replace(/[^0-9.]/g, '');
        
        // Ensure only one decimal point
        const parts = value.split('.');
        if (parts.length > 2) {
            value = parts[0] + '.' + parts.slice(1).join('');
        }
        
        // Limit decimal places to 2
        if (parts[1] && parts[1].length > 2) {
            value = parts[0] + '.' + parts[1].substring(0, 2);
        }
        
        this.value = value;
    });

    // File upload validation
    const receiptInput = document.getElementById('receipt');
    receiptInput.addEventListener('change', function() {
        const file = this.files[0];
        if (file) {
            const maxSize = 5 * 1024 * 1024; // 5MB
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'application/pdf', 
                                 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            
            if (file.size > maxSize) {
                alert('File size must be less than 5MB');
                this.value = '';
                return;
            }
            
            if (!allowedTypes.includes(file.type)) {
                alert('Please select a valid file format (JPG, PNG, GIF, PDF, DOC, DOCX)');
                this.value = '';
                return;
            }
        }
    });

    // Form validation
    const form = document.querySelector('form');
    form.addEventListener('submit', function(e) {
        const expenseName = document.getElementById('expense_name').value.trim();
        const amount = document.getElementById('amount').value;
        const category = document.getElementById('category').value;
        
        if (!expenseName) {
            alert('Please enter expense name');
            e.preventDefault();
            return;
        }
        
        if (!amount || parseFloat(amount) <= 0) {
            alert('Please enter a valid amount');
            e.preventDefault();
            return;
        }
        
        if (!category) {
            alert('Please select a category');
            e.preventDefault();
            return;
        }
        
        // Validate recurring settings
        if (isRecurringCheckbox.checked && !recurringTypeSelect.value) {
            alert('Please select a recurring type');
            e.preventDefault();
            return;
        }
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
