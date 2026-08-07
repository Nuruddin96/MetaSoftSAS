-- ============================================================
-- CHUNK 9: Affiliate / Referral system + service leads
-- ============================================================

CREATE TABLE IF NOT EXISTS affiliates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(20) NOT NULL,
    password VARCHAR(255) NOT NULL,
    referral_code VARCHAR(20) NOT NULL UNIQUE,
    payment_method VARCHAR(30) DEFAULT NULL,
    payment_number VARCHAR(30) DEFAULT NULL,
    status ENUM('active','suspended') DEFAULT 'active',
    remember_token VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
);

-- One-time (SaaS signup, 20%) or lifetime-recurring (service package, 1000৳/month) commissions
CREATE TABLE IF NOT EXISTS affiliate_commissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    affiliate_id BIGINT UNSIGNED NOT NULL,
    type ENUM('saas','service') NOT NULL,
    source_label VARCHAR(200) NOT NULL,
    tenant_id BIGINT UNSIGNED DEFAULT NULL,
    service_lead_id BIGINT UNSIGNED DEFAULT NULL,
    amount DECIMAL(10,2) NOT NULL,
    is_recurring TINYINT(1) DEFAULT 0,
    status ENUM('pending','paid') DEFAULT 'pending',
    note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NULL,
    FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE,
    INDEX idx_affiliate (affiliate_id)
);

-- Leads for the agency's monthly service packages (external clients, manually managed)
CREATE TABLE IF NOT EXISTS service_leads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    affiliate_id BIGINT UNSIGNED NOT NULL,
    client_name VARCHAR(150) NOT NULL,
    client_phone VARCHAR(20) NOT NULL,
    package VARCHAR(100) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    status ENUM('new','contacted','converted','lost') DEFAULT 'new',
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE,
    INDEX idx_affiliate (affiliate_id)
);

ALTER TABLE tenants ADD COLUMN referred_by_affiliate_id BIGINT UNSIGNED DEFAULT NULL AFTER plan_id;
