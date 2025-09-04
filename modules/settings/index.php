<?php
// modules/settings/index.php
session_start();

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

$pageTitle = 'Settings - Invoice Manager';

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Check user role for certain sections
$isAdmin = Helper::hasRole('admin');
$currentUserId = $_SESSION['user_id'];

// Get current settings for the sidebar info
try {
    $settingsQuery = "SELECT company_name FROM company_settings ORDER BY id DESC LIMIT 1";
    $settingsStmt = $db->prepare($settingsQuery);
    $settingsStmt->execute();
    $companySettings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $companySettings = null;
}

// Include header
include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="mb-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Settings</h1>
            <p class="text-gray-600 mt-1">Manage your application settings and configurations</p>
        </div>
        <div class="flex flex-col sm:flex-row gap-3">
            <div class="inline-flex items-center px-3 py-2 bg-blue-50 text-blue-700 rounded-lg text-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                System Configuration
            </div>
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

<!-- Settings Grid -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Left Column - Settings Menu -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-xl border border-gray-200 p-4 sm:p-6 sticky top-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">Settings Menu</h2>
                
                <nav class="space-y-2" id="settingsNav">
                    <!-- Company Settings -->
                    <a href="#company-settings" 
                       class="settings-nav-item flex items-center space-x-3 px-3 py-2.5 rounded-lg text-gray-700 hover:text-gray-900 hover:bg-gray-50 transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                        </svg>
                        <div>
                            <div class="font-medium">Company Settings</div>
                            <div class="text-xs text-gray-500">Logo, contact info, banking</div>
                        </div>
                    </a>

                    <!-- User Management (Admin Only) -->
                    <?php if ($isAdmin): ?>
                    <a href="#user-management" 
                       class="settings-nav-item flex items-center space-x-3 px-3 py-2.5 rounded-lg text-gray-700 hover:text-gray-900 hover:bg-gray-50 transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                        </svg>
                        <div>
                            <div class="font-medium">User Management</div>
                            <div class="text-xs text-gray-500">Add, edit, manage users</div>
                        </div>
                    </a>
                    <?php endif; ?>

                    <!-- Profile Settings -->
                    <a href="#profile-settings" 
                       class="settings-nav-item flex items-center space-x-3 px-3 py-2.5 rounded-lg text-gray-700 hover:text-gray-900 hover:bg-gray-50 transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                        <div>
                            <div class="font-medium">My Profile</div>
                            <div class="text-xs text-gray-500">Personal information</div>
                        </div>
                    </a>

                    <!-- System Information -->
                    <a href="#system-info" 
                       class="settings-nav-item flex items-center space-x-3 px-3 py-2.5 rounded-lg text-gray-700 hover:text-gray-900 hover:bg-gray-50 transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <div>
                            <div class="font-medium">System Info</div>
                            <div class="text-xs text-gray-500">Version, status</div>
                        </div>
                    </a>
                </nav>

                <!-- Quick Info -->
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Company:</span>
                            <span class="font-medium text-gray-900">
                                <?php echo htmlspecialchars($companySettings['company_name'] ?? 'Not Set'); ?>
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Role:</span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo $isAdmin ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800'; ?>">
                                <?php echo ucfirst($_SESSION['user_role']); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column - Settings Content -->
        <div class="lg:col-span-2">
            <div id="settings-content">
                
                <!-- Company Settings Section -->
                <div id="company-settings" class="settings-section">
                    <div class="bg-white rounded-xl border border-gray-200 p-4 sm:p-6">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h2 class="text-2xl font-bold text-gray-900">Company Settings</h2>
                                <p class="text-gray-600 mt-1">Manage your company information for invoices and documents</p>
                            </div>
                            <a href="company-settings.php" 
                               class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                </svg>
                                Manage Company
                            </a>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-4">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                                        <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-gray-900">Company Information</h3>
                                        <p class="text-sm text-gray-600">Logo, name, contact details</p>
                                    </div>
                                </div>

                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                                        <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-gray-900">Banking Details</h3>
                                        <p class="text-sm text-gray-600">Payment instructions for invoices</p>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-4">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                                        <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 4V2a1 1 0 011-1h8a1 1 0 011 1v2m3 0a2 2 0 012 2v11a2 2 0 01-2 2H5a2 2 0 01-2-2V6a2 2 0 012-2h3zM9 12h6m-6 4h6"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-gray-900">Tax Information</h3>
                                        <p class="text-sm text-gray-600">TIN and registration details</p>
                                    </div>
                                </div>

                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 bg-yellow-100 rounded-lg flex items-center justify-center">
                                        <svg class="w-5 h-5 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-gray-900">Logo & Branding</h3>
                                        <p class="text-sm text-gray-600">Upload company logo</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- User Management Section (Admin Only) -->
                <?php if ($isAdmin): ?>
                <div id="user-management" class="settings-section hidden">
                    <div class="bg-white rounded-xl border border-gray-200 p-4 sm:p-6">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h2 class="text-2xl font-bold text-gray-900">User Management</h2>
                                <p class="text-gray-600 mt-1">Manage system users and their permissions</p>
                            </div>
                            <button onclick="openUserModal()" 
                                    class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                </svg>
                                Add User
                            </button>
                            
                            <!-- Debug button -->
                            <button onclick="debugUserManagement()" 
                                    class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                                </svg>
                                Debug Test
                            </button>
                        </div>

                        <!-- Users Table -->
                        <div class="overflow-hidden rounded-lg border border-gray-200">
                            <table class="min-w-full divide-y divide-gray-200" id="usersTable">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200" id="usersTableBody">
                                    <!-- Will be populated by JavaScript -->
                                </tbody>
                            </table>
                        </div>

                        <!-- Loading state -->
                        <div id="usersLoading" class="text-center py-8">
                            <div class="inline-flex items-center">
                                <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-gray-600" fill="none" viewBox="0 0 24 24">
                                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"></circle>
                                    <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" class="opacity-75"></path>
                                </svg>
                                Loading users...
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Profile Settings Section -->
                <div id="profile-settings" class="settings-section hidden">
                    <div class="bg-white rounded-xl border border-gray-200 p-4 sm:p-6">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h2 class="text-2xl font-bold text-gray-900">My Profile</h2>
                                <p class="text-gray-600 mt-1">Update your personal information and password</p>
                            </div>
                        </div>

                        <form id="profileForm" class="space-y-6">
                            <input type="hidden" name="csrf_token" value="<?php echo Helper::generateCSRF(); ?>">
                            <input type="hidden" name="action" value="update_profile">
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Full Name</label>
                                    <input type="text" name="full_name" 
                                           value="<?php echo htmlspecialchars($_SESSION['user_name']); ?>"
                                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Username</label>
                                    <input type="text" name="username" 
                                           value="<?php echo htmlspecialchars($_SESSION['username']); ?>"
                                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                                </div>

                                <div class="md:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                                    <input type="email" name="email" 
                                           value="<?php echo htmlspecialchars($_SESSION['user_email']); ?>"
                                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                                </div>
                            </div>

                            <!-- Password Change Section -->
                            <div class="border-t border-gray-200 pt-6">
                                <h3 class="text-lg font-semibold text-gray-900 mb-4">Change Password</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Current Password</label>
                                        <input type="password" name="current_password" 
                                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">New Password</label>
                                        <input type="password" name="new_password" 
                                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Confirm Password</label>
                                        <input type="password" name="confirm_password" 
                                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                </div>
                                <p class="text-sm text-gray-500 mt-2">Leave password fields empty if you don't want to change your password</p>
                            </div>

                            <div class="flex justify-end">
                                <button type="submit" 
                                        class="inline-flex items-center px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                    Update Profile
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- System Information Section -->
                <div id="system-info" class="settings-section hidden">
                    <div class="bg-white rounded-xl border border-gray-200 p-4 sm:p-6">
                        <div class="mb-6">
                            <h2 class="text-2xl font-bold text-gray-900">System Information</h2>
                            <p class="text-gray-600 mt-1">Application status and version information</p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-4">
                                <div class="flex justify-between items-center py-3 border-b border-gray-200">
                                    <span class="font-medium text-gray-900">Application Version</span>
                                    <span class="text-gray-600">1.0.0</span>
                                </div>
                                <div class="flex justify-between items-center py-3 border-b border-gray-200">
                                    <span class="font-medium text-gray-900">PHP Version</span>
                                    <span class="text-gray-600"><?php echo phpversion(); ?></span>
                                </div>
                                <div class="flex justify-between items-center py-3 border-b border-gray-200">
                                    <span class="font-medium text-gray-900">Database Status</span>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                        </svg>
                                        Connected
                                    </span>
                                </div>
                                <div class="flex justify-between items-center py-3">
                                    <span class="font-medium text-gray-900">Last Login</span>
                                    <span class="text-gray-600"><?php echo date('M j, Y g:i A', $_SESSION['login_time']); ?></span>
                                </div>
                            </div>

                            <div class="space-y-4">
                                <div class="bg-gray-50 rounded-lg p-4">
                                    <h3 class="font-semibold text-gray-900 mb-2">Quick Stats</h3>
                                    <div class="space-y-2 text-sm">
                                        <div class="flex justify-between">
                                            <span class="text-gray-600">Active Users:</span>
                                            <span class="font-medium" id="activeUsersCount">-</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-gray-600">Total Clients:</span>
                                            <span class="font-medium" id="totalClientsCount">-</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-gray-600">Active Projects:</span>
                                            <span class="font-medium" id="activeProjectsCount">-</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="bg-blue-50 rounded-lg p-4">
                                    <h3 class="font-semibold text-blue-900 mb-2">Support</h3>
                                    <p class="text-sm text-blue-700 mb-3">Need help? Contact our support team.</p>
                                    <button class="text-sm bg-blue-600 text-white px-3 py-1 rounded hover:bg-blue-700 transition-colors">
                                        Contact Support
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<!-- User Management Modal (Admin Only) -->
<?php if ($isAdmin): ?>
<div id="userModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-1/2 lg:w-1/3 shadow-lg rounded-md bg-white">
        <div class="flex items-center justify-between mb-4">
            <h3 id="modalTitle" class="text-lg font-semibold text-gray-900">Add New User</h3>
            <button onclick="closeUserModal()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <form id="userForm" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo Helper::generateCSRF(); ?>">
            <input type="hidden" name="action" value="create_user">
            <input type="hidden" name="user_id" value="">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Full Name <span class="text-red-500">*</span></label>
                    <input type="text" name="full_name" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Username <span class="text-red-500">*</span></label>
                    <input type="text" name="username" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
                    <input type="email" name="email" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Role <span class="text-red-500">*</span></label>
                    <select name="role" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="employee">Employee</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="is_active"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>

                <div id="passwordSection" class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password <span class="text-red-500">*</span></label>
                    <input type="password" name="password" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-1">Minimum 6 characters</p>
                </div>
            </div>

            <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200">
                <button type="button" onclick="closeUserModal()" 
                        class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 transition-colors">
                    Cancel
                </button>
                <button type="submit" 
                        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    <span id="submitText">Create User</span>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Navigation functionality
    const navItems = document.querySelectorAll('.settings-nav-item');
    const sections = document.querySelectorAll('.settings-section');
    
    // Show first section by default
    if (sections.length > 0) {
        sections[0].classList.remove('hidden');
        navItems[0].classList.add('bg-blue-50', 'text-blue-700', 'border-blue-200');
    }
    
    navItems.forEach((item, index) => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Remove active class from all items
            navItems.forEach(nav => {
                nav.classList.remove('bg-blue-50', 'text-blue-700', 'border-blue-200');
            });
            
            // Hide all sections
            sections.forEach(section => {
                section.classList.add('hidden');
            });
            
            // Add active class to clicked item
            this.classList.add('bg-blue-50', 'text-blue-700', 'border-blue-200');
            
            // Show corresponding section
            const targetSection = document.querySelector(this.getAttribute('href'));
            if (targetSection) {
                targetSection.classList.remove('hidden');
                
                // Load users if user management section is selected
                if (this.getAttribute('href') === '#user-management') {
                    loadUsers();
                }
                
                // Load system stats if system info section is selected
                if (this.getAttribute('href') === '#system-info') {
                    loadSystemStats();
                }
            }
        });
    });
    
    // Profile form submission
    const profileForm = document.getElementById('profileForm');
    if (profileForm) {
        profileForm.addEventListener('submit', function(e) {
            e.preventDefault();
            updateProfile();
        });
    }
    
    <?php if ($isAdmin): ?>
    // User form submission
    const userForm = document.getElementById('userForm');
    if (userForm) {
        console.log('User form found, attaching event listener');
        userForm.addEventListener('submit', function(e) {
            e.preventDefault();
            console.log('User form submitted, calling submitUser()');
            submitUser();
        });
    } else {
        console.log('User form not found');
    }
    <?php endif; ?>
});

