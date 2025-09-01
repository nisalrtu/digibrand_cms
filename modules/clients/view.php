<?php
// modules/clients/view.php
session_start();

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

// Get and validate client ID
$clientId = Helper::decryptId($_GET['id'] ?? '');
if (!$clientId) {
    Helper::setMessage('Invalid client ID.', 'error');
    Helper::redirect('modules/clients/');
}

$pageTitle = 'Client Details - Invoice Manager';

// Get database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Get client details
    $clientQuery = "SELECT * FROM clients WHERE id = :client_id AND is_active = 1";
    $clientStmt = $db->prepare($clientQuery);
    $clientStmt->bindParam(':client_id', $clientId);
    $clientStmt->execute();
    
    $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$client) {
        Helper::setMessage('Client not found.', 'error');
        Helper::redirect('modules/clients/');
    }
    
    // Get client statistics
    $statsQuery = "
        SELECT 
            (SELECT COUNT(*) FROM projects WHERE client_id = :client_id) as total_projects,
            (SELECT COUNT(*) FROM projects WHERE client_id = :client_id AND status IN ('pending', 'in_progress')) as active_projects,
            (SELECT COUNT(*) FROM projects WHERE client_id = :client_id AND status = 'completed') as completed_projects,
            (SELECT COUNT(*) FROM invoices WHERE client_id = :client_id) as total_invoices,
            (SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE client_id = :client_id) as total_invoiced,
            (SELECT COALESCE(SUM(paid_amount), 0) FROM invoices WHERE client_id = :client_id) as total_paid,
            (SELECT COALESCE(SUM(balance_amount), 0) FROM invoices WHERE client_id = :client_id AND status IN ('sent', 'partially_paid', 'overdue')) as outstanding_amount
    ";
    
    $statsStmt = $db->prepare($statsQuery);
    $statsStmt->bindParam(':client_id', $clientId);
    $statsStmt->execute();
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get recent projects (last 5)
    $projectsQuery = "
        SELECT p.*, 
               (SELECT COUNT(*) FROM project_items WHERE project_id = p.id) as items_count
        FROM projects p 
        WHERE p.client_id = :client_id 
        ORDER BY p.created_at DESC 
        LIMIT 5
    ";
    $projectsStmt = $db->prepare($projectsQuery);
    $projectsStmt->bindParam(':client_id', $clientId);
    $projectsStmt->execute();
    $recentProjects = $projectsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent invoices (last 5)
    $invoicesQuery = "
        SELECT i.*, p.project_name
        FROM invoices i
        LEFT JOIN projects p ON i.project_id = p.id
        WHERE i.client_id = :client_id 
        ORDER BY i.created_at DESC 
        LIMIT 5
    ";
    $invoicesStmt = $db->prepare($invoicesQuery);
    $invoicesStmt->bindParam(':client_id', $clientId);
    $invoicesStmt->execute();
    $recentInvoices = $invoicesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent payments (last 5)
    $paymentsQuery = "
        SELECT pa.*, i.invoice_number
        FROM payments pa
        JOIN invoices i ON pa.invoice_id = i.id
        WHERE i.client_id = :client_id 
        ORDER BY pa.created_at DESC 
        LIMIT 5
    ";
    $paymentsStmt = $db->prepare($paymentsQuery);
    $paymentsStmt->bindParam(':client_id', $clientId);
    $paymentsStmt->execute();
    $recentPayments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    Helper::setMessage('Error loading client details: ' . $e->getMessage(), 'error');
    Helper::redirect('modules/clients/');
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
        <span class="text-gray-900 font-medium truncate"><?php echo htmlspecialchars($client['company_name']); ?></span>
    </div>
</nav>

