CREATE TABLE IF NOT EXISTS recipes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    parent_recipe_id BIGINT UNSIGNED NULL,
    title VARCHAR(300) NOT NULL,
    description TEXT NULL,
    original_language VARCHAR(35) NOT NULL DEFAULT 'en',
    measurement_system ENUM('metric', 'us', 'mixed', 'unspecified') NOT NULL DEFAULT 'unspecified',
    source_type VARCHAR(40) NULL,
    source_url TEXT NULL,
    source_title VARCHAR(500) NULL,
    source_author VARCHAR(300) NULL,
    source_reference TEXT NULL,
    source_text LONGTEXT NULL,
    import_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
    yield_text VARCHAR(200) NULL,
    servings DECIMAL(12,4) NULL,
    prep_minutes INT UNSIGNED NULL,
    cook_minutes INT UNSIGNED NULL,
    total_minutes INT UNSIGNED NULL,
    notes TEXT NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_by_user_id BIGINT UNSIGNED NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY household_recipe (household_id, id),
    UNIQUE KEY household_import_key (household_id, import_key),
    KEY recipe_title (household_id, title),
    FOREIGN KEY (household_id) REFERENCES households(id),
    FOREIGN KEY (household_id, parent_recipe_id) REFERENCES recipes(household_id, id),
    FOREIGN KEY (household_id, created_by_user_id) REFERENCES household_memberships(household_id, user_id),
    FOREIGN KEY (household_id, updated_by_user_id) REFERENCES household_memberships(household_id, user_id),
    CHECK (CHAR_LENGTH(TRIM(title)) > 0),
    CHECK (servings IS NULL OR servings > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_ingredient_groups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    UNIQUE KEY recipe_group (household_id, recipe_id, id),
    UNIQUE KEY group_position (recipe_id, position),
    FOREIGN KEY (household_id, recipe_id) REFERENCES recipes(household_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_ingredients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,
    group_id BIGINT UNSIGNED NULL,
    position INT UNSIGNED NOT NULL,
    original_text TEXT NOT NULL,
    ingredient_name VARCHAR(300) NOT NULL,
    quantity_text VARCHAR(120) NULL,
    quantity_decimal DECIMAL(20,8) NULL,
    quantity_numerator BIGINT UNSIGNED NULL,
    quantity_denominator BIGINT UNSIGNED NULL,
    quantity_max_decimal DECIMAL(20,8) NULL,
    original_unit VARCHAR(80) NULL,
    unit_code VARCHAR(40) NULL,
    preparation VARCHAR(300) NULL,
    is_optional BOOLEAN NOT NULL DEFAULT 0,
    is_main_ingredient BOOLEAN NOT NULL DEFAULT 0,
    scale_quantity BOOLEAN NOT NULL DEFAULT 1,
    UNIQUE KEY ingredient_position (recipe_id, position),
    KEY ingredient_search (household_id, ingredient_name),
    FOREIGN KEY (household_id, recipe_id) REFERENCES recipes(household_id, id) ON DELETE CASCADE,
    FOREIGN KEY (household_id, recipe_id, group_id) REFERENCES recipe_ingredient_groups(household_id, recipe_id, id),
    CHECK (CHAR_LENGTH(TRIM(ingredient_name)) > 0),
    CHECK (quantity_decimal IS NULL OR quantity_decimal >= 0),
    CHECK ((quantity_numerator IS NULL AND quantity_denominator IS NULL) OR
        (quantity_numerator IS NOT NULL AND quantity_denominator IS NOT NULL AND quantity_denominator > 0 AND quantity_decimal IS NULL)),
    CHECK (quantity_max_decimal IS NULL OR (quantity_decimal IS NOT NULL AND quantity_max_decimal >= quantity_decimal))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_steps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    section_title VARCHAR(200) NULL,
    instruction TEXT NOT NULL,
    UNIQUE KEY recipe_step (household_id, recipe_id, id),
    UNIQUE KEY step_position (recipe_id, position),
    FOREIGN KEY (household_id, recipe_id) REFERENCES recipes(household_id, id) ON DELETE CASCADE,
    CHECK (CHAR_LENGTH(TRIM(instruction)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_step_actions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,
    step_id BIGINT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    action_type ENUM('timer', 'appliance_temperature', 'internal_temperature', 'rest', 'reminder') NOT NULL,
    label VARCHAR(300) NULL,
    duration_seconds INT UNSIGNED NULL,
    temperature_value DECIMAL(10,4) NULL,
    temperature_unit ENUM('C', 'F') NULL,
    appliance VARCHAR(80) NULL,
    UNIQUE KEY action_position (step_id, position),
    FOREIGN KEY (household_id, recipe_id, step_id) REFERENCES recipe_steps(household_id, recipe_id, id) ON DELETE CASCADE,
    CHECK ((temperature_value IS NULL AND temperature_unit IS NULL) OR (temperature_value IS NOT NULL AND temperature_unit IS NOT NULL)),
    CHECK (action_type NOT IN ('timer', 'rest') OR (duration_seconds IS NOT NULL AND duration_seconds > 0)),
    CHECK (action_type NOT IN ('appliance_temperature', 'internal_temperature') OR temperature_value IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_photos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    storage_key VARCHAR(500) NULL,
    source_url TEXT NULL,
    mime_type VARCHAR(80) NULL,
    width_pixels INT UNSIGNED NULL,
    height_pixels INT UNSIGNED NULL,
    caption TEXT NULL,
    attribution TEXT NULL,
    is_cover BOOLEAN NOT NULL DEFAULT 0,
    cover_recipe_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN is_cover = 1 THEN recipe_id ELSE NULL END) STORED,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY photo_position (recipe_id, position),
    UNIQUE KEY single_cover (cover_recipe_id),
    FOREIGN KEY (household_id, recipe_id) REFERENCES recipes(household_id, id) ON DELETE CASCADE,
    CHECK (storage_key IS NOT NULL OR source_url IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_tags (
    household_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('cuisine', 'category', 'technique', 'equipment') NOT NULL,
    label VARCHAR(120) NOT NULL,
    PRIMARY KEY (recipe_id, kind, label),
    KEY tag_search (household_id, kind, label),
    FOREIGN KEY (household_id, recipe_id) REFERENCES recipes(household_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_pairings (
    household_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,
    paired_recipe_id BIGINT UNSIGNED NOT NULL,
    notes TEXT NULL,
    PRIMARY KEY (recipe_id, paired_recipe_id),
    FOREIGN KEY (household_id, recipe_id) REFERENCES recipes(household_id, id) ON DELETE CASCADE,
    FOREIGN KEY (household_id, paired_recipe_id) REFERENCES recipes(household_id, id) ON DELETE CASCADE,
    CHECK (recipe_id < paired_recipe_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_favorites (
    household_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (recipe_id, person_id),
    FOREIGN KEY (household_id, recipe_id) REFERENCES recipes(household_id, id) ON DELETE CASCADE,
    FOREIGN KEY (household_id, person_id) REFERENCES household_people(household_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_nutrition (
    household_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED PRIMARY KEY,
    recipe_revision BIGINT UNSIGNED NOT NULL,
    basis ENUM('per_serving', 'whole_recipe') NOT NULL DEFAULT 'per_serving',
    calories_kcal DECIMAL(12,4) NULL,
    protein_g DECIMAL(12,4) NULL,
    carbohydrate_g DECIMAL(12,4) NULL,
    fat_g DECIMAL(12,4) NULL,
    saturated_fat_g DECIMAL(12,4) NULL,
    fiber_g DECIMAL(12,4) NULL,
    sugar_g DECIMAL(12,4) NULL,
    sodium_mg DECIMAL(12,4) NULL,
    method VARCHAR(100) NOT NULL,
    source_reference TEXT NULL,
    is_estimate BOOLEAN NOT NULL DEFAULT 1,
    calculated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    FOREIGN KEY (household_id, recipe_id) REFERENCES recipes(household_id, id) ON DELETE CASCADE,
    CHECK (calories_kcal IS NULL OR calories_kcal >= 0),
    CHECK (protein_g IS NULL OR protein_g >= 0),
    CHECK (carbohydrate_g IS NULL OR carbohydrate_g >= 0),
    CHECK (fat_g IS NULL OR fat_g >= 0),
    CHECK (saturated_fat_g IS NULL OR saturated_fat_g >= 0),
    CHECK (fiber_g IS NULL OR fiber_g >= 0),
    CHECK (sugar_g IS NULL OR sugar_g >= 0),
    CHECK (sodium_mg IS NULL OR sodium_mg >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cooking_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    cooked_at DATETIME(6) NOT NULL,
    recorded_by_user_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY household_event (household_id, id),
    FOREIGN KEY (household_id) REFERENCES households(id),
    FOREIGN KEY (household_id, recorded_by_user_id) REFERENCES household_memberships(household_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cooking_event_recipes (
    household_id BIGINT UNSIGNED NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,
    recipe_revision BIGINT UNSIGNED NOT NULL,
    servings_made DECIMAL(12,4) NULL,
    notes TEXT NULL,
    PRIMARY KEY (event_id, recipe_id),
    KEY recipe_history (household_id, recipe_id, event_id),
    FOREIGN KEY (household_id, event_id) REFERENCES cooking_events(household_id, id) ON DELETE CASCADE,
    FOREIGN KEY (household_id, recipe_id) REFERENCES recipes(household_id, id),
    CHECK (servings_made IS NULL OR servings_made > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
