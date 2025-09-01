<?php
// modules/projects/view.php
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

$pageTitle = 'Project Details - Invoice Manager';

// Get database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Get project details with client information
    $projectQuery = "
        SELECT p.*, c.company_name, c.contact_person, c.mobile_number, c.address, c.city,
               u.username as created_by_name
        FROM projects p 
        LEFT JOIN clients c ON p.client_id = c.id 
        LEFT JOIN users u ON p.created_by = u.id
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
    
    // Get project statistics
    $statsQuery = "
        SELECT 
            (SELECT COUNT(*) FROM project_items WHERE project_id = :project_id) as total_items,
            (SELECT COALESCE(SUM(quantity * unit_price), 0) FROM project_items WHERE project_id = :project_id) as items_total,
            (SELECT COUNT(*) FROM invoices WHERE project_id = :project_id) as total_invoices,
            (SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE project_id = :project_id) as total_invoiced,
            (SELECT COALESCE(SUM(paid_amount), 0) FROM invoices WHERE project_id = :project_id) as total_paid,
            (SELECT COALESCE(SUM(balance_amount), 0) FROM invoices WHERE project_id = :project_id AND status IN ('sent', 'partially_paid', 'overdue')) as outstanding_amount
    ";
    
    $statsStmt = $db->prepare($statsQuery);
    $statsStmt->bindParam(':project_id', $projectId);
    $statsStmt->execute();
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get project items (if table exists)
    $projectItems = [];
    try {
        $itemsQuery = "
            SELECT * FROM project_items 
            WHERE project_id = :project_id 
            ORDER BY created_at ASC
        ";
        $itemsStmt = $db->prepare($itemsQuery);
        $itemsStmt->bindParam(':project_id', $projectId);
        $itemsStmt->execute();
        $projectItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // project_items table might not exist yet
        $projectItems = [];
    }
    
    // Get related invoices
    $invoicesQuery = "
        SELECT * FROM invoices 
        WHERE project_id = :project_id 
        ORDER BY created_at DESC
    ";
    $invoicesStmt = $db->prepare($invoicesQuery);
    $invoicesStmt->bindParam(':project_id', $projectId);
    $invoicesStmt->execute();
    $relatedInvoices = $invoicesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent payments for this project
    $paymentsQuery = "
        SELECT pa.*, i.invoice_number
        FROM payments pa
        JOIN invoices i ON pa.invoice_id = i.id
        WHERE i.project_id = :project_id 
        ORDER BY pa.created_at DESC 
        LIMIT 5
    ";
    $paymentsStmt = $db->prepare($paymentsQuery);
    $paymentsStmt->bindParam(':project_id', $projectId);
    $paymentsStmt->execute();
    $relatedPayments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    Helper::setMessage('Error loading project details: ' . $e->getMessage(), 'error');
    Helper::redirect('modules/projects/');
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
        <a href="<?php echo Helper::baseUrl('modules/clients/view.php?id=' . Helper::encryptId($project['client_id'])); ?>" 
           class="hover:text-gray-900 transition-colors">
            <?php echo htmlspecialchars($project['company_name']); ?>
        </a>
        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
        </svg>
        <span class="text-gray-900 font-medium truncate"><?php echo htmlspecialchars($project['project_name']); ?></span>
    </div>
</nav>

