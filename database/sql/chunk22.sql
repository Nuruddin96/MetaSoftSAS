-- Messenger webhook duplicate-event protection. Meta's delivery is
-- at-least-once (retries on timeout/non-2xx), and until now nothing
-- captured Meta's own per-message ID (mid), so every retry filed a full
-- duplicate row for the same real customer message.
--
-- Nullable + UNIQUE: outgoing (staff-sent) messages don't have a mid and
-- aren't touched by this fix, and MySQL/MariaDB UNIQUE indexes allow
-- unlimited NULLs — only actual duplicate mid values are rejected.
ALTER TABLE messenger_messages ADD COLUMN mid VARCHAR(100) DEFAULT NULL AFTER sender_psid;
ALTER TABLE messenger_messages ADD UNIQUE INDEX uq_mid (mid);
