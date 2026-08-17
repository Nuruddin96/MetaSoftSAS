-- Page Header — a distinct on-page <h1> heading, separate from `title`
-- (which stays the nav-link label — see resources/views/storefront/
-- page.blade.php). Additive only: one new nullable column, nothing else
-- changes. NULL/blank falls back to `title`, same value as before this
-- column existed — see Tenant\WebsiteController::storePage()/updatePage().
ALTER TABLE pages ADD COLUMN page_header VARCHAR(200) DEFAULT NULL AFTER title;
