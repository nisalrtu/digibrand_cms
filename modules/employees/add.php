<?php
// modules/employees/add.php
session_start();

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

// Check if user is admin - only admins can access employee management
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    Helper::setMessage('Access denied. Only administrators can manage employees.', 'error');
    Helper::redirect('modules/dashboard/');
}

$pageTitle = 'Add Employee Payment - CRM System';

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Initialize variables
$errors = [];
$preSelectedEmployee = null;
$preSelectedProject = null;

// Initialize form data
$formData = [
    'employee_id' => '',
    'project_id' => '',
    'payment_amount' => '',
    'payment_date' => date('Y-m-d'),
    'payment_method' => 'cash',
    'payment_reference' => '',
    'description' => '',
    'work_description' => '',
    'payment_status' => 'paid',
    'notes' => ''
];

// Check for pre-selected employee
if (!empty($_GET['employee_id'])) {
    $employeeId = Helper::decryptId($_GET['employee_id']);
    if ($employeeId) {
        $formData['employee_id'] = $employeeId;
    }
}

// Check for pre-selected project
if (!empty($_GET['project_id'])) {
    $projectId = Helper::decryptId($_GET['project_id']);
    if ($projectId) {
        $formData['project_id'] = $projectId;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!Helper::verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        // Get and sanitize form data
        $formData['employee_id'] = intval($_POST['employee_id'] ?? 0);
        $formData['project_id'] = !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;
        $formData['payment_amount'] = Helper::sanitize($_POST['payment_amount'] ?? '');
        $formData['payment_date'] = Helper::sanitize($_POST['payment_date'] ?? '');
        $formData['payment_method'] = Helper::sanitize($_POST['payment_method'] ?? '');
        $formData['payment_reference'] = Helper::sanitize($_POST['payment_reference'] ?? '');
        $formData['description'] = Helper::sanitize($_POST['description'] ?? '');
        $formData['work_description'] = Helper::sanitize($_POST['work_description'] ?? '');
        $formData['payment_status'] = Helper::sanitize($_POST['payment_status'] ?? '');
        $formData['notes'] = Helper::sanitize($_POST['notes'] ?? '');
        
        // Validate required fields
        if (empty($formData['employee_id']) || $formData['employee_id'] <= 0) {
            $errors[] = 'Please select an employee.';
        }
        
        if (empty($formData['payment_amount']) || !is_numeric($formData['payment_amount']) || $formData['payment_amount'] <= 0) {
            $errors[] = 'Please enter a valid payment amount.';
        } elseif ($formData['payment_amount'] > 999999.99) {
            $errors[] = 'Payment amount cannot exceed ' . Helper::formatCurrency(999999.99) . '.';
        }
        
        if (empty($formData['payment_date'])) {
            $errors[] = 'Payment date is required.';
        } elseif (!DateTime::createFromFormat('Y-m-d', $formData['payment_date'])) {
            $errors[] = 'Please enter a valid payment date.';
        } elseif (strtotime($formData['payment_date']) > time()) {
            $errors[] = 'Payment date cannot be in the future.';
        }
        
        if (!in_array($formData['payment_method'], ['cash', 'bank_transfer', 'check', 'card', 'online', 'other'])) {
            $errors[] = 'Please select a valid payment method.';
        }
        
        if (!in_array($formData['payment_status'], ['pending', 'paid', 'cancelled'])) {
            $errors[] = 'Please select a valid payment status.';
        }
        
        if (!empty($formData['payment_reference']) && strlen($formData['payment_reference']) > 100) {
            $errors[] = 'Payment reference must be less than 100 characters.';
        }
        
        if (!empty($formData['work_description']) && strlen($formData['work_description']) > 1000) {
            $errors[] = 'Work description must be less than 1000 characters.';
        }
        
        if (!empty($formData['notes']) && strlen($formData['notes']) > 1000) {
            $errors[] = 'Notes must be less than 1000 characters.';
        }
        
        // Validate employee exists and is active
        if (empty($errors)) {
            try {
                $employeeQuery = "SELECT id, full_name, is_active FROM users WHERE id = :employee_id";
                $employeeStmt = $db->prepare($employeeQuery);
                $employeeStmt->bindParam(':employee_id', $formData['employee_id']);
                $employeeStmt->execute();
                $employee = $employeeStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$employee) {
                    $errors[] = 'Selected employee not found.';
                } elseif (!$employee['is_active']) {
                    $errors[] = 'Cannot add payment for inactive employee.';
                }
            } catch (Exception $e) {
                $errors[] = 'Error validating employee.';
            }
        }
        
        // Validate project if selected
        if (empty($errors) && !empty($formData['project_id'])) {
            try {
                $projectQuery = "SELECT id, project_name FROM projects WHERE id = :project_id";
                $projectStmt = $db->prepare($projectQuery);
                $projectStmt->bindParam(':project_id', $formData['project_id']);
                $projectStmt->execute();
                $project = $projectStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$project) {
                    $errors[] = 'Selected project not found.';
                }
            } catch (Exception $e) {
                $errors[] = 'Error validating project.';
            }
        }
        
        // Auto-generate description if empty
        if (empty($formData['description'])) {
            try {
                $employeeName = $employee['full_name'] ?? 'Unknown Employee';
                $projectName = !empty($formData['project_id']) && isset($project) ? $project['project_name'] : null;
                
                $description = $employeeName;
                if ($projectName) {
                    $description .= ' - ' . $projectName;
                }
                $description .= ' - ' . Helper::formatCurrency($formData['payment_amount']);
                $description .= ' - ' . date('M d, Y', strtotime($formData['payment_date']));
                $description .= ' - ' . ucwords(str_replace('_', ' ', $formData['payment_method']));
                
                if (!empty($formData['work_description'])) {
                    $workDesc = strlen($formData['work_description']) > 50 ? 
                                substr($formData['work_description'], 0, 50) . '...' : 
                                $formData['work_description'];
                    $description .= ' - ' . $workDesc;
                }
                
                $formData['description'] = $description;
            } catch (Exception $e) {
                $formData['description'] = 'Payment to employee';
            }
        }
        
        // Insert payment if no errors
        if (empty($errors)) {
            try {
                $insertQuery = "
                    INSERT INTO employee_payments (
                        employee_id, project_id, payment_amount, payment_date, 
                        payment_method, payment_reference, description, work_description, 
                        payment_status, notes, created_by
                    ) VALUES (
                        :employee_id, :project_id, :payment_amount, :payment_date, 
                        :payment_method, :payment_reference, :description, :work_description, 
                        :payment_status, :notes, :created_by
                    )
                ";
                
                $insertStmt = $db->prepare($insertQuery);
                $insertStmt->bindParam(':employee_id', $formData['employee_id']);
                $insertStmt->bindParam(':project_id', $formData['project_id']);
                $insertStmt->bindParam(':payment_amount', $formData['payment_amount']);
                $insertStmt->bindParam(':payment_date', $formData['payment_date']);
                $insertStmt->bindParam(':payment_method', $formData['payment_method']);
                $insertStmt->bindParam(':payment_reference', $formData['payment_reference']);
                $insertStmt->bindParam(':description', $formData['description']);
                $insertStmt->bindParam(':work_description', $formData['work_description']);
                $insertStmt->bindParam(':payment_status', $formData['payment_status']);
                $insertStmt->bindParam(':notes', $formData['notes']);
                $insertStmt->bindParam(':created_by', $_SESSION['user_id']);
                
                if ($insertStmt->execute()) {
                    Helper::setMessage('Employee payment added successfully!', 'success');
                    
                    // Redirect based on source
                    if (!empty($_GET['employee_id'])) {
                        Helper::redirect('modules/employees/view.php?id=' . $_GET['employee_id']);
                    } elseif (!empty($_GET['project_id'])) {
                        Helper::redirect('modules/projects/view.php?id=' . $_GET['project_id']);
                    } else {
                        Helper::redirect('modules/employees/');
                    }
                } else {
                    $errors[] = 'Error saving payment. Please try again.';
                }
            } catch (Exception $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

try {
    // Get all active employees for dropdown
    $employeesQuery = "SELECT id, full_name, username, role FROM users WHERE is_active = 1 ORDER BY full_name ASC";
    $employeesStmt = $db->prepare($employeesQuery);
    $employeesStmt->execute();
    $employees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all projects for dropdown
    $projectsQuery = "
        SELECT p.id, p.project_name, c.company_name, p.status
        FROM projects p 
        LEFT JOIN clients c ON p.client_id = c.id 
        ORDER BY p.project_name ASC
    ";
    $projectsStmt = $db->prepare($projectsQuery);
    $projectsStmt->execute();
    $projects = $projectsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get pre-selected employee details if available
    if ($formData['employee_id']) {
        $preSelectedEmployeeQuery = "SELECT id, full_name, username, role FROM users WHERE id = :employee_id AND is_active = 1";
        $preSelectedEmployeeStmt = $db->prepare($preSelectedEmployeeQuery);
        $preSelectedEmployeeStmt->bindParam(':employee_id', $formData['employee_id']);
        $preSelectedEmployeeStmt->execute();
        $preSelectedEmployee = $preSelectedEmployeeStmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Get pre-selected project details if available
    if ($formData['project_id']) {
        $preSelectedProjectQuery = "
            SELECT p.id, p.project_name, c.company_name 
            FROM projects p 
            LEFT JOIN clients c ON p.client_id = c.id 
            WHERE p.id = :project_id
        ";
        $preSelectedProjectStmt = $db->prepare($preSelectedProjectQuery);
        $preSelectedProjectStmt->bindParam(':project_id', $formData['project_id']);
        $preSelectedProjectStmt->execute();
        $preSelectedProject = $preSelectedProjectStmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Get recent payments for dashboard display
    $recentPaymentsQuery = "
        SELECT 
            ep.id,
            ep.payment_amount,
            ep.payment_date,
            ep.payment_method,
            ep.payment_status,
            ep.description,
            u.full_name as employee_name,
            p.project_name,
            c.company_name
        FROM employee_payments ep
        INNER JOIN users u ON ep.employee_id = u.id
        LEFT JOIN projects p ON ep.project_id = p.id
        LEFT JOIN clients c ON p.client_id = c.id
        ORDER BY ep.created_at DESC
        LIMIT 10
    ";
    $recentPaymentsStmt = $db->prepare($recentPaymentsQuery);
    $recentPaymentsStmt->execute();
    $recentPayments = $recentPaymentsStmt->fetchAll(PDO::FETCH_ASSOC);
    
 } catch (Exception $e) {
    $errors[] = 'Error loading form data: ' . $e->getMessage();
    $employees = [];
    $projects = [];
    $recentPayments = [];
}include '../../includes/header.php';
?>

<!-- Breadcrumb Navigation -->
<nav class="mb-8">
    <div class="flex items-center space-x-2 text-sm">
        <a href="<?php echo Helper::baseUrl('modules/employees/'); ?>" 
           class="text-gray-500 hover:text-gray-700 transition-colors font-medium">
            Employee Payments
        </a>
        <svg class="w-4 h-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5l7 7-7 7"></path>
        </svg>
        <span class="text-gray-900 font-semibold">Add Payment</span>
    </div>
</nav>

<!-- Page Header -->
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-8">
    <div>
        <h1 class="text-3xl font-bold text-gray-900 mb-2">Add Employee Payment</h1>
        <p class="text-gray-600">Record a new payment to an employee</p>
        <?php if ($preSelectedEmployee): ?>
            <div class="mt-3 inline-flex items-center px-3 py-1 bg-blue-50 text-blue-700 rounded-lg text-sm">
                <i class="fas fa-user mr-2"></i>
                Adding payment for: <strong class="ml-1"><?= htmlspecialchars($preSelectedEmployee['full_name']) ?></strong>
            </div>
        <?php endif; ?>
        <?php if ($preSelectedProject): ?>
            <div class="mt-2 inline-flex items-center px-3 py-1 bg-green-50 text-green-700 rounded-lg text-sm">
                <i class="fas fa-project-diagram mr-2"></i>
                Project: <strong class="ml-1"><?= htmlspecialchars($preSelectedProject['project_name']) ?></strong>
            </div>
        <?php endif; ?>
    </div>
    <div class="mt-4 sm:mt-0">
        <a href="<?php echo Helper::baseUrl('modules/employees/'); ?>" 
           class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors">
            <i class="fas fa-arrow-left mr-2"></i>
            Back to Employees
        </a>
    </div>
</div>

<!-- Error Messages -->
<?php if (!empty($errors)): ?>
    <div class="mb-8 p-4 bg-red-50 border border-red-200 rounded-lg">
        <div class="flex items-center mb-2">
            <i class="fas fa-exclamation-triangle text-red-600 mr-2"></i>
            <h3 class="text-red-800 font-medium">Please correct the following errors:</h3>
        </div>
        <ul class="list-disc list-inside text-red-700 space-y-1 ml-6">
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Add Payment Form -->
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <div class="p-6">
        <form method="POST" class="space-y-6" novalidate>
            <!-- CSRF Token -->
            <input type="hidden" name="csrf_token" value="<?php echo Helper::generateCSRF(); ?>">
            
            <!-- Employee Selection -->
            <div>
                <label for="employee_id" class="block text-sm font-medium text-gray-700 mb-2">
                    Employee <span class="text-red-500">*</span>
                </label>
                <select 
                    id="employee_id" 
                    name="employee_id" 
                    required
                    <?= $preSelectedEmployee ? 'disabled' : '' ?>
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 <?php echo in_array('Please select an employee.', $errors) || in_array('Selected employee not found.', $errors) || in_array('Cannot add payment for inactive employee.', $errors) ? 'border-red-300' : ''; ?>"
                >
                    <option value="">Select an employee...</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= $employee['id'] ?>" <?= $formData['employee_id'] == $employee['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($employee['full_name']) ?> (<?= htmlspecialchars($employee['username']) ?>)
                            <?php if ($employee['role'] === 'admin'): ?>
                                - Admin
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($preSelectedEmployee): ?>
                    <input type="hidden" name="employee_id" value="<?= $formData['employee_id'] ?>">
                <?php endif; ?>
                <p class="mt-1 text-sm text-gray-500">Select the employee receiving this payment</p>
            </div>

            <!-- Project Selection (Optional) -->
            <div>
                <label for="project_id" class="block text-sm font-medium text-gray-700 mb-2">
                    Project <span class="text-gray-400">(Optional)</span>
                </label>
                <select 
                    id="project_id" 
                    name="project_id"
                    <?= $preSelectedProject ? 'disabled' : '' ?>
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                >
                    <option value="">No project (general payment)</option>
                    <?php foreach ($projects as $project): ?>
                        <option value="<?= $project['id'] ?>" <?= $formData['project_id'] == $project['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($project['project_name']) ?>
                            <?php if ($project['company_name']): ?>
                                - <?= htmlspecialchars($project['company_name']) ?>
                            <?php endif; ?>
                            (<?= ucwords(str_replace('_', ' ', $project['status'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($preSelectedProject): ?>
                    <input type="hidden" name="project_id" value="<?= $formData['project_id'] ?>">
                <?php endif; ?>
                <p class="mt-1 text-sm text-gray-500">Link this payment to a specific project if applicable</p>
            </div>

            <!-- Payment Details Row -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Payment Amount -->
                <div>
                    <label for="payment_amount" class="block text-sm font-medium text-gray-700 mb-2">
                        Payment Amount <span class="text-red-500">*</span>
                    </label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-500">LKR</span>
                        <input 
                            type="number" 
                            id="payment_amount" 
                            name="payment_amount" 
                            value="<?php echo htmlspecialchars($formData['payment_amount']); ?>"
                            step="0.01"
                            min="0.01"
                            max="999999.99"
                            required
                            class="w-full pl-12 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 <?php echo in_array('Please enter a valid payment amount.', $errors) || in_array('Payment amount cannot exceed ' . Helper::formatCurrency(999999.99) . '.', $errors) ? 'border-red-300' : ''; ?>"
                            placeholder="0.00"
                        >
                    </div>
                    <p class="mt-1 text-sm text-gray-500">Enter the payment amount in LKR</p>
                </div>

                <!-- Payment Date -->
                <div>
                    <label for="payment_date" class="block text-sm font-medium text-gray-700 mb-2">
                        Payment Date <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="date" 
                        id="payment_date" 
                        name="payment_date" 
                        value="<?php echo htmlspecialchars($formData['payment_date']); ?>"
                        max="<?= date('Y-m-d') ?>"
                        required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 <?php echo in_array('Payment date is required.', $errors) || in_array('Please enter a valid payment date.', $errors) || in_array('Payment date cannot be in the future.', $errors) ? 'border-red-300' : ''; ?>"
                    >
                    <p class="mt-1 text-sm text-gray-500">When was this payment made?</p>
                </div>

                <!-- Payment Status -->
                <div>
                    <label for="payment_status" class="block text-sm font-medium text-gray-700 mb-2">
                        Payment Status <span class="text-red-500">*</span>
                    </label>
                    <select 
                        id="payment_status" 
                        name="payment_status" 
                        required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                    >
                        <option value="paid" <?= $formData['payment_status'] === 'paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="pending" <?= $formData['payment_status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="cancelled" <?= $formData['payment_status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                    <p class="mt-1 text-sm text-gray-500">Current status of this payment</p>
                </div>
            </div>

            <!-- Payment Method and Reference Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Payment Method -->
                <div>
                    <label for="payment_method" class="block text-sm font-medium text-gray-700 mb-2">
                        Payment Method <span class="text-red-500">*</span>
                    </label>
                    <select 
                        id="payment_method" 
                        name="payment_method" 
                        required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                    >
                        <option value="cash" <?= $formData['payment_method'] === 'cash' ? 'selected' : '' ?>>Cash</option>
                        <option value="bank_transfer" <?= $formData['payment_method'] === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                        <option value="check" <?= $formData['payment_method'] === 'check' ? 'selected' : '' ?>>Check</option>
                        <option value="card" <?= $formData['payment_method'] === 'card' ? 'selected' : '' ?>>Card</option>
                        <option value="online" <?= $formData['payment_method'] === 'online' ? 'selected' : '' ?>>Online</option>
                        <option value="other" <?= $formData['payment_method'] === 'other' ? 'selected' : '' ?>>Other</option>
                    </select>
                    <p class="mt-1 text-sm text-gray-500">How was this payment made?</p>
                </div>

                <!-- Payment Reference -->
                <div>
                    <label for="payment_reference" class="block text-sm font-medium text-gray-700 mb-2">
                        Payment Reference <span class="text-gray-400">(Optional)</span>
                    </label>
                    <input 
                        type="text" 
                        id="payment_reference" 
                        name="payment_reference" 
                        value="<?php echo htmlspecialchars($formData['payment_reference']); ?>"
                        maxlength="100"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 <?php echo in_array('Payment reference must be less than 100 characters.', $errors) ? 'border-red-300' : ''; ?>"
                        placeholder="Check number, transaction ID, etc."
                    >
                    <div class="mt-1 flex justify-between">
                        <p class="text-sm text-gray-500">Reference number, transaction ID, or check number</p>
                        <span id="payment_reference_count" class="text-sm text-gray-400">0/100</span>
                    </div>
                </div>
            </div>

            <!-- Work Description -->
            <div>
                <label for="work_description" class="block text-sm font-medium text-gray-700 mb-2">
                    Work Description <span class="text-gray-400">(Optional)</span>
                </label>
                <textarea 
                    id="work_description" 
                    name="work_description" 
                    rows="3"
                    maxlength="1000"
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 resize-none <?php echo in_array('Work description must be less than 1000 characters.', $errors) ? 'border-red-300' : ''; ?>"
                    placeholder="Describe the work performed for this payment..."
                ><?php echo htmlspecialchars($formData['work_description']); ?></textarea>
                <div class="mt-1 flex justify-between">
                    <p class="text-sm text-gray-500">What work was completed for this payment?</p>
                    <span id="work_description_count" class="text-sm text-gray-400">0/1000</span>
                </div>
            </div>

            <!-- Custom Description -->
            <div>
                <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
                    Payment Description 
                    <span class="text-gray-400">(Auto-generated dynamically)</span>
                    <button type="button" id="toggleDescriptionMode" class="ml-2 text-blue-600 hover:text-blue-800 text-xs underline">
                        Switch to Manual
                    </button>
                </label>
                
                <!-- Auto-generated description display -->
                <div id="autoDescriptionContainer" class="mb-3">
                    <div class="p-3 bg-blue-50 border border-blue-200 rounded-lg">
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <div class="flex items-center mb-1">
                                    <div class="text-xs font-medium text-blue-800">Auto-Generated Description:</div>
                                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                        <i class="fas fa-magic mr-1 text-xs"></i>
                                        Full Project Names
                                    </span>
                                </div>
                                <div id="autoGeneratedDescription" class="text-sm text-blue-700 min-h-[1.25rem]">
                                    Select employee and amount to generate description...
                                </div>
                            </div>
                            <button type="button" 
                                    id="refreshDescription" 
                                    class="ml-2 text-blue-600 hover:text-blue-800 transition-colors"
                                    title="Refresh description">
                                <i class="fas fa-sync-alt text-xs"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Manual description textarea (hidden by default) -->
                <div id="manualDescriptionContainer" class="hidden">
                    <textarea 
                        id="description" 
                        name="description" 
                        rows="3"
                        maxlength="500"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 resize-none"
                        placeholder="Enter custom description..."
                    ><?php echo htmlspecialchars($formData['description']); ?></textarea>
                    <div class="mt-1 flex justify-between">
                        <p class="text-sm text-gray-500">Enter your custom description</p>
                        <span id="description_count" class="text-sm text-gray-400">0/500</span>
                    </div>
                </div>
                
                <!-- Hidden input to store the final description -->
                <input type="hidden" id="finalDescription" name="description" value="<?php echo htmlspecialchars($formData['description']); ?>">
                
                <div class="mt-1">
                    <p class="text-sm text-gray-500">
                        <span id="descriptionModeText">Description is automatically generated based on form data</span>
                    </p>
                </div>
            </div>

            <!-- Notes -->
            <div>
                <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">
                    Additional Notes <span class="text-gray-400">(Optional)</span>
                </label>
                <textarea 
                    id="notes" 
                    name="notes" 
                    rows="3"
                    maxlength="1000"
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 resize-none <?php echo in_array('Notes must be less than 1000 characters.', $errors) ? 'border-red-300' : ''; ?>"
                    placeholder="Any additional notes or comments..."
                ><?php echo htmlspecialchars($formData['notes']); ?></textarea>
                <div class="mt-1 flex justify-between">
                    <p class="text-sm text-gray-500">Internal notes about this payment</p>
                    <span id="notes_count" class="text-sm text-gray-400">0/1000</span>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="flex flex-col sm:flex-row justify-between items-center pt-6 border-t border-gray-200 space-y-4 sm:space-y-0">
                <a href="<?php echo Helper::baseUrl('modules/employees/'); ?>" 
                   class="text-gray-600 hover:text-gray-800 transition-colors">
                    <i class="fas fa-arrow-left mr-2"></i>Cancel
                </a>
                
                <div class="flex space-x-3">
                    <button type="button" 
                            id="previewBtn" 
                            class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                        <i class="fas fa-eye mr-2"></i>Preview Final Description
                    </button>
                    
                    <button type="submit" 
                            id="submitBtn" 
                            class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        <i class="fas fa-save mr-2"></i>Add Payment
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Recent Payments Section -->
<div class="mt-8 bg-white rounded-xl border border-gray-200 overflow-hidden">
    <div class="p-6 border-b border-gray-200">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h3 class="text-lg font-bold text-gray-900 mb-1">Recent Payments</h3>
                <p class="text-sm text-gray-600">Latest employee payments in the system</p>
            </div>
            <div class="mt-3 sm:mt-0">
                <a href="<?php echo Helper::baseUrl('modules/employees/'); ?>" 
                   class="inline-flex items-center px-3 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 transition-colors">
                    <i class="fas fa-list mr-2"></i>
                    View All Payments
                </a>
            </div>
        </div>
    </div>
    
    <div class="overflow-x-auto">
        <?php if (!empty($recentPayments)): ?>
            <!-- Desktop Table View -->
            <div class="hidden md:block">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Employee</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Method</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Project</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($recentPayments as $payment): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-8 w-8">
                                            <div class="h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center">
                                                <i class="fas fa-user text-blue-600 text-sm"></i>
                                            </div>
                                        </div>
                                        <div class="ml-3">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?= htmlspecialchars($payment['employee_name']) ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-semibold text-gray-900">
                                        <?= Helper::formatCurrency($payment['payment_amount']) ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        <?= date('M d, Y', strtotime($payment['payment_date'])) ?>
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        <?= date('H:i', strtotime($payment['payment_date'])) ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                        <i class="fas fa-<?= $payment['payment_method'] === 'cash' ? 'money-bill' : ($payment['payment_method'] === 'bank_transfer' ? 'university' : ($payment['payment_method'] === 'check' ? 'file-invoice' : ($payment['payment_method'] === 'card' ? 'credit-card' : 'globe'))) ?> mr-1"></i>
                                        <?= ucwords(str_replace('_', ' ', $payment['payment_method'])) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?= Helper::statusBadge($payment['payment_status']) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if ($payment['project_name']): ?>
                                        <div class="text-sm text-gray-900">
                                            <?= htmlspecialchars($payment['project_name']) ?>
                                        </div>
                                        <?php if ($payment['company_name']): ?>
                                            <div class="text-xs text-gray-500">
                                                <?= htmlspecialchars($payment['company_name']) ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-sm text-gray-400 italic">No project</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Mobile Card View -->
            <div class="md:hidden">
                <div class="space-y-4 p-4">
                    <?php foreach ($recentPayments as $payment): ?>
                        <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                            <div class="flex items-start justify-between mb-3">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-10 w-10">
                                        <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center">
                                            <i class="fas fa-user text-blue-600"></i>
                                        </div>
                                    </div>
                                    <div class="ml-3">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?= htmlspecialchars($payment['employee_name']) ?>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            <?= date('M d, Y', strtotime($payment['payment_date'])) ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-lg font-bold text-gray-900">
                                        <?= Helper::formatCurrency($payment['payment_amount']) ?>
                                    </div>
                                    <?= Helper::statusBadge($payment['payment_status']) ?>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-3 mt-3 pt-3 border-t border-gray-200">
                                <div>
                                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">Method</div>
                                    <div class="mt-1 flex items-center text-sm text-gray-900">
                                        <i class="fas fa-<?= $payment['payment_method'] === 'cash' ? 'money-bill' : ($payment['payment_method'] === 'bank_transfer' ? 'university' : ($payment['payment_method'] === 'check' ? 'file-invoice' : ($payment['payment_method'] === 'card' ? 'credit-card' : 'globe'))) ?> mr-2 text-gray-400"></i>
                                        <?= ucwords(str_replace('_', ' ', $payment['payment_method'])) ?>
                                    </div>
                                </div>
                                <div>
                                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">Project</div>
                                    <div class="mt-1 text-sm text-gray-900">
                                        <?php if ($payment['project_name']): ?>
                                            <?= htmlspecialchars($payment['project_name']) ?>
                                            <?php if ($payment['company_name']): ?>
                                                <div class="text-xs text-gray-500">
                                                    <?= htmlspecialchars($payment['company_name']) ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-gray-400 italic">No project</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($payment['description']): ?>
                                <div class="mt-3 pt-3 border-t border-gray-200">
                                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">Description</div>
                                    <div class="mt-1 text-sm text-gray-700">
                                        <?= htmlspecialchars(strlen($payment['description']) > 100 ? substr($payment['description'], 0, 100) . '...' : $payment['description']) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="p-8 text-center">
                <div class="mx-auto h-24 w-24 text-gray-300 mb-4">
                    <i class="fas fa-money-bill-wave text-6xl"></i>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No Recent Payments</h3>
                <p class="text-gray-500 mb-4">No employee payments have been recorded yet.</p>
                <div class="text-sm text-gray-400">
                    Add your first payment using the form above to see recent payments here.
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Preview Modal -->
<div id="previewModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-xl shadow-lg max-w-md w-full">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-gray-900">Description Preview</h3>
                    <button type="button" onclick="closePreviewModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="p-4 bg-gray-50 rounded-lg">
                    <p id="previewText" class="text-sm text-gray-700"></p>
                </div>
                <div class="mt-4 flex justify-end">
                    <button type="button" onclick="closePreviewModal()" 
                            class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div id="loadingOverlay" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-40">
    <div class="flex items-center justify-center min-h-screen">
        <div class="bg-white rounded-lg p-6 flex items-center space-x-3">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            <span class="text-gray-700">Adding payment...</span>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Global variables for description management
    let descriptionMode = 'auto'; // 'auto' or 'manual'
    let isFormInitialized = false;
    
    // Character counters
    const textFields = [
        { id: 'payment_reference', countId: 'payment_reference_count', max: 100 },
        { id: 'work_description', countId: 'work_description_count', max: 1000 },
        { id: 'notes', countId: 'notes_count', max: 1000 }
    ];
    
    textFields.forEach(field => {
        const input = document.getElementById(field.id);
        const counter = document.getElementById(field.countId);
        
        if (input && counter) {
            input.addEventListener('input', function() {
                const length = this.value.length;
                counter.textContent = `${length}/${field.max}`;
                
                if (length > field.max * 0.9) {
                    counter.classList.add('text-red-500');
                    counter.classList.remove('text-gray-400');
                } else {
                    counter.classList.remove('text-red-500');
                    counter.classList.add('text-gray-400');
                }
            });
            
            // Initialize counter
            input.dispatchEvent(new Event('input'));
        }
    });

    // Description mode toggle functionality
    const toggleBtn = document.getElementById('toggleDescriptionMode');
    const autoContainer = document.getElementById('autoDescriptionContainer');
    const manualContainer = document.getElementById('manualDescriptionContainer');
    const modeText = document.getElementById('descriptionModeText');
    const manualDescTextarea = document.getElementById('description');
    const manualDescCounter = document.getElementById('description_count');
    
    // Setup manual description counter if in manual mode
    if (manualDescTextarea && manualDescCounter) {
        manualDescTextarea.addEventListener('input', function() {
            const length = this.value.length;
            manualDescCounter.textContent = `${length}/500`;
            
            if (length > 450) {
                manualDescCounter.classList.add('text-red-500');
                manualDescCounter.classList.remove('text-gray-400');
            } else {
                manualDescCounter.classList.remove('text-red-500');
                manualDescCounter.classList.add('text-gray-400');
            }
            
            // Update hidden input
            document.getElementById('finalDescription').value = this.value;
        });
    }

    // Toggle between auto and manual description modes
    toggleBtn.addEventListener('click', function() {
        if (descriptionMode === 'auto') {
            descriptionMode = 'manual';
            autoContainer.classList.add('hidden');
            manualContainer.classList.remove('hidden');
            toggleBtn.textContent = 'Switch to Auto';
            modeText.textContent = 'Enter your custom description';
            
            // Copy auto-generated description to manual field
            const autoDesc = document.getElementById('autoGeneratedDescription').textContent;
            if (autoDesc && autoDesc !== 'Select employee and amount to generate description...') {
                manualDescTextarea.value = autoDesc;
                document.getElementById('finalDescription').value = autoDesc;
                manualDescTextarea.dispatchEvent(new Event('input'));
            }
        } else {
            descriptionMode = 'auto';
            autoContainer.classList.remove('hidden');
            manualContainer.classList.add('hidden');
            toggleBtn.textContent = 'Switch to Manual';
            modeText.textContent = 'Description is automatically generated based on form data';
            
            // Clear manual field and regenerate auto description
            manualDescTextarea.value = '';
            generateDynamicDescription();
        }
    });

    // Refresh description button
    document.getElementById('refreshDescription').addEventListener('click', function() {
        if (descriptionMode === 'auto') {
            generateDynamicDescription();
            
            // Add visual feedback
            const icon = this.querySelector('i');
            icon.classList.add('animate-spin');
            setTimeout(() => {
                icon.classList.remove('animate-spin');
            }, 500);
        }
    });

    // Dynamic description generation function
    function generateDynamicDescription() {
        if (descriptionMode !== 'auto') return;
        
        const employeeSelect = document.getElementById('employee_id');
        const projectSelect = document.getElementById('project_id');
        const amountInput = document.getElementById('payment_amount');
        const dateInput = document.getElementById('payment_date');
        const methodSelect = document.getElementById('payment_method');
        const workDescInput = document.getElementById('work_description');
        const descriptionDisplay = document.getElementById('autoGeneratedDescription');
        const finalDescriptionInput = document.getElementById('finalDescription');
        
        let description = '';
        let hasRequiredData = false;
        
        // Employee name (required)
        if (employeeSelect.value && employeeSelect.selectedIndex > 0) {
            const selectedOption = employeeSelect.options[employeeSelect.selectedIndex];
            const employeeName = selectedOption.text.split('(')[0].trim();
            description += employeeName;
            hasRequiredData = true;
        }
        
        // Project name (optional) - Using FULL project name including client name
        if (projectSelect.value && projectSelect.selectedIndex > 0) {
            const selectedOption = projectSelect.options[projectSelect.selectedIndex];
            const fullOptionText = selectedOption.text.trim();
            
            // Extract full project name including client name if available
            if (fullOptionText !== 'No project (general payment)') {
                // Check if the option contains client name (format: "Project Name - Client Name (Status)")
                let projectName = fullOptionText;
                
                // Remove status part (anything in parentheses at the end)
                projectName = projectName.replace(/\s*\([^)]*\)\s*$/, '');
                
                // If it's not the "No project" option, include the full name
                if (projectName && projectName.toLowerCase() !== 'no project (general payment)') {
                    description += description ? ' - ' + projectName : projectName;
                }
            }
        }
        
        // Amount (required)
        const amount = parseFloat(amountInput.value);
        if (amount && amount > 0) {
            const formattedAmount = 'LKR ' + amount.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            description += description ? ' - ' + formattedAmount : formattedAmount;
            hasRequiredData = true;
        }
        
        // Date
        if (dateInput.value) {
            try {
                const date = new Date(dateInput.value);
                const formattedDate = date.toLocaleDateString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric'
                });
                description += description ? ' - ' + formattedDate : formattedDate;
            } catch (e) {
                // Invalid date, skip
            }
        }
        
        // Payment method
        if (methodSelect.value) {
            const methodText = methodSelect.options[methodSelect.selectedIndex].text;
            description += description ? ' - ' + methodText : methodText;
        }
        
        // Work description (truncated if too long)
        if (workDescInput.value.trim()) {
            const workDesc = workDescInput.value.trim();
            let truncatedWorkDesc;
            if (workDesc.length > 50) {
                truncatedWorkDesc = workDesc.substring(0, 50) + '...';
            } else {
                truncatedWorkDesc = workDesc;
            }
            description += description ? ' - ' + truncatedWorkDesc : truncatedWorkDesc;
        }
        
        // Update display and hidden input
        if (hasRequiredData && description) {
            descriptionDisplay.textContent = description;
            descriptionDisplay.className = 'text-sm text-blue-700 min-h-[1.25rem] break-words';
            finalDescriptionInput.value = description;
        } else {
            descriptionDisplay.textContent = 'Select employee and amount to generate description...';
            descriptionDisplay.className = 'text-sm text-gray-500 min-h-[1.25rem] italic';
            finalDescriptionInput.value = '';
        }
        
        // Add animation effect
        descriptionDisplay.style.opacity = '0.7';
        setTimeout(() => {
            descriptionDisplay.style.opacity = '1';
        }, 100);
    }

    // Attach event listeners for dynamic updates
    const formElements = [
        'employee_id', 'project_id', 'payment_amount', 
        'payment_date', 'payment_method', 'work_description'
    ];
    
    formElements.forEach(elementId => {
        const element = document.getElementById(elementId);
        if (element) {
            // Use different events for different input types
            if (element.tagName === 'SELECT') {
                element.addEventListener('change', generateDynamicDescription);
            } else if (element.type === 'number' || element.type === 'date') {
                element.addEventListener('input', debounce(generateDynamicDescription, 300));
            } else {
                element.addEventListener('input', debounce(generateDynamicDescription, 500));
            }
            
            // Also listen for blur events for immediate updates
            element.addEventListener('blur', generateDynamicDescription);
        }
    });

    // Payment amount formatting with dynamic update
    const amountInput = document.getElementById('payment_amount');
    amountInput.addEventListener('input', function() {
        let value = this.value.replace(/[^0-9.]/g, '');
        
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
        
        // Trigger dynamic description update
        if (descriptionMode === 'auto') {
            debounce(generateDynamicDescription, 300)();
        }
        
        // Update preview if modal is open
        if (!document.getElementById('previewModal').classList.contains('hidden')) {
            generatePreview();
        }
    });

    // Auto-update payment date to today if empty
    const dateInput = document.getElementById('payment_date');
    if (!dateInput.value) {
        dateInput.value = new Date().toISOString().split('T')[0];
    }

    // Initialize dynamic description on page load
    setTimeout(() => {
        generateDynamicDescription();
        isFormInitialized = true;
    }, 100);

    // Form submission handling
    const form = document.querySelector('form');
    form.addEventListener('submit', function(e) {
        const submitBtn = document.getElementById('submitBtn');
        const loadingOverlay = document.getElementById('loadingOverlay');
        
        // Ensure final description is set
        if (descriptionMode === 'auto') {
            generateDynamicDescription();
        }
        
        // Show loading state
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Adding...';
        loadingOverlay.classList.remove('hidden');
        
        // Prevent double submission
        this.submitted = true;
    });

    // Preview functionality
    document.getElementById('previewBtn').addEventListener('click', function() {
        generatePreview();
        document.getElementById('previewModal').classList.remove('hidden');
    });

    // Auto-focus first empty required field
    const requiredFields = ['employee_id', 'payment_amount', 'payment_date'];
    for (let fieldId of requiredFields) {
        const field = document.getElementById(fieldId);
        if (field && !field.value) {
            field.focus();
            break;
        }
    }

    // Form validation feedback
    document.querySelectorAll('input[required], select[required], textarea[required]').forEach(field => {
        field.addEventListener('blur', function() {
            if (this.type === 'number') {
                if (!this.value || parseFloat(this.value) <= 0) {
                    this.classList.add('border-red-300');
                } else {
                    this.classList.remove('border-red-300');
                }
            } else {
                if (this.value.trim() === '') {
                    this.classList.add('border-red-300');
                } else {
                    this.classList.remove('border-red-300');
                }
            }
        });
        
        field.addEventListener('input', function() {
            if (this.type === 'number') {
                if (this.value && parseFloat(this.value) > 0) {
                    this.classList.remove('border-red-300');
                }
            } else {
                if (this.value.trim() !== '') {
                    this.classList.remove('border-red-300');
                }
            }
        });
    });

    // Prevent accidental navigation away
    let formChanged = false;
    document.querySelectorAll('input, textarea, select').forEach(field => {
        field.addEventListener('change', () => formChanged = true);
    });

    window.addEventListener('beforeunload', function(e) {
        if (formChanged && !document.querySelector('form').submitted) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + Enter to submit
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            document.querySelector('form').dispatchEvent(new Event('submit'));
        }
        
        // Escape to close modal or go back
        if (e.key === 'Escape') {
            const previewModal = document.getElementById('previewModal');
            if (!previewModal.classList.contains('hidden')) {
                closePreviewModal();
            } else {
                window.history.back();
            }
        }
        
        // Ctrl/Cmd + D to toggle description mode
        if ((e.ctrlKey || e.metaKey) && e.key === 'd') {
            e.preventDefault();
            document.getElementById('toggleDescriptionMode').click();
        }
    });

    // Payment method change handler
    document.getElementById('payment_method').addEventListener('change', function() {
        const referenceField = document.getElementById('payment_reference');
        const referenceLabel = referenceField.previousElementSibling;
        
        switch(this.value) {
            case 'bank_transfer':
                referenceField.placeholder = 'Transaction ID or reference number';
                break;
            case 'check':
                referenceField.placeholder = 'Check number';
                break;
            case 'card':
                referenceField.placeholder = 'Last 4 digits or transaction ID';
                break;
            case 'online':
                referenceField.placeholder = 'Transaction ID';
                break;
            default:
                referenceField.placeholder = 'Reference number or note';
        }
        
        // Update dynamic description
        if (descriptionMode === 'auto') {
            generateDynamicDescription();
        }
    });

    // Employee selection change handler
    document.getElementById('employee_id').addEventListener('change', function() {
        if (descriptionMode === 'auto') {
            generateDynamicDescription();
        }
        
        if (!document.getElementById('previewModal').classList.contains('hidden')) {
            generatePreview();
        }
    });

    // Project selection change handler
    document.getElementById('project_id').addEventListener('change', function() {
        if (descriptionMode === 'auto') {
            generateDynamicDescription();
        }
        
        if (!document.getElementById('previewModal').classList.contains('hidden')) {
            generatePreview();
        }
    });
});

// Debounce function to limit the frequency of function calls
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Preview generation function
function generatePreview() {
    const employeeSelect = document.getElementById('employee_id');
    const projectSelect = document.getElementById('project_id');
    const amountInput = document.getElementById('payment_amount');
    const dateInput = document.getElementById('payment_date');
    const methodSelect = document.getElementById('payment_method');
    const workDescInput = document.getElementById('work_description');
    const finalDescInput = document.getElementById('finalDescription');
    
    let description = '';
    
    // Check if we're in manual mode and have a custom description
    if (descriptionMode === 'manual') {
        const manualDesc = document.getElementById('description').value.trim();
        if (manualDesc) {
            description = manualDesc;
        } else {
            description = 'No custom description entered';
        }
    } else {
        // Use the current auto-generated description
        description = finalDescInput.value || document.getElementById('autoGeneratedDescription').textContent;
        
        // If no auto description, generate one for preview
        if (!description || description === 'Select employee and amount to generate description...') {
            // Generate auto description for preview
            if (employeeSelect.value) {
                const selectedOption = employeeSelect.options[employeeSelect.selectedIndex];
                const employeeName = selectedOption.text.split('(')[0].trim();
                description += employeeName;
            } else {
                description += 'Selected Employee';
            }
            
            // Project name
            if (projectSelect.value) {
                const selectedOption = projectSelect.options[projectSelect.selectedIndex];
                const fullOptionText = selectedOption.text.trim();
                
                // Extract full project name including client name if available
                if (fullOptionText !== 'No project (general payment)') {
                    let projectName = fullOptionText;
                    
                    // Remove status part (anything in parentheses at the end)
                    projectName = projectName.replace(/\s*\([^)]*\)\s*$/, '');
                    
                    if (projectName && projectName.toLowerCase() !== 'no project (general payment)') {
                        description += ' - ' + projectName;
                    }
                }
            }
            
            // Amount
            const amount = parseFloat(amountInput.value) || 0;
            description += ' - LKR ' + amount.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            
            // Date
            if (dateInput.value) {
                const date = new Date(dateInput.value);
                description += ' - ' + date.toLocaleDateString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric'
                });
            }
            
            // Method
            if (methodSelect.value) {
                const methodText = methodSelect.options[methodSelect.selectedIndex].text;
                description += ' - ' + methodText;
            }
            
            // Work description (truncated)
            if (workDescInput.value.trim()) {
                const workDesc = workDescInput.value.trim();
                if (workDesc.length > 50) {
                    description += ' - ' + workDesc.substring(0, 50) + '...';
                } else {
                    description += ' - ' + workDesc;
                }
            }
        }
    }
    
    // Update preview text
    document.getElementById('previewText').textContent = description || 'No description available';
    
    // Update preview modal title based on mode
    const previewTitle = document.querySelector('#previewModal h3');
    if (previewTitle) {
        previewTitle.textContent = descriptionMode === 'manual' ? 
            'Custom Description Preview' : 'Auto-Generated Description Preview';
    }
}

// Close preview modal
function closePreviewModal() {
    document.getElementById('previewModal').classList.add('hidden');
}

// Utility functions
function formatCurrency(amount) {
    return 'LKR ' + new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(amount);
}

function formatDate(dateString) {
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

// Performance monitoring
if ('performance' in window) {
    window.addEventListener('load', function() {
        const loadTime = performance.timing.loadEventEnd - performance.timing.navigationStart;
        console.log('Add payment page loaded in:', loadTime + 'ms');
    });
}

// Mobile-specific enhancements
function initMobileEnhancements() {
    const isMobile = window.innerWidth <= 768;
    
    if (isMobile) {
        // Add mobile-friendly classes
        document.querySelectorAll('input, select, textarea').forEach(field => {
            field.classList.add('mobile-input');
        });
        
        document.querySelectorAll('button').forEach(btn => {
            btn.classList.add('mobile-btn');
        });
        
        // Improve touch targets
        document.querySelectorAll('label').forEach(label => {
            label.style.minHeight = '44px';
            label.style.display = 'flex';
            label.style.alignItems = 'center';
        });
        
        // Auto-scroll to form sections on focus
        document.querySelectorAll('input, select, textarea').forEach(field => {
            field.addEventListener('focus', function() {
                setTimeout(() => {
                    this.scrollIntoView({ 
                        behavior: 'smooth', 
                        block: 'center' 
                    });
                }, 300);
            });
        });
        
        // Add swipe gestures for recent payments on mobile
        if (document.querySelector('.recent-payments-mobile')) {
            initSwipeGestures();
        }
    }
}

// Initialize swipe gestures for mobile recent payments
function initSwipeGestures() {
    let startX = 0;
    let currentX = 0;
    
    document.querySelectorAll('.payment-card-mobile').forEach(card => {
        card.addEventListener('touchstart', (e) => {
            startX = e.touches[0].clientX;
        });
        
        card.addEventListener('touchmove', (e) => {
            currentX = e.touches[0].clientX;
        });
        
        card.addEventListener('touchend', () => {
            const diffX = startX - currentX;
            
            if (Math.abs(diffX) > 50) {
                // Add swipe animation or action here if needed
                card.style.transform = diffX > 0 ? 'translateX(-10px)' : 'translateX(10px)';
                setTimeout(() => {
                    card.style.transform = 'translateX(0)';
                }, 200);
            }
        });
    });
}

// Responsive table/card toggle
function toggleViewMode() {
    const tableView = document.querySelector('.hidden.md\\:block');
    const cardView = document.querySelector('.md\\:hidden');
    
    if (window.innerWidth <= 768) {
        if (tableView) tableView.style.display = 'none';
        if (cardView) cardView.style.display = 'block';
    } else {
        if (tableView) tableView.style.display = 'block';
        if (cardView) cardView.style.display = 'none';
    }
}

// Initialize mobile enhancements
document.addEventListener('DOMContentLoaded', function() {
    initMobileEnhancements();
    toggleViewMode();
});

// Handle window resize
let resizeTimer;
window.addEventListener('resize', function() {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
        toggleViewMode();
        initMobileEnhancements();
    }, 150);
});

