-- Microsoft Clarity session-recording integration (tenant-scoped).
--
-- clarity_project_id: Clarity's public project ID, injected as a client-side
-- script in resources/views/layouts/store.blade.php (same tenant-scoped
-- $mk = MarketingSetting::first() lookup already used for Meta Pixel/GTM).
-- No separate enabled flag: presence of this column is the enable switch,
-- matching the existing fb_pixel_id / gtm_container_id pattern. Does not
-- touch Meta Pixel, Meta CAPI, or GTM in any way.
ALTER TABLE marketing_settings
    ADD COLUMN clarity_project_id VARCHAR(20) DEFAULT NULL AFTER gtm_container_id;
