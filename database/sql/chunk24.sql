-- Messenger -> Order bridge (additive).
--
-- Links an Order back to the Messenger conversation it was auto-created
-- from, and adds a 'messenger' source so these auto-created orders are
-- distinguishable from hand-typed manual orders. No FK to facebook_pages —
-- sender_psid is already tenant-scoped via orders.tenant_id, so this stays
-- decoupled from the OAuth tables' partial-import guard
-- (FacebookPage::tablesReady()).
ALTER TABLE orders ADD COLUMN messenger_psid VARCHAR(100) DEFAULT NULL AFTER channel;
ALTER TABLE orders ADD INDEX idx_messenger_psid (messenger_psid);

ALTER TABLE orders MODIFY source ENUM('web','pos','manual','messenger') DEFAULT 'web';
