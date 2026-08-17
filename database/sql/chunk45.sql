-- Super Admin Plan Management CMS fields — customer-facing marketing
-- content (tagline) and which plan is highlighted as "Popular" on the
-- landing page, both editable by Super Admin instead of hardcoded in
-- Blade (resources/views/central/landing.blade.php previously derived
-- the "Popular" badge purely from `$loop->iteration === 2`). Additive
-- only: two new nullable/default columns, nothing else changes.
ALTER TABLE plans
    ADD COLUMN tagline VARCHAR(150) DEFAULT NULL AFTER name,
    ADD COLUMN is_featured TINYINT(1) DEFAULT 0 AFTER is_active;
