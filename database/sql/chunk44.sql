-- Customer Reviews — tenant-managed testimonials shown on the storefront
-- (name + photo). Additive only: one new table, mirrors the existing
-- `banners` table's shape/conventions exactly (App\Models\Banner,
-- Tenant\WebsiteController::storeBanner()/destroyBanner()) — same
-- tenant-scoped image upload to the "public" disk, same sort_order/
-- is_active pattern — see App\Models\Review.
CREATE TABLE IF NOT EXISTS reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    customer_name VARCHAR(150) NOT NULL,
    photo_path VARCHAR(255) DEFAULT NULL,
    review_text VARCHAR(500) DEFAULT NULL,
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_tenant_active (tenant_id, is_active, sort_order)
);
