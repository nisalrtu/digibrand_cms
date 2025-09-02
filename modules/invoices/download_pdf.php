<?php
// modules/invoices/download_pdf.php
session_start();

require_once '../../core/Database.php';
require_once '../../core/Helper.php';
require_once '../../vendor/autoload.php'; // Make sure mPDF is installed via composer

// Check if user is logged in
if (!Helper::isLoggedIn()) {
    Helper::redirect('modules/auth/login.php');
}

// Get and validate invoice ID
$invoiceId = Helper::decryptId($_GET['id'] ?? '');
if (!$invoiceId) {
    Helper::setMessage('Invalid invoice ID.', 'error');
    Helper::redirect('modules/invoices/');
}

// Get database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Get company settings
    $companyQuery = "SELECT * FROM company_settings ORDER BY id DESC LIMIT 1";
    $companyStmt = $db->prepare($companyQuery);
    $companyStmt->execute();
    $companySettings = $companyStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get invoice details with client and project information
    $invoiceQuery = "
        SELECT i.*, 
               c.company_name, c.contact_person, c.mobile_number, c.address, c.city,
               p.project_name, p.project_type,
               u.username as created_by_name
        FROM invoices i 
        LEFT JOIN clients c ON i.client_id = c.id 
        LEFT JOIN projects p ON i.project_id = p.id
        LEFT JOIN users u ON i.created_by = u.id
        WHERE i.id = :invoice_id
    ";
    $invoiceStmt = $db->prepare($invoiceQuery);
    $invoiceStmt->bindParam(':invoice_id', $invoiceId);
    $invoiceStmt->execute();
    
    $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        Helper::setMessage('Invoice not found.', 'error');
        Helper::redirect('modules/invoices/');
    }
    
    // Get invoice items
    $invoiceItems = [];
    try {
        $itemsQuery = "
            SELECT * FROM invoice_items 
            WHERE invoice_id = :invoice_id 
            ORDER BY id ASC
        ";
        $itemsStmt = $db->prepare($itemsQuery);
        $itemsStmt->bindParam(':invoice_id', $invoiceId);
        $itemsStmt->execute();
        $invoiceItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $invoiceItems = [];
    }
    
    // Get related payments (for calculating paid/balance amounts)
    $relatedPayments = [];
    try {
        $paymentsQuery = "
            SELECT * FROM payments 
            WHERE invoice_id = :invoice_id 
            ORDER BY payment_date DESC, created_at DESC
        ";
        $paymentsStmt = $db->prepare($paymentsQuery);
        $paymentsStmt->bindParam(':invoice_id', $invoiceId);
        $paymentsStmt->execute();
        $relatedPayments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $relatedPayments = [];
    }
    
} catch (Exception $e) {
    Helper::setMessage('Error loading invoice: ' . $e->getMessage(), 'error');
    Helper::redirect('modules/invoices/');
}

// Set default company info if not found in database
if (!$companySettings) {
    $companySettings = [
        'company_name' => 'Your Company Name',
        'email' => 'info@yourcompany.com',
        'mobile_number_1' => '+94 77 123 4567',
        'mobile_number_2' => '+94 11 234 5678',
        'address' => '123 Business Street, Business District',
        'city' => 'Colombo',
        'postal_code' => '00100',
        'bank_name' => 'Commercial Bank of Ceylon',
        'bank_branch' => 'Colombo 03',
        'bank_account_number' => '1234567890',
        'bank_account_name' => 'Nisal Sudharaka Weerarathne',
        'tax_number' => 'TIN123456789',
        'website' => 'www.yourcompany.com',
        'logo_path' => 'assets/uploads/logo.png'
    ];
}

// Calculate totals
$subtotal = 0;
foreach ($invoiceItems as $item) {
    $subtotal += ($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0);
}

$taxAmount = $subtotal * (($invoice['tax_percentage'] ?? 0) / 100);
$discountAmount = $subtotal * (($invoice['discount_percentage'] ?? 0) / 100);
$total = $subtotal + $taxAmount - $discountAmount;

// Create mPDF instance
$mpdf = new \Mpdf\Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'margin_left' => 15,
    'margin_right' => 15,
    'margin_top' => 15,
    'margin_bottom' => 15,
    'default_font_size' => 11,
    'default_font' => 'DejaVuSans'
]);

