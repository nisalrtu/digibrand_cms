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

/* Items section styling (non-accordion) */
.items-section {
    margin-bottom: 1rem;
}

.section-header h2 {
    font-size: 1.125rem;
    font-weight: 600;
    color: #111827;
    margin: 0;
}

.items-container {
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
    max-height: 3000px;
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

/* Mobile-optimized project header */
.project-header {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 0.75rem;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.project-info-grid {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 0.75rem;
    align-items: start;
    margin-bottom: 1rem;
}

.project-info-icon {
    width: 40px;
    height: 40px;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

/* Enhanced status badge */
.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.5rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: capitalize;
    border: 1px solid;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

.status-pending {
    background: #fef3c7;
    color: #92400e;
    border-color: #fcd34d;
}

.status-in-progress {
    background: #dbeafe;
    color: #1e40af;
    border-color: #60a5fa;
}

.status-completed {
    background: #d1fae5;
    color: #065f46;
    border-color: #34d399;
}

.status-on-hold {
    background: #f3f4f6;
    color: #374151;
    border-color: #9ca3af;
}

.status-cancelled {
    background: #fecaca;
    color: #b91c1c;
    border-color: #f87171;
}

/* Client info card */
.client-info-card {
    background: linear-gradient(135deg, #dbeafe 0%, #e0e7ff 100%);
    border: 1px solid #c7d2fe;
    border-radius: 0.75rem;
    padding: 1rem;
    margin-bottom: 1rem;
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
        grid-template-columns: repeat(3, 1fr);
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
    background: #3b82f6;
    color: white;
}

.mobile-button.secondary:hover {
    background: #2563eb;
}

.mobile-button.tertiary {
    background: white;
    color: #374151;
    border: 1px solid #d1d5db;
}

.mobile-button.tertiary:hover {
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

/* Item grid layout for project items */
.item-grid {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 1rem;
    align-items: start;
}

.item-details {
    min-width: 0;
}

.item-pricing {
    text-align: right;
    flex-shrink: 0;
}

.quantity-unit {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    font-size: 0.75rem;
    color: #6b7280;
    margin-top: 0.25rem;
}

.quantity-unit > span {
    background: #f3f4f6;
    padding: 0.25rem 0.5rem;
    border-radius: 0.25rem;
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

/* Responsive improvements */
@media (min-width: 640px) {
    .mobile-container {
        padding: 1.5rem;
    }
    
    .project-header {
        padding: 1.5rem;
    }
    
    .accordion-header {
        padding: 1.25rem 1.5rem;
    }
    
    .list-item {
        padding: 1.25rem;
    }
    
    .item-grid {
        grid-template-columns: 1fr auto;
        gap: 2rem;
    }
}

/* Progress bar for project timeline */
.progress-bar {
    width: 100%;
    height: 4px;
    background: #e5e7eb;
    border-radius: 2px;
    overflow: hidden;
    margin-top: 0.5rem;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #10b981, #059669);
    transition: width 0.3s ease;
}

/* Enhanced invoice/payment status indicators */
.status-indicator {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.5rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 500;
    text-transform: capitalize;
}

.status-draft { background: #f3f4f6; color: #374151; }
.status-sent { background: #dbeafe; color: #1e40af; }
.status-paid { background: #d1fae5; color: #065f46; }
.status-partially-paid { background: #fed7aa; color: #c2410c; }
.status-overdue { background: #fecaca; color: #b91c1c; }

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
    .stat-card,
    .progress-fill {
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
.mobile-button:focus {
    outline: 2px solid #3b82f6;
    outline-offset: 2px;
}

/* Loading states */
.loading {
    opacity: 0.6;
    pointer-events: none;
}

/* Sticky bottom bar - Mobile Only (Enhanced style like items.php) */
.sticky-bottom-bar {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: #1f2937;
    color: white;
    padding: 1rem;
    box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.15);
    border-top: 1px solid #374151;
    z-index: 40;
    transform: translateY(0);
    transition: transform 0.3s ease;
    display: block;
    backdrop-filter: blur(10px);
}

/* Hide sticky bar on tablets and desktop */
@media (min-width: 768px) {
    .sticky-bottom-bar {
        display: none !important;
    }
}

.sticky-bottom-bar.hidden {
    transform: translateY(100%);
}

.bottom-bar-content {
    max-width: 100%;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}

.project-total-info {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
}

.project-value-display {
    display: flex;
    flex-direction: column;
    flex: 1;
}

.project-total-label {
    font-size: 1rem;
    color: #d1d5db;
    font-weight: 500;
    line-height: 1;
    margin-bottom: 0.25rem;
}

.project-total-amount {
    font-size: 1.5rem;
    font-weight: 700;
    color: white;
    line-height: 1.2;
}

.project-status-mini {
    display: inline-flex;
    align-items: center;
    padding: 0.375rem 0.75rem;
    border-radius: 0.5rem;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: capitalize;
    border: 1px solid;
    flex-shrink: 0;
    white-space: nowrap;
    background: rgba(255, 255, 255, 0.1);
    color: white;
    border-color: rgba(255, 255, 255, 0.2);
}

/* Add bottom padding to main content to prevent overlap - Mobile Only */
.mobile-container {
    padding-bottom: 6rem;
}

/* Remove bottom padding on tablets and desktop */
@media (min-width: 768px) {
    .mobile-container {
        padding-bottom: 0;
    }
}

/* Enhanced status colors for bottom bar - Minimal */
.project-status-mini.status-pending {
    background: #fef3c7;
    color: #92400e;
    border-color: #fcd34d;
}

.project-status-mini.status-in-progress {
    background: #dbeafe;
    color: #1e40af;
    border-color: #60a5fa;
}

.project-status-mini.status-completed {
    background: #d1fae5;
    color: #065f46;
    border-color: #34d399;
}

.project-status-mini.status-on-hold {
    background: #f3f4f6;
    color: #374151;
    border-color: #9ca3af;
}

.project-status-mini.status-cancelled {
    background: #fecaca;
    color: #b91c1c;
    border-color: #f87171;
}

.project-status-mini.status-completed {
    background: linear-gradient(135deg, #d1fae5, #34d399);
    color: #065f46;
    border-color: #34d399;
}

.project-status-mini.status-on-hold {
    background: linear-gradient(135deg, #f3f4f6, #9ca3af);
    color: #374151;
    border-color: #9ca3af;
}

.project-status-mini.status-cancelled {
    background: linear-gradient(135deg, #fecaca, #f87171);
    color: #b91c1c;
    border-color: #f87171;
}

/* Responsive adjustments for bottom bar - Mobile only */
@media (max-width: 479px) {
    .sticky-bottom-bar {
        padding: 0.875rem 1rem;
    }
    
    .project-total-amount {
        font-size: 1.25rem;
    }
}

@media (min-width: 480px) and (max-width: 767px) {
    .sticky-bottom-bar {
        padding: 1rem 1.25rem;
    }
    
    .project-total-amount {
        font-size: 1.75rem;
    }
}

/* Hide bottom bar when printing */
@media print {
    .sticky-bottom-bar {
        display: none;
    }
    
    .mobile-container {
        padding-bottom: 0;
    }
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
            <a href="<?php echo Helper::baseUrl('modules/projects/'); ?>" class="hover:text-gray-900 transition-colors">
                <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
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
            <span class="text-gray-900 font-medium"><?php echo htmlspecialchars($project['project_name']); ?></span>
        </div>
    </nav>

    <!-- Project Header -->
    <div class="project-header">
        <!-- Title and Status -->
        <div class="mb-4">
            <div class="flex flex-col sm:flex-row sm:items-start gap-3 mb-2">
                <div class="flex-1 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 leading-tight break-words mb-1">
                        <?php echo htmlspecialchars($project['project_name']); ?>
                    </h1>
                    <p class="text-base text-gray-600">
                        <?php echo ucwords(str_replace('_', ' ', $project['project_type'])); ?> Project
                    </p>
                </div>
                <div class="flex-shrink-0">
                    <?php 
                    $status = $project['status'];
                    $statusIcons = [
                        'pending' => '<svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path></svg>',
                        'in_progress' => '<svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>',
                        'completed' => '<svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg>',
                        'on_hold' => '<svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zM7 8a1 1 0 012 0v4a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v4a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>',
                        'cancelled' => '<svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>'
                    ];
                    $statusIcon = $statusIcons[$status] ?? '';
                    ?>
                    <span class="status-badge status-<?php echo str_replace('_', '-', $status); ?>">
                        <?php echo $statusIcon; ?>
                        <?php echo ucwords(str_replace('_', ' ', $status)); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Client Information Card -->
        <div class="client-info-card">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-blue-600 bg-opacity-20 rounded-lg flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-blue-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-2m-2 0H5m14 0V9a2 2 0 00-2-2H9a2 2 0 00-2 2v12a2 2 0 002 2h4m0 0V9a2 2 0 012-2h2a2 2 0 012 2v12"></path>
                    </svg>
                </div>
                <div class="ml-3 flex-1 min-w-0">
                    <p class="font-medium text-blue-900">
                        <a href="<?php echo Helper::baseUrl('modules/clients/view.php?id=' . Helper::encryptId($project['client_id'])); ?>" 
                           class="hover:text-blue-700 transition-colors">
                            <?php echo htmlspecialchars($project['company_name']); ?>
                        </a>
                    </p>
                    <p class="text-sm text-blue-700"><?php echo htmlspecialchars($project['contact_person']); ?></p>
                    <a href="tel:<?php echo htmlspecialchars($project['mobile_number']); ?>" 
                       class="text-sm text-blue-600 hover:text-blue-500">
                        <?php echo htmlspecialchars($project['mobile_number']); ?>
                    </a>
                </div>
            </div>
        </div>

        <!-- Project Details -->
        <div class="space-y-3 mb-4">
            <div class="project-info-grid">
                <div class="project-info-icon bg-green-100">
                    <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-900">Project Timeline</p>
                    <p class="text-sm text-gray-600">
                        <?php echo $project['start_date'] ? Helper::formatDate($project['start_date'], 'M j, Y') : 'Not set'; ?>
                        <?php if ($project['end_date']): ?>
                            - <?php echo Helper::formatDate($project['end_date'], 'M j, Y'); ?>
                        <?php endif; ?>
                    </p>
                    <?php if ($project['start_date'] && $project['end_date']): ?>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php 
                                $start = new DateTime($project['start_date']);
                                $end = new DateTime($project['end_date']);
                                $now = new DateTime();
                                $total = $end->diff($start)->days;
                                $elapsed = $now->diff($start)->days;
                                $progress = $total > 0 ? min(max(($elapsed / $total) * 100, 0), 100) : 0;
                                echo $progress;
                            ?>%"></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="project-info-grid">
                <div class="project-info-icon bg-purple-100">
                    <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-900">Project Value</p>
                    <p class="text-lg font-bold text-purple-600">
                        <?php echo Helper::formatCurrency($project['total_amount']); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Project Description -->
        <?php if (!empty($project['description'])): ?>
            <div class="mb-4">
                <h3 class="text-sm font-medium text-gray-900 mb-2">Description</h3>
                <div class="text-gray-600 text-sm p-3 bg-gray-50 rounded-lg">
                    <?php echo nl2br(htmlspecialchars($project['description'])); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Project Meta -->
        <div class="text-xs text-gray-500 py-3 border-t border-gray-100">
            Created <?php echo Helper::formatDate($project['created_at'], 'F j, Y \a\t g:i A'); ?>
            <?php if ($project['created_by_name']): ?>
                by <?php echo htmlspecialchars($project['created_by_name']); ?>
            <?php endif; ?>
            <?php if ($project['updated_at'] !== $project['created_at']): ?>
                • Last updated <?php echo Helper::formatDate($project['updated_at'], 'M j, Y \a\t g:i A'); ?>
            <?php endif; ?>
        </div>

        <!-- Action Buttons -->
        <div class="button-grid">
            <a href="<?php echo Helper::baseUrl('modules/projects/edit.php?id=' . Helper::encryptId($project['id'])); ?>" 
               class="mobile-button primary">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                </svg>
                Edit Project
            </a>
            <a href="<?php echo Helper::baseUrl('modules/invoices/create.php?project_id=' . Helper::encryptId($project['id'])); ?>" 
               class="mobile-button secondary">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                Create Invoice
            </a>
            <a href="<?php echo Helper::baseUrl('modules/projects/items.php?project_id=' . Helper::encryptId($project['id'])); ?>" 
               class="mobile-button tertiary">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Manage Items
            </a>
        </div>
    </div>

    <!-- Statistics Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mx-auto mb-2">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
            </div>
            <p class="text-xl font-bold text-gray-900"><?php echo number_format($stats['total_items']); ?></p>
            <p class="text-sm text-gray-600">Items</p>
        </div>

        <div class="stat-card">
            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center mx-auto mb-2">
                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <p class="text-lg font-bold text-gray-900"><?php echo Helper::formatCurrency($stats['items_total']); ?></p>
            <p class="text-sm text-gray-600">Items Value</p>
        </div>

        <div class="stat-card">
            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center mx-auto mb-2">
                <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
            </div>
            <p class="text-lg font-bold text-gray-900"><?php echo Helper::formatCurrency($stats['total_invoiced']); ?></p>
            <p class="text-sm text-gray-600">Invoiced</p>
        </div>

        <div class="stat-card">
            <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center mx-auto mb-2">
                <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <p class="text-lg font-bold text-gray-900"><?php echo Helper::formatCurrency($stats['outstanding_amount']); ?></p>
            <p class="text-sm text-gray-600">Outstanding</p>
        </div>
    </div>

    <!-- Accordion Sections -->
    
    <!-- Project Items Section -->
    <div class="items-section">
        <div class="section-header">
            <div class="flex items-center mb-4">
                <svg class="w-5 h-5 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
                <h2 class="text-lg font-semibold text-gray-900">Project Items (<?php echo $stats['total_items']; ?>)</h2>
            </div>
        </div>
        
        <div class="items-container bg-white border border-gray-200 rounded-lg overflow-hidden">
            <?php if (empty($projectItems)): ?>
                <div class="empty-state">
                    <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                    </svg>
                    <h3>No project items yet</h3>
                    <p>Add items to track work components and pricing.</p>
                    <a href="<?php echo Helper::baseUrl('modules/projects/items.php?project_id=' . Helper::encryptId($project['id'])); ?>" 
                       class="mobile-button primary" style="width: auto; margin: 0 auto; min-width: 200px;">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Add First Item
                    </a>
                </div>
            <?php else: ?>
                <?php foreach ($projectItems as $item): ?>
                    <div class="list-item">
                        <div class="item-grid">
                            <div class="item-details">
                                <h4 class="list-item-title mb-2">
                                    <?php echo htmlspecialchars($item['item_name']); ?>
                                </h4>
                                <div class="quantity-unit">
                                    <span>Qty: <?php echo number_format($item['quantity']); ?></span>
                                    <span>Unit: <?php echo Helper::formatCurrency($item['unit_price']); ?></span>
                                </div>
                                <?php if (!empty($item['description'])): ?>
                                    <div class="text-xs text-gray-600 mt-2">
                                        <?php echo htmlspecialchars($item['description']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="item-pricing">
                                <div class="text-lg font-bold text-gray-900">
                                    <?php echo Helper::formatCurrency($item['quantity'] * $item['unit_price']); ?>
                                </div>
                                <div class="text-xs text-gray-500">Total</div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <div class="text-center p-4 border-t border-gray-100">
                    <a href="<?php echo Helper::baseUrl('modules/projects/items.php?project_id=' . Helper::encryptId($project['id'])); ?>" 
                       class="mobile-button secondary" style="width: auto; min-width: 150px;">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                        </svg>
                        Manage Items
                    </a>
                </div>
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
            <?php if (empty($relatedInvoices)): ?>
                <div class="empty-state">
                    <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <h3>No invoices yet</h3>
                    <p>Create an invoice for this project to bill the client.</p>
                    <a href="<?php echo Helper::baseUrl('modules/invoices/create.php?project_id=' . Helper::encryptId($project['id'])); ?>" 
                       class="mobile-button secondary" style="width: auto; margin: 0 auto; min-width: 200px;">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Create First Invoice
                    </a>
                </div>
            <?php else: ?>
                <?php foreach ($relatedInvoices as $invoice): ?>
                    <div class="list-item">
                        <div class="list-item-header">
                            <div class="flex-1">
                                <a href="<?php echo Helper::baseUrl('modules/invoices/view.php?id=' . Helper::encryptId($invoice['id'])); ?>" 
                                   class="list-item-title hover:text-blue-600">
                                    #<?php echo htmlspecialchars($invoice['invoice_number']); ?>
                                </a>
                                <div class="list-item-meta">
                                    Due <?php echo Helper::formatDate($invoice['due_date'], 'M j, Y'); ?>
                                    • Paid <?php echo Helper::formatCurrency($invoice['paid_amount']); ?>
                                    <?php if ($invoice['balance_amount'] > 0): ?>
                                        • <span class="text-red-600">Balance <?php echo Helper::formatCurrency($invoice['balance_amount']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="flex items-center space-x-2">
                                <div class="text-right">
                                    <div class="list-item-amount"><?php echo Helper::formatCurrency($invoice['total_amount']); ?></div>
                                </div>
                                <span class="status-indicator status-<?php echo str_replace('_', '-', $invoice['status']); ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $invoice['status'])); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <div class="list-item">
                    <div class="text-center">
                        <a href="<?php echo Helper::baseUrl('modules/invoices/?project=' . Helper::encryptId($project['id'])); ?>" 
                           class="text-blue-600 hover:text-blue-700 font-medium mr-4">
                            View All Invoices →
                        </a>
                        <a href="<?php echo Helper::baseUrl('modules/invoices/create.php?project_id=' . Helper::encryptId($project['id'])); ?>" 
                           class="inline-flex items-center px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm">
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                            New Invoice
                        </a>
                    </div>
                </div>
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
            <?php if (empty($relatedPayments)): ?>
                <div class="empty-state">
                    <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v2a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                    <h3>No payments yet</h3>
                    <p>Payments will appear here once invoices are paid.</p>
                </div>
            <?php else: ?>
                <?php foreach ($relatedPayments as $payment): ?>
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

<!-- Sticky Bottom Bar -->
<div class="sticky-bottom-bar" id="stickyBottomBar">
    <div class="bottom-bar-content">
        <div class="project-total-info">
            <div class="project-value-display">
                <div class="project-total-label">Total Project Value</div>
                <div class="project-total-amount"><?php echo Helper::formatCurrency($project['total_amount']); ?></div>
            </div>
            
            <!-- Project Status -->
            <span class="project-status-mini status-<?php echo str_replace('_', '-', $project['status']); ?>">
                <?php echo ucwords(str_replace('_', ' ', $project['status'])); ?>
            </span>
        </div>
    </div>
</div>

<!-- JavaScript for accordion functionality and mobile optimizations -->
<script>
// Accordion functionality with proper mobile handling (for invoices and payments only)
function toggleAccordion(sectionName) {
    const header = document.getElementById(sectionName + '-header');
    const content = document.getElementById(sectionName + '-content');
    
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
    localStorage.setItem('activeProjectSection', isActive ? '' : sectionName);
}

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    // Restore accordion state for invoices and payments only
    const savedSection = localStorage.getItem('activeProjectSection');
    if (savedSection && ['invoices', 'payments'].includes(savedSection) && document.getElementById(savedSection + '-content')) {
        toggleAccordion(savedSection);
    } else {
        // Default to invoices section open
        toggleAccordion('invoices');
    }
    
    // Initialize sticky bottom bar functionality
    initializeStickyBottomBar();
    
    // Animate project value for better visual impact
    animateProjectValue();
    
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
                    window.location.href = '<?php echo Helper::baseUrl('modules/projects/edit.php?id=' . Helper::encryptId($project['id'])); ?>';
                }
                break;
            case 'i':
                if (!e.ctrlKey && !e.metaKey) {
                    e.preventDefault();
                    window.location.href = '<?php echo Helper::baseUrl('modules/invoices/create.php?project_id=' . Helper::encryptId($project['id'])); ?>';
                }
                break;
            case 'm':
                if (!e.ctrlKey && !e.metaKey) {
                    e.preventDefault();
                    window.location.href = '<?php echo Helper::baseUrl('modules/projects/items.php?project_id=' . Helper::encryptId($project['id'])); ?>';
                }
                break;
            case 'b':
                if (!e.ctrlKey && !e.metaKey) {
                    e.preventDefault();
                    window.location.href = '<?php echo Helper::baseUrl('modules/projects/'); ?>';
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
        
        document.querySelectorAll('.accordion-section, .items-section').forEach(section => {
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

// Sticky bottom bar functionality - Mobile Only
function initializeStickyBottomBar() {
    const bottomBar = document.getElementById('stickyBottomBar');
    if (!bottomBar) return;
    
    // Check if we're on mobile
    function isMobile() {
        return window.innerWidth < 768;
    }
    
    // Only initialize if on mobile
    if (!isMobile()) {
        bottomBar.style.display = 'none';
        return;
    }
    
    let lastScrollTop = 0;
    let scrollTimeout;
    
    // Smart hide/show based on scroll direction - Mobile only
    function handleScroll() {
        if (!isMobile()) return;
        
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const scrollingDown = scrollTop > lastScrollTop;
        const scrolledFar = scrollTop > 100; // Only hide after scrolling 100px
        
        if (scrollingDown && scrolledFar) {
            bottomBar.classList.add('hidden');
        } else if (!scrollingDown || scrollTop < 50) {
            bottomBar.classList.remove('hidden');
        }
        
        lastScrollTop = scrollTop <= 0 ? 0 : scrollTop;
        
        // Clear any existing timeout
        if (scrollTimeout) {
            clearTimeout(scrollTimeout);
        }
        
        // Show bottom bar when scrolling stops
        scrollTimeout = setTimeout(() => {
            if (isMobile()) {
                bottomBar.classList.remove('hidden');
            }
        }, 1000);
    }
    
    // Throttled scroll listener for better performance - Mobile only
    let ticking = false;
    function throttledScrollHandler() {
        if (!isMobile()) return;
        
        if (!ticking) {
            requestAnimationFrame(() => {
                handleScroll();
                ticking = false;
            });
            ticking = true;
        }
    }
    
    window.addEventListener('scroll', throttledScrollHandler, { passive: true });
    
    // Show bottom bar on touch/mouse interaction - Mobile only
    const showOnInteraction = () => {
        if (isMobile()) {
            bottomBar.classList.remove('hidden');
        }
    };
    
    document.addEventListener('touchstart', showOnInteraction, { passive: true });
    
    // Hide bottom bar when accordion sections are opened (mobile only)
    const accordionHeaders = document.querySelectorAll('.accordion-header');
    accordionHeaders.forEach(header => {
        header.addEventListener('click', () => {
            setTimeout(() => {
                if (isMobile()) {
                    bottomBar.classList.add('hidden');
                    // Show again after 2 seconds
                    setTimeout(() => {
                        if (isMobile()) {
                            bottomBar.classList.remove('hidden');
                        }
                    }, 2000);
                }
            }, 300);
        });
    });
    
    // Ensure bottom bar is visible on page load - Mobile only
    setTimeout(() => {
        if (isMobile()) {
            bottomBar.classList.remove('hidden');
        }
    }, 500);
    
    // Handle resize events
    window.addEventListener('resize', () => {
        if (isMobile()) {
            bottomBar.style.display = 'block';
            bottomBar.classList.remove('hidden');
        } else {
            bottomBar.style.display = 'none';
        }
    });
    
    // Add tooltip functionality for better UX (simplified for status only)
    initializeTooltips();
}

// Tooltip functionality for better UX
function initializeTooltips() {
    const tooltipElements = document.querySelectorAll('[title]');
    
    tooltipElements.forEach(element => {
        let tooltip;
        
        element.addEventListener('mouseenter', (e) => {
            if (window.innerWidth < 768) return; // Skip tooltips on mobile
            
            const title = element.getAttribute('title');
            if (!title) return;
            
            // Remove title to prevent default tooltip
            element.setAttribute('data-title', title);
            element.removeAttribute('title');
            
            // Create tooltip
            tooltip = document.createElement('div');
            tooltip.className = 'custom-tooltip';
            tooltip.textContent = title;
            tooltip.style.cssText = `
                position: absolute;
                bottom: 100%;
                left: 50%;
                transform: translateX(-50%);
                background: #1f2937;
                color: white;
                padding: 0.5rem 0.75rem;
                border-radius: 0.375rem;
                font-size: 0.75rem;
                font-weight: 500;
                white-space: nowrap;
                z-index: 1001;
                margin-bottom: 0.5rem;
                opacity: 0;
                transition: opacity 0.2s ease;
                pointer-events: none;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            `;
            
            // Add arrow
            const arrow = document.createElement('div');
            arrow.style.cssText = `
                position: absolute;
                top: 100%;
                left: 50%;
                transform: translateX(-50%);
                width: 0;
                height: 0;
                border-left: 4px solid transparent;
                border-right: 4px solid transparent;
                border-top: 4px solid #1f2937;
            `;
            tooltip.appendChild(arrow);
            
            element.style.position = 'relative';
            element.appendChild(tooltip);
            
            // Fade in
            requestAnimationFrame(() => {
                tooltip.style.opacity = '1';
            });
        });
        
        element.addEventListener('mouseleave', () => {
            if (tooltip) {
                tooltip.style.opacity = '0';
                setTimeout(() => {
                    if (tooltip && tooltip.parentNode) {
                        tooltip.parentNode.removeChild(tooltip);
                    }
                }, 200);
            }
            
            // Restore title for accessibility
            const dataTitle = element.getAttribute('data-title');
            if (dataTitle) {
                element.setAttribute('title', dataTitle);
                element.removeAttribute('data-title');
            }
        });
    });
}

// Enhanced project value display with animation
function animateProjectValue() {
    const valueElement = document.querySelector('.project-total-amount');
    if (!valueElement) return;
    
    const finalValue = valueElement.textContent;
    const numericValue = parseFloat(finalValue.replace(/[^0-9.-]+/g, ''));
    
    if (isNaN(numericValue)) return;
    
    let currentValue = 0;
    const increment = numericValue / 30; // 30 frames for smooth animation
    const duration = 1000; // 1 second
    const frameTime = duration / 30;
    
    const animate = () => {
        currentValue += increment;
        if (currentValue >= numericValue) {
            valueElement.textContent = finalValue;
            return;
        }
        
        // Format the current value (simple formatting)
        const formatted = new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD' // You can make this dynamic based on your currency setting
        }).format(currentValue);
        
        valueElement.textContent = formatted;
        setTimeout(animate, frameTime);
    };
    
    // Start animation after a short delay
    setTimeout(animate, 800);
}

// Utility functions
function refreshProjectData() {
    window.location.reload();
}

// Export project functionality
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