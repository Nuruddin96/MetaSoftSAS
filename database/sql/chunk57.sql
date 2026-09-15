-- Landing Page Design System (global design settings).
--
-- Additive column for an already-live table (chunk56.sql) — production
-- already has `landing_pages` without this column, so this file is the
-- ALTER that ships the change to an existing install; chunk56.sql's
-- CREATE TABLE was also updated in the same change so a fresh install
-- gets the column directly and never runs this file.
--
-- NULL (the default for every landing page created before this change)
-- means "use App\Models\LandingPage::defaultDesign()" — see
-- App\Services\LandingPage\DesignResolver — so no existing landing page's
-- rendered output changes until a tenant explicitly opens the new Design
-- tab and saves an override.
ALTER TABLE landing_pages
    ADD COLUMN design JSON DEFAULT NULL AFTER sections;