// User Management Functions (Admin Only)
<?php if ($isAdmin): ?>
function loadUsers() {
    const tableBody = document.getElementById('usersTableBody');
    const loading = document.getElementById('usersLoading');
    
    loading.classList.remove('hidden');
    tableBody.innerHTML = '';
    
    fetch('ajax/manage_users.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=get_users&csrf_token=<?php echo Helper::generateCSRF(); ?>`
    })
    .then(response => response.json())
    .then(data => {
        loading.classList.add('hidden');
        
        if (data.success) {
            displayUsers(data.users);
        } else {
            showMessage(data.message, 'error');
        }
    })
    .catch(error => {
        loading.classList.add('hidden');
        showMessage('Error loading users', 'error');
        console.error('Error:', error);
    });
}

function displayUsers(users) {
    const tableBody = document.getElementById('usersTableBody');
    
    if (users.length === 0) {
        tableBody.innerHTML = `
            <tr>
                <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                    No users found
                </td>
            </tr>
        `;
        return;
    }
    
    tableBody.innerHTML = users.map(user => `
        <tr class="hover:bg-gray-50">
            <td class="px-6 py-4 whitespace-nowrap">
                <div class="flex items-center">
                    <div class="flex-shrink-0 h-10 w-10">
                        <div class="h-10 w-10 rounded-full bg-gray-200 flex items-center justify-center">
                            <span class="text-sm font-medium text-gray-700">
                                ${user.full_name.charAt(0).toUpperCase()}
                            </span>
                        </div>
                    </div>
                    <div class="ml-4">
                        <div class="text-sm font-medium text-gray-900">${escapeHtml(user.full_name)}</div>
                        <div class="text-sm text-gray-500">${escapeHtml(user.username)} • ${escapeHtml(user.email)}</div>
                    </div>
                </div>
            </td>
            <td class="px-6 py-4 whitespace-nowrap">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                    user.role === 'admin' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800'
                }">
                    ${user.role.charAt(0).toUpperCase() + user.role.slice(1)}
                </span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                    user.is_active == 1 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                }">
                    ${user.is_active == 1 ? 'Active' : 'Inactive'}
                </span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                ${formatDate(user.created_at)}
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                <div class="flex space-x-2">
                    <button onclick="editUser(${user.id})" 
                            class="text-blue-600 hover:text-blue-900">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                        </svg>
                    </button>
                    ${user.id != <?php echo $currentUserId; ?> ? `
                        <button onclick="deleteUser(${user.id}, '${escapeHtml(user.full_name)}')" 
                                class="text-red-600 hover:text-red-900">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                            </svg>
                        </button>
                    ` : ''}
                </div>
            </td>
        </tr>
    `).join('');
}

