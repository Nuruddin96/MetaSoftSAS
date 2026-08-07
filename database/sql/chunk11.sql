-- ============================================================
-- CHUNK 11: Agency clients + regular client payments
-- ============================================================

CREATE TABLE IF NOT EXISTS clients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_name VARCHAR(200) NOT NULL,
    client_name VARCHAR(150) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    service VARCHAR(255) DEFAULT NULL,
    monthly_charge DECIMAL(12,2) DEFAULT NULL,
    due_amount DECIMAL(12,2) DEFAULT 0,
    advance_amount DECIMAL(12,2) DEFAULT 0,
    status ENUM('active','paused','ended') DEFAULT 'active',
    note TEXT DEFAULT NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    INDEX idx_status (status)
);

CREATE TABLE IF NOT EXISTS client_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    payment_type ENUM('monthly','ad_spend','advance','other') NOT NULL DEFAULT 'monthly',
    amount DECIMAL(12,2) NOT NULL,
    currency ENUM('BDT','USD') NOT NULL DEFAULT 'BDT',
    gateway ENUM('bkash','nagad','bank','cash','other') NOT NULL DEFAULT 'cash',
    month_for VARCHAR(50) DEFAULT NULL,
    payment_date DATE NOT NULL,
    attachment_path VARCHAR(255) DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NULL,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_client (client_id),
    INDEX idx_date (payment_date)
);
