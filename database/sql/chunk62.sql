-- Real-time(ish) courier status feature: Web/Mobile need an honest "last
-- updated" timestamp next to courier_status (schema.sql), since that
-- column was previously only ever set at dispatch time or on a manual
-- "refresh" tap with no record of WHEN it was last confirmed current.
-- Populated by both the existing on-demand refresh (CourierController::
-- refreshStatus() / Api\Mobile\OrderController::refreshCourierStatus())
-- and the new courier:refresh-statuses scheduled command (routes/console.php).
ALTER TABLE orders ADD COLUMN courier_status_checked_at TIMESTAMP NULL DEFAULT NULL AFTER courier_status;
