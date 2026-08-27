-- Single Product Landing Page Builder (Phase 2).
--
-- One row per tenant-built sales landing page, permanently bound to exactly
-- one product (the checkout section always sells that product — see
-- App\Models\LandingPage / Storefront\LandingPageController). `sections` is
-- an ordered JSON array of {id, type, data} — the section/block system this
-- feature needed but the existing Website Builder (pages/banners,
-- chunk6.sql) never had, since those are plain title+richtext pages, not a
-- reorderable multi-section builder.
CREATE TABLE IF NOT EXISTS landing_pages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    slug VARCHAR(180) NOT NULL,
    status ENUM('draft','published') NOT NULL DEFAULT 'draft',
    sections JSON DEFAULT NULL,
    published_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uq_tenant_landing_slug (tenant_id, slug),
    INDEX idx_tenant_status (tenant_id, status)
);
