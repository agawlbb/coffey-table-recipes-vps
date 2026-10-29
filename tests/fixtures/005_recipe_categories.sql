CREATE TABLE IF NOT EXISTS recipe_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    parent_id BIGINT UNSIGNED NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    label VARCHAR(120) NOT NULL,
    image_key VARCHAR(80) NOT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (parent_id) REFERENCES recipe_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_category_assignments (
    household_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (recipe_id, category_id),
    KEY category_browse (household_id, category_id, recipe_id),
    FOREIGN KEY (household_id, recipe_id) REFERENCES recipes(household_id, id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES recipe_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO recipe_categories (slug,label,image_key,position) VALUES
('main-dishes','Main dishes','main-dishes',1),
('side-dishes','Side dishes','side-dishes',2),
('desserts','Desserts','desserts',3),
('drinks','Drinks','drinks',4),
('breakfast','Breakfast','breakfast',5),
('appetizers','Appetizers','appetizers',6);
