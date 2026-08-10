-- Messenger media attachments (additive).
--
-- messenger_messages.attachment_url already existed but with no way to
-- know what kind of attachment it is (Meta's webhook payload has an
-- explicit `type` per attachment — image/audio/video/file — that was
-- never captured), so the UI could never render a type-aware player/
-- preview. attachment_name is best-effort (Meta rarely provides a real
-- filename for image/audio; used mainly for generic "file" attachments).
-- Still one attachment per message row, matching the existing design —
-- Meta's rare multi-attachment messages keep using only the first one,
-- same as before this change.
ALTER TABLE messenger_messages ADD COLUMN attachment_type VARCHAR(20) DEFAULT NULL AFTER attachment_url;
ALTER TABLE messenger_messages ADD COLUMN attachment_name VARCHAR(255) DEFAULT NULL AFTER attachment_type;
