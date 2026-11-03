-- One-time setup using the existing database administrator, after a private backup.
-- Never grant schema privileges to the runtime account or run on deployment.
ALTER TABLE recipe_step_actions ADD COLUMN duration_max_seconds INT UNSIGNED NULL;
ALTER TABLE shared_timers ADD COLUMN extension_seconds INT UNSIGNED NOT NULL DEFAULT 0;
