-- Additive "Additional Amount" (Extra Charge) field for the mobile app's
-- manual-entry order flows (Api\Mobile\OrderController::store()/complete(),
-- via OrderCreationService) — a merchant-entered amount added on top of
-- discount/delivery, e.g. for a custom surcharge. Web's own order-create
-- form has no equivalent field and is untouched; this column defaults to 0
-- so every existing row's `total` stays exactly as it was.
ALTER TABLE orders ADD COLUMN additional_amount DECIMAL(12,2) DEFAULT 0 AFTER discount;
