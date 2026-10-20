-- Recognition platform, part 7 (import after chunk69.sql). Additive only —
-- no existing row is changed or deleted.
--
-- Discovery directory: well-known brands operating in Bangladesh, listed by
-- the MetaSoft BD team from the brands' own public websites so visitors can
-- find them. These are NOT registered brands: they live in their own table,
-- never in `brands`, so they can never appear in voting, awards, brand
-- counts or carry the MetaSoft BD Verified badge. Public pages say they have
-- not joined or been verified by MetaSoft BD.
--
-- Rows are loaded from database/data/brand-directory.json by
-- `php artisan platform:directory-import --apply` (dry run without --apply;
-- upsert by slug, safe to re-run). The table itself is created below.
--
-- Column meanings:
--   source_type          how the row was created: 'public_research'
--   membership_status    not_member → claim_pending (a registration matched it)
--                        → member (replaced by the verified registered brand)
--   verification_status  whether the team confirmed the listing's facts from
--                        the official source: 'verified' | 'needs_review'.
--                        About the source data only — it is NOT the MetaSoft
--                        BD Verified badge (brands.is_verified).
--                        Only 'verified' rows are shown publicly.
--   source_url           the official page the facts were checked against;
--                        `sources` holds every URL used
--   source_checked_at    when the sources were last checked
--   expires_at           re-check deadline; past it the listing is hidden and
--                        `platform:directory-maintain --apply` archives it
--   replacement_priority 1 (keep longest) … 9 (archive first) when a category
--                        has enough genuine registered brands
--   replaced_by_brand_id the registered brand that replaced this listing
--   claimed_by_brand_id  a registration that matched this listing (by
--                        normalized name or official domain) and awaits review
--   status               active | archived — rows are archived, never deleted
--
-- Safe to re-run (IF NOT EXISTS). Until this is imported the directory and
-- homepage simply show no listings (PlatformSchema::directoryReady()).

CREATE TABLE IF NOT EXISTS brand_directory_listings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    name_key VARCHAR(150) NOT NULL,
    slug VARCHAR(160) NOT NULL,
    brand_category_id BIGINT UNSIGNED DEFAULT NULL,
    description VARCHAR(600) DEFAULT NULL,
    website VARCHAR(255) DEFAULT NULL,
    official_domain VARCHAR(190) DEFAULT NULL,
    facebook VARCHAR(255) DEFAULT NULL,
    ownership VARCHAR(20) NOT NULL DEFAULT 'unconfirmed',
    ownership_note VARCHAR(300) DEFAULT NULL,
    source_type VARCHAR(30) NOT NULL DEFAULT 'public_research',
    membership_status VARCHAR(20) NOT NULL DEFAULT 'not_member',
    verification_status VARCHAR(20) NOT NULL DEFAULT 'needs_review',
    source_url VARCHAR(255) DEFAULT NULL,
    sources JSON DEFAULT NULL,
    source_checked_at DATE DEFAULT NULL,
    expires_at DATE DEFAULT NULL,
    replacement_priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
    replaced_by_brand_id BIGINT UNSIGNED DEFAULT NULL,
    claimed_by_brand_id BIGINT UNSIGNED DEFAULT NULL,
    claimed_at TIMESTAMP NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    archived_at TIMESTAMP NULL,
    archive_reason VARCHAR(30) DEFAULT NULL,
    homepage_order SMALLINT UNSIGNED DEFAULT NULL,
    review_note VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (brand_category_id) REFERENCES brand_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (replaced_by_brand_id) REFERENCES brands(id) ON DELETE SET NULL,
    FOREIGN KEY (claimed_by_brand_id) REFERENCES brands(id) ON DELETE SET NULL,
    UNIQUE KEY uq_bdl_slug (slug),
    INDEX idx_bdl_name_key (name_key),
    INDEX idx_bdl_domain (official_domain),
    INDEX idx_bdl_public (status, verification_status, expires_at)
);