// PDF CSS styling - identical to preview page but optimized for PDF
$css = '
<style>
body {
    font-family: DejaVuSans, sans-serif;
    font-size: 12px;
    line-height: 1.4;
    color: #111827;
    margin: 0;
    padding: 0;
}

.invoice-container {
    max-width: 100%;
    margin: 0 auto;
    background: white;
}

.invoice-header {
    padding: 18px 0;
    border-bottom: 1px solid #e5e7eb;
    margin-bottom: 18px;
}

.header-grid {
    width: 100%;
    position: relative;
}

.header-left {
    float: left;
    width: 50%;
}

.header-right {
    float: right;
    width: 50%;
    text-align: right;
}

.company-logo {
    height: 80px;
    width: auto;
    margin-bottom: 10px;
}

.company-name {
    font-size: 16px;
    font-weight: bold;
    color: #111827;
    margin-bottom: 3px;
}

.company-info {
    font-size: 11px;
    color: #6b7280;
    line-height: 1.3;
    margin-bottom: 2px;
}

.invoice-title {
    font-size: 23px;
    font-weight: bold;
    color: #111827;
    margin-bottom: 12px;
}

.project-name {
    font-size: 11px;
    font-weight: 600;
    color: #111827;
    margin-bottom: 10px;
}

.invoice-details {
    font-size: 10px;
    line-height: 1.4;
}

.invoice-detail-row {
    margin-bottom: 3px;
}

.invoice-detail-label {
    color: #6b7280;
    font-weight: 500;
    display: inline-block;
    width: 65px;
}

.invoice-detail-value {
    color: #111827;
    font-weight: 600;
}

.client-section {
    padding: 15px 0;
    border-bottom: 1px solid #e5e7eb;
    margin-bottom: 15px;
}

.bill-to-title {
    font-size: 13px;
    font-weight: 600;
    color: #111827;
    margin-bottom: 8px;
}

.client-info {
    font-size: 10px;
    line-height: 1.3;
    color: #6b7280;
}

.client-company {
    font-weight: 600;
    color: #111827;
    margin-bottom: 2px;
}

.items-section {
    margin-bottom: 15px;
}

.items-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 10px;
}

.items-table th {
    background-color: #f9fafb;
    color: #374151;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 9px;
    letter-spacing: 0.5px;
    padding: 8px 6px;
    border-bottom: 1px solid #e5e7eb;
    text-align: left;
}

.items-table th.text-center {
    text-align: center;
}

.items-table th.text-right {
    text-align: right;
}

.items-table td {
    padding: 6px 6px;
    border-bottom: 1px solid #f3f4f6;
    vertical-align: top;
}

.items-table td.text-center {
    text-align: center;
}

.items-table td.text-right {
    text-align: right;
}

.item-description {
    font-weight: 500;
    color: #111827;
    font-size: 10px;
}

.totals-section {
    margin-top: 15px;
    text-align: right;
}

.totals-container {
    margin-left: auto;
    width: 220px;
}

.totals-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
}

.totals-table td {
    padding: 3px 0;
    border: none;
    vertical-align: top;
}

.totals-table .total-label {
    color: #6b7280;
    font-weight: 500;
    text-align: left;
    font-size: 11px;
}

.totals-table .total-amount {
    color: #111827;
    font-weight: 500;
    text-align: right;
    font-size: 11px;
}

.total-final-row {
    border-top: 1px solid #e5e7eb;
    padding-top: 3px !important;
}

.total-final .total-label {
    font-weight: 600 !important;
    color: #111827 !important;
    font-size: 12px !important;
}

.total-final .total-amount {
    font-weight: bold !important;
    color: #111827 !important;
    font-size: 12px !important;
}

.payment-section-divider {
    border-top: 1px solid #e5e7eb;
    padding-top: 3px !important;
    margin-top: 6px !important;
}

.payment-section-spacer {
    height: 6px;
}

.payment-amounts .total-label {
    font-weight: bold !important;
    color: #059669 !important;
    font-size: 11px !important;
}

.payment-amounts .total-amount {
    font-weight: bold !important;
    color: #059669 !important;
    font-size: 11px !important;
}

.balance-due .total-label {
    font-weight: bold !important;
    color: #dc2626 !important;
    font-size: 12px !important;
}

