<?php
// modules/clients/add.php
session_start();

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

$pageTitle = 'Add Client - Invoice Manager';

// Initialize variables
$errors = [];
$formData = [
    'company_name' => '',
    'contact_person' => '',
    'mobile_number' => '',
    'address' => '',
    'city' => ''
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!Helper::verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        // Get and sanitize form data
        $formData['company_name'] = Helper::sanitize($_POST['company_name'] ?? '');
        $formData['contact_person'] = Helper::sanitize($_POST['contact_person'] ?? '');
        $formData['mobile_number'] = Helper::sanitize($_POST['mobile_number'] ?? '');
        $formData['address'] = Helper::sanitize($_POST['address'] ?? '');
        $formData['city'] = Helper::sanitize($_POST['city'] ?? '');
        
        // Validate required fields
        if (empty($formData['company_name'])) {
            $errors[] = 'Company name is required.';
        } elseif (strlen($formData['company_name']) > 200) {
            $errors[] = 'Company name must be less than 200 characters.';
        }
        
        if (empty($formData['contact_person'])) {
            $errors[] = 'Contact person is required.';
        } elseif (strlen($formData['contact_person']) > 100) {
            $errors[] = 'Contact person name must be less than 100 characters.';
        }
        
        if (empty($formData['mobile_number'])) {
            $errors[] = 'Mobile number is required.';
        } elseif (!preg_match('/^[0-9+\-\s()]{7,20}$/', $formData['mobile_number'])) {
            $errors[] = 'Please enter a valid mobile number.';
        }
        
        if (empty($formData['address'])) {
            $errors[] = 'Address is required.';
        }
        
        if (empty($formData['city'])) {
            $errors[] = 'City is required.';
        } elseif (strlen($formData['city']) > 100) {
            $errors[] = 'City name must be less than 100 characters.';
        }
        
        // Check if company name already exists
        if (empty($errors)) {
            try {
                $database = new Database();
                $db = $database->getConnection();
                
                $checkQuery = "SELECT id FROM clients WHERE company_name = :company_name AND is_active = 1";
                $checkStmt = $db->prepare($checkQuery);
                $checkStmt->bindParam(':company_name', $formData['company_name']);
                $checkStmt->execute();
                
                if ($checkStmt->rowCount() > 0) {
                    $errors[] = 'A client with this company name already exists.';
                }
            } catch (Exception $e) {
                $errors[] = 'Error checking company name. Please try again.';
            }
        }
        
        // If no errors, save the client
        if (empty($errors)) {
            try {
                $insertQuery = "INSERT INTO clients (company_name, contact_person, mobile_number, address, city) 
                               VALUES (:company_name, :contact_person, :mobile_number, :address, :city)";
                
                $insertStmt = $db->prepare($insertQuery);
                $insertStmt->bindParam(':company_name', $formData['company_name']);
                $insertStmt->bindParam(':contact_person', $formData['contact_person']);
                $insertStmt->bindParam(':mobile_number', $formData['mobile_number']);
                $insertStmt->bindParam(':address', $formData['address']);
                $insertStmt->bindParam(':city', $formData['city']);
                
                if ($insertStmt->execute()) {
                    $clientId = $db->lastInsertId();
                    Helper::setMessage('Client "' . $formData['company_name'] . '" added successfully!', 'success');
                    Helper::redirect('modules/clients/view.php?id=' . Helper::encryptId($clientId));
                } else {
                    $errors[] = 'Error saving client. Please try again.';
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
        <a href="<?php echo Helper::baseUrl('modules/clients/'); ?>" class="hover:text-gray-900 transition-colors">
            Clients
        </a>
        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
        </svg>
        <span class="text-gray-900 font-medium">Add Client</span>
    </div>
</nav>

<!-- Page Header -->
<div class="mb-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Add New Client</h1>
            <p class="text-gray-600 mt-1">Create a new client profile for your business</p>
        </div>
        <div class="mt-4 sm:mt-0">
            <a href="<?php echo Helper::baseUrl('modules/clients/'); ?>" 
               class="inline-flex items-center px-4 py-2 text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Back to Clients
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

<!-- Add Client Form -->
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <div class="p-6">
        <form method="POST" class="space-y-6" novalidate>
            <!-- CSRF Token -->
            <input type="hidden" name="csrf_token" value="<?php echo Helper::generateCSRF(); ?>">
            
            <!-- Company Name -->
            <div>
                <label for="company_name" class="block text-sm font-medium text-gray-700 mb-2">
                    Company Name <span class="text-red-500">*</span>
                </label>
                <input 
                    type="text" 
                    id="company_name" 
                    name="company_name" 
                    value="<?php echo htmlspecialchars($formData['company_name']); ?>"
                    maxlength="200"
                    required
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent transition-all duration-200 <?php echo in_array('Company name is required.', $errors) || in_array('Company name must be less than 200 characters.', $errors) || in_array('A client with this company name already exists.', $errors) ? 'border-red-300' : ''; ?>"
                    placeholder="Enter company name"
                    autocomplete="organization"
                >
                <div class="mt-1 flex justify-between">
                    <p class="text-sm text-gray-500">The official name of the company or business</p>
                    <span id="company_name_count" class="text-sm text-gray-400">0/200</span>
                </div>
            </div>

            <!-- Contact Person -->
            <div>
                <label for="contact_person" class="block text-sm font-medium text-gray-700 mb-2">
                    Contact Person <span class="text-red-500">*</span>
                </label>
                <input 
                    type="text" 
                    id="contact_person" 
                    name="contact_person" 
                    value="<?php echo htmlspecialchars($formData['contact_person']); ?>"
                    maxlength="100"
                    required
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent transition-all duration-200 <?php echo in_array('Contact person is required.', $errors) || in_array('Contact person name must be less than 100 characters.', $errors) ? 'border-red-300' : ''; ?>"
                    placeholder="Enter contact person name"
                    autocomplete="name"
                >
                <div class="mt-1 flex justify-between">
                    <p class="text-sm text-gray-500">Primary contact person at the company</p>
                    <span id="contact_person_count" class="text-sm text-gray-400">0/100</span>
                </div>
            </div>

            <!-- Mobile Number -->
            <div>
                <label for="mobile_number" class="block text-sm font-medium text-gray-700 mb-2">
                    Mobile Number <span class="text-red-500">*</span>
                </label>
                <input 
                    type="tel" 
                    id="mobile_number" 
                    name="mobile_number" 
                    value="<?php echo htmlspecialchars($formData['mobile_number']); ?>"
                    maxlength="20"
                    required
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent transition-all duration-200 <?php echo in_array('Mobile number is required.', $errors) || in_array('Please enter a valid mobile number.', $errors) ? 'border-red-300' : ''; ?>"
                    placeholder="Enter mobile number (e.g., +94 71 234 5678)"
                    autocomplete="tel"
                >
                <p class="mt-1 text-sm text-gray-500">Include country code if international (e.g., +94 for Sri Lanka)</p>
            </div>

            <!-- Address -->
            <div>
                <label for="address" class="block text-sm font-medium text-gray-700 mb-2">
                    Address <span class="text-red-500">*</span>
                </label>
                <textarea 
                    id="address" 
                    name="address" 
                    rows="3"
                    required
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent transition-all duration-200 resize-none <?php echo in_array('Address is required.', $errors) ? 'border-red-300' : ''; ?>"
                    placeholder="Enter complete address"
                    autocomplete="street-address"
                ><?php echo htmlspecialchars($formData['address']); ?></textarea>
                <p class="mt-1 text-sm text-gray-500">Complete business address including street, area, etc.</p>
            </div>

            <!-- City -->
            <div>
                <label for="city" class="block text-sm font-medium text-gray-700 mb-2">
                    City <span class="text-red-500">*</span>
                </label>
                <input 
                    type="text" 
                    id="city" 
                    name="city" 
                    value="<?php echo htmlspecialchars($formData['city']); ?>"
                    maxlength="100"
                    required
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent transition-all duration-200 <?php echo in_array('City is required.', $errors) || in_array('City name must be less than 100 characters.', $errors) ? 'border-red-300' : ''; ?>"
                    placeholder="Enter city name"
                    autocomplete="address-level2"
                >
                <div class="mt-1 flex justify-between">
                    <p class="text-sm text-gray-500">City where the business is located</p>
                    <span id="city_count" class="text-sm text-gray-400">0/100</span>
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
                    <span id="submitText">Add Client</span>
                    <span id="submitLoading" class="hidden">
                        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline" fill="none" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"></circle>
                            <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" class="opacity-75"></path>
                        </svg>
                        Adding Client...
                    </span>
                </button>
                
                <a href="<?php echo Helper::baseUrl('modules/clients/'); ?>" 
                   class="flex-1 sm:flex-none inline-flex items-center justify-center px-6 py-3 bg-white text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition-all duration-200 font-medium">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Mobile-specific Tips -->
<div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4 lg:hidden">
    <div class="flex items-start">
        <svg class="w-5 h-5 text-blue-600 mr-2 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <div>
            <h4 class="text-blue-800 font-medium mb-1">Mobile Tips</h4>
            <ul class="text-blue-700 text-sm space-y-1">
                <li>• All fields marked with * are required</li>
                <li>• Double-tap inputs to access your keyboard suggestions</li>
                <li>• Use your phone's autocomplete for faster typing</li>
            </ul>
        </div>
    </div>
</div>

<style>
/* Enhanced mobile form styles */
@media (max-width: 640px) {
    .flex.flex-col.sm\:flex-row {
        flex-direction: column;
    }
    
    input, textarea, select {
        font-size: 16px; /* Prevent zoom on iOS */
    }
}

/* Focus states for better accessibility */
input:focus, textarea:focus {
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
setupCharacterCounter('company_name', 'company_name_count', 200);
setupCharacterCounter('contact_person', 'contact_person_count', 100);
setupCharacterCounter('city', 'city_count', 100);

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

// Mobile number formatting (basic)
document.getElementById('mobile_number').addEventListener('input', function(e) {
    let value = e.target.value.replace(/[^\d+\-\s()]/g, '');
    e.target.value = value;
});

// Auto-focus first field on desktop
if (window.innerWidth > 768) {
    document.getElementById('company_name').focus();
}

// Form validation feedback
document.querySelectorAll('input[required], textarea[required]').forEach(field => {
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
document.querySelectorAll('input, textarea').forEach(field => {
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
        window.location.href = '<?php echo Helper::baseUrl('modules/clients/'); ?>';
    }
});


</script>

<?php include '../../includes/footer.php'; ?>