function openUserModal(userId = null) {
    console.log('openUserModal called with userId:', userId);
    
    const modal = document.getElementById('userModal');
    const form = document.getElementById('userForm');
    const modalTitle = document.getElementById('modalTitle');
    const submitText = document.getElementById('submitText');
    const passwordSection = document.getElementById('passwordSection');
    
    console.log('Modal elements found:', {
        modal: !!modal,
        form: !!form,
        modalTitle: !!modalTitle,
        submitText: !!submitText,
        passwordSection: !!passwordSection
    });
    
    if (!modal || !form) {
        console.error('Required modal elements not found');
        alert('Error: Modal elements not found. Please check if you have admin permissions.');
        return;
    }
    
    // Reset form
    form.reset();
    form.elements.action.value = userId ? 'update_user' : 'create_user';
    form.elements.user_id.value = userId || '';
    
    if (userId) {
        modalTitle.textContent = 'Edit User';
        submitText.textContent = 'Update User';
        passwordSection.style.display = 'none';
        form.elements.password.required = false;
        
        // Load user data
        loadUserData(userId);
    } else {
        modalTitle.textContent = 'Add New User';
        submitText.textContent = 'Create User';
        passwordSection.style.display = 'block';
        form.elements.password.required = true;
    }
    
    modal.classList.remove('hidden');
    console.log('Modal should now be visible');
}