.balance-due .total-amount {
    font-weight: bold !important;
    color: #dc2626 !important;
    font-size: 12px !important;
}

.notes-section {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #e5e7eb;
}

.notes-title {
    font-size: 13px;
    font-weight: 600;
    color: #111827;
    margin-bottom: 6px;
}

.notes-content {
    font-size: 10px;
    color: #6b7280;
    line-height: 1.4;
    white-space: pre-line;
}

.payment-info-section {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #e5e7eb;
}

.payment-info-title {
    font-size: 13px;
    font-weight: 600;
    color: #111827;
    margin-bottom: 6px;
}

.payment-info {
    font-size: 10px;
    line-height: 1.3;
    margin-bottom: 2px;
}

.payment-info-label {
    color: #6b7280;
    display: inline-block;
    width: 50px;
}

.payment-info-value {
    font-weight: 500;
    color: #111827;
}

.footer-section {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #e5e7eb;
    text-align: center;
}

.footer-text {
    font-size: 9px;
    color: #9ca3af;
}

.footer-tax {
    font-size: 8px;
    color: #d1d5db;
    margin-top: 2px;
}
</style>
';

// Build the HTML content
$html = $css . '
<div class="invoice-container">
    <!-- Invoice Header -->
    <div class="invoice-header">
        <div class="header-grid">
            <div class="header-left">
                <img src="../../' . htmlspecialchars($companySettings['logo_path']) . '" alt="Company Logo" class="company-logo">
                <div class="company-name">' . htmlspecialchars($companySettings['company_name']) . '</div>
                <div class="company-info">' . htmlspecialchars($companySettings['address']) . '</div>
                <div class="company-info">' . htmlspecialchars($companySettings['city'] . ' ' . ($companySettings['postal_code'] ?? '')) . '</div>
                <div class="company-info">' . htmlspecialchars($companySettings['email']) . '</div>
                <div class="company-info">' . htmlspecialchars($companySettings['mobile_number_1']) . '</div>';

if (!empty($companySettings['website'])) {
    $html .= '<div class="company-info">' . htmlspecialchars($companySettings['website']) . '</div>';
}

$html .= '
            </div>
            <div class="header-right">
                <div class="invoice-title">INVOICE</div>';

if (!empty($invoice['project_name'])) {
    $html .= '<div class="project-name">' . htmlspecialchars($invoice['project_name']) . '</div>';
}

$html .= '
                <div class="invoice-details">
                    <div class="invoice-detail-row">
                        <span class="invoice-detail-label">Invoice #:</span>
                        <span class="invoice-detail-value">' . htmlspecialchars($invoice['invoice_number']) . '</span>
                    </div>
                    <div class="invoice-detail-row">
                        <span class="invoice-detail-label">Date:</span>
                        <span class="invoice-detail-value">' . date('M d, Y', strtotime($invoice['invoice_date'])) . '</span>
                    </div>
                    <div class="invoice-detail-row">
                        <span class="invoice-detail-label">Due Date:</span>
                        <span class="invoice-detail-value">' . date('M d, Y', strtotime($invoice['due_date'])) . '</span>
                    </div>
                </div>
            </div>
            <div style="clear: both;"></div>
        </div>
    </div>

    <!-- Client Information -->
    <div class="client-section">
        <div class="bill-to-title">Bill To:</div>
        <div class="client-company">' . htmlspecialchars($invoice['company_name']) . '</div>
        <div class="client-info">' . htmlspecialchars($invoice['contact_person']) . '</div>
        <div class="client-info">' . htmlspecialchars($invoice['address']) . '</div>
        <div class="client-info">' . htmlspecialchars($invoice['city']) . '</div>';

if (!empty($invoice['mobile_number'])) {
    $html .= '<div class="client-info">' . htmlspecialchars($invoice['mobile_number']) . '</div>';
}

$html .= '
    </div>

    <!-- Invoice Items -->
    <div class="items-section">';

