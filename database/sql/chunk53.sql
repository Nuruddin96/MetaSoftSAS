-- Catalog Architecture: subcategories + normalized attribute vocabulary --
--
-- 1) Subcategories use the EXISTING `categories.parent_id` column (already
--    in schema.sql, never previously read/written by any controller — see
--    Api\Mobile\CategoryController's docblock). No new column needed here.
--    business_type_categories.parent_name (chunk52.sql) was reserved for
--    exactly this and was previously always ignored by
--    TenantOnboardingService::seedDefaultCategories() (it explicitly
--    `continue`d past any row with parent_name set) — now wired up (see
--    the SEED EXPANSION section below for the actual subcategory content).
--
-- 2) product_attributes / product_attribute_values are a NEW, purely
--    additive tenant-scoped vocabulary layer (e.g. a reusable "Color" with
--    values "Red"/"Black"/"White") that the catalog UI can suggest from
--    when building a variant. They do NOT replace, restructure, or get
--    read by product_variants.attributes JSON — that column is untouched
--    and remains the actual source of truth for what a variant IS (see
--    ProductVariant model/ProductController::attributesFromInput()).
--    Every existing product/variant continues to work identically whether
--    or not a tenant ever uses this new vocabulary.

CREATE TABLE product_attributes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(60) NOT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uq_tenant_attr_name (tenant_id, name),
    INDEX idx_tenant (tenant_id)
);

CREATE TABLE product_attribute_values (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_attribute_id BIGINT UNSIGNED NOT NULL,
    value VARCHAR(80) NOT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (product_attribute_id) REFERENCES product_attributes(id) ON DELETE CASCADE,
    UNIQUE KEY uq_attribute_value (product_attribute_id, value),
    INDEX idx_attribute (product_attribute_id)
);

-- ============================================================
-- SEED EXPANSION: subcategories + richer default_attributes for the 5
-- business types the catalog-architecture spec calls out by name
-- (Skincare, Fashion, Shoes, Electronics, Home & Kitchen). Every other
-- business type's existing flat category list (chunk52.sql) is
-- deliberately untouched — a content decision, not a code gap; see final
-- report for why this scope was chosen over redoing all 22.
--
-- business_type_categories/business_types are BOTH central-only reference
-- tables, "never referenced live afterward" once a tenant selects a
-- business type (chunk52.sql's own docblock) — reparenting an EXISTING
-- row here via UPDATE only changes what a BRAND-NEW tenant sees at
-- signup from this point forward. It cannot affect any tenant who has
-- already onboarded (their categories/attributes were already copied
-- into their own tenant-scoped tables and are never re-read from here).
-- ============================================================

-- --- Skincare: nest cleansing/moisturizing steps under Face Care, add
-- the two Body Care subcategories the spec calls out, keep everything
-- else (Hair Care/Makeup/Lip Care/Sun Care/Fragrance) as top-level exactly
-- as before.
UPDATE business_type_categories
SET parent_name = 'Face Care'
WHERE business_type_id = (SELECT id FROM business_types WHERE slug='skincare')
  AND name IN ('Cleansing', 'Moisturizer', 'Serum');

INSERT INTO business_type_categories (business_type_id, name, parent_name, sort_order) VALUES
((SELECT id FROM business_types WHERE slug='skincare'), 'Toner', 'Face Care', 71),
((SELECT id FROM business_types WHERE slug='skincare'), 'Body Lotion', 'Body Care', 21),
((SELECT id FROM business_types WHERE slug='skincare'), 'Body Wash', 'Body Care', 22);

UPDATE business_types
SET default_attributes = '["Size","ML","Gram","Color","Weight","Set","Combo","Shade"]'
WHERE slug='skincare';

-- --- Fashion / Women's / Men's Fashion: category lists stay flat by
-- garment type exactly as before (a Men/Women/Kids top-level split would
-- duplicate the separate womens_fashion/mens_fashion business types that
-- already exist for that purpose) — only the attribute vocabulary grows,
-- matching the spec's Fashion example (Size/Color/Fabric/Pattern/Fit).
UPDATE business_types SET default_attributes = '["Size","Color","Fabric","Pattern","Fit"]' WHERE slug='fashion';
UPDATE business_types SET default_attributes = '["Size","Color","Fabric","Pattern","Fit"]' WHERE slug='womens_fashion';
UPDATE business_types SET default_attributes = '["Size","Color","Fabric","Pattern","Fit"]' WHERE slug='mens_fashion';

-- --- Shoes: category list already matches the spec's Men/Women/Kids +
-- Sandals/Sneakers/Formal shape closely enough (kept as-is — see report);
-- just adds Material to the attribute vocabulary.
UPDATE business_types SET default_attributes = '["Size","Color","Material"]' WHERE slug='shoes';

-- --- Electronics: the existing list was 100% accessories with no actual
-- device categories at all — a genuine gap. Adds the 4 device categories
-- the spec calls out plus an 'Accessories' umbrella, and reparents the 8
-- existing accessory rows under it (nothing deleted or renamed).
INSERT INTO business_type_categories (business_type_id, name, sort_order) VALUES
((SELECT id FROM business_types WHERE slug='electronics'), 'Mobile', 5),
((SELECT id FROM business_types WHERE slug='electronics'), 'Laptop', 6),
((SELECT id FROM business_types WHERE slug='electronics'), 'TV', 7),
((SELECT id FROM business_types WHERE slug='electronics'), 'Camera', 8),
((SELECT id FROM business_types WHERE slug='electronics'), 'Accessories', 9);

UPDATE business_type_categories
SET parent_name = 'Accessories'
WHERE business_type_id = (SELECT id FROM business_types WHERE slug='electronics')
  AND name IN ('Mobile Accessories', 'Chargers', 'Cables', 'Earphones', 'Headphones', 'Power Banks', 'Smart Watches', 'Speakers');

UPDATE business_types SET default_attributes = '["Color","Storage","RAM","Model","Warranty"]' WHERE slug='electronics';

-- --- Home & Kitchen: Cookware is clearly a Kitchen subcategory; Storage/
-- Home Decor/Dining/Cleaning stay top-level exactly as before.
UPDATE business_type_categories
SET parent_name = 'Kitchen'
WHERE business_type_id = (SELECT id FROM business_types WHERE slug='home_kitchen')
  AND name = 'Cookware';

UPDATE business_types SET default_attributes = '["Size","Color","Material","Weight","Piece","Set","Combo"]' WHERE slug='home_kitchen';
