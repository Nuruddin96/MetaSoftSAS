-- ============================================================
-- ShopSaaS : Multi-Tenant E-commerce Business Automation
-- Single database, shared tables with tenant_id (shared hosting friendly)
-- MySQL 8.0 / MariaDB 10.6+
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- SECTION 1: LANDLORD / CENTRAL TABLES
-- ============================================================

CREATE TABLE plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,                     -- Starter, Business, Pro
    slug VARCHAR(100) UNIQUE NOT NULL,
    price_monthly DECIMAL(10,2) NOT NULL DEFAULT 0,
    price_yearly DECIMAL(10,2) NOT NULL DEFAULT 0,
    max_products INT DEFAULT NULL,                  -- NULL = unlimited
    max_staff INT DEFAULT NULL,
    max_warehouses INT DEFAULT NULL,
    max_orders_per_month INT DEFAULT NULL,
    allow_custom_domain TINYINT(1) DEFAULT 0,
    allow_pos TINYINT(1) DEFAULT 1,
    allow_courier_api TINYINT(1) DEFAULT 1,
    allow_meta_ads TINYINT(1) DEFAULT 1,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
);

CREATE TABLE tenants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) UNIQUE NOT NULL,
    store_name VARCHAR(150) NOT NULL,
    subdomain VARCHAR(63) UNIQUE NOT NULL,          -- shop1.yourdomain.com
    custom_domain VARCHAR(255) UNIQUE DEFAULT NULL, -- myshop.com (Phase 3)
    custom_domain_verified TINYINT(1) DEFAULT 0,
    owner_name VARCHAR(150) NOT NULL,
    owner_phone VARCHAR(20) NOT NULL,
    owner_email VARCHAR(150) UNIQUE NOT NULL,
    status ENUM('trial','active','expired','suspended') DEFAULT 'trial',
    plan_id BIGINT UNSIGNED NOT NULL,
    trial_ends_at TIMESTAMP NULL,
    subscription_ends_at TIMESTAMP NULL,
    theme VARCHAR(50) DEFAULT 'default',            -- default | minimal | bold
    logo_path VARCHAR(255) DEFAULT NULL,
    primary_color VARCHAR(7) DEFAULT '#0f766e',
    secondary_color VARCHAR(7) DEFAULT '#f59e0b',
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (plan_id) REFERENCES plans(id),
    INDEX idx_subdomain (subdomain),
    INDEX idx_custom_domain (custom_domain),
    INDEX idx_status (status)
);

CREATE TABLE subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    billing_cycle ENUM('monthly','yearly') DEFAULT 'monthly',
    starts_at TIMESTAMP NOT NULL,
    ends_at TIMESTAMP NOT NULL,
    status ENUM('active','expired','cancelled') DEFAULT 'active',
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES plans(id),
    INDEX idx_tenant_status (tenant_id, status)
);

CREATE TABLE subscription_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED DEFAULT NULL,
    gateway ENUM('bkash','nagad','sslcommerz','manual') NOT NULL,
    trx_id VARCHAR(100) DEFAULT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(5) DEFAULT 'BDT',
    status ENUM('pending','completed','failed','refunded') DEFAULT 'pending',
    gateway_response JSON DEFAULT NULL,
    paid_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_trx (trx_id)
);

CREATE TABLE super_admins (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    remember_token VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
);

CREATE TABLE support_tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    subject VARCHAR(255) NOT NULL,
    priority ENUM('low','medium','high') DEFAULT 'medium',
    status ENUM('open','answered','closed') DEFAULT 'open',
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE support_ticket_replies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    sender ENUM('tenant','admin') NOT NULL,
    message TEXT NOT NULL,
    attachment_path VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NULL,
    FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE
);

CREATE TABLE backup_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_path VARCHAR(255) NOT NULL,
    size_mb DECIMAL(10,2) DEFAULT NULL,
    status ENUM('success','failed') NOT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NULL
);

-- BD location reference (shared, no tenant_id)
CREATE TABLE bd_divisions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    bn_name VARCHAR(50) NOT NULL
);

CREATE TABLE bd_districts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    division_id INT UNSIGNED NOT NULL,
    name VARCHAR(50) NOT NULL,
    bn_name VARCHAR(50) NOT NULL,
    FOREIGN KEY (division_id) REFERENCES bd_divisions(id)
);

CREATE TABLE bd_upazilas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    district_id INT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    bn_name VARCHAR(80) NOT NULL,
    FOREIGN KEY (district_id) REFERENCES bd_districts(id)
);

-- ============================================================
-- SECTION 2: TENANT-SCOPED TABLES (every table has tenant_id)
-- ============================================================

-- Staff users of a tenant (owner + employees)
CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('owner','manager','pos_operator','order_manager') DEFAULT 'owner',
    is_active TINYINT(1) DEFAULT 1,
    remember_token VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uq_tenant_email (tenant_id, email)
);

CREATE TABLE warehouses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    address VARCHAR(255) DEFAULT NULL,
    is_default TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_tenant (tenant_id)
);

