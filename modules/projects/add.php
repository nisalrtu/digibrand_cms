<?php
// modules/projects/add.php
session_start();

// Prevent caching of this page
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

$pageTitle = 'Add Project - Invoice Manager';

// Initialize variables
$errors = [];
$clientId = null;
$client = null;
$formData = [
    'project_name' => '',
    'project_type' => '',
    'description' => '',
    'start_date' => '',
    'end_date' => '',
    'total_amount' => '',
    'status' => 'pending'
];

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Check if client ID is provided and validate it (only for GET requests, not POST)
if (isset($_GET['client_id']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $clientId = Helper::decryptId($_GET['client_id']);
    if ($clientId) {
        try {
            $clientQuery = "SELECT * FROM clients WHERE id = :client_id AND is_active = 1";
            $clientStmt = $db->prepare($clientQuery);
            $clientStmt->bindParam(':client_id', $clientId);
            $clientStmt->execute();
            
            $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
            if (!$client) {
                $clientId = null;
            }
        } catch (Exception $e) {
            $clientId = null;
        }
    }
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
        // Get and sanitize form data - clear any cached values first
        $formData = [
            'project_name' => Helper::sanitize($_POST['project_name'] ?? ''),
            'project_type' => Helper::sanitize($_POST['project_type'] ?? ''),
            'description' => Helper::sanitize($_POST['description'] ?? ''),
            'start_date' => Helper::sanitize($_POST['start_date'] ?? ''),
            'end_date' => Helper::sanitize($_POST['end_date'] ?? ''),
            'total_amount' => Helper::sanitize($_POST['total_amount'] ?? ''),
            'status' => Helper::sanitize($_POST['status'] ?? 'pending')
        ];
        
        // Get client ID from form submission, not from URL parameter
        $selectedClientId = Helper::decryptId($_POST['client_id'] ?? '');
        
        // Clear any URL-based client selection when form is submitted
        $clientId = null;
        $client = null;
        
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
        
        // Check if project name already exists for this client
        if (empty($errors) && !empty($selectedClientId)) {
            try {
                $checkQuery = "SELECT id FROM projects WHERE project_name = :project_name AND client_id = :client_id";
                $checkStmt = $db->prepare($checkQuery);
                $checkStmt->bindParam(':project_name', $formData['project_name']);
                $checkStmt->bindParam(':client_id', $selectedClientId);
                $checkStmt->execute();
                
                if ($checkStmt->rowCount() > 0) {
                    $errors[] = 'A project with this name already exists for the selected client.';
                }
            } catch (Exception $e) {
                $errors[] = 'Error checking project name. Please try again.';
            }
        }
        
        // If no errors, save the project
        if (empty($errors)) {
            try {
                $insertQuery = "INSERT INTO projects (
                    client_id, project_name, project_type, description, 
                    start_date, end_date, total_amount, status, created_by, created_at, updated_at
                ) VALUES (
                    :client_id, :project_name, :project_type, :description, 
                    :start_date, :end_date, :total_amount, :status, :created_by, NOW(), NOW()
                )";
                
                $insertStmt = $db->prepare($insertQuery);
                $insertStmt->bindParam(':client_id', $selectedClientId);
                $insertStmt->bindParam(':project_name', $formData['project_name']);
                $insertStmt->bindParam(':project_type', $formData['project_type']);
                $insertStmt->bindParam(':description', $formData['description']);
                $insertStmt->bindParam(':start_date', $formData['start_date']);
                $insertStmt->bindParam(':end_date', $formData['end_date']);
                
                // Handle total_amount - use bindValue for null or bindParam for actual value
                $totalAmountValue = !empty($formData['total_amount']) ? $formData['total_amount'] : 0.00;
                $insertStmt->bindParam(':total_amount', $totalAmountValue);
                
                $insertStmt->bindParam(':status', $formData['status']);
                
                // Add created_by - assuming user_id is stored in session
                $createdBy = $_SESSION['user_id'] ?? 1; // Default to 1 if no user session
                $insertStmt->bindParam(':created_by', $createdBy);
                
                if ($insertStmt->execute()) {
                    $projectId = $db->lastInsertId();
                    Helper::setMessage('Project "' . $formData['project_name'] . '" created successfully!', 'success');
                    Helper::redirect('modules/projects/view.php?id=' . Helper::encryptId($projectId));
                } else {
                    $errors[] = 'Error creating project. Please try again.';
                }
                
            } catch (Exception $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
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
        <span class="text-gray-900 font-medium">Add Project</span>
    </div>
</nav>

<!-- Page Header -->
<div class="mb-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Create New Project</h1>
            <p class="text-gray-600 mt-1">
                <?php if ($client): ?>
                    Create a new project for <strong><?php echo htmlspecialchars($client['company_name']); ?></strong>
                <?php else: ?>
                    Set up a new project for your client
                <?php endif; ?>
            </p>
        </div>
        <div class="mt-4 sm:mt-0">
            <a href="<?php echo Helper::baseUrl('modules/projects/'); ?>" 
               class="inline-flex items-center px-4 py-2 text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Back to Projects
            </a>
        </div>
    </div>
</div>

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

<!-- Add Project Form -->
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <div class="p-6">
        <form method="POST" class="space-y-6" novalidate autocomplete="off">
            <!-- CSRF Token -->
            <input type="hidden" name="csrf_token" value="<?php echo Helper::generateCSRF(); ?>">>
            
            <!-- Client Selection -->
            <div>
                <label for="client_id" class="block text-sm font-medium text-gray-700 mb-2">
                    Client <span class="text-red-500">*</span>
                </label>
                <?php if ($client): ?>
                    <!-- Pre-selected client -->
                    <div class="flex items-center p-4 bg-blue-50 border border-blue-200 rounded-lg">
                        <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-2m-2 0H5m14 0V9a2 2 0 00-2-2H9a2 2 0 00-2 2v12a2 2 0 002 2h4m0 0V9a2 2 0 012-2h2a2 2 0 012 2v12"></path>
                            </svg>
                        </div>
                        <div class="ml-3 flex-1">
                            <p class="font-medium text-gray-900"><?php echo htmlspecialchars($client['company_name']); ?></p>
                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($client['contact_person']); ?></p>
                        </div>
                        <a href="<?php echo Helper::baseUrl('modules/projects/add.php'); ?>" 
                           class="text-blue-600 hover:text-blue-700 text-sm font-medium">
                            Change Client
                        </a>
                    </div>
                    <input type="hidden" name="client_id" value="<?php echo Helper::encryptId($client['id']); ?>">
                <?php else: ?>
                    <!-- Client dropdown -->
                    <select 
                        id="client_id" 
                        name="client_id" 
                        required
                        autocomplete="off"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent transition-all duration-200 <?php echo in_array('Please select a client.', $errors) || in_array('Selected client is not valid.', $errors) ? 'border-red-300' : ''; ?>"
                    >
                        <option value="">Select a client...</option>
                        <?php foreach ($clients as $clientOption): ?>
                            <option value="<?php echo Helper::encryptId($clientOption['id']); ?>" 
                                    <?php 
                                    // For POST (form submission), use the posted value
                                    // For GET (fresh page load), use the URL client_id if available
                                    $isSelected = false;
                                    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                                        $isSelected = (isset($_POST['client_id']) && $_POST['client_id'] === Helper::encryptId($clientOption['id']));
                                    } else {
                                        $isSelected = ($clientId && $clientOption['id'] == $clientId);
                                    }
                                    echo $isSelected ? 'selected' : ''; 
                                    ?>>
                                <?php echo htmlspecialchars($clientOption['company_name']); ?> 
                                (<?php echo htmlspecialchars($clientOption['contact_person']); ?>)
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
                    autocomplete="off"
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
                    <p class="mt-1 text-sm text-gray-500">Project total amount (will default to 0.00 if empty)</p>
                </div>

                <!-- Status -->
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700 mb-2">
                        Initial Status
                    </label>
                    <select 
                        id="status" 
                        name="status" 
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent transition-all duration-200"
                    >
                        <option value="pending" <?php echo $formData['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="in_progress" <?php echo $formData['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="completed" <?php echo $formData['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $formData['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                    <p class="mt-1 text-sm text-gray-500">Current project status</p>
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

            <!-- Form Actions -->
            <div class="flex flex-col sm:flex-row gap-3 pt-6 border-t border-gray-200">
                <button 
                    type="submit" 
                    class="flex-1 sm:flex-none inline-flex items-center justify-center px-6 py-3 bg-gray-800 text-white rounded-lg hover:bg-gray-700 focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition-all duration-200 font-medium"
                    id="submitBtn"
                >
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                    </svg>
                    <span id="submitText">Create Project</span>
                    <span id="submitLoading" class="hidden">
                        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline" fill="none" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"></circle>
                            <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" class="opacity-75"></path>
                        </svg>
                        Creating Project...
                    </span>
                </button>
                
                <a href="<?php echo Helper::baseUrl('modules/projects/'); ?>" 
                   class="flex-1 sm:flex-none inline-flex items-center justify-center px-6 py-3 bg-white text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition-all duration-200 font-medium">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Quick Tips -->
<div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
    <div class="flex items-start">
        <svg class="w-5 h-5 text-blue-600 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <div>
            <h4 class="text-blue-800 font-medium mb-1">Project Creation Tips</h4>
            <ul class="text-blue-700 text-sm space-y-1">
                <li>• Use descriptive project names that clearly identify the work</li>
                <li>• Set realistic deadlines with buffer time for revisions</li>
                <li>• Include budget estimates to track profitability</li>
                <li>• Detailed descriptions help with project management and invoicing</li>
            </ul>
        </div>
    </div>
</div>

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

/* Smooth transitions */
* {
    transition-property: color, background-color, border-color, transform, box-shadow;
    transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
    transition-duration: 150ms;
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
    
    // Set default start date to today
    if (!startDate.value) {
        startDate.value = new Date().toISOString().split('T')[0];
    }
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

// Auto-focus first field on desktop
if (window.innerWidth > 768) {
    const firstField = document.getElementById('client_id') || document.getElementById('project_name');
    if (firstField) {
        firstField.focus();
    }
}

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
document.querySelectorAll('input, textarea, select').forEach(field => {
    field.addEventListener('change', () => formChanged = true);
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
        window.location.href = '<?php echo Helper::baseUrl('modules/projects/'); ?>';
    }
});

// Smart project name suggestions based on client and type
function suggestProjectName() {
    const clientSelect = document.getElementById('client_id');
    const typeSelect = document.getElementById('project_type');
    const nameInput = document.getElementById('project_name');
    
    if (!nameInput.value && clientSelect && typeSelect) {
        const clientText = clientSelect.options[clientSelect.selectedIndex]?.text;
        const typeValue = typeSelect.value;
        
        if (clientText && typeValue && clientText !== 'Select a client...') {
            const clientName = clientText.split(' (')[0]; // Remove contact person part
            const typeLabel = typeSelect.options[typeSelect.selectedIndex].text;
            
            // Suggest format: "ClientName - TypeLabel"
            const suggestion = `${clientName} - ${typeLabel}`;
            if (suggestion.length <= 200) {
                nameInput.placeholder = `e.g., ${suggestion}`;
            }
        }
    }
}

// Setup project name suggestions
document.getElementById('client_id')?.addEventListener('change', suggestProjectName);
document.getElementById('project_type')?.addEventListener('change', suggestProjectName);

// Auto-save draft (optional feature)
let autoSaveTimer;
function autoSave() {
    // Only auto-save if this is a fresh form, not a form with errors
    if (!<?php echo !empty($errors) ? 'true' : 'false'; ?>) {
        const formData = new FormData(document.querySelector('form'));
        const data = Object.fromEntries(formData.entries());
        // Don't save CSRF token or client_id in draft
        delete data.csrf_token;
        if (data.client_id && document.querySelector('input[name="client_id"][type="hidden"]')) {
            delete data.client_id; // Don't override pre-selected client from URL
        }
        localStorage.setItem('project_form_draft', JSON.stringify(data));
    }
}

// Clear all cached data when page loads
function clearFormCache() {
    // Clear browser's form cache
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
    
    // Clear localStorage draft if we have a client pre-selected
    const hasPreselectedClient = <?php echo ($client) ? 'true' : 'false'; ?>;
    if (hasPreselectedClient) {
        localStorage.removeItem('project_form_draft');
    }
    
    // Reset form if this is a fresh load (not a POST request)
    const isPostRequest = <?php echo ($_SERVER['REQUEST_METHOD'] === 'POST') ? 'true' : 'false'; ?>;
    if (!isPostRequest && !hasPreselectedClient) {
        // Only clear if no validation errors
        const hasErrors = <?php echo !empty($errors) ? 'true' : 'false'; ?>;
        if (!hasErrors) {
            setTimeout(() => {
                document.querySelector('form').reset();
                // Restore pre-selected client if any
                const clientSelect = document.getElementById('client_id');
                if (clientSelect && hasPreselectedClient) {
                    clientSelect.value = '<?php echo $client ? Helper::encryptId($client['id']) : ''; ?>';
                }
            }, 100);
        }
    }
}

// Load draft on page load
document.addEventListener('DOMContentLoaded', function() {
    // Clear cache first
    clearFormCache();
    
    // Only load draft if no pre-selected client and no validation errors
    const hasPreselectedClient = <?php echo ($client) ? 'true' : 'false'; ?>;
    const hasErrors = <?php echo !empty($errors) ? 'true' : 'false'; ?>;
    const isPostRequest = <?php echo ($_SERVER['REQUEST_METHOD'] === 'POST') ? 'true' : 'false'; ?>;
    
    if (!hasPreselectedClient && !hasErrors && !isPostRequest) {
        const draft = localStorage.getItem('project_form_draft');
        if (draft) {
            try {
                const data = JSON.parse(draft);
                Object.keys(data).forEach(key => {
                    const field = document.querySelector(`[name="${key}"]`);
                    if (field && field.type !== 'hidden') {
                        field.value = data[key];
                        // Trigger character counters and validation
                        field.dispatchEvent(new Event('input'));
                        field.dispatchEvent(new Event('change'));
                    }
                });
            } catch (e) {
                // Ignore invalid draft data
                localStorage.removeItem('project_form_draft');
            }
        }
    }
    
    // Initial suggestions
    suggestProjectName();
});

// Clear draft on successful submission or when there are no errors in POST
<?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
localStorage.removeItem('project_form_draft');
// Also clear browser cache for this form
if (window.history.replaceState) {
    window.history.replaceState(null, null, window.location.pathname);
}
<?php endif; ?>

// Auto-save on input (debounced)
document.querySelectorAll('input, textarea, select').forEach(field => {
    field.addEventListener('input', function() {
        clearTimeout(autoSaveTimer);
        autoSaveTimer = setTimeout(autoSave, 2000);
    });
});

// Project type icons and descriptions
const projectTypeInfo = {
    'graphics': {
        icon: '🎨',
        description: 'Graphic design, logos, and visual content'
    },
    'social_media': {
        icon: '�',
        description: 'Social media content and management'
    },
    'website': {
        icon: '🌐',
        description: 'Website development and design'
    },
    'software': {
        icon: '�',
        description: 'Software development and applications'
    },
    'other': {
        icon: '📁',
        description: 'Other project types'
    }
};

// Update project type description
document.getElementById('project_type').addEventListener('change', function() {
    const info = projectTypeInfo[this.value];
    const description = this.nextElementSibling;
    
    if (info && description) {
        description.innerHTML = `${info.icon} ${info.description}`;
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