<!-- Project Header -->
<div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div class="flex-1 min-w-0">
            <!-- Project Title -->
            <div class="mb-4">
                <div class="flex flex-col sm:flex-row sm:items-start gap-3 mb-2">
                    <div class="flex-1 min-w-0">
                        <h1 class="text-xl sm:text-2xl font-bold text-gray-900 leading-tight break-words">
                            <?php echo htmlspecialchars($project['project_name']); ?>
                        </h1>
                    </div>
                    <div class="flex-shrink-0">
                        <?php 
                        // Enhanced status badge
                        $status = $project['status'];
                        $statusClasses = [
                            'pending' => 'bg-yellow-100 text-yellow-800 border-yellow-200',
                            'in_progress' => 'bg-blue-100 text-blue-800 border-blue-200',
                            'completed' => 'bg-green-100 text-green-800 border-green-200',
                            'on_hold' => 'bg-gray-100 text-gray-800 border-gray-200',
                            'cancelled' => 'bg-red-100 text-red-800 border-red-200'
                        ];
                        $badgeClass = $statusClasses[$status] ?? 'bg-gray-100 text-gray-800 border-gray-200';
                        
                        $statusIcons = [
                            'pending' => '<svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path></svg>',
                            'in_progress' => '<svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>',
                            'completed' => '<svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg>',
                            'on_hold' => '<svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zM7 8a1 1 0 012 0v4a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v4a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>',
                            'cancelled' => '<svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>'
                        ];
                        $statusIcon = $statusIcons[$status] ?? '';
                        ?>
                        <span class="inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-semibold border <?php echo $badgeClass; ?> shadow-sm">
                            <?php echo $statusIcon; ?>
                            <?php echo ucwords(str_replace('_', ' ', $status)); ?>
                        </span>
                    </div>
                </div>
                <p class="text-base sm:text-lg text-gray-600">
                    <?php echo ucwords(str_replace('_', ' ', $project['project_type'])); ?> Project
                </p>
            </div>

            <!-- Client Info -->
            <div class="flex items-center p-4 bg-blue-50 border border-blue-200 rounded-lg mb-4">
                <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-2m-2 0H5m14 0V9a2 2 0 00-2-2H9a2 2 0 00-2 2v12a2 2 0 002 2h4m0 0V9a2 2 0 012-2h2a2 2 0 012 2v12"></path>
                    </svg>
                </div>
                <div class="ml-3 flex-1 min-w-0">
                    <p class="font-medium text-gray-900">
                        <a href="<?php echo Helper::baseUrl('modules/clients/view.php?id=' . Helper::encryptId($project['client_id'])); ?>" 
                           class="hover:text-blue-600 transition-colors">
                            <?php echo htmlspecialchars($project['company_name']); ?>
                        </a>
                    </p>
                    <p class="text-sm text-gray-600"><?php echo htmlspecialchars($project['contact_person']); ?></p>
                    <p class="text-sm text-gray-600"><?php echo htmlspecialchars($project['mobile_number']); ?></p>
                </div>
            </div>

            <!-- Project Info Grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div class="flex items-start space-x-3">
                    <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-900">Start Date</p>
                        <p class="text-gray-600">
                            <?php echo $project['start_date'] ? Helper::formatDate($project['start_date'], 'M j, Y') : 'Not set'; ?>
                        </p>
                    </div>
                </div>

                <div class="flex items-start space-x-3">
                    <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-900">End Date</p>
                        <p class="text-gray-600">
                            <?php echo $project['end_date'] ? Helper::formatDate($project['end_date'], 'M j, Y') : 'Not set'; ?>
                        </p>
                    </div>
                </div>

                <div class="flex items-start space-x-3">
                    <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-900">Total Amount</p>
                        <p class="text-gray-600 font-medium">
                            <?php echo Helper::formatCurrency($project['total_amount']); ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Project Description -->
            <?php if (!empty($project['description'])): ?>
                <div class="mb-4">
                    <h3 class="text-sm font-medium text-gray-900 mb-2">Description</h3>
                    <div class="text-gray-600 whitespace-pre-line p-3 bg-gray-50 rounded-lg">
                        <?php echo htmlspecialchars($project['description']); ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Project Meta -->
            <div class="text-sm text-gray-500 pt-4 border-t border-gray-100">
                Created <?php echo Helper::formatDate($project['created_at'], 'F j, Y \a\t g:i A'); ?>
                <?php if ($project['created_by_name']): ?>
                    by <?php echo htmlspecialchars($project['created_by_name']); ?>
                <?php endif; ?>
                <?php if ($project['updated_at'] !== $project['created_at']): ?>
                    • Last updated <?php echo Helper::formatDate($project['updated_at'], 'M j, Y \a\t g:i A'); ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Desktop Actions -->
        <div class="hidden sm:flex flex-row gap-3 flex-shrink-0">
            <a href="<?php echo Helper::baseUrl('modules/projects/edit.php?id=' . Helper::encryptId($project['id'])); ?>" 
               class="inline-flex items-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                </svg>
                Edit Project
            </a>
            <a href="<?php echo Helper::baseUrl('modules/invoices/create.php?project_id=' . Helper::encryptId($project['id'])); ?>" 
               class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                Create Invoice
            </a>
            <a href="<?php echo Helper::baseUrl('modules/projects/items.php?project_id=' . Helper::encryptId($project['id'])); ?>" 
               class="inline-flex items-center px-4 py-2 bg-white text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Manage Items
            </a>
        </div>
    </div>

    <!-- Mobile Actions - Separate Row -->
    <div class="sm:hidden mt-4 pt-4 border-t border-gray-200">
        <div class="grid grid-cols-1 gap-3">
            <a href="<?php echo Helper::baseUrl('modules/projects/edit.php?id=' . Helper::encryptId($project['id'])); ?>" 
               class="flex items-center justify-center px-4 py-3 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors text-sm font-medium">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                </svg>
                Edit Project
            </a>
            <a href="<?php echo Helper::baseUrl('modules/invoices/create.php?project_id=' . Helper::encryptId($project['id'])); ?>" 
               class="flex items-center justify-center px-4 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                Create Invoice
            </a>
            <a href="<?php echo Helper::baseUrl('modules/projects/items.php?project_id=' . Helper::encryptId($project['id'])); ?>" 
               class="flex items-center justify-center px-4 py-3 bg-white text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors text-sm font-medium">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Manage Items
            </a>
        </div>
    </div>

    </div>
