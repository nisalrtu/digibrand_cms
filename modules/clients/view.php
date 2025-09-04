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
    
    // Get projects for initial display (first 10)
    $projectsQuery = "
        SELECT p.*, 
               (SELECT COUNT(*) FROM project_items WHERE project_id = p.id) as items_count
        FROM projects p 
        WHERE p.client_id = :client_id 
        ORDER BY p.created_at DESC 
        LIMIT 10
    ";
    $projectsStmt = $db->prepare($projectsQuery);
    $projectsStmt->bindParam(':client_id', $clientId);
    $projectsStmt->execute();
    $recentProjects = $projectsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all projects for "Show All" functionality
    $allProjectsQuery = "
        SELECT p.*, 
               (SELECT COUNT(*) FROM project_items WHERE project_id = p.id) as items_count
        FROM projects p 
        WHERE p.client_id = :client_id 
        ORDER BY p.created_at DESC
    ";
    $allProjectsStmt = $db->prepare($allProjectsQuery);
    $allProjectsStmt->bindParam(':client_id', $clientId);
    $allProjectsStmt->execute();
    $allProjects = $allProjectsStmt->fetchAll(PDO::FETCH_ASSOC);
    
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

<!-- Mobile-First Responsive Styles -->
<style>
/* Base mobile-first styles */
* {
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

/* Mobile-optimized container */
.mobile-container {
    padding: 1rem;
    max-width: 100vw;
    overflow-x: hidden;
}

/* Mobile-first accordion styles */
.accordion-section {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 0.75rem;
    margin-bottom: 1rem;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

/* Projects section styling (non-accordion) */
.projects-section {
    margin-bottom: 1rem;
}

.section-header h2 {
    font-size: 1.125rem;
    font-weight: 600;
    color: #111827;
    margin: 0;
}

.projects-container {
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.accordion-header {
    padding: 1rem 1.25rem;
    background: #f8fafc;
    border-bottom: 1px solid #e5e7eb;
    cursor: pointer;
    user-select: none;
    -webkit-tap-highlight-color: transparent;
    transition: background-color 0.2s ease;
    min-height: 60px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.accordion-header:active {
    background: #e2e8f0;
}

.accordion-header.active {
    background: #dbeafe;
    border-bottom-color: #3b82f6;
}

.accordion-content {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease-out;
}

.accordion-content.active {
    max-height: 2000px;
    transition: max-height 0.3s ease-in;
}

.accordion-icon {
    transition: transform 0.3s ease;
    flex-shrink: 0;
}

.accordion-header.active .accordion-icon {
    transform: rotate(180deg);
}

/* Mobile-optimized statistics grid */
.stats-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
    margin-bottom: 1.5rem;
}

@media (min-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(4, 1fr);
        gap: 1rem;
    }
}

.stat-card {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 0.75rem;
    padding: 1rem;
    text-align: center;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.stat-card:active {
    transform: scale(0.98);
}

/* Mobile-optimized client header */
.client-header {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 0.75rem;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.client-info-grid {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 0.75rem;
    align-items: start;
    margin-bottom: 1rem;
}

.client-info-icon {
    width: 40px;
    height: 40px;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

/* Mobile-friendly buttons */
.button-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 0.75rem;
    margin-top: 1rem;
}

@media (min-width: 640px) {
    .button-grid {
        grid-template-columns: 1fr 1fr;
    }
}

.mobile-button {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0.875rem 1rem;
    border-radius: 0.5rem;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.2s ease;
    min-height: 48px;
    font-size: 0.875rem;
    border: none;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
}

.mobile-button:active {
    transform: scale(0.98);
}

.mobile-button.primary {
    background: #1f2937;
    color: white;
}

.mobile-button.primary:hover {
    background: #374151;
}

.mobile-button.secondary {
    background: white;
    color: #374151;
    border: 1px solid #d1d5db;
}

.mobile-button.secondary:hover {
    background: #f9fafb;
}

/* Mobile-optimized list items */
.list-item {
    padding: 1rem;
    border-bottom: 1px solid #f3f4f6;
    background: white;
    transition: background-color 0.2s ease;
    -webkit-tap-highlight-color: transparent;
}

.list-item:last-child {
    border-bottom: none;
}

.list-item:active {
    background: #f8fafc;
}

.list-item-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.5rem;
}

.list-item-title {
    font-weight: 500;
    color: #111827;
    font-size: 0.875rem;
    line-height: 1.25;
}

.list-item-meta {
    font-size: 0.75rem;
    color: #6b7280;
    margin-top: 0.25rem;
    line-height: 1.2;
}

.list-item-amount {
    font-weight: 600;
    color: #111827;
    font-size: 0.875rem;
    text-align: right;
    flex-shrink: 0;
    margin-left: 1rem;
}

/* Status badges optimized for mobile */
.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.5rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 500;
    text-transform: capitalize;
}

.status-pending {
    background: #fef3c7;
    color: #92400e;
}

.status-in-progress {
    background: #dbeafe;
    color: #1e40af;
}

.status-completed {
    background: #d1fae5;
    color: #065f46;
}

.status-draft {
    background: #f3f4f6;
    color: #374151;
}

.status-sent {
    background: #dbeafe;
    color: #1e40af;
}

.status-paid {
    background: #d1fae5;
    color: #065f46;
}

.status-partially-paid {
    background: #fed7aa;
    color: #c2410c;
}

.status-overdue {
    background: #fecaca;
    color: #b91c1c;
}

/* Empty state styling */
.empty-state {
    text-align: center;
    padding: 2rem 1rem;
    color: #6b7280;
}

.empty-state-icon {
    width: 3rem;
    height: 3rem;
    margin: 0 auto 1rem;
    color: #d1d5db;
}

.empty-state h3 {
    font-weight: 500;
    color: #111827;
    margin-bottom: 0.5rem;
}

.empty-state p {
    font-size: 0.875rem;
    margin-bottom: 1rem;
}

/* Responsive typography */
@media (min-width: 640px) {
    .mobile-container {
        padding: 1.5rem;
    }
    
    .client-header {
        padding: 1.5rem;
    }
    
    .accordion-header {
        padding: 1.25rem 1.5rem;
    }
    
    .list-item {
        padding: 1.25rem;
    }
}

/* Performance optimizations */
.accordion-content {
    will-change: max-height;
}

.mobile-button {
    will-change: transform;
}

/* High contrast mode support */
@media (prefers-contrast: high) {
    .accordion-header {
        border-bottom-width: 2px;
    }
    
    .list-item {
        border-bottom-width: 2px;
    }
}

/* Reduced motion support */
@media (prefers-reduced-motion: reduce) {
    .accordion-content,
    .accordion-icon,
    .mobile-button,
    .stat-card {
        transition: none;
    }
}

/* Touch-friendly improvements for iOS */
@supports (-webkit-touch-callout: none) {
    .mobile-button {
        -webkit-touch-callout: none;
    }
}

/* Focus styles for accessibility */
.accordion-header:focus,
.mobile-button:focus,
button:focus {
    outline: 2px solid #3b82f6;
    outline-offset: 2px;
}

/* Show All button styling */
#show-all-projects-btn button {
    transition: all 0.2s ease;
    padding: 0.75rem 1rem;
    border-radius: 0.5rem;
    width: 100%;
}

#show-all-projects-btn button:hover {
    background-color: #f8fafc;
    transform: translateY(-1px);
}