<!-- Client Header -->
<div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div class="flex-1 min-w-0">
            <!-- Client Title -->
            <div class="mb-4">
                <h1 class="text-2xl font-bold text-gray-900 truncate">
                    <?php echo htmlspecialchars($client['company_name']); ?>
                </h1>
                <p class="text-lg text-gray-600 mt-1">
                    <?php echo htmlspecialchars($client['contact_person']); ?>
                </p>
            </div>

            <!-- Client Info Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div class="flex items-start space-x-3">
                    <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-900">Mobile Number</p>
                        <p class="text-gray-600 break-all">
                            <a href="tel:<?php echo htmlspecialchars($client['mobile_number']); ?>" 
                               class="hover:text-blue-600 transition-colors">
                                <?php echo htmlspecialchars($client['mobile_number']); ?>
                            </a>
                        </p>
                    </div>
                </div>

                <div class="flex items-start space-x-3">
                    <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-900">Location</p>
                        <p class="text-gray-600"><?php echo htmlspecialchars($client['city']); ?></p>
                    </div>
                </div>

                <div class="flex items-start space-x-3 md:col-span-2">
                    <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-900">Address</p>
                        <p class="text-gray-600 whitespace-pre-line"><?php echo htmlspecialchars($client['address']); ?></p>
                    </div>
                </div>
            </div>

            <!-- Client Meta -->
            <div class="text-sm text-gray-500 pt-4 border-t border-gray-100">
                Client since <?php echo Helper::formatDate($client['created_at'], 'F j, Y'); ?>
                <?php if ($client['updated_at'] !== $client['created_at']): ?>
                    • Last updated <?php echo Helper::formatDate($client['updated_at'], 'M j, Y'); ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Desktop Actions -->
        <div class="hidden sm:flex flex-row gap-3 flex-shrink-0">
            <a href="<?php echo Helper::baseUrl('modules/clients/edit.php?id=' . Helper::encryptId($client['id'])); ?>" 
               class="inline-flex items-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                </svg>
                Edit Client
            </a>
            <a href="<?php echo Helper::baseUrl('modules/projects/add.php?client_id=' . Helper::encryptId($client['id'])); ?>" 
               class="inline-flex items-center px-4 py-2 bg-white text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                New Project
            </a>
        </div>
    </div>

    <!-- Mobile Actions - Separate Row -->
    <div class="sm:hidden mt-4 pt-4 border-t border-gray-200">
        <div class="grid grid-cols-1 gap-3">
            <a href="<?php echo Helper::baseUrl('modules/clients/edit.php?id=' . Helper::encryptId($client['id'])); ?>" 
               class="flex items-center justify-center px-4 py-3 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors text-sm font-medium">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                </svg>
                Edit Client
            </a>
            <a href="<?php echo Helper::baseUrl('modules/projects/add.php?client_id=' . Helper::encryptId($client['id'])); ?>" 
               class="flex items-center justify-center px-4 py-3 bg-white text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors text-sm font-medium">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                New Project
            </a>
        </div>
    </div>
</div>

<!-- Statistics Grid -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <!-- Total Projects -->
    <div class="bg-white rounded-xl p-4 border border-gray-200">
        <div class="flex items-center">
            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                </svg>
            </div>
        </div>
        <div class="mt-3">
            <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['total_projects']); ?></p>
            <p class="text-sm text-gray-600">Total Projects</p>
        </div>
    </div>

    <!-- Active Projects -->
    <div class="bg-white rounded-xl p-4 border border-gray-200">
        <div class="flex items-center">
            <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
        </div>
        <div class="mt-3">
            <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['active_projects']); ?></p>
            <p class="text-sm text-gray-600">Active</p>
        </div>
    </div>

    <!-- Total Invoiced -->
    <div class="bg-white rounded-xl p-4 border border-gray-200">
        <div class="flex items-center">
            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
            </div>
        </div>
        <div class="mt-3">
            <p class="text-xl font-bold text-gray-900"><?php echo Helper::formatCurrency($stats['total_invoiced']); ?></p>
            <p class="text-sm text-gray-600">Invoiced</p>
        </div>
    </div>

    <!-- Outstanding Amount -->
    <div class="bg-white rounded-xl p-4 border border-gray-200">
        <div class="flex items-center">
            <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
        </div>
        <div class="mt-3">
            <p class="text-xl font-bold text-gray-900"><?php echo Helper::formatCurrency($stats['outstanding_amount']); ?></p>
            <p class="text-sm text-gray-600">Outstanding</p>
        </div>
    </div>
</div>