// Enhanced error handling for mobile
function showMobileError(message) {
    const errorDiv = document.createElement('div');
    errorDiv.className = 'fixed top-4 left-4 right-4 bg-red-50 border border-red-200 rounded-lg p-4 z-50 mobile-error';
    errorDiv.innerHTML = `
        <div class="flex items-start">
            <i class="fas fa-exclamation-triangle text-red-600 mt-1 mr-3"></i>
            <div class="text-sm text-red-700">${message}</div>
            <button onclick="this.parentElement.parentElement.remove()" class="ml-auto text-red-400 hover:text-red-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    
    document.body.appendChild(errorDiv);
    
    setTimeout(() => {
        if (errorDiv.parentElement) {
            errorDiv.remove();
        }
    }, 5000);
}

// Enhanced success message for mobile
function showMobileSuccess(message) {
    const successDiv = document.createElement('div');
    successDiv.className = 'fixed top-4 left-4 right-4 bg-green-50 border border-green-200 rounded-lg p-4 z-50 mobile-success';
    successDiv.innerHTML = `
        <div class="flex items-start">
            <i class="fas fa-check-circle text-green-600 mt-1 mr-3"></i>
            <div class="text-sm text-green-700">${message}</div>
            <button onclick="this.parentElement.parentElement.remove()" class="ml-auto text-green-400 hover:text-green-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    
    document.body.appendChild(successDiv);
    
    setTimeout(() => {
        if (successDiv.parentElement) {
            successDiv.remove();
        }
    }, 3000);
}
</script>

