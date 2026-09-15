-- Manual/historical order entry: lets staff record the customer's actual
-- order date when backdating an old order entered into the system today.
--
-- Deliberately a separate column from created_at (when the row was
-- entered into the system — must never be overwritten) and confirmed_at
-- (when the order was confirmed, not necessarily the same as when the
-- customer actually ordered). NULL for every existing row and for any
-- order source that doesn't collect it (storefront checkout, POS,
-- Messenger auto-created pending orders) — those already have the right
-- created_at as their effective order date.
ALTER TABLE orders
    ADD COLUMN order_date DATE NULL DEFAULT NULL AFTER source;
