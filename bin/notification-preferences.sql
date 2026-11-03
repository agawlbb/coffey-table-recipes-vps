-- Explicit one-time setup using the database maintenance identity, before source deployment.
-- Existing devices remain enabled. Routine deployment must not run this statement.
ALTER TABLE timer_devices ADD COLUMN receive_cooking_invitations BOOLEAN NOT NULL DEFAULT TRUE;
