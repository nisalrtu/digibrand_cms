<?php
// modules/projects/edit.php
session_start();

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

// Get and validate project ID
$projectId = Helper::decryptId($_GET['id'] ?? '');
if (!$projectId) {
    Helper::setMessage('Invalid project ID.', 'error');
    Helper::redirect('modules/projects/');
}

$pageTitle = 'Edit Project - Invoice Manager';

// Initialize variables
$errors = [];
$project = null;
$originalClientId = null;
$hasInvoices = false;
$hasPayments = false;

// Get database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Get project details with client information
    $projectQuery = "
        SELECT p.*, c.company_name, c.contact_person 
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

    $originalClientId = $project['client_id'];

    // Check for related invoices and payments
    $invoicesQuery = "SELECT COUNT(*) as count FROM invoices WHERE project_id = :project_id";
    $invoicesStmt = $db->prepare($invoicesQuery);
    $invoicesStmt->bindParam(':project_id', $projectId);
    $invoicesStmt->execute();
    $hasInvoices = $invoicesStmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;

    // Check for payments through invoices
    if ($hasInvoices) {
        $paymentsQuery = "
            SELECT COUNT(*) as count 
            FROM payments p 
            JOIN invoices i ON p.invoice_id = i.id 
            WHERE i.project_id = :project_id
        ";
        $paymentsStmt = $db->prepare($paymentsQuery);
        $paymentsStmt->bindParam(':project_id', $projectId);
        $paymentsStmt->execute();
        $hasPayments = $paymentsStmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;
    }

} catch (Exception $e) {
    Helper::setMessage('Error loading project details: ' . $e->getMessage(), 'error');
    Helper::redirect('modules/projects/');
}