CREATE TABLE categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(180) NOT NULL,
    parent_id BIGINT UNSIGNED DEFAULT NULL,
    image_path VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uq_tenant_slug (tenant_id, slug),
    INDEX idx_tenant (tenant_id)
);

CREATE TABLE products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED DEFAULT NULL,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(280) NOT NULL,
    description TEXT DEFAULT NULL,
    has_variants TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    is_featured TINYINT(1) DEFAULT 0,
    thumbnail_path VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    UNIQUE KEY uq_tenant_slug (tenant_id, slug),
    INDEX idx_tenant_active (tenant_id, is_active)
);

CREATE TABLE product_images (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    sort_order INT DEFAULT 0,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- Every product has at least ONE variant row (default variant),
-- so stock/price/barcode logic stays uniform.
CREATE TABLE product_variants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    sku VARCHAR(80) NOT NULL,                       -- auto: TENANTID-PRODID-VARID
    barcode VARCHAR(80) NOT NULL,                   -- auto-generated (Code128/EAN13)
    variant_name VARCHAR(150) DEFAULT 'Default',    -- "Red / XL"
    attributes JSON DEFAULT NULL,                   -- {"color":"Red","size":"XL"}
    purchase_price DECIMAL(12,2) DEFAULT 0,         -- cost (for profit report)
    selling_price DECIMAL(12,2) NOT NULL,
    compare_at_price DECIMAL(12,2) DEFAULT NULL,    -- strikethrough price
    low_stock_threshold INT DEFAULT 5,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uq_tenant_sku (tenant_id, sku),
    UNIQUE KEY uq_tenant_barcode (tenant_id, barcode),
    INDEX idx_barcode (barcode)
);

CREATE TABLE inventory (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    variant_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE CASCADE,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE,
    UNIQUE KEY uq_variant_warehouse (variant_id, warehouse_id),
    INDEX idx_tenant (tenant_id)
);

CREATE TABLE stock_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    variant_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    type ENUM('purchase','sale','pos_sale','return','adjustment','transfer_in','transfer_out') NOT NULL,
    quantity INT NOT NULL,                          -- +in / -out
    reference_type VARCHAR(50) DEFAULT NULL,        -- order, pos_sale, manual
    reference_id BIGINT UNSIGNED DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_tenant_variant (tenant_id, variant_id)
);

CREATE TABLE customers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(150) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    division_id INT UNSIGNED DEFAULT NULL,
    district_id INT UNSIGNED DEFAULT NULL,
    upazila_id INT UNSIGNED DEFAULT NULL,
    due_balance DECIMAL(12,2) DEFAULT 0,            -- বাকি খাতা balance
    total_orders INT DEFAULT 0,
    total_spent DECIMAL(14,2) DEFAULT 0,
    note TEXT DEFAULT NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uq_tenant_phone (tenant_id, phone),
    INDEX idx_tenant (tenant_id)
);

CREATE TABLE orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    order_number VARCHAR(30) NOT NULL,              -- ORD-240001
    source ENUM('web','pos','manual') DEFAULT 'web',
    customer_id BIGINT UNSIGNED DEFAULT NULL,
    customer_name VARCHAR(150) NOT NULL,
    customer_phone VARCHAR(20) NOT NULL,
    customer_address TEXT DEFAULT NULL,
    division_id INT UNSIGNED DEFAULT NULL,
    district_id INT UNSIGNED DEFAULT NULL,
    upazila_id INT UNSIGNED DEFAULT NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount DECIMAL(12,2) DEFAULT 0,
    delivery_charge DECIMAL(10,2) DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(12,2) DEFAULT 0,
    due_amount DECIMAL(12,2) DEFAULT 0,             -- for POS due sales
    payment_method ENUM('cod','bkash','nagad','sslcommerz','cash','due') DEFAULT 'cod',
    payment_status ENUM('unpaid','partial','paid','refunded') DEFAULT 'unpaid',
    status ENUM('pending','confirmed','processing','shipped','delivered','cancelled','returned') DEFAULT 'pending',
    warehouse_id BIGINT UNSIGNED DEFAULT NULL,
    -- courier
    courier_provider VARCHAR(30) DEFAULT NULL,      -- pathao | steadfast | redx
    courier_consignment_id VARCHAR(100) DEFAULT NULL,
    courier_tracking_code VARCHAR(100) DEFAULT NULL,
    courier_status VARCHAR(50) DEFAULT NULL,
    -- fraud check snapshot
    fraud_score INT DEFAULT NULL,                   -- 0-100 success ratio
    fraud_summary JSON DEFAULT NULL,                -- {total:10, delivered:7, returned:3}
    -- attribution
    fb_event_id VARCHAR(64) DEFAULT NULL,           -- CAPI dedup
    utm_source VARCHAR(100) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    confirmed_at TIMESTAMP NULL,
    delivered_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    UNIQUE KEY uq_tenant_order_number (tenant_id, order_number),
    INDEX idx_tenant_status (tenant_id, status),
    INDEX idx_tenant_created (tenant_id, created_at),
    INDEX idx_phone (customer_phone)
);

