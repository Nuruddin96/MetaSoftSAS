-- Messenger inbox integration
CREATE TABLE IF NOT EXISTS messenger_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL UNIQUE,
    page_id VARCHAR(50) NOT NULL,
    page_access_token TEXT NOT NULL,
    page_name VARCHAR(150) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_page (page_id)
);

CREATE TABLE IF NOT EXISTS messenger_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    sender_psid VARCHAR(100) NOT NULL,
    customer_name VARCHAR(150) DEFAULT NULL,
    message_text TEXT DEFAULT NULL,
    attachment_url VARCHAR(500) DEFAULT NULL,
    direction ENUM('in','out') DEFAULT 'in',
    status ENUM('new','contacted','converted','ignored') DEFAULT 'new',
    created_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_tenant (tenant_id, created_at),
    INDEX idx_psid (sender_psid)
);
