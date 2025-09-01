<?php
// modules/clients/index.php
session_start();

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

$pageTitle = 'Clients - Invoice Manager';

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Pagination and search settings
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;
$search = isset($_GET['search']) ? Helper::sanitize($_GET['search']) : '';

try {
    // Build search query with proper parameter binding
    $whereClause = "WHERE is_active = 1";
    $params = [];
    
    if (!empty($search)) {
        $whereClause .= " AND (company_name LIKE :search OR contact_person LIKE :search OR mobile_number LIKE :search OR city LIKE :search OR address LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    // Get total count for pagination
    $countQuery = "SELECT COUNT(*) as total FROM clients " . $whereClause;
    $countStmt = $db->prepare($countQuery);
    if (!empty($params)) {
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
    }
    $countStmt->execute();
    $totalClients = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalClients / $limit);
    
    // Get clients for current page
    $clientsQuery = "SELECT id, company_name, contact_person, mobile_number, address, city, created_at, updated_at 
                     FROM clients " . $whereClause . " ORDER BY company_name ASC LIMIT :limit OFFSET :offset";
    $clientsStmt = $db->prepare($clientsQuery);
    
    // Bind search parameters
    if (!empty($params)) {
        foreach ($params as $key => $value) {
            $clientsStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
    }
    
    // Bind pagination parameters
    $clientsStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $clientsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $clientsStmt->execute();
    $clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get stats for each client (projects count and total revenue)
    if (!empty($clients)) {
        $clientIds = array_column($clients, 'id');
        
        try {
            // Get project counts
            $placeholders = str_repeat('?,', count($clientIds) - 1) . '?';
            $projectCountQuery = "SELECT client_id, COUNT(*) as project_count FROM projects WHERE client_id IN ($placeholders) GROUP BY client_id";
            $projectCountStmt = $db->prepare($projectCountQuery);
            $projectCountStmt->execute($clientIds);
            $projectCounts = $projectCountStmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // Get total revenue from invoices
            $revenueQuery = "SELECT i.client_id, COALESCE(SUM(i.paid_amount), 0) as total_revenue 
                            FROM invoices i WHERE i.client_id IN ($placeholders) GROUP BY i.client_id";
            $revenueStmt = $db->prepare($revenueQuery);
            $revenueStmt->execute($clientIds);
            $revenues = $revenueStmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // Add stats to client data
            foreach ($clients as &$client) {
                $client['project_count'] = $projectCounts[$client['id']] ?? 0;
                $client['total_revenue'] = $revenues[$client['id']] ?? 0;
            }
            unset($client); // Break the reference
            
        } catch (Exception $e) {
            // If stats queries fail, just set default values
            foreach ($clients as &$client) {
                $client['project_count'] = 0;
                $client['total_revenue'] = 0;
            }
            unset($client); // Break the reference
        }
    }
    
} catch (Exception $e) {
    // Log the error for debugging
    error_log("Client index error: " . $e->getMessage());
    
    // Set error message for display
    $error = "Error loading clients: " . $e->getMessage();
    
    // Initialize empty arrays to prevent undefined variable errors
    $clients = [];
    $totalClients = 0;
    $totalPages = 0;
}

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="mb-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Clients</h1>
            <p class="text-gray-600 mt-1">
                <?php echo number_format($totalClients); ?> client<?php echo $totalClients !== 1 ? 's' : ''; ?> found
            </p>
        </div>
        <div class="flex flex-col sm:flex-row gap-3">
            <a href="<?php echo Helper::baseUrl('modules/clients/add.php'); ?>" 
               class="btn-primary inline-flex items-center justify-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Add Client
            </a>
        </div>
    </div>
</div>

<!-- Search and Filters -->
<div class="bg-white rounded-xl border border-gray-200 p-4 mb-6">
    <form method="GET" class="flex flex-col sm:flex-row gap-3">
        <div class="flex-1">
            <div class="relative">
                <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
                <input 
                    type="text" 
                    name="search" 
                    value="<?php echo htmlspecialchars($search); ?>"
                    placeholder="Search clients by name, contact person, phone, or city..."
                    class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent"
                >
            </div>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                Search
            </button>
            <?php if (!empty($search)): ?>
                <a href="<?php echo Helper::baseUrl('modules/clients/'); ?>" 
                   class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                    Clear
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Clients List -->
<?php if (isset($error)): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if (empty($clients)): ?>
    <!-- Empty State -->
    <div class="bg-white rounded-xl border border-gray-200 p-8 text-center">
        <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
        </svg>
        <h3 class="text-lg font-medium text-gray-900 mb-2">
            <?php echo !empty($search) ? 'No clients found' : 'No clients yet'; ?>
        </h3>
        <p class="text-gray-600 mb-4">
            <?php echo !empty($search) ? 'Try adjusting your search criteria.' : 'Get started by adding your first client.'; ?>
        </p>
        <?php if (empty($search)): ?>
            <a href="<?php echo Helper::baseUrl('modules/clients/add.php'); ?>" 
               class="inline-flex items-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Add Your First Client
            </a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <!-- Mobile-Optimized Client Cards -->
    <div class="space-y-4">
        <?php foreach ($clients as $client): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-4 hover:shadow-md transition-shadow">
                <div class="flex items-start justify-between">
                    <div class="flex-1 min-w-0">
                        <!-- Client Header -->
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex-1 min-w-0">
                                <h3 class="text-lg font-semibold text-gray-900 truncate">
                                    <?php echo htmlspecialchars($client['company_name']); ?>
                                </h3>
                                <p class="text-sm text-gray-600">
                                    <?php echo htmlspecialchars($client['contact_person']); ?>
                                </p>
                            </div>
                            <!-- Action Buttons -->
                            <div class="flex items-center gap-2 ml-3">
                                <!-- View Button -->
                                <a href="<?php echo Helper::baseUrl('modules/clients/view.php?id=' . Helper::encryptId($client['id'])); ?>" 
                                   class="inline-flex items-center justify-center w-8 h-8 sm:w-auto sm:h-auto sm:px-3 sm:py-2 text-blue-600 hover:text-blue-700 hover:bg-blue-50 rounded-lg transition-colors group"
                                   title="View Details">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                    </svg>
                                    <span class="hidden sm:inline ml-1 text-xs font-medium">View</span>
                                </a>
                                
                                <!-- Edit Button -->
                                <a href="<?php echo Helper::baseUrl('modules/clients/edit.php?id=' . Helper::encryptId($client['id'])); ?>" 
                                   class="inline-flex items-center justify-center w-8 h-8 sm:w-auto sm:h-auto sm:px-3 sm:py-2 text-gray-600 hover:text-gray-700 hover:bg-gray-50 rounded-lg transition-colors group"
                                   title="Edit Client">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                    </svg>
                                    <span class="hidden sm:inline ml-1 text-xs font-medium">Edit</span>
                                </a>
                                
                                <!-- New Project Button -->
                                <a href="<?php echo Helper::baseUrl('modules/projects/add.php?client_id=' . Helper::encryptId($client['id'])); ?>" 
                                   class="inline-flex items-center justify-center w-8 h-8 sm:w-auto sm:h-auto sm:px-3 sm:py-2 text-green-600 hover:text-green-700 hover:bg-green-50 rounded-lg transition-colors group"
                                   title="New Project">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                    </svg>
                                    <span class="hidden sm:inline ml-1 text-xs font-medium">Project</span>
                                </a>
                                
                                <!-- Delete Button -->
                                <button onclick="confirmDelete(<?php echo $client['id']; ?>, '<?php echo addslashes($client['company_name']); ?>')"
                                        class="inline-flex items-center justify-center w-8 h-8 sm:w-auto sm:h-auto sm:px-3 sm:py-2 text-red-600 hover:text-red-700 hover:bg-red-50 rounded-lg transition-colors group"
                                        title="Delete Client">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                    <span class="hidden sm:inline ml-1 text-xs font-medium">Delete</span>
                                </button>
                            </div>
                        </div>

                        <!-- Client Info Grid -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                            <div class="flex items-center text-sm text-gray-600">
                                <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                                </svg>
                                <?php echo htmlspecialchars($client['mobile_number']); ?>
                            </div>
                            <div class="flex items-center text-sm text-gray-600">
                                <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                                <?php echo htmlspecialchars($client['city']); ?>
                            </div>
                        </div>

                        <!-- Stats Row -->
                        <div class="flex items-center justify-between pt-3 border-t border-gray-100">
                            <div class="flex items-center space-x-4">
                                <div class="text-sm">
                                    <span class="font-medium text-gray-900"><?php echo number_format($client['project_count']); ?></span>
                                    <span class="text-gray-500">project<?php echo $client['project_count'] !== 1 ? 's' : ''; ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="mt-6 flex items-center justify-between">
            <div class="text-sm text-gray-600">
                Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $totalClients); ?> of <?php echo $totalClients; ?> clients
            </div>
            
            <div class="flex items-center space-x-1">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                       class="px-3 py-2 text-sm text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                        Previous
                    </a>
                <?php endif; ?>
                
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                
                for ($i = $startPage; $i <= $endPage; $i++):
                ?>
                    <a href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                       class="px-3 py-2 text-sm <?php echo $i === $page ? 'bg-gray-800 text-white' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-100'; ?> rounded-lg transition-colors">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                       class="px-3 py-2 text-sm text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                        Next
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-xl max-w-md w-full p-6">
        <div class="flex items-center mb-4">
            <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center mr-3">
                <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                </svg>
            </div>
            <h3 class="text-lg font-semibold text-gray-900">Delete Client</h3>
        </div>
        <p class="text-gray-600 mb-6">
            Are you sure you want to delete <strong id="clientName"></strong>? This action cannot be undone and will also delete all associated projects and invoices.
        </p>
        <div class="flex items-center justify-end space-x-3">
            <button onclick="closeDeleteModal()" class="px-4 py-2 text-gray-600 hover:text-gray-800 transition-colors">
                Cancel
            </button>
            <button id="confirmDeleteBtn" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                Delete Client
            </button>
        </div>
    </div>
