<?php
declare(strict_types=1);

/** Household-scoped trash. All dates and expiry decisions use server UTC. */
final class RecipeDeletionService
{
    public function __construct(private PDO $pdo, private HouseholdService $households) {}

    public function recentlyDeleted(string $userId, string $householdId, int $offset = 0): array {
        $this->households->membership($userId, $householdId);
        if ($offset < 0 || $offset > 100000) throw new HttpError(400, 'invalid_filter', 'Invalid offset.');
        $q = $this->pdo->prepare("SELECT id,title,revision,deleted_at,purge_after FROM recipes WHERE trash_household_id=? AND deleted_at IS NOT NULL AND purge_after>UTC_TIMESTAMP(6) ORDER BY deleted_at DESC,id DESC LIMIT 31 OFFSET $offset");
        $q->execute([$householdId]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > 30;
        if ($more) array_pop($rows);
        foreach ($rows as &$row) {
            $row['id'] = (string)$row['id']; $row['revision'] = (string)$row['revision'];
            foreach (['deleted_at','purge_after'] as $key) $row[$key] = str_replace(' ', 'T', $row[$key]).'Z';
        }
        return ['recipes'=>$rows, 'next_offset'=>$more ? $offset+count($rows) : null];
    }

    public function delete(string $userId, string $householdId, string $id, array $body): array {
        return $this->change($userId, $householdId, $id, $body);
    }

    public function restore(string $userId, string $householdId, string $id): array {
        return $this->change($userId, $householdId, $id, null);
    }

    private function change(string $userId, string $householdId, string $id, ?array $body): array {
        $this->households->membership($userId, $householdId);
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare('SELECT revision,deleted_at,purge_after,trash_household_id,(purge_after<=UTC_TIMESTAMP(6)) AS expired FROM recipes WHERE id=? FOR UPDATE');
            $q->execute([$id]); $row=$q->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new HttpError(404,'recipe_not_found','Recipe not found.');
            $q=$this->pdo->prepare('SELECT removed_at FROM recipe_households WHERE recipe_id=? AND household_id=?');
            $q->execute([$id,$householdId]); $attachment=$q->fetch(PDO::FETCH_ASSOC);
            if (!$attachment) throw new HttpError(404,'recipe_not_found','Recipe not in this household.');
            if ($body!==null) {
                if ($attachment['removed_at']===null) {
                    if ((string)($body['revision'] ?? '')!==(string)$row['revision']) throw new HttpError(409,'recipe_changed','This recipe changed. Reload it before deleting.');
                    $this->pdo->prepare('UPDATE recipe_households SET removed_at=UTC_TIMESTAMP(6) WHERE recipe_id=? AND household_id=?')->execute([$id,$householdId]);
                    $q=$this->pdo->prepare('SELECT COUNT(*) FROM recipe_households WHERE recipe_id=? AND removed_at IS NULL');
                    $q->execute([$id]);
                    if ((int)$q->fetchColumn()===0) {
                        $this->pdo->prepare('UPDATE recipes SET deleted_at=UTC_TIMESTAMP(6),purge_after=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 30 DAY),trash_household_id=?,deleted_by_user_id=?,updated_by_user_id=?,revision=revision+1 WHERE id=?')->execute([$householdId,$userId,$userId,$id]);
                    }
                }
                $q=$this->pdo->prepare('SELECT purge_after FROM recipes WHERE id=?'); $q->execute([$id]);
                $expiry=$q->fetchColumn();
                $result=['deleted_recipe_id'=>$id,'purge_after'=>$expiry===null ? null : str_replace(' ','T',$expiry).'Z'];
            } else {
                if ($row['deleted_at']!==null) {
                    if ((string)$row['trash_household_id']!==$householdId) throw new HttpError(404,'recipe_not_found','Recipe not in this household trash.');
                    if ($row['expired']) throw new HttpError(410,'recipe_expired','The 30-day recovery period has ended.');
                    $this->pdo->prepare('UPDATE recipe_households SET removed_at=NULL WHERE recipe_id=? AND household_id=?')->execute([$id,$householdId]);
                    $this->pdo->prepare('UPDATE recipes SET deleted_at=NULL,purge_after=NULL,trash_household_id=NULL,deleted_by_user_id=NULL,updated_by_user_id=?,revision=revision+1 WHERE id=?')->execute([$userId,$id]);
                } elseif ($attachment['removed_at']!==null) {
                    throw new HttpError(404,'recipe_not_found','Add this recipe to your household again from its shared link.');
                }
                $result=['recipe'=>(new RecipeService($this->pdo,$this->households))->detail($userId,$householdId,$id)];
            }
            $this->pdo->commit(); return $result;
        } catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
    }