<!-- Custom Styles -->
<style>
/* Enhanced focus states */
input:focus, select:focus, textarea:focus {
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* Loading animation */
@keyframes spin {
    to { transform: rotate(360deg); }
}

.animate-spin {
    animation: spin 1s linear infinite;
}

/* Modal backdrop */
.modal-backdrop {
    backdrop-filter: blur(4px);
}

/* Form validation styles */
.border-red-300:focus {
    border-color: #fca5a5;
    box-shadow: 0 0 0 3px rgba(248, 113, 113, 0.1);
}

/* Responsive improvements */
@media (max-width: 768px) {
    .grid-cols-1 {
        grid-template-columns: repeat(1, minmax(0, 1fr));
    }
    
    .md\:grid-cols-2 {
        grid-template-columns: repeat(1, minmax(0, 1fr));
    }
    
    .md\:grid-cols-3 {
        grid-template-columns: repeat(1, minmax(0, 1fr));
    }
    
    .sm\:flex-row {
        flex-direction: column;
    }
    
    .sm\:space-y-0 > :not(:first-child) {
        margin-top: 1rem;
        margin-left: 0;
    }
    
    .px-6 {
        padding-left: 1rem;
        padding-right: 1rem;
    }
    
    /* Enhanced mobile form styles */
    .form-container {
        margin: 0 -1rem;
    }
    
    .form-section {
        padding: 1rem;
    }
    
    /* Mobile-friendly input spacing */
    .mobile-input {
        padding: 0.875rem 1rem;
        font-size: 1rem;
    }
    
    /* Touch-friendly buttons */
    .mobile-btn {
        padding: 0.75rem 1.5rem;
        font-size: 1rem;
        min-height: 44px;
    }
    
    /* Mobile modal adjustments */
    .mobile-modal {
        margin: 1rem;
        max-height: calc(100vh - 2rem);
        overflow-y: auto;
    }
    
    /* Improved text sizing for mobile */
    .mobile-text-sm {
        font-size: 0.875rem;
        line-height: 1.25rem;
    }
    
    .mobile-text-xs {
        font-size: 0.75rem;
        line-height: 1rem;
    }
    
    /* Better spacing for mobile forms */
    .mobile-form-spacing {
        margin-bottom: 1.5rem;
    }
    
    /* Mobile-optimized table cards */
    .mobile-card {
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
        transition: box-shadow 0.15s ease-in-out;
    }
    
    .mobile-card:hover {
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }
    
    /* Responsive navigation breadcrumbs */
    .mobile-breadcrumb {
        font-size: 0.75rem;
        flex-wrap: wrap;
    }
    
    .mobile-breadcrumb svg {
        width: 0.875rem;
        height: 0.875rem;
    }
}

/* Tablet specific adjustments */
@media (min-width: 768px) and (max-width: 1024px) {
    .tablet-grid-2 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    
    .tablet-px-4 {
        padding-left: 1rem;
        padding-right: 1rem;
    }
}

/* Enhanced mobile navigation */
@media (max-width: 640px) {
    .page-header {
        text-align: center;
    }
    
    .page-header h1 {
        font-size: 1.875rem;
        line-height: 2.25rem;
    }
    
    .page-header p {
        font-size: 0.875rem;
        margin-top: 0.5rem;
    }
    
    .action-buttons {
        flex-direction: column;
        width: 100%;
        gap: 0.75rem;
    }
    
    .action-buttons a,
    .action-buttons button {
        width: 100%;
        justify-content: center;
    }
    
    /* Mobile form improvements */
    .form-grid-mobile {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .input-with-icon {
        padding-left: 2.5rem;
    }
    
    .currency-input {
        padding-left: 3rem;
    }
    
    /* Mobile-optimized recent payments */
    .recent-payments-mobile {
        margin-top: 1.5rem;
    }
    
    .payment-card-mobile {
        padding: 1rem;
        margin-bottom: 0.75rem;
        border-radius: 0.5rem;
        background: #f9fafb;
        border: 1px solid #e5e7eb;
    }
    
    .payment-amount-mobile {
        font-size: 1.125rem;
        font-weight: 700;
        color: #111827;
    }
    
    .payment-status-mobile {
        margin-top: 0.25rem;
    }
}

/* Print styles */
@media print {
    .no-print {
        display: none !important;
    }
    
    body {
        background: white !important;
    }
    
    .bg-white,
    .bg-gray-50,
    .bg-blue-50 {
        background: white !important;
        border: 1px solid #e5e7eb !important;
    }
    
    .text-white {
        color: black !important;
    }
}

/* Accessibility improvements */
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

/* Focus indicators for keyboard navigation */
button:focus,
select:focus,
input:focus,
textarea:focus {
    outline: 2px solid #2563eb;
    outline-offset: 2px;
}

/* Loading states */
.loading {
    opacity: 0.7;
    pointer-events: none;
}

/* Smooth transitions */
.transition-all {
    transition-property: all;
    transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
    transition-duration: 200ms;
}

/* Custom scrollbar for textareas */
textarea::-webkit-scrollbar {
    width: 6px;
}

textarea::-webkit-scrollbar-track {
    background: #f1f5f9;
}

textarea::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
}

textarea::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* Dynamic description container styles */
.auto-description-container {
    transition: all 0.3s ease-in-out;
}

.description-fade-in {
    animation: fadeIn 0.3s ease-in-out;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(-5px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Enhanced auto-generated description styling */
#autoGeneratedDescription {
    transition: all 0.2s ease-in-out;
    word-wrap: break-word;
    word-break: break-word;
}

#autoGeneratedDescription.updating {
    opacity: 0.6;
    transform: scale(0.98);
}

/* Toggle button styling */
#toggleDescriptionMode {
    transition: all 0.2s ease-in-out;
    padding: 2px 6px;
    border-radius: 4px;
}

