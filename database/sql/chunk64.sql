-- Brand & Entrepreneur Recognition Platform — real data behind the central
-- homepage (which until now rendered App\Support\Home\Showcase sample data).
-- New, additive, central-only tables (no tenant_id): nothing existing is
-- touched. Import after chunk63.sql. App\Support\Platform\PlatformSchema::
-- ready() checks these tables so a deploy that lands before this import
-- shows a "coming soon" page instead of an SQL error.
--
-- Recognition is kept strictly separate from paid promotion:
--   * brands.is_verified  — trust (identity checked by Super Admin)
--   * brands.is_featured  — editorial pick (never paid)
--   * brands.is_sponsored — paid placement (always labelled "Sponsored")
--   * award_nominations.status = 'finalist' — earned, per award category
--   * award_recognitions.type  — winner / peoples_choice / jury_choice
-- None of these columns is derived from another, and no payment flow
-- writes to any of the earned ones.

CREATE TABLE brand_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    bn_name VARCHAR(100) DEFAULT NULL,
    slug VARCHAR(120) NOT NULL,
    icon VARCHAR(30) DEFAULT NULL,
    color VARCHAR(7) DEFAULT NULL,
    image_path VARCHAR(255) DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    UNIQUE KEY uq_brand_category_slug (slug)
);

INSERT INTO brand_categories (name, bn_name, slug, icon, color, sort_order, is_active, created_at, updated_at) VALUES
('Fashion & Apparel', 'ফ্যাশন ও পোশাক', 'fashion-apparel', 'sparkles', '#7C3AED', 1, 1, NOW(), NOW()),
('Food & Beverage', 'খাদ্য ও পানীয়', 'food-beverage', 'heart', '#EA580C', 2, 1, NOW(), NOW()),
('Tech & Startups', 'টেক ও স্টার্টআপ', 'tech-startups', 'rocket', '#2563EB', 3, 1, NOW(), NOW()),
('Handicrafts', 'হস্তশিল্প', 'handicrafts', 'award', '#B45309', 4, 1, NOW(), NOW()),
('Health & Beauty', 'স্বাস্থ্য ও সৌন্দর্য', 'health-beauty', 'star', '#DB2777', 5, 1, NOW(), NOW()),
('Agro & Organic', 'কৃষি ও অর্গানিক', 'agro-organic', 'layers', '#16A34A', 6, 1, NOW(), NOW()),
('Home & Living', 'হোম ও লিভিং', 'home-living', 'home', '#0D9488', 7, 1, NOW(), NOW()),
('Education & Training', 'শিক্ষা ও প্রশিক্ষণ', 'education-training', 'newspaper', '#0EA5E9', 8, 1, NOW(), NOW()),
('Services', 'সেবা', 'services', 'users', '#475569', 9, 1, NOW(), NOW()),
('Other', 'অন্যান্য', 'other', 'store', '#64748B', 99, 1, NOW(), NOW());

-- Brand owner login accounts (guard `brand_owner`). Separate from tenant
-- users/affiliates/super admins, same as every other guard here.
CREATE TABLE brand_owners (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    password VARCHAR(255) NOT NULL,
    remember_token VARCHAR(100) DEFAULT NULL,
    status ENUM('active','suspended') NOT NULL DEFAULT 'active',
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    UNIQUE KEY uq_brand_owner_email (email),
    UNIQUE KEY uq_brand_owner_phone (phone)
);

