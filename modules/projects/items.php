<?php
// modules/projects/items.php
session_start();

require_once '../../core/Database.php';
require_once '../../core/Helper.php';

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

// Get and validate project ID
$projectId = Helper::decryptId($_GET['project_id'] ?? '');
if (!$projectId) {
    Helper::setMessage('Invalid project ID.', 'error');
    Helper::redirect('modules/projects/');
}

$pageTitle = 'Project Items - Invoice Manager';

// Initialize variables
$errors = [];
$successMessage = '';
$project = null;
$projectItems = [];
$formData = [
    'item_name' => '',
    'description' => '',
    'item_type' => '',
    'quantity' => 1,
    'unit_price' => ''
];

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Get project details with client information
try {
    $projectQuery = "
        SELECT p.*, c.company_name, c.contact_person
        FROM projects p 
        LEFT JOIN clients c ON p.client_id = c.id 
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
} catch (Exception $e) {
    Helper::setMessage('Error loading project details: ' . $e->getMessage(), 'error');
    Helper::redirect('modules/projects/');
}

// Handle item deletion
if (isset($_GET['delete_item'])) {
    $itemId = Helper::decryptId($_GET['delete_item']);
    if ($itemId) {
        try {
            $deleteQuery = "DELETE FROM project_items WHERE id = :item_id AND project_id = :project_id";
            $deleteStmt = $db->prepare($deleteQuery);
            $deleteStmt->bindParam(':item_id', $itemId);
            $deleteStmt->bindParam(':project_id', $projectId);
            
            if ($deleteStmt->execute()) {
                Helper::setMessage('Item deleted successfully!', 'success');
            } else {
                Helper::setMessage('Error deleting item.', 'error');
            }
        } catch (Exception $e) {
            Helper::setMessage('Database error: ' . $e->getMessage(), 'error');
        }
        
        // Redirect to avoid resubmission
        Helper::redirect('modules/projects/items.php?project_id=' . Helper::encryptId($projectId));
    }
}