function closeUserModal() {
    const modal = document.getElementById('userModal');
    modal.classList.add('hidden');
}

function loadUserData(userId) {
    fetch('ajax/manage_users.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=get_user&user_id=${userId}&csrf_token=<?php echo Helper::generateCSRF(); ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const form = document.getElementById('userForm');
            form.elements.full_name.value = data.user.full_name;
            form.elements.username.value = data.user.username;
            form.elements.email.value = data.user.email;
            form.elements.role.value = data.user.role;
            form.elements.is_active.value = data.user.is_active;
        } else {
            showMessage(data.message, 'error');
        }
    })
    .catch(error => {
        showMessage('Error loading user data', 'error');
        console.error('Error:', error);
    });
}

function submitUser() {
    console.log('submitUser function called');
    
    const form = document.getElementById('userForm');
    if (!form) {
        console.error('User form not found');
        alert('Error: User form not found');
        return;
    }
    
    console.log('Form found, creating FormData');
    const formData = new FormData(form);
    
    // Log form data for debugging
    console.log('Form data contents:');
    for (let [key, value] of formData.entries()) {
        console.log(key + ': ' + value);
    }
    
    const submitButton = form.querySelector('button[type="submit"]');
    const submitText = document.getElementById('submitText');
    
    if (!submitButton || !submitText) {
        console.error('Submit button or text not found');
        alert('Error: Submit button elements not found');
        return;
    }
    
    submitButton.disabled = true;
    submitText.textContent = 'Processing...';
    
    console.log('Making fetch request to ajax/manage_users.php');
    
    fetch('ajax/manage_users.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('Response received:', response);
        console.log('Response status:', response.status);
        console.log('Response headers:', response.headers);
        return response.text(); // Get as text first to see raw response
    })
    .then(text => {
        console.log('Response text:', text);
        submitButton.disabled = false;
        submitText.textContent = form.elements.action.value === 'create_user' ? 'Create User' : 'Update User';
        
        try {
            const data = JSON.parse(text);
            console.log('Parsed JSON:', data);
            
            if (data.success) {
                closeUserModal();
                loadUsers();
                showMessage(data.message, 'success');
            } else {
                showMessage(data.message, 'error');
            }
        } catch (e) {
            console.error('JSON parse error:', e);
            console.error('Raw response:', text);
            showMessage('Server response error. Check console for details.', 'error');
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        submitButton.disabled = false;
        submitText.textContent = form.elements.action.value === 'create_user' ? 'Create User' : 'Update User';
        showMessage('Network error: ' + error.message, 'error');
    });
}

