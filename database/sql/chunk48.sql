-- Meta CAPI production hardening (Phase 1): Test Mode safety switch +
-- last-event status snapshot for tenant settings visibility.
--
-- capi_test_mode: explicit ON/OFF switch. fb_test_event_code (existing
-- column) is only ever sent to Meta when this is 1. Defaults to 0 so
-- existing tenants who already had a test_event_code saved do NOT start
-- tagging production Purchase events as test events after this deploys.
--
-- capi_last_status / capi_last_http_status / capi_last_error /
-- capi_last_event_at: snapshot of the most recent real Purchase CAPI call
-- for this tenant, written by MetaCapiService::sendPurchase() via
-- CheckoutController@sendCapiPurchase. Powers the "Last CAPI event status"
-- block in tenant settings without needing to query the log files.
ALTER TABLE marketing_settings
    ADD COLUMN capi_test_mode TINYINT(1) NOT NULL DEFAULT 0 AFTER fb_test_event_code,
    ADD COLUMN capi_last_status VARCHAR(20) DEFAULT NULL,
    ADD COLUMN capi_last_http_status SMALLINT DEFAULT NULL,
    ADD COLUMN capi_last_error VARCHAR(255) DEFAULT NULL,
    ADD COLUMN capi_last_event_at TIMESTAMP NULL DEFAULT NULL;
