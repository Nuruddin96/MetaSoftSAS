-- Recognition platform, part 2 (import after chunk64.sql). Additive only.
--
-- 1. vote_campaigns.vote_limit gains 'program': one phone number may cast
--    ONE vote in the whole award programme — across every category and
--    every campaign linked to the same award (VoteService). 'daily' and
--    'once' keep their existing meaning, so other programmes are unaffected.
-- 2. platform_settings: small key → JSON store for the Super Admin
--    "Homepage" section (hero text, which award/campaign the homepage shows,
--    sample-content fallback, optional sections). Not a CMS.

ALTER TABLE vote_campaigns
    MODIFY vote_limit ENUM('daily','once','program') NOT NULL DEFAULT 'daily';

CREATE TABLE platform_settings (
    `key` VARCHAR(80) NOT NULL PRIMARY KEY,
    value JSON DEFAULT NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
);