</div>

<style>
/* Mobile optimizations for action buttons */
@media (max-width: 640px) {
    .grid.grid-cols-1.sm\:grid-cols-2 {
        grid-template-columns: repeat(1, minmax(0, 1fr));
    }
    
    .flex.flex-col.sm\:flex-row {
        flex-direction: column;
    }
    
    .space-x-4 > :not([hidden]) ~ :not([hidden]) {
        margin-left: 0;
        margin-top: 0.5rem;
    }
    
    /* Ensure action buttons are properly sized on mobile */
    .action-buttons {
        min-width: 32px;
        min-height: 32px;
    }
    
    /* Stack buttons vertically on very small screens */
    @media (max-width: 480px) {
        .flex.items-center.gap-2 {
            flex-wrap: wrap;
        }
    }
}

/* Action button hover effects */
.group:hover svg {
    transform: scale(1.1);
    transition: transform 0.2s ease;
}

/* Button focus states for accessibility */
button:focus,
a:focus {
    outline: 2px solid #3B82F6;
    outline-offset: 2px;
}

/* Card hover effects */
.bg-white.rounded-xl.border:hover {
    transform: translateY(-1px);
    transition: transform 0.2s ease;
}

/* Ensure proper touch targets on mobile */
@media (max-width: 768px) {
    .w-8.h-8 {
        min-width: 44px;
        min-height: 44px;
    }
}
</style>

