-- ============================================================
-- CHUNK 8: Central login/reset + Product Source module
-- ============================================================

-- Super-admin managed sourcing catalog (global, no tenant_id)
CREATE TABLE IF NOT EXISTS source_products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    slug VARCHAR(220) NOT NULL UNIQUE,
    image_path VARCHAR(255) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    min_order_qty INT DEFAULT 1,
    delivery_time_days VARCHAR(50) DEFAULT NULL,
    shipping_cost DECIMAL(10,2) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS source_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    source_product_id BIGINT UNSIGNED NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    note TEXT DEFAULT NULL,
    contact_phone VARCHAR(20) DEFAULT NULL,
    status ENUM('pending','contacted','confirmed','shipped','delivered','cancelled') DEFAULT 'pending',
    admin_note TEXT DEFAULT NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (source_product_id) REFERENCES source_products(id),
    INDEX idx_tenant (tenant_id),
    INDEX idx_status (status)
);
