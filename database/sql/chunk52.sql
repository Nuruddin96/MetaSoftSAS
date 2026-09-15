-- Tenant Onboarding Wizard — guided 5-6 step setup shown once, right after
-- signup, instead of dropping a new tenant straight into an empty
-- dashboard. See App\Services\Tenant\TenantOnboardingService and
-- App\Http\Controllers\Tenant\OnboardingController for the flow itself.
--
-- Two central (non-tenant-scoped) reference tables — same shape as `plans`,
-- not tenant data, never filtered by BelongsToTenant:
--   business_types            — the fixed list a tenant picks from in Step 1
--   business_type_categories  — the "starter" categories offered in Step 3
--     for that business type; copied into the tenant's own `categories`
--     table on selection, never referenced live afterward.
-- default_attributes on business_types (e.g. ["Size","ML","Set","Combo"])
-- suggests product_variants.attributes JSON keys during the optional
-- Step 5 first-product form — NOT a new attribute/unit table, since
-- product_variants.attributes already is exactly that free-form store
-- (see ProductController::attributesFromInput()).
CREATE TABLE business_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(60) UNIQUE NOT NULL,
    name_bn VARCHAR(100) NOT NULL,
    name_en VARCHAR(100) NOT NULL,
    icon VARCHAR(10) DEFAULT NULL,             -- emoji, used as a lightweight card icon
    default_attributes JSON DEFAULT NULL,
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
);

CREATE TABLE business_type_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_type_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    -- Reserved for future subcategory seeding (mirrors categories.parent_id
    -- by name rather than id, since these rows never become real Category
    -- rows themselves). Not consumed yet: CategoryController::store() has
    -- no parent_id input today, so v1 onboarding only seeds top-level
    -- categories (parent_name IS NULL) — see
    -- TenantOnboardingService::seedDefaultCategories()'s docblock.
    parent_name VARCHAR(150) DEFAULT NULL,
    sort_order INT DEFAULT 0,
    FOREIGN KEY (business_type_id) REFERENCES business_types(id) ON DELETE CASCADE,
    INDEX idx_business_type (business_type_id)
);

-- onboarding_step is the tenant's current/resume position (one of
-- TenantOnboardingService::STEPS, e.g. 'business_type', 'first_product');
-- NULL until they've submitted Step 1 at least once. onboarding_completed_at
-- is the single source of truth for "has this tenant finished (or been
-- exempted from) the wizard" — RequireOnboarding middleware redirects to
-- the wizard only while this is NULL.
ALTER TABLE tenants
    ADD COLUMN business_type_id BIGINT UNSIGNED DEFAULT NULL AFTER plan_id,
    ADD COLUMN onboarding_step VARCHAR(30) DEFAULT NULL AFTER business_type_id,
    ADD COLUMN onboarding_completed_at TIMESTAMP NULL DEFAULT NULL AFTER onboarding_step;

ALTER TABLE tenants
    ADD FOREIGN KEY (business_type_id) REFERENCES business_types(id) ON DELETE SET NULL;

