-- Recognition platform, part 6 (import after chunk68.sql). Additive only —
-- no row is changed or deleted.
--
-- Brand cover image: an optional wide banner uploaded next to the logo on
-- "List Your Brand" and shown at the top of the public brand profile.
-- Files are stored on the public disk under brands/covers/; the column holds
-- that relative path, like logo_path.
--
-- Safe to re-run (IF NOT EXISTS, MariaDB 10.0+). Until this is imported the
-- app hides the cover upload and ignores any submitted cover
-- (PlatformSchema::coverReady()), and profiles keep using the first gallery
-- photo or the brand gradient as their banner.

ALTER TABLE brands ADD COLUMN IF NOT EXISTS cover_path VARCHAR(255) DEFAULT NULL AFTER logo_path;
