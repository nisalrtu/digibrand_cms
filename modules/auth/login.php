<?php
// modules/auth/login.php
session_start();

// Include required files
require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// If user is already logged in, redirect to dashboard
if (Helper::isLoggedIn()) {
    Helper::redirect('modules/dashboard/');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Invoice Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Custom minimal styles */
        .login-container {
            min-height: 100vh;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        }
        
        .login-form {
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.9);
        }
        
        .input-focus:focus {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #374151 0%, #1f2937 100%);
        }
        
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
        
        .loading {
            display: none;
        }
        
        .loading.show {
            display: inline-block;
        }
        
        /* Mobile optimizations */
        @media (max-width: 640px) {
            .login-form {
                margin: 1rem;
                min-height: calc(100vh - 2rem);
            }
        }
    </style>
</head>
<body>
    <div class="login-container flex items-center justify-center p-4">
        <div class="login-form w-full max-w-md rounded-2xl shadow-xl p-8 border border-gray-100">
            <!-- Header -->
            <div class="text-center mb-8">
                <div class="w-16 h-16 bg-gray-800 rounded-xl mx-auto mb-4 flex items-center justify-center">
                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-gray-900 mb-2">Invoice Manager</h1>
                <p class="text-gray-600 text-sm">Sign in to your account</p>
            </div>

            <!-- Alert Messages -->
            <div id="alertMessage" class="hidden mb-4 p-3 rounded-lg text-sm"></div>

            <!-- Show Flash Message if exists -->
            <?php
            $flash = Helper::getFlashMessage();
            if ($flash):
                $alertClass = $flash['type'] === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200';
            ?>
                <div class="mb-4 p-3 rounded-lg text-sm <?php echo $alertClass; ?>">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form id="loginForm" class="space-y-6">
                <!-- CSRF Token -->
                <input type="hidden" name="csrf_token" value="<?php echo Helper::generateCSRF(); ?>">
                
                <!-- Username/Email Field -->
                <div>
                    <label for="loginInput" class="block text-sm font-medium text-gray-700 mb-2">
                        Username or Email
                    </label>
                    <input 
                        type="text" 
                        id="loginInput" 
                        name="login_input" 
                        required
                        autocomplete="username"
                        class="input-focus w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent transition-all duration-200 text-base"
                        placeholder="Enter username or email"
                    >
                </div>

                <!-- Password Field -->
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                        Password
                    </label>
                    <div class="relative">
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            required
                            autocomplete="current-password"
                            class="input-focus w-full px-4 py-3 pr-12 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent transition-all duration-200 text-base"
                            placeholder="Enter password"
                        >
                        <button 
                            type="button" 
                            id="togglePassword"
                            class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600"
                        >
                            <svg id="eyeIcon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Submit Button -->
                <button 
                    type="submit" 
                    id="loginBtn"
                    class="btn-primary w-full py-3 px-4 text-white font-medium rounded-lg transition-all duration-200 focus:ring-2 focus:ring-gray-500 focus:ring-offset-2"
                >
                    <span id="loginText">Sign In</span>
                    <span id="loginLoading" class="loading">
                        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline" fill="none" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"></circle>
                            <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" class="opacity-75"></path>
                        </svg>
                        Signing in...
                    </span>
                </button>
            </form>

            <!-- Footer -->
            <div class="mt-8 text-center">
                <p class="text-xs text-gray-500">
                    Invoice Manager v1.0 &copy; 2024
                </p>
            </div>
        </div>
    </div>

    <script>
        // Get CSRF Token from hidden input
        const csrfToken = document.querySelector('input[name="csrf_token"]').value;
        
        // DOM Elements
        const loginForm = document.getElementById('loginForm');
        const loginBtn = document.getElementById('loginBtn');
        const loginText = document.getElementById('loginText');
        const loginLoading = document.getElementById('loginLoading');
        const alertMessage = document.getElementById('alertMessage');
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const eyeIcon = document.getElementById('eyeIcon');

        // Toggle Password Visibility
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            if (type === 'text') {
                eyeIcon.innerHTML = `
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21"></path>
                `;
            } else {
                eyeIcon.innerHTML = `
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                `;
            }
        });

        // Show Alert Message
        function showAlert(message, type = 'error') {
            const alertClasses = {
                'error': 'bg-red-50 text-red-700 border border-red-200',
                'success': 'bg-green-50 text-green-700 border border-green-200',
                'info': 'bg-blue-50 text-blue-700 border border-blue-200'
            };
            
            alertMessage.className = `mb-4 p-3 rounded-lg text-sm ${alertClasses[type]}`;
            alertMessage.textContent = message;
            alertMessage.classList.remove('hidden');
            
            // Auto hide after 5 seconds
            setTimeout(() => {
                alertMessage.classList.add('hidden');
            }, 5000);
        }

        // Handle Form Submit
        loginForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            // Show loading state
            loginBtn.disabled = true;
            loginText.style.display = 'none';
            loginLoading.classList.add('show');
            alertMessage.classList.add('hidden');
            
            // Get form data
            const formData = new FormData(loginForm);
            formData.append('action', 'login');
            
            try {
                const response = await fetch('AuthController.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert('Login successful! Redirecting...', 'success');
                    
                    // Redirect to dashboard
                    setTimeout(() => {
                        window.location.href = '<?php echo Helper::baseUrl('modules/dashboard/'); ?>';
                    }, 1000);
                } else {
                    showAlert(result.message, 'error');
                }
                
            } catch (error) {
                showAlert('Network error. Please try again.', 'error');
                console.error('Login error:', error);
            } finally {
                // Reset button state
                loginBtn.disabled = false;
                loginText.style.display = 'inline';
                loginLoading.classList.remove('show');
            }
        });

        // Auto-focus first input on larger screens
        if (window.innerWidth > 768) {
            document.getElementById('loginInput').focus();
        }

        // Handle Enter key on inputs
        document.querySelectorAll('input').forEach(input => {
            input.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    loginForm.dispatchEvent(new Event('submit'));
                }
            });
        });

        // Auto-hide flash messages after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const flashMessage = document.querySelector('.mb-4.p-3.rounded-lg.text-sm:not(#alertMessage)');
            if (flashMessage) {
                setTimeout(() => {
                    flashMessage.style.opacity = '0';
                    setTimeout(() => {
                        flashMessage.remove();
                    }, 300);
                }, 5000);
            }
        });
    </script>
</body>
</html>