<script>
// Delete confirmation
let clientToDelete = null;

function confirmDelete(clientId, clientName) {
    clientToDelete = clientId;
    document.getElementById('clientName').textContent = clientName;
    document.getElementById('deleteModal').classList.remove('hidden');
    document.getElementById('deleteModal').classList.add('flex');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
    document.getElementById('deleteModal').classList.remove('flex');
    clientToDelete = null;
}

document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
    if (clientToDelete) {
        // Create form and submit
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'ClientController.php';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'delete';
        
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'client_id';
        idInput.value = clientToDelete;
        
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = '<?php echo Helper::generateCSRF(); ?>';
        
        form.appendChild(actionInput);
        form.appendChild(idInput);
        form.appendChild(csrfInput);
        
        document.body.appendChild(form);
        form.submit();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDeleteModal();
    }
});

// Auto-focus search on desktop
if (window.innerWidth > 768) {
    document.querySelector('input[name="search"]')?.focus();
}

// Loading states for better UX
document.querySelectorAll('a, button').forEach(element => {
    element.addEventListener('click', function() {
        if (this.type !== 'button' || this.onclick) {
            this.style.opacity = '0.7';
            this.style.pointerEvents = 'none';
            
            setTimeout(() => {
                this.style.opacity = '';
                this.style.pointerEvents = '';
            }, 3000);
        }
    });
});
</script>

<?php include '../../includes/footer.php'; ?>