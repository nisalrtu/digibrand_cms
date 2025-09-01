<!-- includes/header.php -->
<?php
require_once __DIR__ . '/../core/Helper.php';

if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'Invoice Manager'; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Custom styles for mobile optimization */
        .sidebar {
            transform: translateX(-100%);
            transition: transform 0.3s ease-in-out;
        }
        
        .sidebar.open {
            transform: translateX(0);
        }
        
        .sidebar-backdrop {
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease-in-out;
        }
        
        .sidebar-backdrop.show {
            opacity: 1;
            visibility: visible;
        }
        
        .nav-item {
            transition: all 0.2s ease;
        }
        
        .nav-item:hover {
            transform: translateX(4px);
        }
        
        .nav-item.active {
            background: linear-gradient(135deg, #374151 0%, #1f2937 100%);
            transform: translateX(4px);
        }
        
        /* Mobile-first responsive design */
        @media (min-width: 1024px) {
            .sidebar {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 256px;
            }
        }
        
        /* Smooth scrolling */
        html {
            scroll-behavior: smooth;
        }
        
        /* Custom scrollbar */
        .sidebar::-webkit-scrollbar {
            width: 4px;
        }
        
        .sidebar::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        
        .sidebar::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 2px;
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-900">
    <!-- Mobile Header -->
    <header class="lg:hidden bg-white border-b border-gray-200 px-4 py-3 flex items-center justify-between sticky top-0 z-40">
        <!-- Menu Button -->
        <button 
            id="menuToggle" 
            class="p-2 rounded-lg text-gray-600 hover:text-gray-900 hover:bg-gray-100 transition-colors"
            aria-label="Toggle menu"
        >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
            </svg>
        </button>
        
        <!-- Logo/Title -->
        <div class="flex items-center space-x-2">
            <div class="w-8 h-8 bg-gray-800 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
            </div>
            <span class="font-semibold text-gray-900">Invoice Manager</span>
        </div>
        
        <!-- User Avatar -->
        <div class="flex items-center space-x-2">
            <div class="w-8 h-8 bg-gray-300 rounded-full flex items-center justify-center">
                <span class="text-sm font-medium text-gray-600">
                    <?php echo strtoupper(substr($_SESSION['user_name'] ?? 'U', 0, 1)); ?>
                </span>
            </div>
        </div>
    </header>

    <!-- Sidebar Backdrop (Mobile) -->
    <div 
        id="sidebarBackdrop" 
        class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden sidebar-backdrop"
    ></div>

    <!-- Sidebar -->
    <nav 
        id="sidebar" 
        class="sidebar fixed top-0 left-0 z-50 w-64 h-full bg-white border-r border-gray-200 lg:translate-x-0 overflow-y-auto"
    >
        <!-- Sidebar Header -->
        <div class="p-4 border-b border-gray-200 lg:border-none">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-gray-800 rounded-xl flex items-center justify-center">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                    </div>
                    <div class="hidden lg:block">
                        <h1 class="text-lg font-bold text-gray-900">Invoice Manager</h1>
                        <p class="text-sm text-gray-500">v1.0</p>
                    </div>
                </div>
                
                <!-- Close button (Mobile) -->
                <button 
                    id="closeSidebar" 
                    class="lg:hidden p-2 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100"
                    aria-label="Close menu"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>

        <!-- User Info -->
        <div class="p-4 bg-gray-50 border-b border-gray-200">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-gray-300 rounded-full flex items-center justify-center">
                    <span class="font-medium text-gray-600">
                        <?php echo strtoupper(substr($_SESSION['user_name'] ?? 'User', 0, 1)); ?>
                    </span>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-medium text-gray-900 truncate">
                        <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?>
                    </p>
                    <p class="text-sm text-gray-500 truncate">
                        <?php echo ucfirst($_SESSION['user_role'] ?? 'employee'); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Navigation Menu -->
        <div class="p-4 space-y-2">
            <!-- Dashboard -->
            <a href="<?php echo Helper::baseUrl('modules/dashboard/'); ?>" 
               class="nav-item flex items-center space-x-3 px-3 py-2.5 rounded-lg text-gray-700 hover:text-gray-900 hover:bg-gray-100 <?php echo (strpos($_SERVER['REQUEST_URI'], 'dashboard') !== false) ? 'active text-white' : ''; ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v14l-5-3-5 3V5z"></path>
                </svg>
                <span class="font-medium">Dashboard</span>
            </a>

            <!-- Clients -->
            <a href="<?php echo Helper::baseUrl('modules/clients/'); ?>" 
               class="nav-item flex items-center space-x-3 px-3 py-2.5 rounded-lg text-gray-700 hover:text-gray-900 hover:bg-gray-100 <?php echo (strpos($_SERVER['REQUEST_URI'], 'clients') !== false) ? 'active text-white' : ''; ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                </svg>
                <span class="font-medium">Clients</span>
            </a>

            <!-- Projects -->
            <a href="<?php echo Helper::baseUrl('modules/projects/'); ?>" 
               class="nav-item flex items-center space-x-3 px-3 py-2.5 rounded-lg text-gray-700 hover:text-gray-900 hover:bg-gray-100 <?php echo (strpos($_SERVER['REQUEST_URI'], 'projects') !== false) ? 'active text-white' : ''; ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                </svg>
                <span class="font-medium">Projects</span>
            </a>

            <!-- Invoices -->
            <a href="<?php echo Helper::baseUrl('modules/invoices/'); ?>" 
               class="nav-item flex items-center space-x-3 px-3 py-2.5 rounded-lg text-gray-700 hover:text-gray-900 hover:bg-gray-100 <?php echo (strpos($_SERVER['REQUEST_URI'], 'invoices') !== false) ? 'active text-white' : ''; ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <span class="font-medium">Invoices</span>
            </a>

            <!-- Payments -->
            <a href="<?php echo Helper::baseUrl('modules/payments/'); ?>" 
               class="nav-item flex items-center space-x-3 px-3 py-2.5 rounded-lg text-gray-700 hover:text-gray-900 hover:bg-gray-100 <?php echo (strpos($_SERVER['REQUEST_URI'], 'payments') !== false) ? 'active text-white' : ''; ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v2a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                </svg>
                <span class="font-medium">Payments</span>
            </a>

            <!-- Reports -->
            <a href="<?php echo Helper::baseUrl('modules/reports/'); ?>" 
               class="nav-item flex items-center space-x-3 px-3 py-2.5 rounded-lg text-gray-700 hover:text-gray-900 hover:bg-gray-100 <?php echo (strpos($_SERVER['REQUEST_URI'], 'reports') !== false) ? 'active text-white' : ''; ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
                <span class="font-medium">Reports</span>
            </a>
        </div>

        <!-- Bottom Actions -->
        <div class="absolute bottom-0 left-0 right-0 p-4 border-t border-gray-200 bg-white">
            <a href="<?php echo Helper::baseUrl('modules/auth/logout.php'); ?>" 
               class="flex items-center space-x-3 px-3 py-2.5 rounded-lg text-red-600 hover:text-red-700 hover:bg-red-50 transition-colors w-full">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                <span class="font-medium">Logout</span>
            </a>
        </div>
    </nav>

    <!-- Main Content Area -->
    <main class="main-content min-h-screen">
        <!-- Page Content will go here -->
        <div class="p-4 lg:p-6">

    <script>
        // Mobile sidebar functionality
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarBackdrop = document.getElementById('sidebarBackdrop');
        const closeSidebar = document.getElementById('closeSidebar');

        // Toggle sidebar
        function toggleSidebar() {
            sidebar.classList.toggle('open');
            sidebarBackdrop.classList.toggle('show');
            document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
        }

        // Close sidebar
        function closeSidebarFunc() {
            sidebar.classList.remove('open');
            sidebarBackdrop.classList.remove('show');
            document.body.style.overflow = '';
        }

        // Event listeners
        if (menuToggle) {
            menuToggle.addEventListener('click', toggleSidebar);
        }
        
        if (closeSidebar) {
            closeSidebar.addEventListener('click', closeSidebarFunc);
        }
        
        if (sidebarBackdrop) {
            sidebarBackdrop.addEventListener('click', closeSidebarFunc);
        }

        // Close sidebar on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sidebar.classList.contains('open')) {
                closeSidebarFunc();
            }
        });

        // Close sidebar on window resize to desktop size
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 1024 && sidebar.classList.contains('open')) {
                closeSidebarFunc();
            }
        });

        // Touch gestures for mobile
        let startX = 0;
        let currentX = 0;
        let isDragging = false;

        document.addEventListener('touchstart', function(e) {
            startX = e.touches[0].clientX;
        });

        document.addEventListener('touchmove', function(e) {
            if (!isDragging) return;
            currentX = e.touches[0].clientX;
        });

        document.addEventListener('touchend', function(e) {
            if (!isDragging) return;
            
            const diffX = currentX - startX;
            
            // Swipe right to open sidebar (from left edge)
            if (startX < 50 && diffX > 100 && !sidebar.classList.contains('open')) {
                toggleSidebar();
            }
            
            // Swipe left to close sidebar
            if (diffX < -100 && sidebar.classList.contains('open')) {
                closeSidebarFunc();
            }
            
            isDragging = false;
        });
    </script>