    /** Expiry and restore serialize on the same recipe row. History survives removal. */
    public function purgeExpired(string $mediaDirectory, int $limit = 100): int {
        $limit = max(1, min(1000, $limit)); $count = 0;
        $ids = $this->pdo->query("SELECT id FROM recipes WHERE deleted_at IS NOT NULL AND purge_after<=UTC_TIMESTAMP(6) ORDER BY purge_after,id LIMIT $limit")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $this->pdo->beginTransaction();
            try {
            $q=$this->pdo->prepare('SELECT id FROM recipe_relationship_lock WHERE id=1 FOR UPDATE');
            $q->execute();
            if ($q->fetchColumn()===false) throw new RuntimeException('Relationship migration is required.');
                $q = $this->pdo->prepare('SELECT id,title,household_id FROM recipes WHERE id=? AND deleted_at IS NOT NULL AND purge_after<=UTC_TIMESTAMP(6) FOR UPDATE');
                $q->execute([$id]); $row = $q->fetch(PDO::FETCH_ASSOC);
                if (!$row) { $this->pdo->commit(); continue; }
                $q=$this->pdo->prepare('SELECT COUNT(*) FROM recipe_households WHERE recipe_id=? AND removed_at IS NULL'); $q->execute([$id]);
                if ((int)$q->fetchColumn()>0) { $this->pdo->commit(); continue; }
                $q = $this->pdo->prepare('SELECT storage_key FROM recipe_photos WHERE recipe_id=?'); $q->execute([$id]); $files = $q->fetchAll(PDO::FETCH_COLUMN);
                $this->pdo->prepare('INSERT INTO deleted_recipe_cooking_history (household_id,event_id,recipe_id,recipe_title,recipe_revision,servings_made,notes) SELECT household_id,event_id,recipe_id,?,recipe_revision,servings_made,notes FROM cooking_event_recipes WHERE recipe_id=?')->execute([$row['title'],$id]);
                $this->pdo->prepare('DELETE FROM cooking_event_recipes WHERE recipe_id=?')->execute([$id]);
                $this->pdo->prepare('UPDATE recipes SET parent_recipe_id=NULL,revision=revision+1 WHERE parent_recipe_id=?')->execute([$id]);
                $this->pdo->prepare('DELETE FROM recipes WHERE id=?')->execute([$id]);
                $this->pdo->commit(); $count++;
                foreach (array_unique($files) as $key) {
                    if (!preg_match('#^media/recipes/([a-f0-9]{48}\.(?:jpg|png|webp))$#', (string)$key, $match)) continue;
                    $q = $this->pdo->prepare('SELECT COUNT(*) FROM recipe_photos WHERE storage_key=?'); $q->execute([$key]);
                    if ((int)$q->fetchColumn() === 0) {
                        $file = rtrim($mediaDirectory, '/\\').DIRECTORY_SEPARATOR.$match[1];
                        if (is_file($file) && !is_link($file) && !unlink($file)) error_log('Expired recipe photo cleanup failed.');
                    }
                }
            } catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
        }
        return $count;
    }
}