#toggleDescriptionMode:hover {
    background-color: rgba(59, 130, 246, 0.1);
}

/* Refresh button animation */
#refreshDescription {
    transition: all 0.2s ease-in-out;
}

#refreshDescription:hover {
    transform: scale(1.1);
}

#refreshDescription:active {
    transform: scale(0.95);
}

/* Manual description container transition */
#manualDescriptionContainer {
    transition: all 0.3s ease-in-out;
}

#autoDescriptionContainer {
    transition: all 0.3s ease-in-out;
}

/* Enhanced focus styles for accessibility */
#toggleDescriptionMode:focus {
    outline: 2px solid #2563eb;
    outline-offset: 2px;
}

#refreshDescription:focus {
    outline: 2px solid #2563eb;
    outline-offset: 2px;
    border-radius: 4px;
}

/* Responsive adjustments for dynamic description */
@media (max-width: 768px) {
    #autoDescriptionContainer {
        margin-bottom: 1rem;
    }
    
    #autoGeneratedDescription {
        font-size: 0.875rem;
        line-height: 1.25rem;
        padding: 0.5rem;
    }
    
    #toggleDescriptionMode {
        font-size: 0.75rem;
        padding: 1px 4px;
    }
    
    #refreshDescription {
        padding: 0.25rem;
    }
    
    .description-controls {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }
}