<!-- Content Tabs -->
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <!-- Tab Navigation -->
    <div class="border-b border-gray-200">
        <nav class="flex overflow-x-auto">
            <button onclick="showTab('projects')" 
                    class="tab-button flex-shrink-0 px-4 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300 transition-colors whitespace-nowrap active"
                    id="projects-tab">
                <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                </svg>
                Projects (<?php echo $stats['total_projects']; ?>)
            </button>
            <button onclick="showTab('invoices')" 
                    class="tab-button flex-shrink-0 px-4 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300 transition-colors whitespace-nowrap"
                    id="invoices-tab">
                <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                Invoices (<?php echo $stats['total_invoices']; ?>)
            </button>
            <button onclick="showTab('payments')" 
                    class="tab-button flex-shrink-0 px-4 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300 transition-colors whitespace-nowrap"
                    id="payments-tab">
                <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v2a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                </svg>
                Payments
            </button>
        </nav>
    </div>

    <!-- Tab Content -->
    <div class="p-6">
        <!-- Projects Tab -->
        <div id="projects-content" class="tab-content">
            <?php if (empty($recentProjects)): ?>
                <div class="text-center py-8">
                    <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                    </svg>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No projects yet</h3>
                    <p class="text-gray-600 mb-4">Start by creating a project for this client.</p>
                    <a href="<?php echo Helper::baseUrl('modules/projects/add.php?client_id=' . Helper::encryptId($client['id'])); ?>" 
                       class="inline-flex items-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Create First Project
                    </a>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($recentProjects as $project): ?>
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                            <div class="flex-1 min-w-0">
                                <h4 class="font-medium text-gray-900 truncate">
                                    <a href="<?php echo Helper::baseUrl('modules/projects/view.php?id=' . Helper::encryptId($project['id'])); ?>" 
                                       class="hover:text-blue-600 transition-colors">
                                        <?php echo htmlspecialchars($project['project_name']); ?>
                                    </a>
                                </h4>
                                <div class="flex items-center space-x-4 mt-1 text-sm text-gray-600">
                                    <span><?php echo ucwords(str_replace('_', ' ', $project['project_type'])); ?></span>
                                    <span>•</span>
                                    <span><?php echo $project['items_count']; ?> items</span>
                                    <span>•</span>
                                    <span><?php echo Helper::formatDate($project['created_at'], 'M j, Y'); ?></span>
                                </div>
                            </div>
                            <div class="flex items-center space-x-3 ml-4">
                                <div class="text-right">
                                    <p class="font-medium text-gray-900"><?php echo Helper::formatCurrency($project['total_amount']); ?></p>
                                </div>
                                <?php echo Helper::statusBadge($project['status']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if ($stats['total_projects'] > 5): ?>
                        <div class="text-center pt-4">
                            <a href="<?php echo Helper::baseUrl('modules/projects/?client=' . Helper::encryptId($client['id'])); ?>" 
                               class="text-blue-600 hover:text-blue-700 font-medium">
                                View All <?php echo $stats['total_projects']; ?> Projects →
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Invoices Tab -->
        <div id="invoices-content" class="tab-content hidden">
            <?php if (empty($recentInvoices)): ?>
                <div class="text-center py-8">
                    <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No invoices yet</h3>
                    <a href="<?php echo Helper::baseUrl('modules/invoices/create.php?client_id=' . Helper::encryptId($client['id'])); ?>" 
                       class="inline-flex items-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Create First Invoice
                    </a>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($recentInvoices as $invoice): ?>
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                            <div class="flex-1 min-w-0">
                                <h4 class="font-medium text-gray-900 truncate">
                                    <a href="<?php echo Helper::baseUrl('modules/invoices/view.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
                                       class="hover:text-blue-600 transition-colors">
                                        #<?php echo htmlspecialchars($invoice['invoice_number']); ?>
                                    </a>
                                </h4>
                                <div class="flex items-center space-x-4 mt-1 text-sm text-gray-600">
                                    <?php if ($invoice['project_name']): ?>
                                        <span><?php echo htmlspecialchars($invoice['project_name']); ?></span>
                                        <span>•</span>
                                    <?php endif; ?>
                                    <span>Due <?php echo Helper::formatDate($invoice['due_date'], 'M j, Y'); ?></span>
                                    <span>•</span>
                                    <span>Paid <?php echo Helper::formatCurrency($invoice['paid_amount']); ?></span>
                                </div>
                            </div>
                            <div class="flex items-center space-x-3 ml-4">
                                <div class="text-right">
                                    <p class="font-medium text-gray-900"><?php echo Helper::formatCurrency($invoice['total_amount']); ?></p>
                                    <?php if ($invoice['balance_amount'] > 0): ?>
                                        <p class="text-sm text-red-600">Balance: <?php echo Helper::formatCurrency($invoice['balance_amount']); ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php echo Helper::statusBadge($invoice['status']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if ($stats['total_invoices'] > 5): ?>
                        <div class="text-center pt-4">
                            <a href="<?php echo Helper::baseUrl('modules/invoices/?client=' . Helper::encryptId($client['id'])); ?>" 
                               class="text-blue-600 hover:text-blue-700 font-medium">
                                View All <?php echo $stats['total_invoices']; ?> Invoices →
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Payments Tab -->
        <div id="payments-content" class="tab-content hidden">
            <?php if (empty($recentPayments)): ?>
                <div class="text-center py-8">
                    <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v2a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No payments yet</h3>
                    <p class="text-gray-600 mb-4">Payments will appear here once invoices are paid.</p>
                    <a href="<?php echo Helper::baseUrl('modules/payments/add.php?client_id=' . Helper::encryptId($client['id'])); ?>" 
                       class="inline-flex items-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Record Payment
                    </a>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($recentPayments as $payment): ?>
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                            <div class="flex-1 min-w-0">
                                <h4 class="font-medium text-gray-900">
                                    Payment for #<?php echo htmlspecialchars($payment['invoice_number']); ?>
                                </h4>
                                <div class="flex items-center space-x-4 mt-1 text-sm text-gray-600">
                                    <span><?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?></span>
                                    <span>•</span>
                                    <span><?php echo Helper::formatDate($payment['payment_date'], 'M j, Y'); ?></span>
                                    <?php if ($payment['payment_reference']): ?>
                                        <span>•</span>
                                        <span>Ref: <?php echo htmlspecialchars($payment['payment_reference']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($payment['notes']): ?>
                                    <p class="text-sm text-gray-600 mt-2 truncate"><?php echo htmlspecialchars($payment['notes']); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center space-x-3 ml-4">
                                <div class="text-right">
                                    <p class="font-medium text-green-600"><?php echo Helper::formatCurrency($payment['payment_amount']); ?></p>
                                    <p class="text-xs text-gray-500"><?php echo Helper::formatDate($payment['created_at'], 'M j, g:i A'); ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="text-center pt-4">
                        <a href="<?php echo Helper::baseUrl('modules/payments/?client=' . Helper::encryptId($client['id'])); ?>" 
                           class="text-blue-600 hover:text-blue-700 font-medium">
                            View All Payments →
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
/* Tab styles */
.tab-button.active {
    color: #374151;
    border-bottom-color: #374151;
}

.tab-content {
    opacity: 1;
    transition: opacity 0.3s ease-in-out;
}

.tab-content.hidden {
    display: none;
}

/* Mobile optimizations */
@media (max-width: 640px) {
    .grid.grid-cols-2.lg\:grid-cols-4 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    
    .grid.grid-cols-1.md\:grid-cols-2 {
        grid-template-columns: repeat(1, minmax(0, 1fr));
    }
    
    .flex.flex-col.sm\:flex-row {
        flex-direction: column;
    }
    
    /* Make phone numbers clickable on mobile */
    a[href^="tel:"] {
        color: #3b82f6;
        text-decoration: underline;
    }
}

/* Card hover effects */
.bg-gray-50.rounded-lg:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

/* Smooth animations */
.transition-colors {
    transition-property: color, background-color, border-color;
    transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
    transition-duration: 150ms;
}

/* Loading states */
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
// Tab functionality
function showTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.add('hidden');
    });
    
    // Remove active class from all tabs
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('active');
    });
    
    // Show selected tab content
    document.getElementById(tabName + '-content').classList.remove('hidden');
    
    // Add active class to selected tab
    document.getElementById(tabName + '-tab').classList.add('active');
    
    // Store active tab in localStorage
    localStorage.setItem('activeClientTab', tabName);
}

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    // Restore active tab from localStorage or default to projects
    const activeTab = localStorage.getItem('activeClientTab') || 'projects';
    showTab(activeTab);
    
    // Add loading states to links
    document.querySelectorAll('a[href]').forEach(link => {
        link.addEventListener('click', function() {
            if (!this.href.includes('#') && !this.href.includes('tel:')) {
                this.style.opacity = '0.7';
                this.style.pointerEvents = 'none';
                
                setTimeout(() => {
                    this.style.opacity = '';
                    this.style.pointerEvents = '';
                }, 3000);
            }
        });
    });
    
    // Auto-refresh stats every 30 seconds (optional)
    setInterval(function() {
        // In a real application, you might want to refresh stats via AJAX
        console.log('Auto-refresh would happen here');
    }, 30000);
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Press 'E' to edit client
    if (e.key.toLowerCase() === 'e' && !e.ctrlKey && !e.metaKey && !e.target.matches('input, textarea')) {
        e.preventDefault();
        window.location.href = '<?php echo Helper::baseUrl('modules/clients/edit.php?id=' . Helper::encryptId($client['id'])); ?>';
    }
    
    // Press 'N' to create new project
    if (e.key.toLowerCase() === 'n' && !e.ctrlKey && !e.metaKey && !e.target.matches('input, textarea')) {
        e.preventDefault();
        window.location.href = '<?php echo Helper::baseUrl('modules/projects/add.php?client_id=' . Helper::encryptId($client['id'])); ?>';
    }
    
    // Press 'B' to go back
    if (e.key.toLowerCase() === 'b' && !e.ctrlKey && !e.metaKey && !e.target.matches('input, textarea')) {
        e.preventDefault();
        window.location.href = '<?php echo Helper::baseUrl('modules/clients/'); ?>';
    }
    
    // Tab navigation with numbers
    if (e.key >= '1' && e.key <= '3' && !e.ctrlKey && !e.metaKey && !e.target.matches('input, textarea')) {
        e.preventDefault();
        const tabs = ['projects', 'invoices', 'payments'];
        const tabIndex = parseInt(e.key) - 1;
        if (tabs[tabIndex]) {
            showTab(tabs[tabIndex]);
        }
    }
});

