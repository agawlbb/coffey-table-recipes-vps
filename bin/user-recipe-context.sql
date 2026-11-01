-- Explicit setup only; routine deployment does not run schema changes.
CREATE TABLE IF NOT EXISTS user_recipe_context (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    state_json LONGTEXT NOT NULL CHECK (JSON_VALID(state_json)),
    CONSTRAINT user_recipe_context_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