if (!empty($invoiceItems)) {
    $html .= '
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 50%;">Description</th>
                    <th class="text-center" style="width: 15%;">Qty</th>
                    <th class="text-right" style="width: 17.5%;">Unit Price</th>
                    <th class="text-right" style="width: 17.5%;">Total</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($invoiceItems as $item) {
        $itemTotal = ($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0);
        $html .= '
                <tr>
                    <td>
                        <div class="item-description">' . htmlspecialchars($item['description']) . '</div>
                    </td>
                    <td class="text-center">' . number_format($item['quantity'], 2) . '</td>
                    <td class="text-right">LKR ' . number_format($item['unit_price'], 2) . '</td>
                    <td class="text-right">LKR ' . number_format($itemTotal, 2) . '</td>
                </tr>';
    }

    $html .= '
            </tbody>
        </table>';
} else {
    $html .= '<div style="text-align: center; padding: 20px; color: #6b7280;">No items found for this invoice.</div>';
}

$html .= '
    </div>

    <!-- Totals -->
    <div class="totals-section">
        <div class="totals-container">
            <table class="totals-table">
                <tr>
                    <td class="total-label">Subtotal:</td>
                    <td class="total-amount">LKR ' . number_format($subtotal, 2) . '</td>
                </tr>';

if (($invoice['discount_percentage'] ?? 0) > 0) {
    $html .= '
                <tr>
                    <td class="total-label">Discount (' . number_format($invoice['discount_percentage'], 1) . '%):</td>
                    <td class="total-amount">-LKR ' . number_format($discountAmount, 2) . '</td>
                </tr>';
}

if (($invoice['tax_percentage'] ?? 0) > 0) {
    $html .= '
                <tr>
                    <td class="total-label">Tax (' . number_format($invoice['tax_percentage'], 1) . '%):</td>
                    <td class="total-amount">LKR ' . number_format($taxAmount, 2) . '</td>
                </tr>';
}

$html .= '
                <tr class="total-final">
                    <td class="total-label total-final-row">Total:</td>
                    <td class="total-amount total-final-row">LKR ' . number_format($total, 2) . '</td>
                </tr>';

// Add payment information if payments exist
if (!empty($relatedPayments)) {
    $html .= '
                <tr>
                    <td colspan="2" class="payment-section-spacer"></td>
                </tr>
                <tr class="payment-amounts">
                    <td class="total-label payment-section-divider">Paid Amount:</td>
                    <td class="total-amount payment-section-divider">LKR ' . number_format($invoice['paid_amount'] ?? 0, 2) . '</td>
                </tr>';
    
    if (($invoice['balance_amount'] ?? 0) > 0) {
        $html .= '
                <tr class="balance-due">
                    <td class="total-label">Balance Due:</td>
                    <td class="total-amount">LKR ' . number_format($invoice['balance_amount'], 2) . '</td>
                </tr>';
    }
}

$html .= '
            </table>
        </div>
    </div>';

// Notes
if (!empty($invoice['notes'])) {
    $html .= '
    <div class="notes-section">
        <div class="notes-title">Notes:</div>
        <div class="notes-content">' . nl2br(htmlspecialchars($invoice['notes'])) . '</div>
    </div>';
}

// Payment Information
$html .= '
    <div class="payment-info-section">
        <div class="payment-info-title">Payment Information:</div>
        <div class="payment-info">
            <span class="payment-info-label">Bank:</span>
            <span class="payment-info-value">' . htmlspecialchars($companySettings['bank_name']) . ' - ' . htmlspecialchars($companySettings['bank_branch']) . '</span>
        </div>
        <div class="payment-info">
            <span class="payment-info-label">Account:</span>
            <span class="payment-info-value">' . htmlspecialchars($companySettings['bank_account_number']) . '</span>
        </div>
        <div class="payment-info">
            <span class="payment-info-label">Name:</span>
            <span class="payment-info-value">' . htmlspecialchars($companySettings['bank_account_name']) . '</span>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer-section">
        <div class="footer-text">Thank you for your business! Please make payment within the due date.</div>';

if (!empty($companySettings['tax_number'])) {
    $html .= '<div class="footer-tax">Tax ID: ' . htmlspecialchars($companySettings['tax_number']) . '</div>';
}

$html .= '
    </div>
</div>';

// Write HTML to PDF
$mpdf->WriteHTML($html);

// Output PDF
$filename = 'Invoice-' . $invoice['invoice_number'] . '.pdf';
$mpdf->Output($filename, 'D'); // 'D' for download, 'I' for inline view

?>