function editUser(userId) {
    openUserModal(userId);
}

function deleteUser(userId, userName) {
    if (confirm(`Are you sure you want to delete user "${userName}"? This action cannot be undone.`)) {
        fetch('ajax/manage_users.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=delete_user&user_id=${userId}&csrf_token=<?php echo Helper::generateCSRF(); ?>`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadUsers();
                showMessage(data.message, 'success');
            } else {
                showMessage(data.message, 'error');
            }
        })
        .catch(error => {
            showMessage('Error deleting user', 'error');
            console.error('Error:', error);
        });
    }
}
<?php endif; ?>

// Profile update function
function updateProfile() {
    const form = document.getElementById('profileForm');
    const formData = new FormData(form);
    const submitButton = form.querySelector('button[type="submit"]');
    
    submitButton.disabled = true;
    submitButton.innerHTML = 'Updating...';
    
    fetch('ajax/manage_profile.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        submitButton.disabled = false;
        submitButton.innerHTML = `
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            Update Profile
        `;
        
        if (data.success) {
            showMessage(data.message, 'success');
            // Update session data if changed
            if (data.updated_data) {
                location.reload(); // Reload to update header info
            }
        } else {
            showMessage(data.message, 'error');
        }
    })
    .catch(error => {
        submitButton.disabled = false;
        submitButton.innerHTML = `
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            Update Profile
        `;
        showMessage('Error updating profile', 'error');
        console.error('Error:', error);
    });
}