CREATE TABLE order_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    variant_id BIGINT UNSIGNED DEFAULT NULL,
    product_name VARCHAR(255) NOT NULL,             -- snapshot
    variant_name VARCHAR(150) DEFAULT NULL,
    sku VARCHAR(80) DEFAULT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    purchase_price DECIMAL(12,2) DEFAULT 0,         -- cost snapshot (profit calc)
    quantity INT NOT NULL,
    line_total DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    INDEX idx_tenant (tenant_id)
);

CREATE TABLE incomplete_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    customer_name VARCHAR(150) DEFAULT NULL,
    customer_phone VARCHAR(20) DEFAULT NULL,
    customer_address TEXT DEFAULT NULL,
    cart_json JSON NOT NULL,                        -- items snapshot
    total DECIMAL(12,2) DEFAULT 0,
    status ENUM('abandoned','contacted','recovered','discarded') DEFAULT 'abandoned',
    recovered_order_id BIGINT UNSIGNED DEFAULT NULL,
    last_activity_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_tenant_status (tenant_id, status)
);

-- বাকি খাতা ledger
CREATE TABLE due_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED DEFAULT NULL,
    type ENUM('due','payment') NOT NULL,            -- due = বাকি বাড়লো, payment = পরিশোধ
    amount DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    note VARCHAR(255) DEFAULT NULL,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_tenant_customer (tenant_id, customer_id)
);

CREATE TABLE expense_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE expenses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    expense_category_id BIGINT UNSIGNED DEFAULT NULL,
    title VARCHAR(255) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    expense_date DATE NOT NULL,
    note TEXT DEFAULT NULL,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_tenant_date (tenant_id, expense_date)
);

CREATE TABLE coupons (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(50) NOT NULL,
    type ENUM('fixed','percent') NOT NULL,
    value DECIMAL(10,2) NOT NULL,
    min_order_amount DECIMAL(10,2) DEFAULT 0,
    max_uses INT DEFAULT NULL,
    used_count INT DEFAULT 0,
    starts_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uq_tenant_code (tenant_id, code)
);

-- ============================================================
-- SECTION 3: TENANT INTEGRATION SETTINGS
-- ============================================================

CREATE TABLE courier_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    provider ENUM('pathao','steadfast','redx') NOT NULL,
    credentials JSON NOT NULL,                      -- encrypted: api_key, secret, store_id...
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uq_tenant_provider (tenant_id, provider)
);

CREATE TABLE fraud_check_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    phone VARCHAR(20) NOT NULL,
    result JSON NOT NULL,                           -- per-courier: {steadfast:{total,delivered,returned},...}
    success_ratio INT DEFAULT NULL,                 -- 0-100
    checked_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_tenant_phone (tenant_id, phone)
);

CREATE TABLE marketing_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL UNIQUE,
    fb_pixel_id VARCHAR(50) DEFAULT NULL,
    fb_capi_token TEXT DEFAULT NULL,                -- encrypted
    fb_test_event_code VARCHAR(50) DEFAULT NULL,
    gtm_container_id VARCHAR(20) DEFAULT NULL,      -- GTM-XXXXXX
    meta_app_id VARCHAR(50) DEFAULT NULL,
    meta_app_secret TEXT DEFAULT NULL,              -- encrypted
    meta_access_token TEXT DEFAULT NULL,            -- encrypted
    meta_ad_account_id VARCHAR(50) DEFAULT NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE payment_gateway_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    gateway ENUM('bkash','nagad','sslcommerz') NOT NULL,
    credentials JSON NOT NULL,                      -- encrypted
    is_active TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uq_tenant_gateway (tenant_id, gateway)
);

CREATE TABLE sms_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL UNIQUE,
    provider VARCHAR(50) DEFAULT NULL,              -- bulksmsbd, etc.
    credentials JSON DEFAULT NULL,                  -- encrypted
    sender_id VARCHAR(20) DEFAULT NULL,
    send_on_confirm TINYINT(1) DEFAULT 1,
    send_on_shipped TINYINT(1) DEFAULT 1,
    send_on_delivered TINYINT(1) DEFAULT 1,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE sms_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    phone VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    trigger_event VARCHAR(50) DEFAULT NULL,
    status ENUM('queued','sent','failed') DEFAULT 'queued',
    provider_response JSON DEFAULT NULL,
    created_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_tenant_status (tenant_id, status)
);

CREATE TABLE store_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    `key` VARCHAR(100) NOT NULL,                    -- delivery_charge_inside, announcement, etc.
    `value` TEXT DEFAULT NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uq_tenant_key (tenant_id, `key`)
);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- SEED: default plans
-- ============================================================
INSERT INTO plans (name, slug, price_monthly, price_yearly, max_products, max_staff, max_warehouses, allow_custom_domain, is_active, sort_order) VALUES
('Starter',  'starter',  500.00,  5000.00,  200,  2,  1, 0, 1, 1),
('Business', 'business', 1000.00, 10000.00, 1000, 5,  3, 0, 1, 2),
('Pro',      'pro',      2000.00, 20000.00, NULL, NULL, NULL, 1, 1, 3);