-- Every tenant that existed before this feature shipped must never see the
-- wizard (requirement: "Existing tenants must not be broken by this
-- feature") — mark them already-onboarded as of import time.
UPDATE tenants SET onboarding_completed_at = COALESCE(onboarding_completed_at, created_at, NOW())
    WHERE onboarding_completed_at IS NULL;

-- ============================================================
-- SEED: Bangladesh-focused business type taxonomy
-- ============================================================
INSERT INTO business_types (slug, name_bn, name_en, icon, default_attributes, sort_order) VALUES
('skincare',      'স্কিনকেয়ার ও কসমেটিকস',   'Skin Care & Cosmetics',        '💄', '["Size","ML","Gram"]',            10),
('fashion',       'ফ্যাশন ও পোশাক',           'Fashion & Clothing',           '👗', '["Size","Color"]',                20),
('womens_fashion','মহিলাদের ফ্যাশন',          "Women's Fashion",              '👚', '["Size","Color"]',                30),
('mens_fashion',  'পুরুষদের ফ্যাশন',          "Men's Fashion",                '👔', '["Size","Color"]',                40),
('kids_baby',     'বাচ্চা ও শিশু পণ্য',        'Kids & Baby Products',         '🧸', '["Size","Age Range"]',            50),
('shoes',         'জুতা ও ফুটওয়্যার',         'Shoes & Footwear',             '👟', '["Size","Color"]',                60),
('bags',          'ব্যাগ ও এক্সেসরিজ',         'Bags & Accessories',           '👜', '["Color","Size"]',                70),
('jewellery',     'জুয়েলারি ও ঘড়ি',           'Jewellery & Watches',          '💍', '["Size","Color"]',                80),
('electronics',   'ইলেকট্রনিক্স ও গ্যাজেট',    'Electronics & Gadgets',        '📱', '["Color","Piece"]',               90),
('mobile_accessories','মোবাইল ও কম্পিউটার এক্সেসরিজ','Mobile & Computer Accessories','🔌', '["Color","Piece"]',       100),
('home_kitchen',  'হোম ও কিচেন',              'Home & Kitchen',               '🍽️', '["Piece","Set","Combo"]',         110),
('furniture',     'ফার্নিচার ও হোম ডেকর',      'Furniture & Home Decor',       '🛋️', '["Size","Color"]',                120),
('grocery',       'গ্রোসারি ও খাবার',          'Grocery & Food',               '🛒', '["Gram","Piece","Pack"]',         130),
('health',        'স্বাস্থ্য ও ওয়েলনেস',       'Health & Wellness',            '💊', '["Piece","Pack"]',                140),
('perfume',       'পারফিউম ও সুগন্ধি',         'Perfume & Fragrance',          '🌸', '["ML","Size"]',                   150),
('books',         'বই ও স্টেশনারি',           'Books & Stationery',           '📚', '["Piece","Set"]',                 160),
('sports',        'স্পোর্টস ও ফিটনেস',         'Sports & Fitness',             '⚽', '["Size","Piece"]',                170),
('islamic',       'ইসলামিক পণ্য',              'Islamic Products',             '🕌', '["Size","Piece"]',                180),
('gifts',         'গিফট ও কাস্টমাইজড পণ্য',    'Gifts & Customized Products',  '🎁', '["Size","Piece"]',                190),
('pet',           'পোষা প্রাণীর পণ্য',         'Pet Products',                 '🐾', '["Size","Piece"]',                200),
('automotive',    'অটোমোটিভ পণ্য',            'Automotive Products',          '🚗', '["Piece","Set"]',                 210),
('handmade',      'দেশীয় ও হস্তনির্মিত পণ্য',   'Local/Handmade Products',      '🧶', '["Size","Piece"]',                220),
('other',         'অন্যান্য',                  'Other',                        '🏪', NULL,                              999);

INSERT INTO business_type_categories (business_type_id, name, sort_order) VALUES
((SELECT id FROM business_types WHERE slug='skincare'), 'Face Care', 10),
((SELECT id FROM business_types WHERE slug='skincare'), 'Body Care', 20),
((SELECT id FROM business_types WHERE slug='skincare'), 'Hair Care', 30),
((SELECT id FROM business_types WHERE slug='skincare'), 'Makeup', 40),
((SELECT id FROM business_types WHERE slug='skincare'), 'Lip Care', 50),
((SELECT id FROM business_types WHERE slug='skincare'), 'Sun Care', 60),
((SELECT id FROM business_types WHERE slug='skincare'), 'Cleansing', 70),
((SELECT id FROM business_types WHERE slug='skincare'), 'Moisturizer', 80),
((SELECT id FROM business_types WHERE slug='skincare'), 'Serum', 90),
((SELECT id FROM business_types WHERE slug='skincare'), 'Fragrance', 100),

((SELECT id FROM business_types WHERE slug='fashion'), 'Saree', 10),
((SELECT id FROM business_types WHERE slug='fashion'), 'Three Piece', 20),
((SELECT id FROM business_types WHERE slug='fashion'), 'Salwar Kameez', 30),
((SELECT id FROM business_types WHERE slug='fashion'), 'Panjabi', 40),
((SELECT id FROM business_types WHERE slug='fashion'), 'Shirt', 50),
((SELECT id FROM business_types WHERE slug='fashion'), 'T-Shirt', 60),
((SELECT id FROM business_types WHERE slug='fashion'), 'Pants', 70),
((SELECT id FROM business_types WHERE slug='fashion'), 'Kids Wear', 80),
((SELECT id FROM business_types WHERE slug='fashion'), 'Hijab', 90),

((SELECT id FROM business_types WHERE slug='womens_fashion'), 'Saree', 10),
((SELECT id FROM business_types WHERE slug='womens_fashion'), 'Three Piece', 20),
((SELECT id FROM business_types WHERE slug='womens_fashion'), 'Salwar Kameez', 30),
((SELECT id FROM business_types WHERE slug='womens_fashion'), 'Hijab', 40),
((SELECT id FROM business_types WHERE slug='womens_fashion'), 'Abaya', 50),
((SELECT id FROM business_types WHERE slug='womens_fashion'), 'Kurti', 60),

((SELECT id FROM business_types WHERE slug='mens_fashion'), 'Panjabi', 10),
((SELECT id FROM business_types WHERE slug='mens_fashion'), 'Shirt', 20),
((SELECT id FROM business_types WHERE slug='mens_fashion'), 'T-Shirt', 30),
((SELECT id FROM business_types WHERE slug='mens_fashion'), 'Pants', 40),
((SELECT id FROM business_types WHERE slug='mens_fashion'), 'Polo', 50),

((SELECT id FROM business_types WHERE slug='kids_baby'), 'Baby Clothing', 10),
((SELECT id FROM business_types WHERE slug='kids_baby'), 'Diapers', 20),
((SELECT id FROM business_types WHERE slug='kids_baby'), 'Feeding', 30),
((SELECT id FROM business_types WHERE slug='kids_baby'), 'Toys', 40),
((SELECT id FROM business_types WHERE slug='kids_baby'), 'Baby Care', 50),
((SELECT id FROM business_types WHERE slug='kids_baby'), 'School Items', 60),

((SELECT id FROM business_types WHERE slug='shoes'), "Men's Shoes", 10),
((SELECT id FROM business_types WHERE slug='shoes'), "Women's Shoes", 20),
((SELECT id FROM business_types WHERE slug='shoes'), 'Kids Shoes', 30),
((SELECT id FROM business_types WHERE slug='shoes'), 'Sandals', 40),
((SELECT id FROM business_types WHERE slug='shoes'), 'Sneakers', 50),
((SELECT id FROM business_types WHERE slug='shoes'), 'Formal', 60),

((SELECT id FROM business_types WHERE slug='bags'), 'Bags', 10),
((SELECT id FROM business_types WHERE slug='bags'), 'Wallets', 20),
((SELECT id FROM business_types WHERE slug='bags'), 'Belts', 30),
((SELECT id FROM business_types WHERE slug='bags'), 'Sunglasses', 40),

((SELECT id FROM business_types WHERE slug='jewellery'), 'Necklace', 10),
((SELECT id FROM business_types WHERE slug='jewellery'), 'Earrings', 20),
((SELECT id FROM business_types WHERE slug='jewellery'), 'Ring', 30),
((SELECT id FROM business_types WHERE slug='jewellery'), 'Bracelet', 40),
((SELECT id FROM business_types WHERE slug='jewellery'), 'Watches', 50),

((SELECT id FROM business_types WHERE slug='electronics'), 'Mobile Accessories', 10),
((SELECT id FROM business_types WHERE slug='electronics'), 'Chargers', 20),
((SELECT id FROM business_types WHERE slug='electronics'), 'Cables', 30),
((SELECT id FROM business_types WHERE slug='electronics'), 'Earphones', 40),
((SELECT id FROM business_types WHERE slug='electronics'), 'Headphones', 50),
((SELECT id FROM business_types WHERE slug='electronics'), 'Power Banks', 60),
((SELECT id FROM business_types WHERE slug='electronics'), 'Smart Watches', 70),
((SELECT id FROM business_types WHERE slug='electronics'), 'Speakers', 80),

((SELECT id FROM business_types WHERE slug='mobile_accessories'), 'Chargers', 10),
((SELECT id FROM business_types WHERE slug='mobile_accessories'), 'Cables', 20),
((SELECT id FROM business_types WHERE slug='mobile_accessories'), 'Earphones', 30),
((SELECT id FROM business_types WHERE slug='mobile_accessories'), 'Power Banks', 40),
((SELECT id FROM business_types WHERE slug='mobile_accessories'), 'Cases & Covers', 50),

((SELECT id FROM business_types WHERE slug='home_kitchen'), 'Kitchen', 10),
((SELECT id FROM business_types WHERE slug='home_kitchen'), 'Cookware', 20),
((SELECT id FROM business_types WHERE slug='home_kitchen'), 'Storage', 30),
((SELECT id FROM business_types WHERE slug='home_kitchen'), 'Home Decor', 40),
((SELECT id FROM business_types WHERE slug='home_kitchen'), 'Cleaning', 50),
((SELECT id FROM business_types WHERE slug='home_kitchen'), 'Dining', 60),

((SELECT id FROM business_types WHERE slug='furniture'), 'Living Room', 10),
((SELECT id FROM business_types WHERE slug='furniture'), 'Bedroom', 20),
((SELECT id FROM business_types WHERE slug='furniture'), 'Home Decor', 30),
((SELECT id FROM business_types WHERE slug='furniture'), 'Office Furniture', 40),

((SELECT id FROM business_types WHERE slug='grocery'), 'Rice', 10),
((SELECT id FROM business_types WHERE slug='grocery'), 'Oil', 20),
((SELECT id FROM business_types WHERE slug='grocery'), 'Spices', 30),
((SELECT id FROM business_types WHERE slug='grocery'), 'Snacks', 40),
((SELECT id FROM business_types WHERE slug='grocery'), 'Beverages', 50),
((SELECT id FROM business_types WHERE slug='grocery'), 'Dry Food', 60),
((SELECT id FROM business_types WHERE slug='grocery'), 'Fresh Food', 70),

((SELECT id FROM business_types WHERE slug='health'), 'Supplements', 10),
((SELECT id FROM business_types WHERE slug='health'), 'Personal Care', 20),
((SELECT id FROM business_types WHERE slug='health'), 'Fitness', 30),
((SELECT id FROM business_types WHERE slug='health'), 'Wellness', 40),

((SELECT id FROM business_types WHERE slug='perfume'), "Men's Perfume", 10),
((SELECT id FROM business_types WHERE slug='perfume'), "Women's Perfume", 20),
((SELECT id FROM business_types WHERE slug='perfume'), 'Unisex', 30),
((SELECT id FROM business_types WHERE slug='perfume'), 'Attar', 40),

((SELECT id FROM business_types WHERE slug='books'), 'Books', 10),
((SELECT id FROM business_types WHERE slug='books'), 'Notebooks', 20),
((SELECT id FROM business_types WHERE slug='books'), 'Pens & Pencils', 30),
((SELECT id FROM business_types WHERE slug='books'), 'Office Stationery', 40),

((SELECT id FROM business_types WHERE slug='sports'), 'Gym Equipment', 10),
((SELECT id FROM business_types WHERE slug='sports'), 'Sportswear', 20),
((SELECT id FROM business_types WHERE slug='sports'), 'Outdoor Gear', 30),
((SELECT id FROM business_types WHERE slug='sports'), 'Accessories', 40),

((SELECT id FROM business_types WHERE slug='islamic'), 'Panjabi', 10),
((SELECT id FROM business_types WHERE slug='islamic'), 'Hijab', 20),
((SELECT id FROM business_types WHERE slug='islamic'), 'Prayer Items', 30),
((SELECT id FROM business_types WHERE slug='islamic'), 'Islamic Books', 40),
((SELECT id FROM business_types WHERE slug='islamic'), 'Tasbih', 50),
((SELECT id FROM business_types WHERE slug='islamic'), 'Islamic Gifts', 60),

((SELECT id FROM business_types WHERE slug='gifts'), 'Personalized Gifts', 10),
((SELECT id FROM business_types WHERE slug='gifts'), 'Gift Hampers', 20),
((SELECT id FROM business_types WHERE slug='gifts'), 'Photo Frames', 30),
((SELECT id FROM business_types WHERE slug='gifts'), 'Greeting Cards', 40),

((SELECT id FROM business_types WHERE slug='pet'), 'Pet Food', 10),
((SELECT id FROM business_types WHERE slug='pet'), 'Pet Accessories', 20),
((SELECT id FROM business_types WHERE slug='pet'), 'Pet Care', 30),

((SELECT id FROM business_types WHERE slug='automotive'), 'Car Accessories', 10),
((SELECT id FROM business_types WHERE slug='automotive'), 'Bike Accessories', 20),
((SELECT id FROM business_types WHERE slug='automotive'), 'Spare Parts', 30),
((SELECT id FROM business_types WHERE slug='automotive'), 'Car Care', 40),

((SELECT id FROM business_types WHERE slug='handmade'), 'Handicrafts', 10),
((SELECT id FROM business_types WHERE slug='handmade'), 'Handloom', 20),
((SELECT id FROM business_types WHERE slug='handmade'), 'Pottery', 30),
((SELECT id FROM business_types WHERE slug='handmade'), 'Home Decor', 40);
