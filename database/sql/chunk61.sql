-- Fixes a real production bug found while investigating "cannot permanently
-- delete a tenant" (Facebook Page connection cleanup task): chunk23.sql's
-- facebook_connections.connected_by_user_id references users(id) with no
-- ON DELETE action (defaults to RESTRICT), unlike the identical
-- facebook_oauth_states.user_id FK right above it in that same file, which
-- correctly cascades. Deleting a tenant cascades to delete both its `users`
-- rows and its `facebook_connections` row (each via their own tenant_id
-- CASCADE), but MySQL still evaluates the non-cascading
-- connected_by_user_id constraint against the user row independently of
-- that — the two cascade paths don't get sequenced so one always finishes
-- before the other conflicts, so `DELETE FROM tenants WHERE id = ?` throws
-- SQLSTATE 23000 (1451) for ANY tenant that ever connected Facebook,
-- whenever that FK evaluation loses the race. Confirmed against production:
-- repeated failed delete attempts on tenant_id=5 in storage/logs/
-- laravel.log, all with this exact constraint name and error.
--
-- This only changes the FK's ON DELETE behavior — no rows are touched by
-- this file itself, and no existing facebook_connections row's data
-- changes (a plain ALTER on a foreign key definition doesn't rewrite the
-- referencing column's values, and MySQL permits DROP/ADD FOREIGN KEY
-- while existing values happen to satisfy the constraint, which they do
-- here since no orphaned connected_by_user_id currently exists — that was
-- never possible to reach before this fix, since a tenant delete simply
-- failed outright instead of producing an orphan).
ALTER TABLE facebook_connections DROP FOREIGN KEY facebook_connections_ibfk_2;
ALTER TABLE facebook_connections ADD CONSTRAINT facebook_connections_ibfk_2
    FOREIGN KEY (connected_by_user_id) REFERENCES users(id) ON DELETE CASCADE;