// Load system stats
function loadSystemStats() {
    fetch('ajax/system_stats.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=get_stats&csrf_token=<?php echo Helper::generateCSRF(); ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('activeUsersCount').textContent = data.stats.active_users || '0';
            document.getElementById('totalClientsCount').textContent = data.stats.total_clients || '0';
            document.getElementById('activeProjectsCount').textContent = data.stats.active_projects || '0';
        }
    })
    .catch(error => {
        console.error('Error loading stats:', error);
    });
}

// Utility functions
function showMessage(message, type) {
    // Create or update flash message
    const existingMessage = document.querySelector('.flash-message');
    if (existingMessage) {
        existingMessage.remove();
    }
    
    const messageDiv = document.createElement('div');
    messageDiv.className = `flash-message mb-6 p-4 rounded-lg ${
        type === 'success' ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200'
    }`;
    messageDiv.innerHTML = message;
    
    const container = document.querySelector('.max-w-7xl');
    container.insertBefore(messageDiv, container.children[1]);
    
    // Auto-hide after 5 seconds
    setTimeout(() => {
        messageDiv.remove();
    }, 5000);
}

function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric' 
    });
}

// Debug function to test all components
function debugUserManagement() {
    console.log('=== USER MANAGEMENT DEBUG ===');
    
    // Check if modal exists
    const modal = document.getElementById('userModal');
    console.log('Modal found:', !!modal);
    
    // Check if form exists
    const form = document.getElementById('userForm');
    console.log('Form found:', !!form);
    
    if (form) {
        // Check form elements
        console.log('Form elements:');
        console.log('- csrf_token:', !!form.elements.csrf_token);
        console.log('- action:', !!form.elements.action);
        console.log('- user_id:', !!form.elements.user_id);
        console.log('- full_name:', !!form.elements.full_name);
        console.log('- username:', !!form.elements.username);
        console.log('- email:', !!form.elements.email);
        console.log('- role:', !!form.elements.role);
        console.log('- is_active:', !!form.elements.is_active);
        console.log('- password:', !!form.elements.password);
    }
    
    // Check if submitUser function exists
    console.log('submitUser function exists:', typeof submitUser === 'function');
    
    // Check if openUserModal function exists
    console.log('openUserModal function exists:', typeof openUserModal === 'function');
    
    // Test opening modal
    console.log('Testing modal open...');
    try {
        openUserModal();
        console.log('Modal opened successfully');
        
        // Test if modal is visible
        if (modal) {
            const isVisible = !modal.classList.contains('hidden');
            console.log('Modal visible:', isVisible);
        }
        
        // Test form submission
        if (form) {
            console.log('Testing form submission event...');
            
            // Fill form with test data
            form.elements.full_name.value = 'Debug Test User';
            form.elements.username.value = 'debugtest_' + Date.now();
            form.elements.email.value = 'debugtest_' + Date.now() + '@example.com';
            form.elements.role.value = 'employee';
            form.elements.is_active.value = '1';
            form.elements.password.value = 'password123';
            
            console.log('Test data filled in form');
            console.log('You can now click "Create User" to test form submission');
        }
        
    } catch (e) {
        console.error('Error during modal test:', e);
    }
    
    // Test AJAX endpoint
    console.log('Testing AJAX endpoint...');
    fetch('ajax/manage_users.php', {
        method: 'POST',
        body: new URLSearchParams({
            action: 'get_users',
            csrf_token: '<?php echo Helper::generateCSRF(); ?>'
        })
    })
    .then(response => response.text())
    .then(text => {
        console.log('AJAX endpoint response:', text);
        try {
            const data = JSON.parse(text);
            console.log('AJAX endpoint working:', data.success);
        } catch (e) {
            console.error('AJAX endpoint returned invalid JSON:', text);
        }
    })
    .catch(error => {
        console.error('AJAX endpoint error:', error);
    });
    
    console.log('=== DEBUG COMPLETE ===');
}
</script>

<?php include '../../includes/footer.php'; ?>