// Get all active clients for the dropdown
try {
    $clientsQuery = "SELECT id, company_name, contact_person FROM clients WHERE is_active = 1 ORDER BY company_name ASC";
    $clientsStmt = $db->prepare($clientsQuery);
    $clientsStmt->execute();
    $clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $clients = [];
    $errors[] = 'Error loading clients. Please refresh the page.';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!Helper::verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        // Get and sanitize form data
        $formData = [
            'project_name' => Helper::sanitize($_POST['project_name'] ?? ''),
            'project_type' => Helper::sanitize($_POST['project_type'] ?? ''),
            'description' => Helper::sanitize($_POST['description'] ?? ''),
            'start_date' => Helper::sanitize($_POST['start_date'] ?? ''),
            'end_date' => Helper::sanitize($_POST['end_date'] ?? ''),
            'total_amount' => Helper::sanitize($_POST['total_amount'] ?? ''),
            'status' => Helper::sanitize($_POST['status'] ?? 'pending')
        ];
        
        $selectedClientId = Helper::decryptId($_POST['client_id'] ?? '');
        
        // Validate required fields
        if (empty($formData['project_name'])) {
            $errors[] = 'Project name is required.';
        } elseif (strlen($formData['project_name']) > 200) {
            $errors[] = 'Project name must be less than 200 characters.';
        }
        
        if (empty($selectedClientId)) {
            $errors[] = 'Please select a client.';
        }
        
        if (empty($formData['project_type'])) {
            $errors[] = 'Project type is required.';
        }
        
        if (empty($formData['start_date'])) {
            $errors[] = 'Start date is required.';
        } elseif (!strtotime($formData['start_date'])) {
            $errors[] = 'Please enter a valid start date.';
        }
        
        if (empty($formData['end_date'])) {
            $errors[] = 'End date is required.';
        } elseif (!strtotime($formData['end_date'])) {
            $errors[] = 'Please enter a valid end date.';
        } elseif (!empty($formData['start_date']) && strtotime($formData['end_date']) < strtotime($formData['start_date'])) {
            $errors[] = 'End date cannot be before the start date.';
        }
        
        if (!empty($formData['total_amount'])) {
            if (!is_numeric($formData['total_amount']) || $formData['total_amount'] < 0) {
                $errors[] = 'Total amount must be a valid positive number.';
            }
        }
        
        // Validate client selection
        if (!empty($selectedClientId)) {
            try {
                $validateClientQuery = "SELECT id FROM clients WHERE id = :client_id AND is_active = 1";
                $validateClientStmt = $db->prepare($validateClientQuery);
                $validateClientStmt->bindParam(':client_id', $selectedClientId);
                $validateClientStmt->execute();
                
                if ($validateClientStmt->rowCount() === 0) {
                    $errors[] = 'Selected client is not valid.';
                }
            } catch (Exception $e) {
                $errors[] = 'Error validating client. Please try again.';
            }
        }

        // Restrict client changes if there are invoices or payments
        if ($hasInvoices && $selectedClientId != $originalClientId) {
            $errors[] = 'Cannot change client: This project has related invoices.';
        }

        // Restrict status changes based on business rules
        if ($hasPayments && $formData['status'] === 'cancelled') {
            $errors[] = 'Cannot cancel project: This project has received payments.';
        }
        
        // Check if project name already exists for this client (excluding current project)
        if (empty($errors) && !empty($selectedClientId)) {
            try {
                $checkQuery = "SELECT id FROM projects WHERE project_name = :project_name AND client_id = :client_id AND id != :project_id";
                $checkStmt = $db->prepare($checkQuery);
                $checkStmt->bindParam(':project_name', $formData['project_name']);
                $checkStmt->bindParam(':client_id', $selectedClientId);
                $checkStmt->bindParam(':project_id', $projectId);
                $checkStmt->execute();
                
                if ($checkStmt->rowCount() > 0) {
                    $errors[] = 'A project with this name already exists for the selected client.';
                }
            } catch (Exception $e) {
                $errors[] = 'Error checking project name. Please try again.';
            }
        }
        
        // If no errors, update the project
        if (empty($errors)) {
            try {
                $updateQuery = "UPDATE projects SET 
                    client_id = :client_id,
                    project_name = :project_name, 
                    project_type = :project_type, 
                    description = :description, 
                    start_date = :start_date, 
                    end_date = :end_date, 
                    total_amount = :total_amount, 
                    status = :status,
                    updated_at = NOW()
                    WHERE id = :project_id";
                
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->bindParam(':client_id', $selectedClientId);
                $updateStmt->bindParam(':project_name', $formData['project_name']);
                $updateStmt->bindParam(':project_type', $formData['project_type']);
                $updateStmt->bindParam(':description', $formData['description']);
                $updateStmt->bindParam(':start_date', $formData['start_date']);
                $updateStmt->bindParam(':end_date', $formData['end_date']);
                
                // Handle total_amount
                $totalAmountValue = !empty($formData['total_amount']) ? $formData['total_amount'] : 0.00;
                $updateStmt->bindParam(':total_amount', $totalAmountValue);
                
                $updateStmt->bindParam(':status', $formData['status']);
                $updateStmt->bindParam(':project_id', $projectId);
                
                if ($updateStmt->execute()) {
                    Helper::setMessage('Project "' . $formData['project_name'] . '" updated successfully!', 'success');
                    Helper::redirect('modules/projects/view.php?id=' . Helper::encryptId($projectId));
                } else {
                    $errors[] = 'Error updating project. Please try again.';
                }
                
            } catch (Exception $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
} else {
    // Pre-populate form with existing data
    $formData = [
        'project_name' => $project['project_name'],
        'project_type' => $project['project_type'],
        'description' => $project['description'],
        'start_date' => $project['start_date'],
        'end_date' => $project['end_date'],
        'total_amount' => $project['total_amount'],
        'status' => $project['status']
    ];
}

include '../../includes/header.php';
?>

<!-- Breadcrumb -->
<nav class="mb-6">
    <div class="flex items-center space-x-2 text-sm text-gray-600">
        <a href="<?php echo Helper::baseUrl('modules/projects/'); ?>" class="hover:text-gray-900 transition-colors">
            Projects
        </a>
        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
        </svg>
        <a href="<?php echo Helper::baseUrl('modules/projects/view.php?id=' . Helper::encryptId($projectId)); ?>" class="hover:text-gray-900 transition-colors">
            <?php echo htmlspecialchars($project['project_name']); ?>
        </a>
        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
        </svg>
        <span class="text-gray-900 font-medium">Edit</span>
    </div>
</nav>

<!-- Page Header -->
<div class="mb-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Edit Project</h1>
            <p class="text-gray-600 mt-1">
                Update project details for <strong><?php echo htmlspecialchars($project['project_name']); ?></strong>
            </p>
        </div>
        <div class="mt-4 sm:mt-0 flex items-center space-x-3">
            <?php 
            $status = $project['status'];
            $statusClasses = [
                'pending' => 'bg-yellow-100 text-yellow-800 border-yellow-200',
                'in_progress' => 'bg-blue-100 text-blue-800 border-blue-200',
                'completed' => 'bg-green-100 text-green-800 border-green-200',
                'on_hold' => 'bg-gray-100 text-gray-800 border-gray-200',
                'cancelled' => 'bg-red-100 text-red-800 border-red-200'
            ];
            $badgeClass = $statusClasses[$status] ?? 'bg-gray-100 text-gray-800 border-gray-200';
            ?>
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border <?php echo $badgeClass; ?>">
                <?php echo ucwords(str_replace('_', ' ', $status)); ?>
            </span>
            <a href="<?php echo Helper::baseUrl('modules/projects/view.php?id=' . Helper::encryptId($projectId)); ?>" 
               class="inline-flex items-center px-4 py-2 text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Back to Project
            </a>
        </div>
    </div>
</div>

<!-- Warning Messages -->
<?php if ($hasInvoices || $hasPayments): ?>
    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
        <div class="flex">
            <svg class="w-5 h-5 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.464 0L3.35 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
            </svg>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-yellow-800">Edit Restrictions</h3>
                <div class="mt-1 text-sm text-yellow-700">
                    <ul class="list-disc list-inside space-y-1">
                        <?php if ($hasInvoices): ?>
                            <li>This project has related invoices - client cannot be changed</li>
                        <?php endif; ?>
                        <?php if ($hasPayments): ?>
                            <li>This project has received payments - cannot be cancelled</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Error Messages -->
<?php if (!empty($errors)): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
        <div class="flex items-center mb-2">
            <svg class="w-5 h-5 text-red-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <h3 class="text-red-800 font-medium">Please fix the following errors:</h3>
        </div>
        <ul class="text-red-700 text-sm space-y-1">
            <?php foreach ($errors as $error): ?>
                <li>• <?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Edit Project Form -->
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <div class="p-6">
        <form method="POST" class="space-y-6" novalidate>
            <!-- CSRF Token -->
            <input type="hidden" name="csrf_token" value="<?php echo Helper::generateCSRF(); ?>">
            
            <!-- Client Selection -->
            <div>
                <label for="client_id" class="block text-sm font-medium text-gray-700 mb-2">
                    Client <span class="text-red-500">*</span>
                </label>
                
                <?php if ($hasInvoices): ?>
                    <!-- Locked client display -->
                    <div class="flex items-center p-4 bg-gray-50 border border-gray-200 rounded-lg">
                        <div class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                        </div>
                        <div class="ml-3 flex-1">
                            <p class="font-medium text-gray-900"><?php echo htmlspecialchars($project['company_name']); ?></p>
                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($project['contact_person']); ?></p>
                        </div>
                        <div class="text-sm text-gray-500">
                            <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                            Locked (has invoices)
                        </div>
                    </div>
                    <input type="hidden" name="client_id" value="<?php echo Helper::encryptId($originalClientId); ?>">
                <?php else: ?>
                    <!-- Client dropdown -->
                    <select 
                        id="client_id" 
                        name="client_id" 
                        required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent transition-all duration-200 <?php echo in_array('Please select a client.', $errors) || in_array('Selected client is not valid.', $errors) ? 'border-red-300' : ''; ?>"
                    >
                        <option value="">Select a client...</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?php echo Helper::encryptId($client['id']); ?>" 
                                    <?php echo (isset($_POST['client_id']) ? ($_POST['client_id'] === Helper::encryptId($client['id'])) : ($client['id'] == $originalClientId)) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($client['company_name']); ?> 
                                (<?php echo htmlspecialchars($client['contact_person']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="mt-1 text-sm text-gray-500">
                        Choose the client this project is for. 
                        <a href="<?php echo Helper::baseUrl('modules/clients/add.php'); ?>" 
                           class="text-blue-600 hover:text-blue-700">Add new client</a>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Project Name -->
            <div>
                <label for="project_name" class="block text-sm font-medium text-gray-700 mb-2">
                    Project Name <span class="text-red-500">*</span>
                </label>
                <input 
                    type="text" 
                    id="project_name" 
                    name="project_name" 
                    value="<?php echo htmlspecialchars($formData['project_name']); ?>"
                    maxlength="200"
                    required
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent transition-all duration-200 <?php echo in_array('Project name is required.', $errors) || in_array('Project name must be less than 200 characters.', $errors) || str_contains(implode(' ', $errors), 'project with this name already exists') ? 'border-red-300' : ''; ?>"
                    placeholder="Enter project name"
                >
                <div class="mt-1 flex justify-between">
                    <p class="text-sm text-gray-500">A clear, descriptive name for the project</p>
                    <span id="project_name_count" class="text-sm text-gray-400">0/200</span>
                </div>
            </div>

            <!-- Project Type -->
            <div>
                <label for="project_type" class="block text-sm font-medium text-gray-700 mb-2">
                    Project Type <span class="text-red-500">*</span>
                </label>
                <select 
                    id="project_type" 
                    name="project_type" 
                    required
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent transition-all duration-200 <?php echo in_array('Project type is required.', $errors) ? 'border-red-300' : ''; ?>"
                >
                    <option value="">Select project type...</option>
                    <option value="graphics" <?php echo $formData['project_type'] === 'graphics' ? 'selected' : ''; ?>>Graphics</option>
                    <option value="social_media" <?php echo $formData['project_type'] === 'social_media' ? 'selected' : ''; ?>>Social Media</option>
                    <option value="website" <?php echo $formData['project_type'] === 'website' ? 'selected' : ''; ?>>Website</option>
                    <option value="software" <?php echo $formData['project_type'] === 'software' ? 'selected' : ''; ?>>Software</option>
                    <option value="other" <?php echo $formData['project_type'] === 'other' ? 'selected' : ''; ?>>Other</option>
                </select>
                <p class="mt-1 text-sm text-gray-500">Category that best describes this project</p>
            </div>

            <!-- Date Fields Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Start Date -->
                <div>
                    <label for="start_date" class="block text-sm font-medium text-gray-700 mb-2">
                        Start Date <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="date" 
                        id="start_date" 
                        name="start_date" 
                        value="<?php echo htmlspecialchars($formData['start_date']); ?>"
                        required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent transition-all duration-200 <?php echo in_array('Start date is required.', $errors) || in_array('Please enter a valid start date.', $errors) ? 'border-red-300' : ''; ?>"
                    >
                    <p class="mt-1 text-sm text-gray-500">When the project will begin</p>
                </div>

                <!-- End Date -->
                <div>
                    <label for="end_date" class="block text-sm font-medium text-gray-700 mb-2">
                        End Date <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="date" 
                        id="end_date" 
                        name="end_date" 
                        value="<?php echo htmlspecialchars($formData['end_date']); ?>"
                        required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent transition-all duration-200 <?php echo in_array('End date is required.', $errors) || in_array('Please enter a valid end date.', $errors) || in_array('End date cannot be before the start date.', $errors) ? 'border-red-300' : ''; ?>"
                    >
                    <p class="mt-1 text-sm text-gray-500">Expected completion date</p>
                </div>
            </div>

            <!-- Total Amount and Status Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Total Amount -->
                <div>
                    <label for="total_amount" class="block text-sm font-medium text-gray-700 mb-2">
                        Total Amount (LKR)
                    </label>
                    <input 
                        type="number" 
                        id="total_amount" 
                        name="total_amount" 
                        value="<?php echo htmlspecialchars($formData['total_amount']); ?>"
                        min="0"
                        step="0.01"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent transition-all duration-200 <?php echo in_array('Total amount must be a valid positive number.', $errors) ? 'border-red-300' : ''; ?>"
                        placeholder="0.00"
                    >
                    <p class="mt-1 text-sm text-gray-500">Project total amount</p>
                </div>

                <!-- Status -->
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700 mb-2">
                        Project Status
                    </label>
                    <select 
                        id="status" 
                        name="status" 
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent transition-all duration-200"
                        <?php echo ($hasPayments && $formData['status'] !== 'cancelled') ? '' : ''; ?>
                    >
                        <option value="pending" <?php echo $formData['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="in_progress" <?php echo $formData['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="completed" <?php echo $formData['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="on_hold" <?php echo $formData['status'] === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                        <?php if (!$hasPayments): ?>
                            <option value="cancelled" <?php echo $formData['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        <?php endif; ?>
                    </select>
                    <p class="mt-1 text-sm text-gray-500">
                        Current project status
                        <?php if ($hasPayments): ?>
                            <span class="text-yellow-600">(Cannot cancel - has payments)</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <!-- Description -->
            <div>
                <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
                    Project Description
                </label>
                <textarea 
                    id="description" 
                    name="description" 
                    rows="4"
                    maxlength="1000"
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent transition-all duration-200 resize-none"
                    placeholder="Describe the project objectives, scope, and requirements..."
                ><?php echo htmlspecialchars($formData['description']); ?></textarea>
                <div class="mt-1 flex justify-between">
                    <p class="text-sm text-gray-500">Detailed description of project goals and deliverables</p>
                    <span id="description_count" class="text-sm text-gray-400">0/1000</span>
                </div>
            </div>

            <!-- Project Meta Information -->
            <div class="bg-gray-50 rounded-lg p-4">
                <h3 class="text-sm font-medium text-gray-900 mb-3">Project Information</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div>
                        <span class="text-gray-500">Created:</span>
                        <span class="text-gray-900 font-medium ml-2">
                            <?php echo Helper::formatDate($project['created_at'], 'M j, Y \a\t g:i A'); ?>
                        </span>
                    </div>
                    <div>
                        <span class="text-gray-500">Last Updated:</span>
                        <span class="text-gray-900 font-medium ml-2">
                            <?php echo Helper::formatDate($project['updated_at'], 'M j, Y \a\t g:i A'); ?>
                        </span>
                    </div>
                    <?php if ($hasInvoices): ?>
                        <div>
                            <span class="text-gray-500">Has Invoices:</span>
                            <span class="text-yellow-600 font-medium ml-2">Yes</span>
                        </div>
                    <?php endif; ?>
                    <?php if ($hasPayments): ?>
                        <div>
                            <span class="text-gray-500">Has Payments:</span>
                            <span class="text-green-600 font-medium ml-2">Yes</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="flex flex-col sm:flex-row gap-3 pt-6 border-t border-gray-200">
                <button 
                    type="submit" 
                    class="flex-1 sm:flex-none inline-flex items-center justify-center px-6 py-3 bg-gray-800 text-white rounded-lg hover:bg-gray-700 focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition-all duration-200 font-medium"
                    id="submitBtn"
                >
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                    </svg>
                    <span id="submitText">Update Project</span>
                    <span id="submitLoading" class="hidden">
                        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline" fill="none" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"></circle>
                            <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" class="opacity-75"></path>
                        </svg>
                        Updating Project...
                    </span>
                </button>
                
                <a href="<?php echo Helper::baseUrl('modules/projects/view.php?id=' . Helper::encryptId($projectId)); ?>" 
                   class="flex-1 sm:flex-none inline-flex items-center justify-center px-6 py-3 bg-white text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition-all duration-200 font-medium">
                    Cancel
                </a>
                
                <a href="<?php echo Helper::baseUrl('modules/projects/'); ?>" 
                   class="flex-1 sm:flex-none inline-flex items-center justify-center px-6 py-3 bg-white text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition-all duration-200 font-medium">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                    </svg>
                    All Projects
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Related Data Warning -->
<?php if ($hasInvoices || $hasPayments): ?>
    <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
        <div class="flex items-start">
            <svg class="w-5 h-5 text-blue-600 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <div>
                <h4 class="text-blue-800 font-medium mb-1">Project Edit Restrictions</h4>
                <div class="text-blue-700 text-sm space-y-1">
                    <?php if ($hasInvoices): ?>
                        <p>• This project has invoices, so the client cannot be changed to maintain data integrity</p>
                    <?php endif; ?>
                    <?php if ($hasPayments): ?>
                        <p>• This project has received payments, so it cannot be cancelled</p>
                    <?php endif; ?>
                    <p>• You can still modify other project details like dates, description, and amount</p>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- Quick Tips -->
    <div class="mt-6 bg-green-50 border border-green-200 rounded-lg p-4">
        <div class="flex items-start">
            <svg class="w-5 h-5 text-green-600 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <div>
                <h4 class="text-green-800 font-medium mb-1">Edit Tips</h4>
                <ul class="text-green-700 text-sm space-y-1">
                    <li>• Update project details as requirements change</li>
                    <li>• Adjust dates if timeline shifts</li>
                    <li>• Change status to track project progress</li>
                    <li>• Update amount based on scope changes</li>
                </ul>
            </div>
        </div>
    </div>
<?php endif; ?>

<style>
/* Enhanced mobile form styles */
@media (max-width: 640px) {
    .grid.grid-cols-1.md\:grid-cols-2 {
        grid-template-columns: repeat(1, minmax(0, 1fr));
    }
    
    input, textarea, select {
        font-size: 16px; /* Prevent zoom on iOS */
    }
}

/* Focus states for better accessibility */
input:focus, textarea:focus, select:focus {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

/* Character counter styles */
.text-gray-400 {
    font-variant-numeric: tabular-nums;
}

/* Form validation styles */
.border-red-300 {
    border-color: #fca5a5;
    box-shadow: 0 0 0 1px #fca5a5;
}

.border-red-300:focus {
    border-color: #ef4444;
    box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
}

/* Loading button styles */
#submitBtn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

/* Date input styles */
input[type="date"] {
    position: relative;
}

input[type="date"]::-webkit-calendar-picker-indicator {
    padding: 4px;
    cursor: pointer;
}

/* Custom select styles */
select {
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
    background-position: right 12px center;
    background-repeat: no-repeat;
    background-size: 16px;
    padding-right: 48px;
}

/* Disabled/locked fields */
.locked-field {
    background-color: #f9fafb;
    color: #6b7280;
    cursor: not-allowed;
}

/* Smooth transitions */
* {
    transition-property: color, background-color, border-color, transform, box-shadow;
    transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
    transition-duration: 150ms;
}

/* Status badge animations */
.status-badge {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.8; }
}
</style>

<script>
// Character counters
function setupCharacterCounter(inputId, counterId, maxLength) {
    const input = document.getElementById(inputId);
    const counter = document.getElementById(counterId);
    
    if (input && counter) {
        function updateCounter() {
            const length = input.value.length;
            counter.textContent = `${length}/${maxLength}`;
            
            if (length > maxLength * 0.8) {
                counter.classList.add('text-orange-500');
                counter.classList.remove('text-gray-400');
            } else {
                counter.classList.add('text-gray-400');
                counter.classList.remove('text-orange-500');
            }
        }
        
        input.addEventListener('input', updateCounter);
        updateCounter(); // Initial count
    }
}

// Setup character counters
setupCharacterCounter('project_name', 'project_name_count', 200);
setupCharacterCounter('description', 'description_count', 1000);

// Date validation
function setupDateValidation() {
    const startDate = document.getElementById('start_date');
    const endDate = document.getElementById('end_date');
    
    function validateDates() {
        if (startDate.value && endDate.value) {
            const start = new Date(startDate.value);
            const end = new Date(endDate.value);
            
            if (end < start) {
                endDate.setCustomValidity('End date cannot be before start date');
                endDate.classList.add('border-red-300');
            } else {
                endDate.setCustomValidity('');
                endDate.classList.remove('border-red-300');
            }
        }
    }
    
    startDate.addEventListener('change', validateDates);
    endDate.addEventListener('change', validateDates);
}

setupDateValidation();

// Form submission handling
document.querySelector('form').addEventListener('submit', function(e) {
    const submitBtn = document.getElementById('submitBtn');
    const submitText = document.getElementById('submitText');
    const submitLoading = document.getElementById('submitLoading');
    
    // Show loading state
    submitBtn.disabled = true;
    submitText.classList.add('hidden');
    submitLoading.classList.remove('hidden');
    
    // Re-enable after timeout (in case of error)
    setTimeout(() => {
        submitBtn.disabled = false;
        submitText.classList.remove('hidden');
        submitLoading.classList.add('hidden');
    }, 10000);
});

// Total amount formatting
document.getElementById('total_amount').addEventListener('input', function(e) {
    let value = e.target.value;
    // Remove non-numeric characters except decimal point
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
    
    e.target.value = value;
});

// Form validation feedback
document.querySelectorAll('input[required], textarea[required], select[required]').forEach(field => {
    field.addEventListener('blur', function() {
        if (this.value.trim() === '') {
            this.classList.add('border-red-300');
        } else {
            this.classList.remove('border-red-300');
        }
    });
    
    field.addEventListener('input', function() {
        if (this.value.trim() !== '') {
            this.classList.remove('border-red-300');
        }
    });
});

// Prevent accidental navigation away
let formChanged = false;
const originalValues = {};

// Store original values
document.querySelectorAll('input, textarea, select').forEach(field => {
    originalValues[field.name] = field.value;
});

// Track changes
document.querySelectorAll('input, textarea, select').forEach(field => {
    field.addEventListener('change', function() {
        formChanged = originalValues[this.name] !== this.value;
    });
});

window.addEventListener('beforeunload', function(e) {
    if (formChanged && !document.querySelector('form').submitted) {
        e.preventDefault();
        e.returnValue = '';
    }
});

// Mark form as submitted to prevent warning
document.querySelector('form').addEventListener('submit', function() {
    this.submitted = true;
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl/Cmd + Enter to submit
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        document.querySelector('form').dispatchEvent(new Event('submit'));
    }
    
    // Escape to go back
    if (e.key === 'Escape') {
        window.location.href = '<?php echo Helper::baseUrl('modules/projects/view.php?id=' . Helper::encryptId($projectId)); ?>';
    }
});

// Status change confirmation for sensitive actions
document.getElementById('status').addEventListener('change', function() {
    const newStatus = this.value;
    const originalStatus = '<?php echo $project['status']; ?>';
    
    // Confirm if changing to cancelled
    if (newStatus === 'cancelled' && originalStatus !== 'cancelled') {
        if (!confirm('Are you sure you want to cancel this project? This action should be carefully considered.')) {
            this.value = originalStatus;
            return;
        }
    }
    
    // Confirm if changing from completed back to other status
    if (originalStatus === 'completed' && newStatus !== 'completed') {
        if (!confirm('Are you sure you want to change this project from completed status? This may affect reporting.')) {
            this.value = originalStatus;
            return;
        }
    }
});

// Auto-save draft (optional feature) - only for non-sensitive fields
let autoSaveTimer;
function autoSave() {
    const formData = new FormData(document.querySelector('form'));
    const data = Object.fromEntries(formData.entries());
    // Don't save CSRF token
    delete data.csrf_token;
    
    localStorage.setItem('project_edit_draft_<?php echo $projectId; ?>', JSON.stringify(data));
}

// Auto-save on input (debounced) - excluding sensitive fields
document.querySelectorAll('input:not([name="client_id"]):not([name="status"]), textarea').forEach(field => {
    field.addEventListener('input', function() {
        clearTimeout(autoSaveTimer);
        autoSaveTimer = setTimeout(autoSave, 3000);
    });
});

// Clear draft on successful submission
document.querySelector('form').addEventListener('submit', function() {
    localStorage.removeItem('project_edit_draft_<?php echo $projectId; ?>');
});

// Load draft on page load (only if no validation errors)
document.addEventListener('DOMContentLoaded', function() {
    const hasErrors = <?php echo !empty($errors) ? 'true' : 'false'; ?>;
    
    if (!hasErrors) {
        const draft = localStorage.getItem('project_edit_draft_<?php echo $projectId; ?>');
        if (draft) {
            try {
                const data = JSON.parse(draft);
                Object.keys(data).forEach(key => {
                    const field = document.querySelector(`[name="${key}"]`);
                    if (field && !field.disabled && field.type !== 'hidden') {
                        // Only restore non-sensitive fields
                        if (!['client_id', 'status'].includes(key)) {
                            field.value = data[key];
                            // Trigger character counters and validation
                            field.dispatchEvent(new Event('input'));
                        }
                    }
                });
            } catch (e) {
                // Ignore invalid draft data
                localStorage.removeItem('project_edit_draft_<?php echo $projectId; ?>');
            }
        }
    }
});

// Project timeline visualization (optional enhancement)
function updateProjectProgress() {
    const startDate = document.getElementById('start_date').value;
    const endDate = document.getElementById('end_date').value;
    const currentDate = new Date().toISOString().split('T')[0];
    
    if (startDate && endDate) {
        const start = new Date(startDate);
        const end = new Date(endDate);
        const current = new Date(currentDate);
        
        const totalDuration = end - start;
        const elapsed = current - start;
        const progress = Math.min(Math.max((elapsed / totalDuration) * 100, 0), 100);
        
        // You could add a progress bar here
        console.log(`Project progress: ${progress.toFixed(1)}%`);
    }
}

// Update progress when dates change
document.getElementById('start_date').addEventListener('change', updateProjectProgress);
document.getElementById('end_date').addEventListener('change', updateProjectProgress);

// Initial progress calculation
updateProjectProgress();

// Enhanced form interactions
document.querySelectorAll('input, select, textarea').forEach(field => {
    // Add focus rings
    field.addEventListener('focus', function() {
        this.style.boxShadow = '0 0 0 3px rgba(59, 130, 246, 0.1)';
    });
    
    field.addEventListener('blur', function() {
        this.style.boxShadow = '';
    });
});

// Confirmation for critical changes
function confirmCriticalChange(message) {
    return confirm(message + '\n\nThis action may affect related invoices and payments. Are you sure you want to continue?');
}

// Warn about invoice/payment impacts
<?php if ($hasInvoices || $hasPayments): ?>
document.querySelectorAll('input, select, textarea').forEach(field => {
    field.addEventListener('change', function() {
        if (['project_name', 'total_amount'].includes(this.name)) {
            // Show temporary notice
            const notice = document.createElement('div');
            notice.className = 'text-xs text-orange-600 mt-1';
            notice.textContent = 'Note: This project has related invoices/payments';
            
            // Remove existing notices
            const existingNotice = this.parentNode.querySelector('.text-orange-600');
            if (existingNotice) {
                existingNotice.remove();
            }
            
            this.parentNode.appendChild(notice);
            
            // Remove notice after 3 seconds
            setTimeout(() => {
                if (notice.parentNode) {
                    notice.remove();
                }
            }, 3000);
        }
    });
});
<?php endif; ?>

// Smooth status transitions
document.getElementById('status').addEventListener('change', function() {
    const badge = document.querySelector('.inline-flex.items-center.px-2\\.5');
    if (badge) {
        badge.style.opacity = '0.5';
        badge.style.transform = 'scale(0.95)';
        
        setTimeout(() => {
            badge.style.opacity = '';
            badge.style.transform = '';
        }, 300);
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