/* Loading state for description updates */
.description-loading {
    position: relative;
    overflow: hidden;
}

.description-loading::after {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(
        90deg,
        transparent,
        rgba(59, 130, 246, 0.2),
        transparent
    );
    animation: shimmer 1.5s infinite;
}

@keyframes shimmer {
    0% {
        left: -100%;
    }
    100% {
        left: 100%;
    }
}

/* Enhanced preview modal styles */
#previewModal .bg-white {
    max-height: 90vh;
    overflow-y: auto;
}

#previewText {
    max-height: 200px;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: #cbd5e1 #f1f5f9;
}

/* Better visual hierarchy for description types */
.description-type-indicator {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 500;
    margin-left: 8px;
}

.auto-type {
    background-color: #dbeafe;
    color: #1e40af;
}

.manual-type {
    background-color: #f3e8ff;
    color: #7c3aed;
}

/* Smooth mode transition */
.mode-transition {
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Enhanced button states */
.btn-processing {
    background-color: #9ca3af;
    cursor: not-allowed;
    transform: scale(0.98);
}

.btn-processing:hover {
    background-color: #9ca3af;
}

/* Real-time update indicators */
.real-time-indicator {
    display: inline-flex;
    align-items: center;
    margin-left: 8px;
    color: #10b981;
    font-size: 0.75rem;
    opacity: 0;
    transition: opacity 0.3s ease-in-out;
}

.real-time-indicator.active {
    opacity: 1;
}

.real-time-indicator::before {
    content: '●';
    animation: pulse 2s infinite;
    margin-right: 4px;
}

@keyframes pulse {
    0%, 100% {
        opacity: 1;
    }
    50% {
        opacity: 0.5;
    }
}
</style>

<?php include '../../includes/footer.php'; ?>