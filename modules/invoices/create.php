<?php
// modules/invoices/create.php
session_start();

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

$pageTitle = 'Create Invoice - Invoice Manager';

// Initialize variables
$errors = [];
$projectId = null;
$project = null;
$client = null;
$projectItems = [];
$formData = [
    'invoice_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+30 days')),
    'tax_rate' => '0.00',
    'notes' => '',
    'status' => 'draft'
];

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Check if project ID is provided and validate it
if (isset($_GET['project_id'])) {
    $projectId = Helper::decryptId($_GET['project_id']);
    if ($projectId) {
        try {
            // Get project details with client information
            $projectQuery = "
                SELECT p.*, c.company_name, c.contact_person, c.mobile_number, c.address, c.city
                FROM projects p 
                LEFT JOIN clients c ON p.client_id = c.id 
                WHERE p.id = :project_id
            ";
            $projectStmt = $db->prepare($projectQuery);
            $projectStmt->bindParam(':project_id', $projectId);
            $projectStmt->execute();
            
            $project = $projectStmt->fetch(PDO::FETCH_ASSOC);
            if (!$project) {
                Helper::setMessage('Project not found.', 'error');
                Helper::redirect('modules/projects/');
            }

            $client = $project; // Client info is included in project query

            // Get project items if they exist
            try {
                $itemsQuery = "
                    SELECT * FROM project_items 
                    WHERE project_id = :project_id 
                    ORDER BY created_at ASC
                ";
                $itemsStmt = $db->prepare($itemsQuery);
                $itemsStmt->bindParam(':project_id', $projectId);
                $itemsStmt->execute();
                $projectItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // project_items table might not exist yet
                $projectItems = [];
            }

        } catch (Exception $e) {
            Helper::setMessage('Error loading project details.', 'error');
            Helper::redirect('modules/projects/');
        }
    } else {
        Helper::setMessage('Invalid project ID.', 'error');
        Helper::redirect('modules/projects/');
    }
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
        $formData['invoice_date'] = Helper::sanitize($_POST['invoice_date'] ?? '');
        $formData['due_date'] = Helper::sanitize($_POST['due_date'] ?? '');
        $formData['tax_rate'] = floatval($_POST['tax_rate'] ?? 0);
        $formData['notes'] = Helper::sanitize($_POST['notes'] ?? '');
        $formData['status'] = Helper::sanitize($_POST['status'] ?? 'draft');

        // Get invoice items from form
        $invoiceItems = [];
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                if (!empty($item['description']) && !empty($item['quantity']) && !empty($item['unit_price'])) {
                    $invoiceItems[] = [
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

        if (empty($invoiceItems)) {
            $errors[] = 'Please add at least one invoice item.';
        }

        // If no errors, create the invoice
        if (empty($errors)) {
            try {
                $db->beginTransaction();

                // Calculate totals
                $subtotal = array_sum(array_column($invoiceItems, 'total'));
                $taxAmount = ($subtotal * $formData['tax_rate']) / 100;
                $totalAmount = $subtotal + $taxAmount;

                // Generate invoice number
                $currentYear = date('Y');
                $invoiceNumberQuery = "SELECT COUNT(*) + 1 as next_number FROM invoices WHERE YEAR(created_at) = :year";
                $invoiceNumberStmt = $db->prepare($invoiceNumberQuery);
                $invoiceNumberStmt->bindParam(':year', $currentYear);
                $invoiceNumberStmt->execute();
                $nextNumber = $invoiceNumberStmt->fetch(PDO::FETCH_ASSOC)['next_number'];
                $invoiceNumber = 'INV-' . $currentYear . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

                // Use project's client if project is selected, otherwise use selected client
                $finalClientId = $selectedProjectId ? 
                    (($project && $project['client_id']) ? $project['client_id'] : $selectedClientId) : 
                    $selectedClientId;

                // Insert invoice
                $invoiceQuery = "
                    INSERT INTO invoices (
                        invoice_number, project_id, client_id, invoice_date, due_date,
                        subtotal, tax_rate, tax_amount, total_amount, balance_amount,
                        status, notes, created_by
                    ) VALUES (
                        :invoice_number, :project_id, :client_id, :invoice_date, :due_date,
                        :subtotal, :tax_rate, :tax_amount, :total_amount, :balance_amount,
                        :status, :notes, :created_by
                    )
                ";
                
                $invoiceStmt = $db->prepare($invoiceQuery);
                $invoiceStmt->bindParam(':invoice_number', $invoiceNumber);
                $invoiceStmt->bindParam(':project_id', $selectedProjectId);
                $invoiceStmt->bindParam(':client_id', $finalClientId);
                $invoiceStmt->bindParam(':invoice_date', $formData['invoice_date']);
                $invoiceStmt->bindParam(':due_date', $formData['due_date']);
                $invoiceStmt->bindParam(':subtotal', $subtotal);
                $invoiceStmt->bindParam(':tax_rate', $formData['tax_rate']);
                $invoiceStmt->bindParam(':tax_amount', $taxAmount);
                $invoiceStmt->bindParam(':total_amount', $totalAmount);
                $invoiceStmt->bindParam(':balance_amount', $totalAmount); // Initially, balance = total
                $invoiceStmt->bindParam(':status', $formData['status']);
                $invoiceStmt->bindParam(':notes', $formData['notes']);
                $invoiceStmt->bindParam(':created_by', $_SESSION['user_id']);
                
                $invoiceStmt->execute();
                $invoiceId = $db->lastInsertId();

                // Insert invoice items (you may need to create this table)
                try {
                    foreach ($invoiceItems as $item) {
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

                Helper::setMessage('Invoice created successfully! Invoice number: ' . $invoiceNumber, 'success');
                Helper::redirect('modules/invoices/view.php?id=' . Helper::encryptId($invoiceId));

            } catch (Exception $e) {
                $db->rollBack();
                $errors[] = 'Error creating invoice: ' . $e->getMessage();
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
        <?php if ($project): ?>
            <a href="<?php echo Helper::baseUrl('modules/projects/view.php?id=' . Helper::encryptId($project['id'])); ?>" 
               class="hover:text-gray-900 transition-colors">
                <?php echo htmlspecialchars($project['project_name']); ?>
            </a>
            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
        <?php endif; ?>
        <span class="text-gray-900 font-medium">Create Invoice</span>
    </div>
</nav>

<!-- Page Header -->
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Create Invoice</h1>
        <p class="text-gray-600 mt-1">
            <?php if ($project): ?>
                Creating invoice for project: <span class="font-medium"><?php echo htmlspecialchars($project['project_name']); ?></span>
            <?php else: ?>
                Create a new invoice for your client
            <?php endif; ?>
        </p>
    </div>
</div>

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
    
    <!-- Project/Client Selection -->
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
                                <?php echo ($projectId && $projectId == $availableProject['id']) ? 'selected' : ''; ?>
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
                                <?php echo ($client && $client['client_id'] == $availableClient['id']) ? 'selected' : ''; ?>>
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
                       value="<?php echo htmlspecialchars($formData['invoice_date']); ?>"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                       required>
            </div>

            <!-- Due Date -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Due Date <span class="text-red-500">*</span></label>
                <input type="date" 
                       name="due_date" 
                       value="<?php echo htmlspecialchars($formData['due_date']); ?>"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                       required>
            </div>

            <!-- Tax Rate -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Tax Rate (%)</label>
                <input type="number" 
                       name="tax_rate" 
                       value="<?php echo htmlspecialchars($formData['tax_rate']); ?>"
                       min="0" 
                       max="100" 
                       step="0.01"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                       id="taxRate">
            </div>

            <!-- Status -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                <select name="status" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="draft" <?php echo $formData['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="sent" <?php echo $formData['status'] === 'sent' ? 'selected' : ''; ?>>Sent</option>
                </select>
            </div>
        </div>

        <!-- Notes -->
        <div class="mt-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
            <textarea name="notes" 
                      rows="3" 
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                      placeholder="Additional notes or terms..."><?php echo htmlspecialchars($formData['notes']); ?></textarea>
        </div>
    </div>

    <!-- Client Info Preview -->
    <?php if ($client): ?>
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <h3 class="font-medium text-blue-900 mb-2">Invoice To:</h3>
            <div class="text-sm text-blue-800">
                <p class="font-medium"><?php echo htmlspecialchars($client['company_name']); ?></p>
                <p><?php echo htmlspecialchars($client['contact_person']); ?></p>
                <?php if (!empty($client['address'])): ?>
                    <p><?php echo htmlspecialchars($client['address']); ?></p>
                <?php endif; ?>
                <?php if (!empty($client['city'])): ?>
                    <p><?php echo htmlspecialchars($client['city']); ?></p>
                <?php endif; ?>
                <?php if (!empty($client['mobile_number'])): ?>
                    <p>Tel: <?php echo htmlspecialchars($client['mobile_number']); ?></p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

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
                    <!-- Pre-populate with project items if available -->
                    <?php if (!empty($projectItems)): ?>
                        <?php foreach ($projectItems as $index => $item): ?>
                            <tr class="invoice-item border-b border-gray-100">
                                <td class="py-2 px-2">
                                    <input type="text" 
                                           name="items[<?php echo $index; ?>][description]" 
                                           value="<?php echo htmlspecialchars($item['item_name'] . (!empty($item['description']) ? ' - ' . $item['description'] : '')); ?>"
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
                                           value="<?php echo Helper::formatCurrency($item['quantity'] * $item['unit_price']); ?>"
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
                        <!-- Add one empty row if no project items -->
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
            </div>
        </div>
    </div>

    <!-- Form Actions -->
    <div class="flex flex-col sm:flex-row gap-4 sm:justify-end">
        <a href="<?php echo Helper::baseUrl($project ? 'modules/projects/view.php?id=' . Helper::encryptId($project['id']) : 'modules/invoices/'); ?>" 
           class="px-6 py-3 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors text-center">
            Cancel
        </a>
        <button type="submit" 
                name="status" 
                value="draft"
                class="px-6 py-3 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors">
            Save as Draft
        </button>
        <button type="submit" 
                name="status" 
                value="sent"
                class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
            Create & Send Invoice
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
</style>

<script>
let itemIndex = <?php echo !empty($projectItems) ? count($projectItems) : 1; ?>;

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
        // You could make an AJAX call here to get project items
        // For now, we'll just show the client info
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
    // Add loading state
    this.classList.add('form-submitting');
    
    // Disable submit buttons to prevent double submission
    const submitButtons = this.querySelectorAll('button[type="submit"]');
    submitButtons.forEach(button => {
        button.disabled = true;
        button.textContent = 'Creating...';
    });
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
    // Ctrl+Enter to save as draft
    if (e.ctrlKey && e.key === 'Enter') {
        e.preventDefault();
        document.querySelector('button[value="draft"]').click();
    }
    
    // Ctrl+Shift+Enter to create and send
    if (e.ctrlKey && e.shiftKey && e.key === 'Enter') {
        e.preventDefault();
        document.querySelector('button[value="sent"]').click();
    }
    
    // Ctrl+Plus to add item
    if (e.ctrlKey && e.key === '=') {
        e.preventDefault();
        addInvoiceItem();
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