-- slug is NULL until the first approval, then fixed (renames never change
-- it, so shared profile/vote links keep working). name_key is the
-- normalized name used for duplicate-registration checks.
CREATE TABLE brands (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    brand_owner_id BIGINT UNSIGNED DEFAULT NULL,
    name VARCHAR(150) NOT NULL,
    name_key VARCHAR(150) NOT NULL,
    slug VARCHAR(160) DEFAULT NULL,
    brand_category_id BIGINT UNSIGNED DEFAULT NULL,
    sub_category VARCHAR(100) DEFAULT NULL,
    founder_name VARCHAR(150) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(150) NOT NULL,
    district VARCHAR(50) NOT NULL,
    division VARCHAR(50) NOT NULL,
    logo_path VARCHAR(255) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    products_info TEXT DEFAULT NULL,
    website VARCHAR(255) DEFAULT NULL,
    facebook VARCHAR(255) DEFAULT NULL,
    instagram VARCHAR(255) DEFAULT NULL,
    tiktok VARCHAR(255) DEFAULT NULL,
    youtube VARCHAR(255) DEFAULT NULL,
    gallery JSON DEFAULT NULL,
    founded_year SMALLINT UNSIGNED DEFAULT NULL,
    status ENUM('pending','approved','rejected','suspended') NOT NULL DEFAULT 'pending',
    status_reason VARCHAR(500) DEFAULT NULL,
    approved_at TIMESTAMP NULL,
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    verified_at TIMESTAMP NULL,
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    featured_order INT NOT NULL DEFAULT 0,
    is_sponsored TINYINT(1) NOT NULL DEFAULT 0,
    sponsored_until DATE DEFAULT NULL,
    views_count INT UNSIGNED NOT NULL DEFAULT 0,
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (brand_owner_id) REFERENCES brand_owners(id) ON DELETE SET NULL,
    FOREIGN KEY (brand_category_id) REFERENCES brand_categories(id) ON DELETE SET NULL,
    UNIQUE KEY uq_brand_slug (slug),
    INDEX idx_brand_status (status, deleted_at),
    INDEX idx_brand_name_key (name_key),
    INDEX idx_brand_featured (is_featured, featured_order)
);

-- Owner edits to identity fields of an already-approved brand wait here
-- instead of replacing verified information immediately.
CREATE TABLE brand_change_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    brand_id BIGINT UNSIGNED NOT NULL,
    changes JSON NOT NULL,
    original JSON DEFAULT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    review_note VARCHAR(500) DEFAULT NULL,
    reviewed_by BIGINT UNSIGNED DEFAULT NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE,
    INDEX idx_bcr_status (status)
);

-- In-app notifications. recipient_type 'owner' + recipient_id = brand_owners.id;
-- recipient_type 'admin' + NULL recipient_id = every Super Admin.
CREATE TABLE platform_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_type VARCHAR(20) NOT NULL,
    recipient_id BIGINT UNSIGNED DEFAULT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(200) NOT NULL,
    body VARCHAR(1000) DEFAULT NULL,
    url VARCHAR(255) DEFAULT NULL,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    INDEX idx_pn_recipient (recipient_type, recipient_id, read_at)
);

-- Who changed what, when and why — every Super Admin action on brands,
-- awards, campaigns and votes writes one row.
CREATE TABLE platform_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_type VARCHAR(20) NOT NULL,
    actor_id BIGINT UNSIGNED DEFAULT NULL,
    actor_name VARCHAR(150) DEFAULT NULL,
    action VARCHAR(60) NOT NULL,
    subject_type VARCHAR(40) NOT NULL,
    subject_id BIGINT UNSIGNED DEFAULT NULL,
    changes JSON DEFAULT NULL,
    reason VARCHAR(500) DEFAULT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP NULL,
    INDEX idx_pal_subject (subject_type, subject_id),
    INDEX idx_pal_created (created_at)
);

CREATE TABLE awards (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    bn_title VARCHAR(200) DEFAULT NULL,
    slug VARCHAR(220) NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    description TEXT DEFAULT NULL,
    rules TEXT DEFAULT NULL,
    jury_info TEXT DEFAULT NULL,
    status ENUM('draft','nominations_open','voting','jury_review','completed','archived') NOT NULL DEFAULT 'draft',
    nomination_starts_at DATETIME DEFAULT NULL,
    nomination_ends_at DATETIME DEFAULT NULL,
    voting_starts_at DATETIME DEFAULT NULL,
    voting_ends_at DATETIME DEFAULT NULL,
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    UNIQUE KEY uq_award_slug (slug)
);

-- brand_category_id limits eligibility (NULL = open to every category).
CREATE TABLE award_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    award_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(500) DEFAULT NULL,
    brand_category_id BIGINT UNSIGNED DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (award_id) REFERENCES awards(id) ON DELETE CASCADE,
    FOREIGN KEY (brand_category_id) REFERENCES brand_categories(id) ON DELETE SET NULL
);

