<?php
// modules/settings/company-settings.php
session_start();

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

$pageTitle = 'Company Settings - Invoice Manager';

// Initialize variables
$errors = [];
$successMessage = '';
$settings = null;

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!Helper::verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        // Get and sanitize form data
        $formData = [
            'company_name' => Helper::sanitize($_POST['company_name'] ?? ''),
            'email' => Helper::sanitize($_POST['email'] ?? ''),
            'mobile_number_1' => Helper::sanitize($_POST['mobile_number_1'] ?? ''),
            'mobile_number_2' => Helper::sanitize($_POST['mobile_number_2'] ?? ''),
            'address' => Helper::sanitize($_POST['address'] ?? ''),
            'city' => Helper::sanitize($_POST['city'] ?? ''),
            'postal_code' => Helper::sanitize($_POST['postal_code'] ?? ''),
            'bank_name' => Helper::sanitize($_POST['bank_name'] ?? ''),
            'bank_branch' => Helper::sanitize($_POST['bank_branch'] ?? ''),
            'bank_account_number' => Helper::sanitize($_POST['bank_account_number'] ?? ''),
            'bank_account_name' => Helper::sanitize($_POST['bank_account_name'] ?? ''),
            'tax_number' => Helper::sanitize($_POST['tax_number'] ?? ''),
            'website' => Helper::sanitize($_POST['website'] ?? '')
        ];
        
        // Validate required fields
        if (empty($formData['company_name'])) {
            $errors[] = 'Company name is required.';
        } elseif (strlen($formData['company_name']) > 200) {
            $errors[] = 'Company name must be less than 200 characters.';
        }
        
        // Validate email if provided
        if (!empty($formData['email']) && !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        
        // Validate mobile numbers if provided
        if (!empty($formData['mobile_number_1']) && !preg_match('/^[0-9+\-\s()]{7,20}$/', $formData['mobile_number_1'])) {
            $errors[] = 'Please enter a valid primary mobile number.';
        }
        
        if (!empty($formData['mobile_number_2']) && !preg_match('/^[0-9+\-\s()]{7,20}$/', $formData['mobile_number_2'])) {
            $errors[] = 'Please enter a valid secondary mobile number.';
        }
        
        // Validate website URL if provided
        if (!empty($formData['website'])) {
            $url = $formData['website'];
            if (!filter_var($url, FILTER_VALIDATE_URL) && !filter_var('http://' . $url, FILTER_VALIDATE_URL)) {
                $errors[] = 'Please enter a valid website URL.';
            }
        }
        
        // Handle logo upload
        $logoPath = null;
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../../assets/uploads/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $fileExtension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($fileExtension, $allowedExtensions)) {
                // Check file size (2MB limit)
                if ($_FILES['logo']['size'] <= 2 * 1024 * 1024) {
                    $fileName = 'logo.' . $fileExtension;
                    $uploadPath = $uploadDir . $fileName;
                    
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadPath)) {
                        $logoPath = 'assets/uploads/' . $fileName;
                    } else {
                        $errors[] = 'Failed to upload logo file.';
                    }
                } else {
                    $errors[] = 'Logo file size must be less than 2MB.';
                }
            } else {
                $errors[] = 'Logo must be a JPG, JPEG, PNG, or GIF file.';
            }
        }
        
        // If no validation errors, save to database
        if (empty($errors)) {
            try {
                // Check if settings already exist
                $checkQuery = "SELECT id FROM company_settings LIMIT 1";
                $checkStmt = $db->prepare($checkQuery);
                $checkStmt->execute();
                $existingSettings = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existingSettings) {
                    // Update existing settings
                    $updateFields = [
                        'company_name = :company_name',
                        'email = :email',
                        'mobile_number_1 = :mobile_number_1',
                        'mobile_number_2 = :mobile_number_2',
                        'address = :address',
                        'city = :city',
                        'postal_code = :postal_code',
                        'bank_name = :bank_name',
                        'bank_branch = :bank_branch',
                        'bank_account_number = :bank_account_number',
                        'bank_account_name = :bank_account_name',
                        'tax_number = :tax_number',
                        'website = :website'
                    ];
                    
                    if ($logoPath) {
                        $updateFields[] = 'logo_path = :logo_path';
                    }
                    
                    $updateQuery = "UPDATE company_settings SET " . implode(', ', $updateFields) . ", updated_at = CURRENT_TIMESTAMP WHERE id = :id";
                    $updateStmt = $db->prepare($updateQuery);
                    
                    // Bind parameters
                    foreach ($formData as $key => $value) {
                        $updateStmt->bindValue(':' . $key, $value);
                    }
                    if ($logoPath) {
                        $updateStmt->bindValue(':logo_path', $logoPath);
                    }
                    $updateStmt->bindValue(':id', $existingSettings['id']);
                    
                    if ($updateStmt->execute()) {
                        Helper::setMessage('Company settings updated successfully!', 'success');
                        Helper::redirect('modules/settings/');
                    } else {
                        $errors[] = 'Error updating settings. Please try again.';
                    }
                } else {
                    // Insert new settings
                    $insertFields = array_keys($formData);
                    if ($logoPath) {
                        $insertFields[] = 'logo_path';
                        $formData['logo_path'] = $logoPath;
                    }
                    
                    $insertQuery = "INSERT INTO company_settings (" . implode(', ', $insertFields) . ") VALUES (:" . implode(', :', $insertFields) . ")";
                    $insertStmt = $db->prepare($insertQuery);
                    
                    // Bind parameters
                    foreach ($formData as $key => $value) {
                        $insertStmt->bindValue(':' . $key, $value);
                    }
                    
                    if ($insertStmt->execute()) {
                        Helper::setMessage('Company settings created successfully!', 'success');
                        Helper::redirect('modules/settings/');
                    } else {
                        $errors[] = 'Error creating settings. Please try again.';
                    }
                }
                
            } catch (Exception $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Get current settings
try {
    $settingsQuery = "SELECT * FROM company_settings ORDER BY id DESC LIMIT 1";
    $settingsStmt = $db->prepare($settingsQuery);
    $settingsStmt->execute();
    $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errors[] = 'Error loading current settings.';
}

// Include header
include '../../includes/header.php';
?>

<div class="max-w-6xl mx-auto">
    <!-- Page Header -->
    <div class="mb-8">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
            <div>
                <nav class="flex mb-4" aria-label="Breadcrumb">
                    <ol class="inline-flex items-center space-x-1 md:space-x-3">
                        <li class="inline-flex items-center">
                            <a href="<?php echo Helper::baseUrl('modules/settings/'); ?>" class="text-gray-700 hover:text-blue-600 inline-flex items-center">
                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                                Settings
                            </a>
                        </li>
                        <li>
                            <div class="flex items-center">
                                <svg class="w-6 h-6 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path>
                                </svg>
                                <span class="ml-1 md:ml-2 text-gray-500">Company Settings</span>
                            </div>
                        </li>
                    </ol>
                </nav>
                <h1 class="text-3xl font-bold text-gray-900">Company Settings</h1>
                <p class="mt-2 text-gray-600">Manage your company information for invoices and documents</p>
            </div>
            <div class="mt-4 sm:mt-0">
                <div class="inline-flex items-center px-3 py-2 bg-blue-50 text-blue-700 rounded-lg text-sm">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    Used in invoice headers
                </div>
            </div>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if (!empty($errors)): ?>
        <div class="mb-6 p-4 bg-red-50 text-red-800 border border-red-200 rounded-lg">
            <ul class="list-disc list-inside">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php 
    $flash = Helper::getFlashMessage();
    if ($flash): 
    ?>
        <div class="mb-6 p-4 rounded-lg <?php echo $flash['type'] === 'success' ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200'; ?>">
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
    <?php endif; ?>

    <!-- Settings Form -->
    <form method="POST" enctype="multipart/form-data" class="space-y-8">
        <input type="hidden" name="csrf_token" value="<?php echo Helper::generateCSRF(); ?>">
        
        <!-- Company Information -->
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <div class="mb-6">
                <h2 class="text-xl font-semibold text-gray-900">Company Information</h2>
                <p class="text-gray-600">Basic company details that appear on invoices</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Company Name -->
                <div class="md:col-span-2">
                    <label for="company_name" class="block text-sm font-medium text-gray-700 mb-2">
                        Company Name <span class="text-red-500">*</span>
                    </label>
                    <input type="text" 
                           id="company_name" 
                           name="company_name" 
                           required
                           maxlength="200"
                           value="<?php echo htmlspecialchars($settings['company_name'] ?? ''); ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="Your Company Name">
                </div>

                <!-- Email -->
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                        Email Address
                    </label>
                    <input type="email" 
                           id="email" 
                           name="email" 
                           maxlength="100"
                           value="<?php echo htmlspecialchars($settings['email'] ?? ''); ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="info@yourcompany.com">
                </div>

                <!-- Website -->
                <div>
                    <label for="website" class="block text-sm font-medium text-gray-700 mb-2">
                        Website
                    </label>
                    <input type="url" 
                           id="website" 
                           name="website" 
                           maxlength="100"
                           value="<?php echo htmlspecialchars($settings['website'] ?? ''); ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="www.yourcompany.com">
                </div>

                <!-- Primary Mobile -->
                <div>
                    <label for="mobile_number_1" class="block text-sm font-medium text-gray-700 mb-2">
                        Primary Mobile Number
                    </label>
                    <input type="tel" 
                           id="mobile_number_1" 
                           name="mobile_number_1" 
                           maxlength="20"
                           value="<?php echo htmlspecialchars($settings['mobile_number_1'] ?? ''); ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="+94 77 123 4567">
                </div>

                <!-- Secondary Mobile -->
                <div>
                    <label for="mobile_number_2" class="block text-sm font-medium text-gray-700 mb-2">
                        Secondary Mobile Number
                    </label>
                    <input type="tel" 
                           id="mobile_number_2" 
                           name="mobile_number_2" 
                           maxlength="20"
                           value="<?php echo htmlspecialchars($settings['mobile_number_2'] ?? ''); ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="+94 11 234 5678">
                </div>

                <!-- Address -->
                <div class="md:col-span-2">
                    <label for="address" class="block text-sm font-medium text-gray-700 mb-2">
                        Address
                    </label>
                    <textarea id="address" 
                              name="address" 
                              rows="3"
                              class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                              placeholder="123 Business Street, Business District"><?php echo htmlspecialchars($settings['address'] ?? ''); ?></textarea>
                </div>

                <!-- City -->
                <div>
                    <label for="city" class="block text-sm font-medium text-gray-700 mb-2">
                        City
                    </label>
                    <input type="text" 
                           id="city" 
                           name="city" 
                           maxlength="100"
                           value="<?php echo htmlspecialchars($settings['city'] ?? ''); ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="Colombo">
                </div>

                <!-- Postal Code -->
                <div>
                    <label for="postal_code" class="block text-sm font-medium text-gray-700 mb-2">
                        Postal Code
                    </label>
                    <input type="text" 
                           id="postal_code" 
                           name="postal_code" 
                           maxlength="20"
                           value="<?php echo htmlspecialchars($settings['postal_code'] ?? ''); ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="00100">
                </div>

                <!-- Tax Number -->
                <div>
                    <label for="tax_number" class="block text-sm font-medium text-gray-700 mb-2">
                        Tax Number / TIN
                    </label>
                    <input type="text" 
                           id="tax_number" 
                           name="tax_number" 
                           maxlength="50"
                           value="<?php echo htmlspecialchars($settings['tax_number'] ?? ''); ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="TIN123456789">
                </div>
            </div>
        </div>

        <!-- Banking Information -->
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <div class="mb-6">
                <h2 class="text-xl font-semibold text-gray-900">Banking Information</h2>
                <p class="text-gray-600">Bank details for payment instructions on invoices</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Bank Name -->
                <div>
                    <label for="bank_name" class="block text-sm font-medium text-gray-700 mb-2">
                        Bank Name
                    </label>
                    <input type="text" 
                           id="bank_name" 
                           name="bank_name" 
                           maxlength="100"
                           value="<?php echo htmlspecialchars($settings['bank_name'] ?? ''); ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="Commercial Bank of Ceylon">
                </div>

                <!-- Bank Branch -->
                <div>
                    <label for="bank_branch" class="block text-sm font-medium text-gray-700 mb-2">
                        Bank Branch
                    </label>
                    <input type="text" 
                           id="bank_branch" 
                           name="bank_branch" 
                           maxlength="100"
                           value="<?php echo htmlspecialchars($settings['bank_branch'] ?? ''); ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="Colombo 03">
                </div>

                <!-- Account Number -->
                <div>
                    <label for="bank_account_number" class="block text-sm font-medium text-gray-700 mb-2">
                        Account Number
                    </label>
                    <input type="text" 
                           id="bank_account_number" 
                           name="bank_account_number" 
                           maxlength="50"
                           value="<?php echo htmlspecialchars($settings['bank_account_number'] ?? ''); ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="1234567890">
                </div>

                <!-- Account Name -->
                <div>
                    <label for="bank_account_name" class="block text-sm font-medium text-gray-700 mb-2">
                        Account Name
                    </label>
                    <input type="text" 
                           id="bank_account_name" 
                           name="bank_account_name" 
                           maxlength="100"
                           value="<?php echo htmlspecialchars($settings['bank_account_name'] ?? ''); ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="Your Company Name">
                </div>
            </div>
        </div>

        <!-- Logo Upload -->
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <div class="mb-6">
                <h2 class="text-xl font-semibold text-gray-900">Company Logo</h2>
                <p class="text-gray-600">Upload your company logo for invoices (JPG, PNG, GIF - max 2MB)</p>
            </div>

            <div class="flex items-start space-x-6">
                <!-- Current Logo -->
                <?php if (!empty($settings['logo_path']) && file_exists('../../' . $settings['logo_path'])): ?>
                    <div class="flex-shrink-0">
                        <div class="w-32 h-32 bg-gray-100 rounded-lg overflow-hidden border">
                            <img src="<?php echo Helper::baseUrl($settings['logo_path']); ?>" 
                                 alt="Company Logo" 
                                 class="w-full h-full object-contain">
                        </div>
                        <p class="text-sm text-gray-500 mt-2 text-center">Current Logo</p>
                    </div>
                <?php else: ?>
                    <div class="flex-shrink-0">
                        <div class="w-32 h-32 bg-gray-100 rounded-lg border flex items-center justify-center">
                            <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                        </div>
                        <p class="text-sm text-gray-500 mt-2 text-center">No Logo</p>
                    </div>
                <?php endif; ?>

                <!-- File Upload -->
                <div class="flex-1">
                    <label for="logo" class="block text-sm font-medium text-gray-700 mb-2">
                        Upload New Logo
                    </label>
                    <input type="file" 
                           id="logo" 
                           name="logo" 
                           accept="image/*"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-sm text-gray-500 mt-2">
                        Recommended: 300x150px or similar aspect ratio. Maximum file size: 2MB.
                    </p>
                </div>
            </div>
        </div>

        <!-- Form Actions -->
        <div class="flex items-center justify-between pt-6 border-t border-gray-200">
            <div class="text-sm text-gray-600">
                <span class="text-red-500">*</span> Required fields
            </div>
            <div class="flex flex-col sm:flex-row gap-3">
                <button type="button" 
                        onclick="window.history.back()" 
                        class="inline-flex items-center justify-center px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                    Cancel
                </button>
                <button type="submit" 
                        class="inline-flex items-center justify-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    Save Settings
                </button>
            </div>
        </div>
    </form>
</div>

<!-- JavaScript for form enhancements -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-format phone numbers
    const phoneInputs = document.querySelectorAll('input[type="tel"]');
    phoneInputs.forEach(input => {
        input.addEventListener('blur', function() {
            let value = this.value.replace(/\D/g, '');
            if (value.startsWith('94')) {
                this.value = '+94 ' + value.slice(2, 4) + ' ' + value.slice(4, 7) + ' ' + value.slice(7);
            } else if (value.startsWith('0')) {
                this.value = '+94 ' + value.slice(1, 3) + ' ' + value.slice(3, 6) + ' ' + value.slice(6);
            }
        });
    });

    // Website URL formatting
    const websiteInput = document.getElementById('website');
    if (websiteInput) {
        websiteInput.addEventListener('blur', function() {
            let value = this.value.trim();
            if (value && !value.startsWith('http://') && !value.startsWith('https://')) {
                if (!value.startsWith('www.')) {
                    value = 'www.' + value;
                }
                this.value = value;
            }
        });
    }

    // File upload preview and validation
    const logoInput = document.getElementById('logo');
    if (logoInput) {
        logoInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // Check file size (2MB limit)
                if (file.size > 2 * 1024 * 1024) {
                    alert('File size must be less than 2MB');
                    this.value = '';
                    return;
                }
                
                // Check file type
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Please select a valid image file (JPG, PNG, or GIF)');
                    this.value = '';
                    return;
                }
                
                // Show preview if current logo exists
                const reader = new FileReader();
                reader.onload = function(e) {
                    const currentImg = document.querySelector('img[alt="Company Logo"]');
                    if (currentImg) {
                        currentImg.src = e.target.result;
                    } else {
                        // Create new image preview if no current logo
                        const logoContainer = document.querySelector('.flex-shrink-0');
                        const placeholder = logoContainer.querySelector('.bg-gray-100');
                        
                        if (placeholder && placeholder.querySelector('svg')) {
                            placeholder.innerHTML = `<img src="${e.target.result}" alt="Company Logo" class="w-full h-full object-contain">`;
                        }
                    }
                };
                reader.readAsDataURL(file);
            }
        });
    }

    // Form submission loading state
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = `
                    <svg class="animate-spin w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"></circle>
                        <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" class="opacity-75"></path>
                    </svg>
                    Saving...
                `;
                
                // Reset button after 10 seconds as fallback
                setTimeout(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = `
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        Save Settings
                    `;
                }, 10000);
            }
        });
    }
});
</script>

<?php include '../../includes/footer.php'; ?>