// Handle form submission for adding new item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['edit_item_id'])) {
    // Verify CSRF token
    if (!Helper::verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        // Get and sanitize form data
        $formData['item_name'] = Helper::sanitize($_POST['item_name'] ?? '');
        $formData['description'] = Helper::sanitize($_POST['description'] ?? '');
        $formData['item_type'] = Helper::sanitize($_POST['item_type'] ?? '');
        $formData['quantity'] = (int)($_POST['quantity'] ?? 1);
        $formData['unit_price'] = Helper::sanitize($_POST['unit_price'] ?? '');
        
        // Validate required fields
        if (empty($formData['item_name'])) {
            $errors[] = 'Item name is required.';
        } elseif (strlen($formData['item_name']) > 200) {
            $errors[] = 'Item name must be less than 200 characters.';
        }
        
        if (empty($formData['item_type'])) {
            $errors[] = 'Item type is required.';
        }
        
        if ($formData['quantity'] < 1) {
            $errors[] = 'Quantity must be at least 1.';
        }
        
        if (empty($formData['unit_price'])) {
            $errors[] = 'Unit price is required.';
        } elseif (!is_numeric($formData['unit_price']) || $formData['unit_price'] < 0) {
            $errors[] = 'Unit price must be a valid positive number.';
        }
        
        // If no errors, save the item
        if (empty($errors)) {
            try {
                $totalPrice = $formData['quantity'] * $formData['unit_price'];
                
                $insertQuery = "INSERT INTO project_items (
                    project_id, item_name, description, item_type, 
                    quantity, unit_price, total_price, created_at, updated_at
                ) VALUES (
                    :project_id, :item_name, :description, :item_type, 
                    :quantity, :unit_price, :total_price, NOW(), NOW()
                )";
                
                $insertStmt = $db->prepare($insertQuery);
                $insertStmt->bindParam(':project_id', $projectId);
                $insertStmt->bindParam(':item_name', $formData['item_name']);
                $insertStmt->bindParam(':description', $formData['description']);
                $insertStmt->bindParam(':item_type', $formData['item_type']);
                $insertStmt->bindParam(':quantity', $formData['quantity']);
                $insertStmt->bindParam(':unit_price', $formData['unit_price']);
                $insertStmt->bindParam(':total_price', $totalPrice);
                
                if ($insertStmt->execute()) {
                    Helper::setMessage('Item "' . $formData['item_name'] . '" added successfully!', 'success');
                    // Reset form data
                    $formData = [
                        'item_name' => '',
                        'description' => '',
                        'item_type' => '',
                        'quantity' => 1,
                        'unit_price' => ''
                    ];
                } else {
                    $errors[] = 'Error adding item. Please try again.';
                }
                
            } catch (Exception $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Handle item editing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_item_id'])) {
    $editItemId = Helper::decryptId($_POST['edit_item_id']);
    
    // Verify CSRF token
    if (!Helper::verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        // Get and sanitize form data
        $editData = [
            'item_name' => Helper::sanitize($_POST['edit_item_name'] ?? ''),
            'description' => Helper::sanitize($_POST['edit_description'] ?? ''),
            'item_type' => Helper::sanitize($_POST['edit_item_type'] ?? ''),
            'quantity' => (int)($_POST['edit_quantity'] ?? 1),
            'unit_price' => Helper::sanitize($_POST['edit_unit_price'] ?? '')
        ];
        
        // Validate required fields
        if (empty($editData['item_name'])) {
            $errors[] = 'Item name is required.';
        } elseif (strlen($editData['item_name']) > 200) {
            $errors[] = 'Item name must be less than 200 characters.';
        }
        
        if (empty($editData['item_type'])) {
            $errors[] = 'Item type is required.';
        }
        
        if ($editData['quantity'] < 1) {
            $errors[] = 'Quantity must be at least 1.';
        }
        
        if (empty($editData['unit_price'])) {
            $errors[] = 'Unit price is required.';
        } elseif (!is_numeric($editData['unit_price']) || $editData['unit_price'] < 0) {
            $errors[] = 'Unit price must be a valid positive number.';
        }
        
        // If no errors, update the item
        if (empty($errors)) {
            try {
                $totalPrice = $editData['quantity'] * $editData['unit_price'];
                
                $updateQuery = "UPDATE project_items SET 
                    item_name = :item_name, 
                    description = :description, 
                    item_type = :item_type, 
                    quantity = :quantity, 
                    unit_price = :unit_price, 
                    total_price = :total_price, 
                    updated_at = NOW()
                WHERE id = :item_id AND project_id = :project_id";
                
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->bindParam(':item_name', $editData['item_name']);
                $updateStmt->bindParam(':description', $editData['description']);
                $updateStmt->bindParam(':item_type', $editData['item_type']);
                $updateStmt->bindParam(':quantity', $editData['quantity']);
                $updateStmt->bindParam(':unit_price', $editData['unit_price']);
                $updateStmt->bindParam(':total_price', $totalPrice);
                $updateStmt->bindParam(':item_id', $editItemId);
                $updateStmt->bindParam(':project_id', $projectId);
                
                if ($updateStmt->execute()) {
                    Helper::setMessage('Item updated successfully!', 'success');
                } else {
                    $errors[] = 'Error updating item. Please try again.';
                }
                
            } catch (Exception $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Get all project items
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
    $errors[] = 'Error loading project items: ' . $e->getMessage();
    $projectItems = [];
}

// Calculate totals
$totalItems = count($projectItems);
$totalQuantity = array_sum(array_column($projectItems, 'quantity'));
$totalValue = array_sum(array_column($projectItems, 'total_price'));

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
        <a href="<?php echo Helper::baseUrl('modules/projects/view.php?id=' . Helper::encryptId($projectId)); ?>" 
           class="hover:text-gray-900 transition-colors">
            <?php echo htmlspecialchars($project['project_name']); ?>
        </a>
        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
        </svg>
        <span class="text-gray-900 font-medium">Items</span>
    </div>
</nav>

<!-- Page Header -->
<div class="mb-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Project Items</h1>
            <p class="text-gray-600 mt-1">
                Manage items for <strong><?php echo htmlspecialchars($project['project_name']); ?></strong>
                <span class="text-gray-400">•</span>
                <strong><?php echo htmlspecialchars($project['company_name']); ?></strong>
            </p>
        </div>
        <div class="mt-4 sm:mt-0">
            <a href="<?php echo Helper::baseUrl('modules/projects/view.php?id=' . Helper::encryptId($projectId)); ?>" 
               class="inline-flex items-center px-4 py-2 text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Back to Project
            </a>
        </div>
    </div>
</div>

<!-- Flash Messages -->
<?php $flashMessage = Helper::getFlashMessage(); ?>
<?php if ($flashMessage): ?>
    <div class="mb-6 p-4 rounded-lg <?php echo $flashMessage['type'] === 'success' ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200'; ?>">
        <div class="flex items-center">
            <svg class="w-5 h-5 <?php echo $flashMessage['type'] === 'success' ? 'text-green-600' : 'text-red-600'; ?> mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <?php if ($flashMessage['type'] === 'success'): ?>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                <?php else: ?>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                <?php endif; ?>
            </svg>
            <span class="<?php echo $flashMessage['type'] === 'success' ? 'text-green-800' : 'text-red-800'; ?> font-medium">
                <?php echo htmlspecialchars($flashMessage['message']); ?>
            </span>
        </div>
    </div>
<?php endif; ?>

<!-- Error Messages -->
<?php if (!empty($errors)): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
        <div class="flex items-center mb-2">
            <svg class="w-5 h-5 text-red-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <h3 class="text-red-800 font-medium">Please fix the following errors:</h3>
        </div>
        <ul class="text-red-700 text-sm space-y-1">
            <?php foreach ($errors as $error): ?>
                <li>• <?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Statistics Cards -->
<div class="grid grid-cols-1 md:grid-cols-1 gap-6 mb-8">
    <div class="bg-white rounded-xl p-6 border border-gray-200">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
        </div>
        <div class="mt-4">
            <p class="text-3xl font-bold text-gray-900"><?php echo Helper::formatCurrency($totalValue); ?></p>
            <p class="text-sm text-gray-600">Total Project Value</p>
        </div>
    </div>
</div>

<!-- Add New Item Form -->
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-8">
    <div class="p-6 border-b border-gray-200">
        <h2 class="text-lg font-semibold text-gray-900">Add New Item</h2>
        <p class="text-gray-600 text-sm mt-1">Add items to track project components and pricing</p>
    </div>
    
    <div class="p-6">
        <form method="POST" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4" novalidate>
            <!-- CSRF Token -->
            <input type="hidden" name="csrf_token" value="<?php echo Helper::generateCSRF(); ?>">
            
            <!-- Item Name -->
            <div class="lg:col-span-2">
                <label for="item_name" class="block text-sm font-medium text-gray-700 mb-1">
                    Item Name <span class="text-red-500">*</span>
                </label>
                <input 
                    type="text" 
                    id="item_name" 
                    name="item_name" 
                    value="<?php echo htmlspecialchars($formData['item_name']); ?>"
                    maxlength="200"
                    required
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent text-sm"
                    placeholder="Enter item name"
                >
            </div>
            
            <!-- Item Type -->
            <div>
                <label for="item_type" class="block text-sm font-medium text-gray-700 mb-1">
                    Type <span class="text-red-500">*</span>
                </label>
                <select 
                    id="item_type" 
                    name="item_type" 
                    required
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent text-sm"
                >
                    <option value="">Select type...</option>
                    <option value="graphics" <?php echo $formData['item_type'] === 'graphics' ? 'selected' : ''; ?>>Graphics</option>
                    <option value="social_media" <?php echo $formData['item_type'] === 'social_media' ? 'selected' : ''; ?>>Social Media</option>
                    <option value="website" <?php echo $formData['item_type'] === 'website' ? 'selected' : ''; ?>>Website</option>
                    <option value="software" <?php echo $formData['item_type'] === 'software' ? 'selected' : ''; ?>>Software</option>
                    <option value="other" <?php echo $formData['item_type'] === 'other' ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>
            
            <!-- Quantity -->
            <div>
                <label for="quantity" class="block text-sm font-medium text-gray-700 mb-1">
                    Quantity <span class="text-red-500">*</span>
                </label>
                <input 
                    type="number" 
                    id="quantity" 
                    name="quantity" 
                    value="<?php echo htmlspecialchars($formData['quantity']); ?>"
                    min="1"
                    required
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent text-sm"
                >
            </div>
            
            <!-- Unit Price -->
            <div>
                <label for="unit_price" class="block text-sm font-medium text-gray-700 mb-1">
                    Unit Price (LKR) <span class="text-red-500">*</span>
                </label>
                <input 
                    type="number" 
                    id="unit_price" 
                    name="unit_price" 
                    value="<?php echo htmlspecialchars($formData['unit_price']); ?>"
                    min="0"
                    step="0.01"
                    required
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent text-sm"
                    placeholder="0.00"
                >
            </div>
            
            <!-- Description (Full Width) -->
            <div class="lg:col-span-5">
                <label for="description" class="block text-sm font-medium text-gray-700 mb-1">
                    Description
                </label>
                <textarea 
                    id="description" 
                    name="description" 
                    rows="2"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent text-sm resize-none"
                    placeholder="Optional item description..."
                ><?php echo htmlspecialchars($formData['description']); ?></textarea>
            </div>
            
            <!-- Submit Button -->
            <div class="lg:col-span-5 flex justify-end">
                <button 
                    type="submit" 
                    class="inline-flex items-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition-colors text-sm font-medium"
                >
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                    </svg>
                    Add Item
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Items List -->
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <div class="p-6 border-b border-gray-200">
        <h2 class="text-lg font-semibold text-gray-900">Project Items</h2>
        <p class="text-gray-600 text-sm mt-1"><?php echo $totalItems; ?> items • Total value: <?php echo Helper::formatCurrency($totalValue); ?></p>
    </div>
    
    <?php if (empty($projectItems)): ?>
        <div class="p-8 text-center">
            <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
            </svg>
            <h3 class="text-lg font-medium text-gray-900 mb-2">No items yet</h3>
            <p class="text-gray-600">Start by adding the first item to this project using the form above.</p>
        </div>
    <?php else: ?>
        <!-- Desktop Table View -->
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Unit Price</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($projectItems as $item): ?>
                        <tr class="hover:bg-gray-50" id="item-row-<?php echo $item['id']; ?>">
                            <td class="px-6 py-4">
                                <div>
                                    <div class="font-medium text-gray-900"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                    <?php if (!empty($item['description'])): ?>
                                        <div class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars($item['description']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                    <?php echo ucwords(str_replace('_', ' ', $item['item_type'])); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right font-medium text-gray-900">
                                <?php echo number_format($item['quantity']); ?>
                            </td>
                            <td class="px-6 py-4 text-right font-medium text-gray-900">
                                <?php echo Helper::formatCurrency($item['unit_price']); ?>
                            </td>
                            <td class="px-6 py-4 text-right font-bold text-gray-900">
                                <?php echo Helper::formatCurrency($item['total_price']); ?>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <div class="flex items-center justify-center space-x-2">
                                    <button 
                                        onclick="editItem(<?php echo htmlspecialchars(json_encode($item)); ?>)"
                                        class="text-blue-600 hover:text-blue-900 transition-colors"
                                        title="Edit item"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                        </svg>
                                    </button>
                                    <button 
                                        onclick="deleteItem('<?php echo Helper::encryptId($item['id']); ?>', '<?php echo htmlspecialchars($item['item_name']); ?>')"
                                        class="text-red-600 hover:text-red-900 transition-colors"
                                        title="Delete item"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-gray-50">
                    <tr>
                        <td colspan="4" class="px-6 py-4 text-right font-bold text-gray-900">Total Project Value:</td>
                        <td class="px-6 py-4 text-right font-bold text-gray-900 text-lg">
                            <?php echo Helper::formatCurrency($totalValue); ?>
                        </td>
                        <td class="px-6 py-4"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Mobile Card View -->
        <div class="md:hidden space-y-3 p-4">
            <?php foreach ($projectItems as $item): ?>
                <div class="bg-white rounded-lg p-4 border border-gray-200 hover:shadow-sm transition-all" id="mobile-item-<?php echo $item['id']; ?>">
                    <!-- Item Header -->
                    <div class="flex justify-between items-start mb-3">
                        <div class="flex-1 min-w-0">
                            <h3 class="font-medium text-gray-900 text-base mb-1">
                                <?php echo htmlspecialchars($item['item_name']); ?>
                            </h3>
                            <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-blue-100 text-blue-700">
                                <?php echo ucwords(str_replace('_', ' ', $item['item_type'])); ?>
                            </span>
                        </div>
                        <!-- Action Buttons -->
                        <div class="flex items-center space-x-1 ml-3">
                            <button 
                                onclick="editItem(<?php echo htmlspecialchars(json_encode($item)); ?>)"
                                class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                                title="Edit"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                </svg>
                            </button>
                            <button 
                                onclick="deleteItem('<?php echo Helper::encryptId($item['id']); ?>', '<?php echo htmlspecialchars($item['item_name']); ?>')"
                                class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                                title="Delete"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Description -->
                    <?php if (!empty($item['description'])): ?>
                        <p class="text-sm text-gray-600 mb-3 leading-relaxed"><?php echo htmlspecialchars($item['description']); ?></p>
                    <?php endif; ?>

                    <!-- Item Details -->
                    <div class="flex items-center justify-between text-sm">
                        <div class="flex items-center space-x-4 text-gray-600">
                            <span><strong><?php echo number_format($item['quantity']); ?></strong> × <?php echo Helper::formatCurrency($item['unit_price']); ?></span>
                        </div>
                        <div class="text-right">
                            <span class="text-lg font-bold text-gray-900"><?php echo Helper::formatCurrency($item['total_price']); ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Sticky Total Project Value (Mobile) -->
<div class="md:hidden fixed bottom-0 left-0 right-0 bg-gray-800 text-white p-4 shadow-lg border-t border-gray-700 z-40">
    <div class="flex justify-between items-center">
        <span class="text-lg font-medium">Total Project Value</span>
        <span class="text-2xl font-bold"><?php echo Helper::formatCurrency($totalValue); ?></span>
    </div>
</div>

<!-- Bottom padding for sticky footer -->
<div class="md:hidden h-20"></div>

<!-- Edit Item Modal -->
<div id="editModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md bg-white rounded-lg shadow-lg">
        <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Edit Item</h3>
            
            <form id="editForm" method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo Helper::generateCSRF(); ?>">
                <input type="hidden" name="edit_item_id" id="editItemId">
                
                <div>
                    <label for="edit_item_name" class="block text-sm font-medium text-gray-700 mb-1">
                        Item Name <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="text" 
                        id="edit_item_name" 
                        name="edit_item_name" 
                        maxlength="200"
                        required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent text-sm"
                    >
                </div>
                
                <div>
                    <label for="edit_item_type" class="block text-sm font-medium text-gray-700 mb-1">
                        Type <span class="text-red-500">*</span>
                    </label>
                    <select 
                        id="edit_item_type" 
                        name="edit_item_type" 
                        required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent text-sm"
                    >
                        <option value="">Select type...</option>
                        <option value="graphics">Graphics</option>
                        <option value="social_media">Social Media</option>
                        <option value="website">Website</option>
                        <option value="software">Software</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="edit_quantity" class="block text-sm font-medium text-gray-700 mb-1">
                            Quantity <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="number" 
                            id="edit_quantity" 
                            name="edit_quantity" 
                            min="1"
                            required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent text-sm"
                        >
                    </div>
                    
                    <div>
                        <label for="edit_unit_price" class="block text-sm font-medium text-gray-700 mb-1">
                            Unit Price <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="number" 
                            id="edit_unit_price" 
                            name="edit_unit_price" 
                            min="0"
                            step="0.01"
                            required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent text-sm"
                        >
                    </div>
                </div>
                
                <div>
                    <label for="edit_description" class="block text-sm font-medium text-gray-700 mb-1">
                        Description
                    </label>
                    <textarea 
                        id="edit_description" 
                        name="edit_description" 
                        rows="3"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent text-sm resize-none"
                    ></textarea>
                </div>
                
                <div class="flex justify-end space-x-3 pt-4">
                    <button 
                        type="button" 
                        onclick="closeEditModal()"
                        class="px-4 py-2 bg-white text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors text-sm font-medium"
                    >
                        Cancel
                    </button>
                    <button 
                        type="submit" 
                        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium"
                    >
                        Update Item
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
/* Custom styles for the items page */
@media (max-width: 768px) {
    .grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-5 {
        grid-template-columns: repeat(1, minmax(0, 1fr));
    }
    
    .lg\:col-span-2 {
        grid-column: span 1;
    }
    
    .lg\:col-span-5 {
        grid-column: span 1;
    }
    
    /* Make table scrollable on mobile */
    .overflow-x-auto {
        -webkit-overflow-scrolling: touch;
    }
    
    table {
        min-width: 600px;
    }
    
    /* Add padding to body to prevent sticky footer overlap */
    body {
        padding-bottom: 80px;
    }
    
    /* Ensure content doesn't get hidden behind sticky footer */
    .container {
        margin-bottom: 80px;
    }
}

/* Table hover effects */
tbody tr:hover {
    background-color: #f9fafb;
}

/* Modal backdrop */
.modal-backdrop {
    backdrop-filter: blur(4px);
}

/* Form focus states */
input:focus, textarea:focus, select:focus {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

/* Button hover effects */
button:hover {
    transform: translateY(-1px);
}

/* Sticky footer styles */
.sticky-total {
    backdrop-filter: blur(10px);
    box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.15);
}

/* Mobile card improvements */
@media (max-width: 768px) {
    .mobile-card {
        transition: all 0.2s ease;
    }
    
    .mobile-card:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }
}

/* Badge styles */
.badge-graphics { background-color: #e0f2fe; color: #0369a1; }
.badge-social-media { background-color: #fce7f3; color: #be185d; }
.badge-website { background-color: #dcfce7; color: #166534; }
.badge-software { background-color: #fef3c7; color: #a16207; }
.badge-other { background-color: #f3f4f6; color: #374151; }

/* Smooth scrolling */
html {
    scroll-behavior: smooth;
}

/* Loading state for dynamic updates */
.loading {
    opacity: 0.6;
    pointer-events: none;
}

/* Animation for total updates */
.total-update {
    animation: pulse 0.5s ease-in-out;
}

@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.05); }
    100% { transform: scale(1); }
}
</style>

<script>
// Calculate totals automatically
function calculateTotal() {
    const quantity = parseFloat(document.getElementById('quantity').value) || 0;
    const unitPrice = parseFloat(document.getElementById('unit_price').value) || 0;
    const total = quantity * unitPrice;
    
    // You could show a live preview here if desired
    // console.log('Total: LKR ' + total.toFixed(2));
}

// Setup auto-calculation
document.getElementById('quantity').addEventListener('input', calculateTotal);
document.getElementById('unit_price').addEventListener('input', calculateTotal);

// Edit item functionality
function editItem(item) {
    document.getElementById('editItemId').value = '<?php echo Helper::encryptId(""); ?>' + item.id;
    document.getElementById('edit_item_name').value = item.item_name;
    document.getElementById('edit_item_type').value = item.item_type;
    document.getElementById('edit_quantity').value = item.quantity;
    document.getElementById('edit_unit_price').value = item.unit_price;
    document.getElementById('edit_description').value = item.description || '';
    
    // Fix the encrypted ID
    document.getElementById('editItemId').value = '<?php echo Helper::encryptId(0); ?>'.replace('0', item.id);
    
    document.getElementById('editModal').classList.remove('hidden');
}

function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
}

// Delete item functionality
function deleteItem(itemId, itemName) {
    if (confirm(`Are you sure you want to delete "${itemName}"? This action cannot be undone.`)) {
        window.location.href = `?project_id=<?php echo Helper::encryptId($projectId); ?>&delete_item=${itemId}`;
    }
}

// Form validation
document.addEventListener('DOMContentLoaded', function() {
    // Auto-focus first field
    const firstField = document.getElementById('item_name');
    if (firstField && window.innerWidth > 768) {
        firstField.focus();
    }
    
    // Format number inputs
    const numberInputs = document.querySelectorAll('input[type="number"]');
    numberInputs.forEach(input => {
        input.addEventListener('input', function() {
            if (this.step === '0.01') {
                // Format price inputs
                const value = parseFloat(this.value);
                if (!isNaN(value)) {
                    this.value = value.toFixed(2);
                }
            }
        });
    });
    
    // Real-time validation
    const requiredFields = document.querySelectorAll('input[required], select[required]');
    requiredFields.forEach(field => {
        field.addEventListener('blur', function() {
            if (this.value.trim() === '') {
                this.classList.add('border-red-300');
            } else {
                this.classList.remove('border-red-300');
            }
        });
        
        field.addEventListener('input', function() {
            if (this.value.trim() !== '') {
                this.classList.remove('border-red-300');
            }
        });
    });
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Escape to close modal
    if (e.key === 'Escape') {
        closeEditModal();
    }
    
    // Ctrl/Cmd + Enter to submit form
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        const activeForm = document.querySelector('form:not(#editForm)');
        if (activeForm && !document.getElementById('editModal').classList.contains('hidden')) {
            document.getElementById('editForm').submit();
        } else if (activeForm) {
            activeForm.submit();
        }
    }
});

// Click outside modal to close
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditModal();
    }
});

// Auto-save form data (optional)
let autoSaveTimer;
function autoSave() {
    const formData = new FormData(document.querySelector('form'));
    const data = Object.fromEntries(formData.entries());
    localStorage.setItem('item_form_draft', JSON.stringify(data));
}

// Load draft on page load
document.addEventListener('DOMContentLoaded', function() {
    const draft = localStorage.getItem('item_form_draft');
    if (draft) {
        try {
            const data = JSON.parse(draft);
            Object.keys(data).forEach(key => {
                const field = document.querySelector(`[name="${key}"]`);
                if (field && key !== 'csrf_token') {
                    field.value = data[key];
                }
            });
        } catch (e) {
            // Invalid JSON, ignore
        }
    }
});

// Clear draft when form is submitted successfully
<?php if (empty($errors) && $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
localStorage.removeItem('item_form_draft');
<?php endif; ?>

// Auto-save on input (debounced)
document.querySelectorAll('input, textarea, select').forEach(field => {
    field.addEventListener('input', function() {
        clearTimeout(autoSaveTimer);
        autoSaveTimer = setTimeout(autoSave, 2000);
    });
});

// Update totals in real-time
function updateTotals() {
    const rows = document.querySelectorAll('tbody tr');
    let totalValue = 0;
    let totalQuantity = 0;
    
    rows.forEach(row => {
        const quantityCell = row.querySelector('td:nth-child(3)');
        const totalCell = row.querySelector('td:nth-child(5)');
        
        if (quantityCell && totalCell) {
            const quantity = parseInt(quantityCell.textContent.replace(/,/g, ''));
            const total = parseFloat(totalCell.textContent.replace(/[^0-9.-]/g, ''));
            
            totalQuantity += quantity;
            totalValue += total;
        }
    });
    
    // Update mobile cards total
    const mobileCards = document.querySelectorAll('[id^="mobile-item-"]');
    if (mobileCards.length > 0) {
        totalValue = 0;
        mobileCards.forEach(card => {
            const totalElement = card.querySelector('.text-lg.font-bold');
            if (totalElement) {
                const total = parseFloat(totalElement.textContent.replace(/[^0-9.-]/g, ''));
                totalValue += total;
            }
        });
    }
    
    // Update sticky footer total
    updateStickyTotal(totalValue);
}

// Update sticky total with animation
function updateStickyTotal(newTotal) {
    const stickyTotal = document.querySelector('.fixed.bottom-0 .text-2xl.font-bold');
    if (stickyTotal) {
        stickyTotal.classList.add('total-update');
        stickyTotal.textContent = formatCurrency(newTotal);
        
        setTimeout(() => {
            stickyTotal.classList.remove('total-update');
        }, 500);
    }
}

// Simple currency formatter
function formatCurrency(amount) {
    return 'LKR ' + new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(amount);
}

// Smooth scroll to top when adding new item
function scrollToForm() {
    const form = document.querySelector('#item_name');
    if (form) {
        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

// Initialize calculations
document.addEventListener('DOMContentLoaded', function() {
    updateTotals();
    
    // Add event listeners for form submissions to trigger total updates
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function() {
            // Add slight delay to allow DOM updates
            setTimeout(updateTotals, 100);
        });
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