// Touch gestures for mobile tab switching
let startX = 0;
let currentX = 0;
let isDragging = false;

document.addEventListener('touchstart', function(e) {
    startX = e.touches[0].clientX;
    isDragging = true;
});

document.addEventListener('touchmove', function(e) {
    if (!isDragging) return;
    currentX = e.touches[0].clientX;
});

document.addEventListener('touchend', function(e) {
    if (!isDragging) return;
    
    const diffX = currentX - startX;
    const activeTab = localStorage.getItem('activeClientTab') || 'projects';
    const tabs = ['projects', 'invoices', 'payments'];
    const currentIndex = tabs.indexOf(activeTab);
    
    // Swipe left for next tab
    if (diffX < -100 && currentIndex < tabs.length - 1) {
        showTab(tabs[currentIndex + 1]);
    }
    
    // Swipe right for previous tab
    if (diffX > 100 && currentIndex > 0) {
        showTab(tabs[currentIndex - 1]);
    }
    
    isDragging = false;
});

// Print functionality (optional)
function printClient() {
    window.print();
}

// Export client data (optional feature)
function exportClient() {
    const clientData = {
        company_name: '<?php echo addslashes($client['company_name']); ?>',
        contact_person: '<?php echo addslashes($client['contact_person']); ?>',
        mobile_number: '<?php echo addslashes($client['mobile_number']); ?>',
        address: '<?php echo addslashes($client['address']); ?>',
        city: '<?php echo addslashes($client['city']); ?>',
        stats: <?php echo json_encode($stats); ?>,
        created_at: '<?php echo $client['created_at']; ?>'
    };
    
    const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(clientData, null, 2));
    const downloadAnchorNode = document.createElement('a');
    downloadAnchorNode.setAttribute("href", dataStr);
    downloadAnchorNode.setAttribute("download", "client_<?php echo $client['id']; ?>_data.json");
    document.body.appendChild(downloadAnchorNode);
    downloadAnchorNode.click();
    downloadAnchorNode.remove();
}
</script>

<?php include '../../includes/footer.php'; ?>