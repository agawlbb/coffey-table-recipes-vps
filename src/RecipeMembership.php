<?php
declare(strict_types=1);

/** All membership mutations lock the canonical recipe first. */
final class RecipeMembership {
    public function __construct(private PDO $pdo, private HouseholdService $households) {}

    public function attach(string $userId, string $householdId, string $recipeId): array {
        $this->households->membership($userId,$householdId);
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare('SELECT id FROM recipes WHERE id=? AND deleted_at IS NULL FOR UPDATE');
            $q->execute([$recipeId]);
            if (!$q->fetchColumn()) throw new HttpError(404,'recipe_not_found','Recipe not found.');
            $q=$this->pdo->prepare('SELECT removed_at FROM recipe_households WHERE recipe_id=? AND household_id=?');
            $q->execute([$recipeId,$householdId]);
            $existing=$q->fetch(PDO::FETCH_ASSOC);
            if (!$existing) $this->pdo->prepare('INSERT INTO recipe_households(recipe_id,household_id) VALUES (?,?)')->execute([$recipeId,$householdId]);
            elseif ($existing['removed_at']!==null) $this->pdo->prepare('UPDATE recipe_households SET removed_at=NULL WHERE recipe_id=? AND household_id=?')->execute([$recipeId,$householdId]);
            $this->pdo->commit();
            return ['recipe'=>(new RecipeService($this->pdo,$this->households))->detail($userId,$householdId,$recipeId)];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
}