#show-all-projects-btn button:active {
    transform: translateY(0) scale(0.98);
}

/* Load More button styling */
#load-more-btn {
    transition: all 0.2s ease;
}

#load-more-btn:hover:not(:disabled) {
    background-color: #f8fafc;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

#load-more-btn:active:not(:disabled) {
    transform: translateY(0) scale(0.98);
}

#load-more-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

/* Smooth transitions for project list */
#all-projects-list {
    transition: all 0.3s ease-in-out;
}

#additional-projects-list .project-item {
    animation: slideInUp 0.3s ease-out;
}

@keyframes slideInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

#all-projects-list.showing {
    animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
    from {
        opacity: 0;
        max-height: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        max-height: 1000px;
        transform: translateY(0);
    }
}

/* Loading states */
.loading {
    opacity: 0.6;
    pointer-events: none;
}

/* Smooth scroll behavior */
html {
    scroll-behavior: smooth;
}

/* Print styles */
@media print {
    .mobile-container {
        padding: 0;
    }
    
    .accordion-content {
        max-height: none !important;
    }
    
    .accordion-header {
        background: white;
    }
}
</style>

<div class="mobile-container">
    <!-- Breadcrumb -->
    <nav class="mb-4">
        <div class="flex items-center space-x-2 text-sm text-gray-600">
            <a href="<?php echo Helper::baseUrl('modules/clients/'); ?>" class="hover:text-gray-900 transition-colors">
                <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
                Clients
            </a>
            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
            <span class="text-gray-900 font-medium"><?php echo htmlspecialchars($client['company_name']); ?></span>
        </div>
    </nav>

    <!-- Client Header -->
    <div class="client-header">
        <div class="mb-4">
            <h1 class="text-xl font-bold text-gray-900 mb-1">
                <?php echo htmlspecialchars($client['company_name']); ?>
            </h1>
            <p class="text-base text-gray-600">
                <?php echo htmlspecialchars($client['contact_person']); ?>
            </p>
        </div>

        <!-- Client Information -->
        <div class="space-y-3 mb-4">
            <div class="client-info-grid">
                <div class="client-info-icon bg-blue-100">
                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-900">Phone</p>
                    <a href="tel:<?php echo htmlspecialchars($client['mobile_number']); ?>" 
                       class="text-blue-600 text-sm">
                        <?php echo htmlspecialchars($client['mobile_number']); ?>
                    </a>
                </div>
            </div>

            <div class="client-info-grid">
                <div class="client-info-icon bg-green-100">
                    <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-900">City</p>
                    <p class="text-gray-600 text-sm"><?php echo htmlspecialchars($client['city']); ?></p>
                </div>
            </div>

            <div class="client-info-grid">
                <div class="client-info-icon bg-purple-100">
                    <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-900">Address</p>
                    <p class="text-gray-600 text-sm whitespace-pre-line"><?php echo htmlspecialchars($client['address']); ?></p>
                </div>
            </div>
        </div>

        <!-- Client Meta -->
        <div class="text-xs text-gray-500 py-3 border-t border-gray-100">
            Client since <?php echo Helper::formatDate($client['created_at'], 'F j, Y'); ?>
            <?php if ($client['updated_at'] !== $client['created_at']): ?>
                • Last updated <?php echo Helper::formatDate($client['updated_at'], 'M j, Y'); ?>
            <?php endif; ?>
        </div>

        <!-- Action Buttons -->
        <div class="button-grid">
            <a href="<?php echo Helper::baseUrl('modules/clients/edit.php?id=' . Helper::encryptId($client['id'])); ?>" 
               class="mobile-button primary">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                </svg>
                Edit Client
            </a>
            <a href="<?php echo Helper::baseUrl('modules/projects/add.php?client_id=' . Helper::encryptId($client['id'])); ?>" 
               class="mobile-button secondary">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                New Project
            </a>
        </div>
    </div>

    <!-- Statistics Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mx-auto mb-2">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                </svg>
            </div>
            <p class="text-xl font-bold text-gray-900"><?php echo number_format($stats['total_projects']); ?></p>
            <p class="text-sm text-gray-600">Projects</p>
        </div>

        <div class="stat-card">
            <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center mx-auto mb-2">
                <svg class="w-5 h-5 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <p class="text-xl font-bold text-gray-900"><?php echo number_format($stats['active_projects']); ?></p>
            <p class="text-sm text-gray-600">Active</p>
        </div>

        <div class="stat-card">
            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center mx-auto mb-2">
                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
            </div>
            <p class="text-lg font-bold text-gray-900"><?php echo Helper::formatCurrency($stats['total_invoiced']); ?></p>
            <p class="text-sm text-gray-600">Invoiced</p>
        </div>

        <div class="stat-card">
            <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center mx-auto mb-2">
                <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <p class="text-lg font-bold text-gray-900"><?php echo Helper::formatCurrency($stats['outstanding_amount']); ?></p>
            <p class="text-sm text-gray-600">Outstanding</p>
        </div>
    </div>

    <!-- Accordion Sections -->
    
    <!-- Projects Section -->
    <div class="projects-section">
        <div class="section-header">
            <div class="flex items-center mb-4">
                <svg class="w-5 h-5 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                </svg>
                <h2 class="text-lg font-semibold text-gray-900">Projects (<?php echo $stats['total_projects']; ?>)</h2>
            </div>
        </div>
        
        <div class="projects-container bg-white border border-gray-200 rounded-lg overflow-hidden">
            <?php if (empty($recentProjects)): ?>
                <div class="empty-state">
                    <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                    </svg>
                    <h3>No projects yet</h3>
                    <p>Start by creating a project for this client.</p>
                    <a href="<?php echo Helper::baseUrl('modules/projects/add.php?client_id=' . Helper::encryptId($client['id'])); ?>" 
                       class="mobile-button primary" style="width: auto; margin: 0 auto; min-width: 200px;">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Create First Project
                    </a>
                </div>
            <?php else: ?>
                <div id="projects-list">
                    <?php foreach ($recentProjects as $project): ?>
                        <div class="list-item project-item">
                            <div class="list-item-header">
                                <div class="flex-1">
                                    <a href="<?php echo Helper::baseUrl('modules/projects/view.php?id=' . Helper::encryptId($project['id'])); ?>" 
                                       class="list-item-title hover:text-blue-600">
                                        <?php echo htmlspecialchars($project['project_name']); ?>
                                    </a>
                                    <div class="list-item-meta">
                                        <?php echo ucwords(str_replace('_', ' ', $project['project_type'])); ?>
                                        • <?php echo $project['items_count']; ?> items
                                        • <?php echo Helper::formatDate($project['created_at'], 'M j, Y'); ?>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <div class="text-right">
                                        <div class="list-item-amount"><?php echo Helper::formatCurrency($project['total_amount']); ?></div>
                                    </div>
                                    <span class="status-badge status-<?php echo str_replace('_', '-', $project['status']); ?>">
                                        <?php echo ucwords(str_replace('_', ' ', $project['status'])); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Container for additional projects loaded via "Load More" -->
                <div id="additional-projects-list"></div>
                
                <?php if ($stats['total_projects'] > 10): ?>
                    <div class="text-center p-4 border-t border-gray-100">
                        <button onclick="loadMoreProjects()" 
                                id="load-more-btn"
                                class="mobile-button secondary" style="width: auto; min-width: 150px;">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                            </svg>
                            <span id="load-more-text">Load More Projects</span>
                            <span id="loading-text" style="display: none;">Loading...</span>
                        </button>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Invoices Section -->
    <div class="accordion-section">
        <div class="accordion-header" onclick="toggleAccordion('invoices')" id="invoices-header">
            <div class="flex items-center">
                <svg class="w-5 h-5 text-purple-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <span class="font-medium">Invoices (<?php echo $stats['total_invoices']; ?>)</span>
            </div>
            <svg class="w-5 h-5 text-gray-400 accordion-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
        </div>
        <div class="accordion-content" id="invoices-content">
            <?php if (empty($recentInvoices)): ?>
                <div class="empty-state">
                    <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <h3>No invoices yet</h3>
                    <p>Create your first invoice for this client.</p>
                    <a href="<?php echo Helper::baseUrl('modules/invoices/create.php?client_id=' . Helper::encryptId($client['id'])); ?>" 
                       class="mobile-button primary" style="width: auto; margin: 0 auto; min-width: 200px;">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Create First Invoice
                    </a>
                </div>
            <?php else: ?>
                <?php foreach ($recentInvoices as $invoice): ?>
                    <div class="list-item">
                        <div class="list-item-header">
                            <div class="flex-1">
                                <a href="<?php echo Helper::baseUrl('modules/invoices/view.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
                                   class="list-item-title hover:text-blue-600">
                                    #<?php echo htmlspecialchars($invoice['invoice_number']); ?>
                                </a>
                                <div class="list-item-meta">
                                    <?php if ($invoice['project_name']): ?>
                                        <?php echo htmlspecialchars($invoice['project_name']); ?>
                                        • 
                                    <?php endif; ?>
                                    Due <?php echo Helper::formatDate($invoice['due_date'], 'M j, Y'); ?>
                                    • Paid <?php echo Helper::formatCurrency($invoice['paid_amount']); ?>
                                </div>
                            </div>
                            <div class="flex items-center space-x-2">
                                <div class="text-right">
                                    <div class="list-item-amount"><?php echo Helper::formatCurrency($invoice['total_amount']); ?></div>
                                    <?php if ($invoice['balance_amount'] > 0): ?>
                                        <div class="text-xs text-red-600">Balance: <?php echo Helper::formatCurrency($invoice['balance_amount']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <span class="status-badge status-<?php echo str_replace('_', '-', $invoice['status']); ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $invoice['status'])); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <?php if ($stats['total_invoices'] > 5): ?>
                    <div class="list-item">
                        <div class="text-center">
                            <a href="<?php echo Helper::baseUrl('modules/invoices/?client=' . Helper::encryptId($client['id'])); ?>" 
                               class="text-blue-600 hover:text-blue-700 font-medium">
                                View All <?php echo $stats['total_invoices']; ?> Invoices →
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payments Section -->
    <div class="accordion-section">
        <div class="accordion-header" onclick="toggleAccordion('payments')" id="payments-header">
            <div class="flex items-center">
                <svg class="w-5 h-5 text-emerald-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v2a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                </svg>
                <span class="font-medium">Payments</span>
            </div>
            <svg class="w-5 h-5 text-gray-400 accordion-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
        </div>
        <div class="accordion-content" id="payments-content">
            <?php if (empty($recentPayments)): ?>
                <div class="empty-state">
                    <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v2a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                    <h3>No payments yet</h3>
                    <p>Payments will appear here once invoices are paid.</p>
                    <a href="<?php echo Helper::baseUrl('modules/payments/add.php?client_id=' . Helper::encryptId($client['id'])); ?>" 
                       class="mobile-button primary" style="width: auto; margin: 0 auto; min-width: 200px;">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Record Payment
                    </a>
                </div>
            <?php else: ?>
                <?php foreach ($recentPayments as $payment): ?>
                    <div class="list-item">
                        <div class="list-item-header">
                            <div class="flex-1">
                                <div class="list-item-title">
                                    Payment for #<?php echo htmlspecialchars($payment['invoice_number']); ?>
                                </div>
                                <div class="list-item-meta">
                                    <?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?>
                                    • <?php echo Helper::formatDate($payment['payment_date'], 'M j, Y'); ?>
                                    <?php if ($payment['payment_reference']): ?>
                                        • Ref: <?php echo htmlspecialchars($payment['payment_reference']); ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($payment['notes']): ?>
                                    <div class="text-xs text-gray-600 mt-1">
                                        <?php echo htmlspecialchars($payment['notes']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="text-right">
                                <div class="list-item-amount text-green-600"><?php echo Helper::formatCurrency($payment['payment_amount']); ?></div>
                                <div class="text-xs text-gray-500"><?php echo Helper::formatDate($payment['created_at'], 'M j, g:i A'); ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <div class="list-item">
                    <div class="text-center">
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

<!-- JavaScript for accordion functionality and mobile optimizations -->
<script>
// Accordion functionality with proper mobile handling (for invoices and payments only)
function toggleAccordion(sectionName) {
    const header = document.getElementById(sectionName + '-header');
    const content = document.getElementById(sectionName + '-content');
    const icon = header.querySelector('.accordion-icon');
    
    if (!header || !content) return;
    
    const isActive = content.classList.contains('active');
    
    // Close all other accordions first
    document.querySelectorAll('.accordion-content.active').forEach(activeContent => {
        if (activeContent !== content) {
            activeContent.classList.remove('active');
            const activeHeader = activeContent.closest('.accordion-section').querySelector('.accordion-header');
            if (activeHeader) {
                activeHeader.classList.remove('active');
            }
        }
    });
    
    // Toggle current accordion
    if (isActive) {
        content.classList.remove('active');
        header.classList.remove('active');
    } else {
        content.classList.add('active');
        header.classList.add('active');
    }
    
    // Store state
    localStorage.setItem('activeClientSection', isActive ? '' : sectionName);
}

// Load more projects functionality
let projectsOffset = 10; // Start from the 11th project
let isLoadingProjects = false;

function loadMoreProjects() {
    if (isLoadingProjects) return;
    
    isLoadingProjects = true;
    const loadMoreBtn = document.getElementById('load-more-btn');
    const loadMoreText = document.getElementById('load-more-text');
    const loadingText = document.getElementById('loading-text');
    
    // Update button state
    loadMoreBtn.disabled = true;
    loadMoreText.style.display = 'none';
    loadingText.style.display = 'inline';
    
    // Get client ID from current page
    const clientId = '<?php echo Helper::encryptId($clientId); ?>';
    
    // Make API request
    fetch(`<?php echo Helper::baseUrl('api/get_all_projects.php'); ?>?client_id=${clientId}&offset=${projectsOffset}&limit=10`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data.projects.length > 0) {
                const additionalProjectsList = document.getElementById('additional-projects-list');
                
                // Add new projects to the list
                data.data.projects.forEach(project => {
                    const projectHtml = `
                        <div class="list-item project-item">
                            <div class="list-item-header">
                                <div class="flex-1">
                                    <a href="${project.view_url}" 
                                       class="list-item-title hover:text-blue-600">
                                        ${project.project_name}
                                    </a>
                                    <div class="list-item-meta">
                                        ${project.project_type_formatted}
                                        • ${project.items_count} items
                                        • ${project.created_at_formatted}
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <div class="text-right">
                                        <div class="list-item-amount">${project.total_amount_formatted}</div>
                                    </div>
                                    <span class="status-badge status-${project.status_class}">
                                        ${project.status_formatted}
                                    </span>
                                </div>
                            </div>
                        </div>
                    `;
                    additionalProjectsList.insertAdjacentHTML('beforeend', projectHtml);
                });
                
                // Update offset for next load
                projectsOffset += data.data.projects.length;
                
                // Hide button if no more projects
                if (!data.data.pagination.has_more) {
                    loadMoreBtn.style.display = 'none';
                } else {
                    // Reset button state
                    loadMoreBtn.disabled = false;
                    loadMoreText.style.display = 'inline';
                    loadingText.style.display = 'none';
                }
                
                // Smooth scroll to show new projects
                setTimeout(() => {
                    const lastProject = additionalProjectsList.lastElementChild;
                    if (lastProject) {
                        lastProject.scrollIntoView({ 
                            behavior: 'smooth', 
                            block: 'center' 
                        });
                    }
                }, 100);
                
            } else {
                // No more projects or error
                loadMoreBtn.style.display = 'none';
            }
        })
        .catch(error => {
            console.error('Error loading projects:', error);
            // Reset button state on error
            loadMoreBtn.disabled = false;
            loadMoreText.style.display = 'inline';
            loadingText.style.display = 'none';
            
            // Show error message
            const errorDiv = document.createElement('div');
            errorDiv.className = 'text-center p-4 text-red-600';
            errorDiv.textContent = 'Error loading projects. Please try again.';
            document.getElementById('additional-projects-list').appendChild(errorDiv);
        })
        .finally(() => {
            isLoadingProjects = false;
        });
}

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    // Restore accordion state for invoices and payments only
    const savedSection = localStorage.getItem('activeClientSection');
    if (savedSection && ['invoices', 'payments'].includes(savedSection) && document.getElementById(savedSection + '-content')) {
        toggleAccordion(savedSection);
    } else {
        // Default to invoices section open
        toggleAccordion('invoices');
    }
    
    // Add loading states for navigation
    const navigationLinks = document.querySelectorAll('a[href]:not([href^="tel:"])');
    navigationLinks.forEach(link => {
        link.addEventListener('click', function() {
            if (!this.href.includes('#')) {
                this.classList.add('loading');
                
                // Reset loading state after timeout
                setTimeout(() => {
                    this.classList.remove('loading');
                }, 3000);
            }
        });
    });
    
    // Enhance touch feedback
    const touchElements = document.querySelectorAll('.accordion-header, .mobile-button, .list-item');
    touchElements.forEach(element => {
        element.addEventListener('touchstart', function() {
            this.style.transform = 'scale(0.98)';
        }, { passive: true });
        
        element.addEventListener('touchend', function() {
            this.style.transform = '';
        }, { passive: true });
        
        element.addEventListener('touchcancel', function() {
            this.style.transform = '';
        }, { passive: true });
    });
    
    // Add swipe gesture for accordion navigation on mobile
    let startY = 0;
    let startX = 0;
    let isScrolling = false;
    
    document.addEventListener('touchstart', function(e) {
        startY = e.touches[0].pageY;
        startX = e.touches[0].pageX;
        isScrolling = undefined;
    }, { passive: true });
    
    document.addEventListener('touchmove', function(e) {
        if (typeof isScrolling === 'undefined') {
            isScrolling = Math.abs(startY - e.touches[0].pageY) > Math.abs(startX - e.touches[0].pageX);
        }
    }, { passive: true });
    
    // Smooth scroll to accordion when opened
    const accordionHeaders = document.querySelectorAll('.accordion-header');
    accordionHeaders.forEach(header => {
        header.addEventListener('click', function() {
            setTimeout(() => {
                if (this.classList.contains('active')) {
                    this.scrollIntoView({ 
                        behavior: 'smooth', 
                        block: 'nearest',
                        inline: 'nearest'
                    });
                }
            }, 100);
        });
    });
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.target.matches('input, textarea')) return;
        
        switch(e.key.toLowerCase()) {
            case 'e':
                if (!e.ctrlKey && !e.metaKey) {
                    e.preventDefault();
                    window.location.href = '<?php echo Helper::baseUrl('modules/clients/edit.php?id=' . Helper::encryptId($client['id'])); ?>';
                }
                break;
            case 'n':
                if (!e.ctrlKey && !e.metaKey) {
                    e.preventDefault();
                    window.location.href = '<?php echo Helper::baseUrl('modules/projects/add.php?client_id=' . Helper::encryptId($client['id'])); ?>';
                }
                break;
            case 'b':
                if (!e.ctrlKey && !e.metaKey) {
                    e.preventDefault();
                    window.location.href = '<?php echo Helper::baseUrl('modules/clients/'); ?>';
                }
                break;
            case '1':
                if (!e.ctrlKey && !e.metaKey) {
                    e.preventDefault();
                    toggleAccordion('invoices');
                }
                break;
            case '2':
                if (!e.ctrlKey && !e.metaKey) {
                    e.preventDefault();
                    toggleAccordion('payments');
                }
                break;
            case 'l':
                if (!e.ctrlKey && !e.metaKey) {
                    e.preventDefault();
                    const loadMoreBtn = document.getElementById('load-more-btn');
                    if (loadMoreBtn && loadMoreBtn.style.display !== 'none' && !loadMoreBtn.disabled) {
                        loadMoreProjects();
                    }
                }
                break;
        }
    });
    
    // Performance optimizations
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animate-fade-in');
                }
            });
        });
        
        document.querySelectorAll('.accordion-section, .projects-section').forEach(section => {
            observer.observe(section);
        });
    }
    
    // Add CSS animation class
    const style = document.createElement('style');
    style.textContent = `
        .animate-fade-in {
            animation: fadeIn 0.3s ease-out;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    `;
    document.head.appendChild(style);
});

// Utility functions
function refreshClientData() {
    // In production, this would make an AJAX call to refresh data
    window.location.reload();
}

// Export client functionality (optional)
function exportClientData() {
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

// Connection status handling
window.addEventListener('online', function() {
    const notification = document.createElement('div');
    notification.className = 'fixed top-4 right-4 bg-green-500 text-white px-4 py-2 rounded-lg shadow-lg z-50 text-sm';
    notification.textContent = 'Connection restored';
    document.body.appendChild(notification);
    setTimeout(() => notification.remove(), 3000);
});

window.addEventListener('offline', function() {
    const notification = document.createElement('div');
    notification.className = 'fixed top-4 right-4 bg-red-500 text-white px-4 py-2 rounded-lg shadow-lg z-50 text-sm';
    notification.textContent = 'Working offline';
    document.body.appendChild(notification);
    setTimeout(() => notification.remove(), 3000);
});
</script>

<?php include '../../includes/footer.php'; ?>