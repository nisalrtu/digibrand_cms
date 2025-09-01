-- Invoicing App Database Schema
-- Created for PHP Backend with Tailwind CSS and JS Frontend

-- Users table (Admin and Employee login)
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'employee') NOT NULL DEFAULT 'employee',
    full_name VARCHAR(100) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Clients table (Simple as requested)
CREATE TABLE clients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_name VARCHAR(200) NOT NULL,
    contact_person VARCHAR(100) NOT NULL,
    mobile_number VARCHAR(20) NOT NULL,
    address TEXT NOT NULL,
    city VARCHAR(100) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Projects table
CREATE TABLE projects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id INT NOT NULL,
    project_name VARCHAR(200) NOT NULL,
    description TEXT,
    project_type ENUM('graphics', 'social_media', 'website', 'software', 'other') NOT NULL,
    status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    total_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    start_date DATE,
    end_date DATE,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Project items/services table
CREATE TABLE project_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    item_name VARCHAR(200) NOT NULL,
    description TEXT,
    item_type ENUM('graphics', 'social_media', 'website', 'software', 'other') NOT NULL,
    quantity INT DEFAULT 1,
    unit_price DECIMAL(10, 2) NOT NULL,
    total_price DECIMAL(10, 2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

-- Invoices table
CREATE TABLE invoices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_number VARCHAR(50) UNIQUE NOT NULL,
    project_id INT NOT NULL,
    client_id INT NOT NULL,
    invoice_date DATE NOT NULL,
    due_date DATE NOT NULL,
    subtotal DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    tax_rate DECIMAL(5, 2) DEFAULT 0.00,
    tax_amount DECIMAL(10, 2) DEFAULT 0.00,
    total_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    paid_amount DECIMAL(10, 2) DEFAULT 0.00,
    balance_amount DECIMAL(10, 2) DEFAULT 0.00,
    status ENUM('draft', 'sent', 'paid', 'partially_paid', 'overdue') DEFAULT 'draft',
    notes TEXT,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Payments table (Track all payments - partial and full)
CREATE TABLE payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_id INT NOT NULL,
    payment_amount DECIMAL(10, 2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method ENUM('cash', 'bank_transfer', 'check', 'card', 'online', 'other') NOT NULL,
    payment_reference VARCHAR(100),
    notes TEXT,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Create invoice_items table to store individual line items for invoices
CREATE TABLE invoice_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_id INT NOT NULL,
    description TEXT NOT NULL,
    quantity DECIMAL(10, 2) NOT NULL DEFAULT 1.00,
    unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
);


-- Add index for better performance
CREATE INDEX idx_invoice_items_invoice_id ON invoice_items(invoice_id);


-- Add company_settings table for invoice header information
CREATE TABLE company_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_name VARCHAR(200) NOT NULL,
    email VARCHAR(100),
    mobile_number_1 VARCHAR(20),
    mobile_number_2 VARCHAR(20),
    address TEXT,
    city VARCHAR(100),
    postal_code VARCHAR(20),
    bank_name VARCHAR(100),
    bank_branch VARCHAR(100),
    bank_account_number VARCHAR(50),
    bank_account_name VARCHAR(100),
    tax_number VARCHAR(50),
    website VARCHAR(100),
    logo_path VARCHAR(255) DEFAULT 'assets/uploads/logo.png',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default company settings (modify as needed)
INSERT INTO company_settings (
    company_name, 
    email, 
    mobile_number_1, 
    mobile_number_2, 
    address, 
    city, 
    postal_code,
    bank_name,
    bank_branch,
    bank_account_number,
    bank_account_name,
    tax_number,
    website
) VALUES (
    'Your Company Name',
    'info@yourcompany.com',
    '+94 77 123 4567',
    '+94 11 234 5678',
    '123 Business Street, Business District',
    'Colombo',
    '00100',
    'Commercial Bank of Ceylon',
    'Colombo 03',
    '1234567890',
    'Your Company Name',
    'TIN123456789',
    'www.yourcompany.com'
);


-- Insert default admin user (password: admin123 - should be changed)
INSERT INTO users (username, email, password, role, full_name) VALUES 
('admin', 'admin@company.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'System Administrator');

-- Create indexes for better performance
CREATE INDEX idx_clients_company ON clients(company_name);
CREATE INDEX idx_projects_client ON projects(client_id);
CREATE INDEX idx_projects_status ON projects(status);
CREATE INDEX idx_invoices_number ON invoices(invoice_number);
CREATE INDEX idx_invoices_status ON invoices(status);
CREATE INDEX idx_payments_invoice ON payments(invoice_id);
CREATE INDEX idx_payments_date ON payments(payment_date);

-- Create triggers to update project total amount when items change
DELIMITER //
CREATE TRIGGER update_project_total_after_insert
    AFTER INSERT ON project_items
    FOR EACH ROW
BEGIN
    UPDATE projects 
    SET total_amount = (
        SELECT COALESCE(SUM(total_price), 0) 
        FROM project_items 
        WHERE project_id = NEW.project_id
    )
    WHERE id = NEW.project_id;
END//

CREATE TRIGGER update_project_total_after_update
    AFTER UPDATE ON project_items
    FOR EACH ROW
BEGIN
    UPDATE projects 
    SET total_amount = (
        SELECT COALESCE(SUM(total_price), 0) 
        FROM project_items 
        WHERE project_id = NEW.project_id
    )
    WHERE id = NEW.project_id;
END//

CREATE TRIGGER update_project_total_after_delete
    AFTER DELETE ON project_items
    FOR EACH ROW
BEGIN
    UPDATE projects 
    SET total_amount = (
        SELECT COALESCE(SUM(total_price), 0) 
        FROM project_items 
        WHERE project_id = OLD.project_id
    )
    WHERE id = OLD.project_id;
END//

-- Create trigger to update invoice balance when payments change
CREATE TRIGGER update_invoice_balance_after_payment_insert
    AFTER INSERT ON payments
    FOR EACH ROW
BEGIN
    UPDATE invoices 
    SET 
        paid_amount = (
            SELECT COALESCE(SUM(payment_amount), 0) 
            FROM payments 
            WHERE invoice_id = NEW.invoice_id
        ),
        balance_amount = total_amount - (
            SELECT COALESCE(SUM(payment_amount), 0) 
            FROM payments 
            WHERE invoice_id = NEW.invoice_id
        ),
        status = CASE 
            WHEN (total_amount - (
                SELECT COALESCE(SUM(payment_amount), 0) 
                FROM payments 
                WHERE invoice_id = NEW.invoice_id
            )) = 0 THEN 'paid'
            WHEN (
                SELECT COALESCE(SUM(payment_amount), 0) 
                FROM payments 
                WHERE invoice_id = NEW.invoice_id
            ) > 0 THEN 'partially_paid'
            ELSE status
        END
    WHERE id = NEW.invoice_id;
END//

CREATE TRIGGER update_invoice_balance_after_payment_update
    AFTER UPDATE ON payments
    FOR EACH ROW
BEGIN
    UPDATE invoices 
    SET 
        paid_amount = (
            SELECT COALESCE(SUM(payment_amount), 0) 
            FROM payments 
            WHERE invoice_id = NEW.invoice_id
        ),
        balance_amount = total_amount - (
            SELECT COALESCE(SUM(payment_amount), 0) 
            FROM payments 
            WHERE invoice_id = NEW.invoice_id
        ),
        status = CASE 
            WHEN (total_amount - (
                SELECT COALESCE(SUM(payment_amount), 0) 
                FROM payments 
                WHERE invoice_id = NEW.invoice_id
            )) = 0 THEN 'paid'
            WHEN (
                SELECT COALESCE(SUM(payment_amount), 0) 
                FROM payments 
                WHERE invoice_id = NEW.invoice_id
            ) > 0 THEN 'partially_paid'
            ELSE status
        END
    WHERE id = NEW.invoice_id;
END//

CREATE TRIGGER update_invoice_balance_after_payment_delete
    AFTER DELETE ON payments
    FOR EACH ROW
BEGIN
    UPDATE invoices 
    SET 
        paid_amount = (
            SELECT COALESCE(SUM(payment_amount), 0) 
            FROM payments 
            WHERE invoice_id = OLD.invoice_id
        ),
        balance_amount = total_amount - (
            SELECT COALESCE(SUM(payment_amount), 0) 
            FROM payments 
            WHERE invoice_id = OLD.invoice_id
        ),
        status = CASE 
            WHEN (total_amount - (
                SELECT COALESCE(SUM(payment_amount), 0) 
                FROM payments 
                WHERE invoice_id = OLD.invoice_id
            )) = 0 THEN 'paid'
            WHEN (
                SELECT COALESCE(SUM(payment_amount), 0) 
                FROM payments 
                WHERE invoice_id = OLD.invoice_id
            ) > 0 THEN 'partially_paid'
            ELSE 'sent'
        END
    WHERE id = OLD.invoice_id;
END//
DELIMITER ;