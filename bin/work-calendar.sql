-- Explicit one-time setup before deploying calendar support. Never run during routine deployment.
CREATE TABLE household_work_calendars (
    household_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    dates_json LONGTEXT NOT NULL,
    color CHAR(7) NOT NULL DEFAULT '#E09F3E',
    FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE
);