</div>

<!-- Statistics Grid -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <!-- Total Items -->
    <div class="bg-white rounded-xl p-4 border border-gray-200">
        <div class="flex items-center">
            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
            </div>
        </div>
        <div class="mt-3">
            <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['total_items']); ?></p>
            <p class="text-sm text-gray-600">Project Items</p>
        </div>
    </div>

    <!-- Items Total -->
    <div class="bg-white rounded-xl p-4 border border-gray-200">
        <div class="flex items-center">
            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
        </div>
        <div class="mt-3">
            <p class="text-xl font-bold text-gray-900"><?php echo Helper::formatCurrency($stats['items_total']); ?></p>
            <p class="text-sm text-gray-600">Items Value</p>
        </div>
    </div>

    <!-- Total Invoiced -->
    <div class="bg-white rounded-xl p-4 border border-gray-200">
        <div class="flex items-center">
            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path>
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
            <button onclick="showTab('items')" 
                    class="tab-button flex-shrink-0 px-4 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300 transition-colors whitespace-nowrap active"
                    id="items-tab">
                <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
                Items (<?php echo $stats['total_items']; ?>)
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
        <!-- Items Tab -->
        <div id="items-content" class="tab-content">
            <?php if (empty($projectItems)): ?>
                <div class="text-center py-8">
                    <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                    </svg>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No project items yet</h3>
                    <p class="text-gray-600 mb-4">Add items to track work components and pricing.</p>
                    <a href="<?php echo Helper::baseUrl('modules/projects/items.php?project_id=' . Helper::encryptId($project['id'])); ?>" 
                       class="inline-flex items-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Add First Item
                    </a>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($projectItems as $item): ?>
                        <div class="p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                            <!-- Mobile and Desktop Layout -->
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                <div class="flex-1 min-w-0">
                                    <h4 class="font-medium text-gray-900 truncate mb-2">
                                        <?php echo htmlspecialchars($item['item_name']); ?>
                                    </h4>
                                    <!-- Mobile: Stack info vertically -->
                                    <div class="space-y-2 sm:space-y-0 sm:flex sm:items-center sm:space-x-4 text-sm text-gray-600">
                                        <div class="flex items-center space-x-4">
                                            <span class="font-medium">Qty: <?php echo number_format($item['quantity']); ?></span>
                                            <span class="hidden sm:inline">•</span>
                                            <span class="font-medium">Unit: <?php echo Helper::formatCurrency($item['unit_price']); ?></span>
                                        </div>
                                        <?php if (!empty($item['description'])): ?>
                                            <div class="sm:flex sm:items-center">
                                                <span class="hidden sm:inline sm:mr-4">•</span>
                                                <span class="block sm:inline text-gray-500 text-xs sm:text-sm break-words">
                                                    <?php echo htmlspecialchars($item['description']); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <!-- Total Amount - Mobile: Full width, Desktop: Right aligned -->
                                <div class="flex justify-between items-center sm:block sm:text-right sm:ml-4">
                                    <span class="text-sm text-gray-500 sm:hidden">Total:</span>
                                    <div>
                                        <p class="font-bold text-lg sm:text-base text-gray-900">
                                            <?php echo Helper::formatCurrency($item['quantity'] * $item['unit_price']); ?>
                                        </p>
                                        <p class="text-xs text-gray-500 hidden sm:block">Total</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="text-center pt-4">
                        <a href="<?php echo Helper::baseUrl('modules/projects/items.php?project_id=' . Helper::encryptId($project['id'])); ?>" 
                           class="text-blue-600 hover:text-blue-700 font-medium">
                            Manage All Items →
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Invoices Tab -->
        <div id="invoices-content" class="tab-content hidden">
            <?php if (empty($relatedInvoices)): ?>
                <div class="text-center py-8">
                    <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No invoices yet</h3>
                    <p class="text-gray-600 mb-4">Create an invoice for this project to bill the client.</p>
                    <a href="<?php echo Helper::baseUrl('modules/invoices/create.php?project_id=' . Helper::encryptId($project['id'])); ?>" 
                       class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Create First Invoice
                    </a>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($relatedInvoices as $invoice): ?>
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                            <div class="flex-1 min-w-0">
                                <h4 class="font-medium text-gray-900 truncate">
                                    <a href="<?php echo Helper::baseUrl('modules/invoices/view.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
                                       class="hover:text-blue-600 transition-colors">
                                        #<?php echo htmlspecialchars($invoice['invoice_number']); ?>
                                    </a>
                                </h4>
                                <div class="flex items-center space-x-4 mt-1 text-sm text-gray-600">
                                    <span>Due <?php echo Helper::formatDate($invoice['due_date'], 'M j, Y'); ?></span>
                                    <span>•</span>
                                    <span>Paid <?php echo Helper::formatCurrency($invoice['paid_amount']); ?></span>
                                    <?php if ($invoice['balance_amount'] > 0): ?>
                                        <span>•</span>
                                        <span class="text-red-600">Balance <?php echo Helper::formatCurrency($invoice['balance_amount']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="flex items-center space-x-3 ml-4">
                                <div class="text-right">
                                    <p class="font-medium text-gray-900"><?php echo Helper::formatCurrency($invoice['total_amount']); ?></p>
                                </div>
                                <?php echo Helper::statusBadge($invoice['status']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="text-center pt-4">
                        <a href="<?php echo Helper::baseUrl('modules/invoices/?project=' . Helper::encryptId($project['id'])); ?>" 
                           class="text-blue-600 hover:text-blue-700 font-medium mr-4">
                            View All Invoices →
                        </a>
                        <a href="<?php echo Helper::baseUrl('modules/invoices/create.php?project_id=' . Helper::encryptId($project['id'])); ?>" 
                           class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                            New Invoice
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Payments Tab -->
        <div id="payments-content" class="tab-content hidden">
            <?php if (empty($relatedPayments)): ?>
                <div class="text-center py-8">
                    <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v2a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No payments yet</h3>
                    <p class="text-gray-600">Payments will appear here once invoices are paid.</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($relatedPayments as $payment): ?>
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
                        <a href="<?php echo Helper::baseUrl('modules/payments/?project=' . Helper::encryptId($project['id'])); ?>" 
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
.tab-button {
    cursor: pointer;
    transition: all 0.3s ease;
}

.tab-button:hover {
    color: #374151 !important;
    border-bottom-color: #9ca3af !important;
}

.tab-button.active {
    color: #374151 !important;
    border-bottom-color: #374151 !important;
    font-weight: 600;
}

.tab-content {
    opacity: 1;
    transition: opacity 0.3s ease-in-out;
}

.tab-content.hidden {
    display: none !important;
}

/* Mobile optimizations */
@media (max-width: 640px) {
    .grid.grid-cols-2.lg\:grid-cols-4 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    
    .grid.grid-cols-1.md\:grid-cols-3 {
        grid-template-columns: repeat(1, minmax(0, 1fr));
    }
    
    .flex.flex-col.sm\:flex-row {
        flex-direction: column;
    }
}

/* Card hover effects */
.bg-gray-50.rounded-lg:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

/* Progress bar for project timeline */
.project-progress {
    background: linear-gradient(90deg, #10b981 0%, #10b981 var(--progress), #e5e7eb var(--progress), #e5e7eb 100%);
    height: 4px;
    border-radius: 2px;
}

/* Smooth animations */
.transition-colors {
    transition-property: color, background-color, border-color;
    transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
    transition-duration: 150ms;
}
</style>

<script>
// Define showTab function in global scope immediately
window.showTab = function(tabName) {
    try {
        // Hide all tab contents
        const tabContents = document.querySelectorAll('.tab-content');
        tabContents.forEach(content => {
            content.classList.add('hidden');
        });
        
        // Remove active class from all tabs
        const tabButtons = document.querySelectorAll('.tab-button');
        tabButtons.forEach(button => {
            button.classList.remove('active');
        });
        
        // Show selected tab content
        const tabContent = document.getElementById(tabName + '-content');
        if (tabContent) {
            tabContent.classList.remove('hidden');
        }
        
        // Add active class to selected tab
        const tabButton = document.getElementById(tabName + '-tab');
        if (tabButton) {
            tabButton.classList.add('active');
        }
        
        // Store active tab in localStorage
        localStorage.setItem('activeProjectTab', tabName);
        
    } catch (error) {
        console.error('Error in showTab function:', error);
    }
};

// Ensure showTab is also available as a regular function
function showTab(tabName) {
    return window.showTab(tabName);
}

// Test function availability immediately
// Functions are now available globally

// Debug function - call debugTabs() in browser console to test
window.debugTabs = function() {
    console.log('=== TAB DEBUG ===');
    console.log('Available tabs:', ['items', 'invoices', 'payments']);
    console.log('showTab function type:', typeof window.showTab);
    
    ['items', 'invoices', 'payments'].forEach(tabName => {
        const button = document.getElementById(tabName + '-tab');
        const content = document.getElementById(tabName + '-content');
        
        console.log(`${tabName}:`);
        console.log(`  Button exists:`, !!button);
        console.log(`  Content exists:`, !!content);
        if (content) {
            console.log(`  Content hidden:`, content.classList.contains('hidden'));
        }
    });
    
    console.log('=== END DEBUG ===');
    
    // Test switching to invoices tab
    console.log('Testing invoices tab...');
    window.showTab('invoices');
};

// Manual tab switching functions for testing
window.showItemsTab = function() { window.showTab('items'); };
window.showInvoicesTab = function() { window.showTab('invoices'); };
window.showPaymentsTab = function() { window.showTab('payments'); };

// Calculate project progress based on dates
function calculateProgress() {
    const startDate = new Date('<?php echo $project['start_date']; ?>');
    const endDate = new Date('<?php echo $project['end_date']; ?>');
    const currentDate = new Date();
    
    if (startDate && endDate && currentDate >= startDate) {
        const totalDuration = endDate - startDate;
        const elapsed = currentDate - startDate;
        const progress = Math.min(Math.max((elapsed / totalDuration) * 100, 0), 100);
        
        // Update progress bars if they exist
        document.querySelectorAll('.project-progress').forEach(bar => {
            bar.style.setProperty('--progress', progress + '%');
        });
        
        return progress;
    }
    return 0;
}

// Initialize page - simpler approach
document.addEventListener('DOMContentLoaded', function() {
    // Test if showTab is available
    if (typeof showTab !== 'function') {
        console.error('showTab function not available!');
        return;
    }
    
    // Restore active tab from localStorage or default to items
    const activeTab = localStorage.getItem('activeProjectTab') || 'items';
    
    // Initialize tabs immediately
    showTab(activeTab);
    
    // Add event listeners as backup
    document.querySelectorAll('.tab-button').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const tabName = this.id.replace('-tab', '');
            showTab(tabName);
        });
    });
    
    // Calculate progress
    calculateProgress();
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
    
    // Force show items tab if nothing is visible
    setTimeout(() => {
        const visibleTabs = document.querySelectorAll('.tab-content:not(.hidden)');
        if (visibleTabs.length === 0) {
            showTab('items');
        }
    }, 500);
    
    // Auto-refresh stats every 30 seconds (optional)
    setInterval(function() {
        // In a real application, you might want to refresh stats via AJAX
    }, 30000);
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Press 'E' to edit project
    if (e.key.toLowerCase() === 'e' && !e.ctrlKey && !e.metaKey && !e.target.matches('input, textarea')) {
        e.preventDefault();
        window.location.href = '<?php echo Helper::baseUrl('modules/projects/edit.php?id=' . Helper::encryptId($project['id'])); ?>';
    }
    
    // Press 'I' to create invoice
    if (e.key.toLowerCase() === 'i' && !e.ctrlKey && !e.metaKey && !e.target.matches('input, textarea')) {
        e.preventDefault();
        window.location.href = '<?php echo Helper::baseUrl('modules/invoices/create.php?project_id=' . Helper::encryptId($project['id'])); ?>';
    }
    
    // Press 'M' to manage items
    if (e.key.toLowerCase() === 'm' && !e.ctrlKey && !e.metaKey && !e.target.matches('input, textarea')) {
        e.preventDefault();
        window.location.href = '<?php echo Helper::baseUrl('modules/projects/items.php?project_id=' . Helper::encryptId($project['id'])); ?>';
    }
    
    // Press 'B' to go back
    if (e.key.toLowerCase() === 'b' && !e.ctrlKey && !e.metaKey && !e.target.matches('input, textarea')) {
        e.preventDefault();
        window.location.href = '<?php echo Helper::baseUrl('modules/projects/'); ?>';
    }
    
    // Tab navigation with numbers
    if (e.key >= '1' && e.key <= '3' && !e.ctrlKey && !e.metaKey && !e.target.matches('input, textarea')) {
        e.preventDefault();
        const tabs = ['items', 'invoices', 'payments'];
        const tabIndex = parseInt(e.key) - 1;
        if (tabs[tabIndex]) {
            showTab(tabs[tabIndex]);
        }
    }
});

