-- Recognition platform, part 3 (import after chunk65.sql). Additive only —
-- no existing row, vote, verification state or award is changed.
--
-- 1. brand_owner_password_reset_tokens: self-service password recovery for
--    brand owners (auth.passwords.brand_owners). Deliberately its own table,
--    NOT the tenant panel's password_reset_tokens: a tenant user and a brand
--    owner may share an email address, and a reset for one must never be
--    usable against the other. Tokens are stored hashed, expire after 60
--    minutes and are deleted on use (Laravel's password broker).
-- 2. brands.contact_checked_*: Super Admin records that a person checked the
--    owner's phone/WhatsApp contact (e.g. called or messaged them) before
--    approval. This is a manual review note, NOT automated phone-ownership
--    (OTP) verification.
-- 3. brands.verification_basis: what evidence the admin actually reviewed
--    when granting the Verified badge (JSON list of keys from
--    App\Models\Brand::VERIFICATION_EVIDENCE). NULL for brands verified
--    before this import — the public page then names no specific evidence.
--
-- Until this file is imported the app keeps working: the owner "forgot
-- password" page falls back to WhatsApp support and the admin review panel
-- shows an "import chunk66.sql" notice (App\Support\Platform\PlatformSchema).

CREATE TABLE brand_owner_password_reset_tokens (
    email VARCHAR(150) NOT NULL PRIMARY KEY,
    token VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NULL
);

ALTER TABLE brands
    ADD COLUMN contact_checked_at TIMESTAMP NULL AFTER verified_at,
    ADD COLUMN contact_checked_by BIGINT UNSIGNED NULL AFTER contact_checked_at,
    ADD COLUMN contact_check_method VARCHAR(20) NULL AFTER contact_checked_by,
    ADD COLUMN contact_check_note VARCHAR(255) NULL AFTER contact_check_method,
    ADD COLUMN verification_basis JSON NULL AFTER contact_check_note;
