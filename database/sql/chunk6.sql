-- ============================================================
-- CHUNK 6: Website builder — pages, banners
-- ============================================================

CREATE TABLE IF NOT EXISTS pages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    slug VARCHAR(180) NOT NULL,
    content LONGTEXT DEFAULT NULL,
    show_in_header TINYINT(1) DEFAULT 0,
    show_in_footer TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uq_tenant_page_slug (tenant_id, slug),
    INDEX idx_tenant (tenant_id)
);

CREATE TABLE IF NOT EXISTS banners (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    image_path VARCHAR(255) DEFAULT NULL,
    title VARCHAR(150) DEFAULT NULL,
    subtitle VARCHAR(255) DEFAULT NULL,
    button_text VARCHAR(50) DEFAULT NULL,
    button_link VARCHAR(255) DEFAULT NULL,
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_tenant (tenant_id)
);