CREATE TABLE award_nominations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    award_id BIGINT UNSIGNED NOT NULL,
    award_category_id BIGINT UNSIGNED NOT NULL,
    brand_id BIGINT UNSIGNED NOT NULL,
    source ENUM('owner','admin') NOT NULL DEFAULT 'owner',
    statement TEXT DEFAULT NULL,
    status ENUM('submitted','accepted','shortlisted','finalist','rejected','withdrawn') NOT NULL DEFAULT 'submitted',
    admin_note VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (award_id) REFERENCES awards(id) ON DELETE CASCADE,
    FOREIGN KEY (award_category_id) REFERENCES award_categories(id) ON DELETE CASCADE,
    FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE,
    UNIQUE KEY uq_nomination (award_category_id, brand_id),
    INDEX idx_nomination_status (award_id, status)
);

CREATE TABLE award_recognitions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    award_id BIGINT UNSIGNED NOT NULL,
    award_category_id BIGINT UNSIGNED DEFAULT NULL,
    brand_id BIGINT UNSIGNED NOT NULL,
    type ENUM('winner','peoples_choice','jury_choice') NOT NULL,
    title VARCHAR(200) DEFAULT NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (award_id) REFERENCES awards(id) ON DELETE CASCADE,
    FOREIGN KEY (award_category_id) REFERENCES award_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE,
    INDEX idx_recognition_brand (brand_id)
);

-- Public voting. A campaign may belong to an award (its categories/
-- nominees can be imported from it) or stand alone.
CREATE TABLE vote_campaigns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    award_id BIGINT UNSIGNED DEFAULT NULL,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(220) NOT NULL,
    description TEXT DEFAULT NULL,
    status ENUM('draft','active','paused','ended') NOT NULL DEFAULT 'draft',
    starts_at DATETIME DEFAULT NULL,
    ends_at DATETIME DEFAULT NULL,
    vote_limit ENUM('daily','once') NOT NULL DEFAULT 'daily',
    show_counts TINYINT(1) NOT NULL DEFAULT 1,
    started_notified_at TIMESTAMP NULL,
    ended_notified_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (award_id) REFERENCES awards(id) ON DELETE SET NULL,
    UNIQUE KEY uq_campaign_slug (slug)
);

CREATE TABLE vote_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    award_category_id BIGINT UNSIGNED DEFAULT NULL,
    name VARCHAR(150) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (campaign_id) REFERENCES vote_campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (award_category_id) REFERENCES award_categories(id) ON DELETE SET NULL
);

-- votes_count is a cache of this entry's VALID votes; only VoteService
-- (casting) and Super Admin invalidation/restoration ever change it.
CREATE TABLE vote_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    vote_category_id BIGINT UNSIGNED NOT NULL,
    brand_id BIGINT UNSIGNED NOT NULL,
    votes_count INT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (campaign_id) REFERENCES vote_campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (vote_category_id) REFERENCES vote_categories(id) ON DELETE CASCADE,
    FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE,
    UNIQUE KEY uq_entry (vote_category_id, brand_id),
    INDEX idx_entry_brand (brand_id)
);

-- One vote per phone, per category, per period (period_key = 'YYYY-MM-DD'
-- for daily campaigns, 'once' otherwise), enforced by uq_vote. Phones are
-- stored only as a keyed hash plus a masked display form.
CREATE TABLE votes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    vote_category_id BIGINT UNSIGNED NOT NULL,
    vote_entry_id BIGINT UNSIGNED NOT NULL,
    voter_hash CHAR(64) NOT NULL,
    phone_masked VARCHAR(20) NOT NULL,
    period_key VARCHAR(10) NOT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    device_hash CHAR(64) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    flags VARCHAR(255) DEFAULT NULL,
    status ENUM('valid','invalid') NOT NULL DEFAULT 'valid',
    invalidated_by BIGINT UNSIGNED DEFAULT NULL,
    invalidated_at TIMESTAMP NULL,
    invalid_reason VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NULL,
    FOREIGN KEY (campaign_id) REFERENCES vote_campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (vote_category_id) REFERENCES vote_categories(id) ON DELETE CASCADE,
    FOREIGN KEY (vote_entry_id) REFERENCES vote_entries(id) ON DELETE CASCADE,
    UNIQUE KEY uq_vote (vote_category_id, voter_hash, period_key),
    INDEX idx_vote_entry (vote_entry_id, status),
    INDEX idx_vote_ip (campaign_id, ip),
    INDEX idx_vote_device (campaign_id, device_hash),
    INDEX idx_vote_created (campaign_id, created_at)
);
