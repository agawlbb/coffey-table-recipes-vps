<?php
declare(strict_types=1);

/** Public, read-only recipe display using the database recipe ID. */
final class RecipeShare
{
    public static function recipeId($value): string {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,18}$/D', $value)) throw new HttpError(404, 'recipe_not_found', 'Recipe not found.');
        return $value;
    }

    public static function load(PDO $pdo, string $id): array {
        $read = static function(string $sql, array $parameters) use ($pdo): array {
            $statement = $pdo->prepare($sql); $statement->execute($parameters);
            return array_map(static function(array $row): array {
                foreach ($row as $key => $value) if ($value !== null && ($key === 'id' || str_ends_with($key, '_id'))) $row[$key] = (string)$value;
                return $row;
            }, $statement->fetchAll(PDO::FETCH_ASSOC));
        };
        $recipe = $read('SELECT id,household_id,title,source_type,description,original_language,servings,yield_text,prep_minutes,cook_minutes,total_minutes,notes,revision,parent_recipe_id,source_title,source_author,source_url,source_reference FROM recipes WHERE deleted_at IS NULL AND id=?', [$id])[0] ?? null;
        if (!$recipe || $recipe['source_type'] === 'blank') throw new HttpError(404, 'recipe_not_found', 'Recipe not found.');
        $household = $recipe['household_id'];
        foreach (['ingredients'=>'recipe_ingredients','groups'=>'recipe_ingredient_groups','steps'=>'recipe_steps','photos'=>'recipe_photos'] as $key=>$table) $recipe[$key] = $read("SELECT * FROM $table WHERE household_id=? AND recipe_id=? ORDER BY position,id", [$household,$id]);
        $recipe['tags'] = $read('SELECT kind,label FROM recipe_tags WHERE household_id=? AND recipe_id=? ORDER BY kind,label', [$household,$id]);
        $recipe['nutrition'] = $read('SELECT * FROM recipe_nutrition WHERE household_id=? AND recipe_id=?', [$household,$id])[0] ?? null;
        if ($recipe['nutrition'] !== null) $recipe['nutrition']['stale'] = (string)$recipe['nutrition']['recipe_revision'] !== (string)$recipe['revision'];
        $parent=$recipe['parent_recipe_id'];
        $recipe['related'] = $read('SELECT id,title FROM recipes WHERE deleted_at IS NULL AND id<>? AND (parent_recipe_id=? OR id=? OR (? IS NOT NULL AND parent_recipe_id=?)) ORDER BY title,id', [$id,$id,$parent,$parent,$parent]);
        $recipe['pairings'] = $read('SELECT r.id,r.title FROM recipe_pairings p JOIN recipes r ON r.deleted_at IS NULL AND r.id=CASE WHEN p.recipe_id=? THEN p.paired_recipe_id ELSE p.recipe_id END WHERE (p.recipe_id=? OR p.paired_recipe_id=?) ORDER BY r.title,r.id', [$id,$id,$id]);
        return $recipe;
    }
}