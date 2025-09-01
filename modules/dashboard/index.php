<?php
// modules/dashboard/index.php
session_start();

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

$pageTitle = 'Dashboard - Invoice Manager';

// Get dashboard stats
$database = new Database();
$db = $database->getConnection();

try {
    // Get total clients
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM clients WHERE is_active = 1");
    $stmt->execute();
    $totalClients = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Get total projects
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM projects");
    $stmt->execute();
    $totalProjects = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Get active projects
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM projects WHERE status IN ('pending', 'in_progress')");
    $stmt->execute();
    $activeProjects = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Get total revenue (from paid invoices)
    $stmt = $db->prepare("SELECT COALESCE(SUM(paid_amount), 0) as total FROM invoices");
    $stmt->execute();
    $totalRevenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get pending payments
    $stmt = $db->prepare("SELECT COALESCE(SUM(balance_amount), 0) as total FROM invoices WHERE status IN ('sent', 'partially_paid', 'overdue')");
    $stmt->execute();
    $pendingPayments = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get recent invoices
    $stmt = $db->prepare("
        SELECT i.*, c.company_name 
        FROM invoices i 
        JOIN clients c ON i.client_id = c.id 
        ORDER BY i.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recentInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent projects
    $stmt = $db->prepare("
        SELECT p.*, c.company_name 
        FROM projects p 
        JOIN clients c ON p.client_id = c.id 
        ORDER BY p.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recentProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = "Error loading dashboard data: " . $e->getMessage();
}

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="mb-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
            <p class="text-gray-600 mt-1">Welcome back, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</p>
        </div>
        <div class="mt-4 sm:mt-0">
            <p class="text-sm text-gray-500">
                <?php echo date('l, F j, Y'); ?>
            </p>
        </div>
    </div>
</div>

<!-- Quick Stats Grid -->
<div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
    <!-- Total Clients -->
    <div class="bg-white rounded-xl p-4 border border-gray-200 hover:shadow-md transition-shadow">
        <div class="flex items-center">
            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                </svg>
            </div>
        </div>
        <div class="mt-3">
            <p class="text-2xl font-bold text-gray-900"><?php echo number_format($totalClients); ?></p>
            <p class="text-sm text-gray-600">Clients</p>
        </div>
    </div>

    <!-- Total Projects -->
    <div class="bg-white rounded-xl p-4 border border-gray-200 hover:shadow-md transition-shadow">
        <div class="flex items-center">
            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                </svg>
            </div>
        </div>
        <div class="mt-3">
            <p class="text-2xl font-bold text-gray-900"><?php echo number_format($totalProjects); ?></p>
            <p class="text-sm text-gray-600">Projects</p>
        </div>
    </div>

    <!-- Active Projects -->
    <div class="bg-white rounded-xl p-4 border border-gray-200 hover:shadow-md transition-shadow">
        <div class="flex items-center">
            <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
        </div>
        <div class="mt-3">
            <p class="text-2xl font-bold text-gray-900"><?php echo number_format($activeProjects); ?></p>
            <p class="text-sm text-gray-600">Active</p>
        </div>
    </div>

    <!-- Total Revenue -->
    <div class="bg-white rounded-xl p-4 border border-gray-200 hover:shadow-md transition-shadow">
        <div class="flex items-center">
            <div class="w-10 h-10 bg-emerald-100 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
        </div>
        <div class="mt-3">
            <p class="text-xl font-bold text-gray-900"><?php echo Helper::formatCurrency($totalRevenue); ?></p>
            <p class="text-sm text-gray-600">Revenue</p>
        </div>
    </div>

    <!-- Pending Payments -->
    <div class="bg-white rounded-xl p-4 border border-gray-200 hover:shadow-md transition-shadow">
        <div class="flex items-center">
            <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
        </div>
        <div class="mt-3">
            <p class="text-xl font-bold text-gray-900"><?php echo Helper::formatCurrency($pendingPayments); ?></p>
            <p class="text-sm text-gray-600">Pending</p>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <a href="<?php echo Helper::baseUrl('modules/clients/add.php'); ?>" 
       class="bg-white rounded-xl p-4 border border-gray-200 hover:shadow-md hover:border-gray-300 transition-all text-center group">
        <div class="w-12 h-12 bg-blue-50 rounded-xl flex items-center justify-center mx-auto mb-3 group-hover:bg-blue-100 transition-colors">
            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
            </svg>
        </div>
        <p class="font-medium text-gray-900 group-hover:text-blue-600 transition-colors">Add Client</p>
    </a>

    <a href="<?php echo Helper::baseUrl('modules/projects/add.php'); ?>" 
       class="bg-white rounded-xl p-4 border border-gray-200 hover:shadow-md hover:border-gray-300 transition-all text-center group">
        <div class="w-12 h-12 bg-green-50 rounded-xl flex items-center justify-center mx-auto mb-3 group-hover:bg-green-100 transition-colors">
            <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
            </svg>
        </div>
        <p class="font-medium text-gray-900 group-hover:text-green-600 transition-colors">New Project</p>
    </a>

    <a href="<?php echo Helper::baseUrl('modules/invoices/create.php'); ?>" 
       class="bg-white rounded-xl p-4 border border-gray-200 hover:shadow-md hover:border-gray-300 transition-all text-center group">
        <div class="w-12 h-12 bg-purple-50 rounded-xl flex items-center justify-center mx-auto mb-3 group-hover:bg-purple-100 transition-colors">
            <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
        </div>
        <p class="font-medium text-gray-900 group-hover:text-purple-600 transition-colors">Create Invoice</p>
    </a>

    <a href="<?php echo Helper::baseUrl('modules/payments/add.php'); ?>" 
       class="bg-white rounded-xl p-4 border border-gray-200 hover:shadow-md hover:border-gray-300 transition-all text-center group">
        <div class="w-12 h-12 bg-emerald-50 rounded-xl flex items-center justify-center mx-auto mb-3 group-hover:bg-emerald-100 transition-colors">
            <svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v2a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
            </svg>
        </div>
        <p class="font-medium text-gray-900 group-hover:text-emerald-600 transition-colors">Add Payment</p>
    </a>
</div>

<!-- Recent Activity -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Recent Invoices -->
    <div class="bg-white rounded-xl border border-gray-200">
        <div class="p-4 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900">Recent Invoices</h3>
                <a href="<?php echo Helper::baseUrl('modules/invoices/'); ?>" 
                   class="text-sm text-blue-600 hover:text-blue-700 font-medium">View All</a>
            </div>
        </div>
        <div class="p-4">
            <?php if (empty($recentInvoices)): ?>
                <div class="text-center py-8">
                    <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <p class="text-gray-500">No invoices yet</p>
                    <a href="<?php echo Helper::baseUrl('modules/invoices/create.php'); ?>" 
                       class="text-blue-600 hover:text-blue-700 font-medium">Create your first invoice</a>
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($recentInvoices as $invoice): ?>
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-gray-900 truncate">
                                    #<?php echo htmlspecialchars($invoice['invoice_number']); ?>
                                </p>
                                <p class="text-sm text-gray-500 truncate">
                                    <?php echo htmlspecialchars($invoice['company_name']); ?>
                                </p>
                            </div>
                            <div class="flex items-center space-x-2">
                                <p class="text-sm font-medium text-gray-900">
                                    <?php echo Helper::formatCurrency($invoice['total_amount']); ?>
                                </p>
                                <?php echo Helper::statusBadge($invoice['status']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Projects -->
    <div class="bg-white rounded-xl border border-gray-200">
        <div class="p-4 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900">Recent Projects</h3>
                <a href="<?php echo Helper::baseUrl('modules/projects/'); ?>" 
                   class="text-sm text-blue-600 hover:text-blue-700 font-medium">View All</a>
            </div>
        </div>
        <div class="p-4">
            <?php if (empty($recentProjects)): ?>
                <div class="text-center py-8">
                    <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                    </svg>
                    <p class="text-gray-500">No projects yet</p>
                    <a href="<?php echo Helper::baseUrl('modules/projects/add.php'); ?>" 
                       class="text-blue-600 hover:text-blue-700 font-medium">Create your first project</a>
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($recentProjects as $project): ?>
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-gray-900 truncate">
                                    <?php echo htmlspecialchars($project['project_name']); ?>
                                </p>
                                <p class="text-sm text-gray-500 truncate">
                                    <?php echo htmlspecialchars($project['company_name']); ?>
                                </p>
                            </div>
                            <div class="flex items-center space-x-2">
                                <p class="text-sm font-medium text-gray-900">
                                    <?php echo Helper::formatCurrency($project['total_amount']); ?>
                                </p>
                                <?php echo Helper::statusBadge($project['status']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
/* Additional mobile optimizations */
@media (max-width: 640px) {
    .grid.grid-cols-2.lg\:grid-cols-5 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
    }
    
    .grid.grid-cols-2.lg\:grid-cols-4 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    
    .grid.grid-cols-1.lg\:grid-cols-2 {
        grid-template-columns: repeat(1, minmax(0, 1fr));
    }
}

/* Enhance touch targets for mobile */
@media (max-width: 768px) {
    .hover\:shadow-md:hover {
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }
    
    /* Make cards more touch-friendly */
    .bg-white.rounded-xl.p-4 {
        min-height: 44px; /* iOS touch target size */
    }
}

/* Loading animation for dynamic content */
.loading-shimmer {
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: shimmer 1.5s infinite;
}

@keyframes shimmer {
    0% { background-position: -200% 0; }
    100% { background-position: 200% 0; }
}
</style>

<script>
// Dashboard JavaScript functionality
document.addEventListener('DOMContentLoaded', function() {
    // Add loading states for dynamic content
    const dynamicElements = document.querySelectorAll('[data-dynamic]');
    dynamicElements.forEach(el => {
        el.classList.add('loading-shimmer');
        
        // Simulate loading completion
        setTimeout(() => {
            el.classList.remove('loading-shimmer');
        }, 1000);
    });
    
    // Auto-refresh stats every 5 minutes
    setInterval(function() {
        // In a real application, you would make an AJAX call here
        // to refresh the stats without reloading the page
        console.log('Auto-refreshing stats...');
    }, 300000); // 5 minutes
    
    // Add swipe gestures for mobile card navigation
    let startX = 0;
    let currentX = 0;
    let cardBeingDragged = null;
    
    document.querySelectorAll('.bg-white.rounded-xl').forEach(card => {
        card.addEventListener('touchstart', function(e) {
            startX = e.touches[0].clientX;
            cardBeingDragged = this;
        });
        
        card.addEventListener('touchmove', function(e) {
            if (!cardBeingDragged) return;
            currentX = e.touches[0].clientX;
            const diffX = currentX - startX;
            
            // Add subtle transform for feedback
            if (Math.abs(diffX) > 10) {
                this.style.transform = `translateX(${diffX * 0.1}px)`;
            }
        });
        
        card.addEventListener('touchend', function(e) {
            if (cardBeingDragged) {
                this.style.transform = '';
                cardBeingDragged = null;
            }
        });
    });
});

// Format numbers with animation
function animateNumber(element, start, end, duration = 1000) {
    const range = end - start;
    const increment = range / (duration / 16);
    let current = start;
    
    const timer = setInterval(() => {
        current += increment;
        if ((increment > 0 && current >= end) || (increment < 0 && current <= end)) {
            current = end;
            clearInterval(timer);
        }
        element.textContent = Math.floor(current).toLocaleString();
    }, 16);
}

// Initialize number animations on load
window.addEventListener('load', function() {
    const numberElements = document.querySelectorAll('[data-animate-number]');
    numberElements.forEach(el => {
        const finalNumber = parseInt(el.textContent.replace(/,/g, ''));
        el.textContent = '0';
        animateNumber(el, 0, finalNumber);
    });
});
</script>

<?php include '../../includes/footer.php'; ?>