// Touch gesture support for mobile tab switching
(function() {
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
        const activeTab = localStorage.getItem('activeProjectTab') || 'items';
        const tabs = ['items', 'invoices', 'payments'];
        const currentIndex = tabs.indexOf(activeTab);
        
        // Swipe left for next tab
        if (diffX < -100 && currentIndex < tabs.length - 1) {
            window.showTab(tabs[currentIndex + 1]);
        }
        
        // Swipe right for previous tab
        if (diffX > 100 && currentIndex > 0) {
            window.showTab(tabs[currentIndex - 1]);
        }
        
        isDragging = false;
    });
})();

// Update project status with visual feedback
function updateProjectStatus(newStatus) {
    // This would typically be an AJAX call
    console.log('Updating project status to:', newStatus);
    
    // Show visual feedback
    const statusBadge = document.querySelector('.status-badge');
    if (statusBadge) {
        statusBadge.style.opacity = '0.5';
        statusBadge.textContent = 'Updating...';
        
        // Simulate API call
        setTimeout(() => {
            location.reload();
        }, 1000);
    }
}

// Print functionality
function printProject() {
    window.print();
}

// Export project data
function exportProject() {
    const projectData = {
        project_name: '<?php echo addslashes($project['project_name']); ?>',
        project_type: '<?php echo addslashes($project['project_type']); ?>',
        status: '<?php echo addslashes($project['status']); ?>',
        client: '<?php echo addslashes($project['company_name']); ?>',
        start_date: '<?php echo $project['start_date']; ?>',
        end_date: '<?php echo $project['end_date']; ?>',
        total_amount: <?php echo $project['total_amount']; ?>,
        stats: <?php echo json_encode($stats); ?>,
        created_at: '<?php echo $project['created_at']; ?>'
    };
    
    const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(projectData, null, 2));
    const downloadAnchorNode = document.createElement('a');
    downloadAnchorNode.setAttribute("href", dataStr);
    downloadAnchorNode.setAttribute("download", "project_<?php echo $project['id']; ?>_data.json");
    document.body.appendChild(downloadAnchorNode);
    downloadAnchorNode.click();
    downloadAnchorNode.remove();
}

// Real-time status updates (WebSocket placeholder)
// In a real application, you might use WebSockets for real-time updates
function connectWebSocket() {
    // WebSocket connection would go here
    console.log('WebSocket connection would be established here');
}
</script>

<?php include '../../includes/footer.php'; ?>
