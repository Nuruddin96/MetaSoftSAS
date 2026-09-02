-- WordPress -> MetaSoftSAS order sync (Phase 5 of the WordPress
-- integration plan — see docs/wordpress-integration-architecture.md).
-- Additive, mirrors chunk24.sql's exact "messenger_psid column + new
-- source ENUM value" shape for the Messenger -> Order bridge, since that
-- is the closest existing precedent for "a webhook-created order needs a
-- stable external reference + a way to tell it apart from a hand-typed
-- one" — no new mapping table needed, and per Phase 5's own instructions,
-- do not over-engineer past what the existing architecture already gives.
--
-- wordpress_order_id: the WooCommerce order id (wp_posts.ID for that
-- order on the connected site). UNIQUE(tenant_id, wordpress_order_id) is
-- the actual idempotency guarantee for webhook retries — MySQL treats
-- multiple NULLs in a UNIQUE index as distinct, so this never constrains
-- any non-WordPress order (which always has NULL here).
ALTER TABLE orders ADD COLUMN wordpress_order_id BIGINT UNSIGNED DEFAULT NULL AFTER messenger_psid;
ALTER TABLE orders ADD UNIQUE KEY uq_tenant_wp_order (tenant_id, wordpress_order_id);

-- 'wordpress' as a new *technical* origin (source), distinguishing a
-- webhook-created order from every existing source — used internally to
-- decide whether an order's status change should be pushed back out to
-- WooCommerce (see WordPressOrderSyncService::pushStatusUpdate()) and to
-- skip a duplicate Meta CAPI Purchase event (the connected WordPress site
-- already fires its own Pixel/CAPI for the same sale).
ALTER TABLE orders MODIFY source ENUM('web','pos','manual','messenger','wordpress') DEFAULT 'web';

-- 'wordpress' as a new *marketing* channel (channel), reusing the exact
-- badge/label/color/icon infrastructure the tenant panel's order list/show
-- pages already have for 'website'/'facebook'/'whatsapp'/etc. — the
-- "simple source/channel indicator" Phase 5's admin-visibility requirement
-- asks for.
ALTER TABLE orders MODIFY channel ENUM('website','facebook','instagram','whatsapp','call','others','wordpress') DEFAULT 'website';
