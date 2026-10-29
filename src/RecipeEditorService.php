<?php
declare(strict_types=1);

/** Atomic, revision-checked editing of a household recipe. */
final class RecipeEditorService
{
    public function __construct(private PDO $pdo, private HouseholdService $households, private string $mediaDirectory, private string $mediaUrl, private $categorizeBlank = null) {}

    private string $validationContext = '';
    private function invalid(string $message): void { throw new HttpError(422, 'invalid_recipe', $this->validationContext.$message); }
    private function fields(array $row, array $spec): array {
        $result = [];
        foreach ($spec as $key => $rule) {
            $fieldLabel=str_replace("_"," ",$key);
            $value = $row[$key] ?? null;
            if ($value === '') $value = null;
            if ($rule === 'bool') {
                if (!in_array($value, [null, true, false, 0, 1, '0', '1'], true)) $this->invalid("Check the $fieldLabel field.");
                $result[$key] = (int) ($value ?? 0); continue;
            }
            if ($value === null) {
                if (str_starts_with($rule, '!')) $this->invalid("Please enter $fieldLabel.");
                $result[$key] = null; continue;
            }
            $rule = ltrim($rule, '!');
            if (str_starts_with($rule, 'enum:')) {
                if (!is_string($value) || !in_array($value, explode(',', substr($rule, 5)), true)) $this->invalid("Check the $fieldLabel field.");
            } elseif (in_array($rule, ['number', 'positive', 'nonnegative', 'integer'], true)) {
                if ((!is_string($value) && !is_int($value) && !is_float($value)) || !is_numeric($value) || !is_finite((float) $value)
                    || abs((float) $value) > 1000000000 || ($rule === 'positive' && (float) $value <= 0)
                    || (in_array($rule, ['nonnegative', 'integer'], true) && (float) $value < 0)
                    || ($rule === 'integer' && !preg_match('/^\d+$/', (string) $value))) $this->invalid("Check the $fieldLabel field.");
            } else {
                if (!is_string($value) || mb_strlen($value) > (int) $rule || (str_starts_with($spec[$key], '!') && trim($value) === '')) $this->invalid("Check the $fieldLabel field.");
                $value = trim($value);
            }
            $result[$key] = $value;
        }
        return $result;
    }
    private function list(array $body, string $key, int $maximum = 300): array {
        $rows = $body[$key] ?? null;
        if (!is_array($rows) || array_values($rows) !== $rows || count($rows) > $maximum) $this->invalid("Invalid $key list.");
        foreach ($rows as $row) if (!is_array($row)) $this->invalid("Invalid $key entry.");
        return $rows;
    }
    private function insert(string $table, array $values): string {
        $keys = array_keys($values);
        $statement = $this->pdo->prepare('INSERT INTO '.$table.' ('.implode(',', $keys).') VALUES ('.implode(',', array_fill(0, count($keys), '?')).')');
        $statement->execute(array_values($values));
        return isset($values['id']) ? (string) $values['id'] : $this->pdo->lastInsertId();
    }
    private function ownedId(array $row, array $existing, array &$seen): array {
        $id = (string) ($row['id'] ?? '');
        if ($id === '') $this->invalid('Missing item identifier.');
        if (isset($seen[$id])) $this->invalid('Duplicate item identifier.');
        $seen[$id] = true;
        if (str_starts_with($id, 'new-')) return [];
        if (!isset($existing[$id])) $this->invalid('An item no longer belongs to this recipe. Reload and try again.');
        return ['id' => $id];
    }
    public function save(string $userId, string $householdId, string $recipeId, array $body): array {
        $this->validationContext='';
        $this->households->membership($userId, $householdId);
        $receipt = new RecipeSaveReceipts($this->pdo, $userId, $householdId, $recipeId, $body);
        if (($saved = $receipt->replay()) !== null) {
            (new RecipeService($this->pdo,$this->households))->detail($userId,$householdId,(string)$saved['id']);
            return $saved;
        }
        if (($body['source_type'] ?? null)==='blank' && ($body['category_assignments'] ?? null)===[] && $this->categorizeBlank!==null) {
            $title=$this->fields($body,['title'=>'!300'])['title'];
            if ($recipeId!=='') {
                $current=(new RecipeService($this->pdo,$this->households))->detail($userId,$householdId,$recipeId);
                if ((string)($body['revision'] ?? '')!==(string)$current['revision']) throw new HttpError(409,'recipe_changed','This recipe changed. Reload and try again.');
                if ($current['household_count']>1 && ($body['confirm_shared_edit'] ?? false)!==true) throw new HttpError(409,'shared_confirmation_required','Confirm changes to this shared recipe.');
            }
            $catalog=(new RecipeService($this->pdo,$this->households))->editOptions($userId,$householdId)['categories'];
            $body['category_assignments']=[($this->categorizeBlank)($title,$catalog,$userId)];
        }
        $createdFiles = [];
        $creating = $recipeId === '';
        $viewHousehold=$householdId;
        $this->pdo->beginTransaction();
        try {
            // Serialize canonical relationship changes before taking any recipe locks.
            $q=$this->pdo->prepare('SELECT id FROM recipe_relationship_lock WHERE id=1 FOR UPDATE');
            $q->execute();
            if ($q->fetchColumn()===false) throw new RuntimeException('Relationship migration is required.');
            // The relationship lock serializes receipt lookup + recipe mutation.
            // Both the immutable receipt and recipe commit in one transaction.
            if (($saved = $receipt->replay()) !== null) {
                (new RecipeService($this->pdo,$this->households))->detail($userId,$viewHousehold,(string)$saved['id']);
                $this->pdo->commit();
                return $saved;
            }
            if (!$creating) {
                $q=$this->pdo->prepare('SELECT household_id FROM recipes WHERE id=? AND deleted_at IS NULL FOR UPDATE');
                $q->execute([$recipeId]); $storage=$q->fetchColumn();
                $q=$this->pdo->prepare('SELECT household_id FROM recipe_households WHERE recipe_id=? AND removed_at IS NULL');
                $q->execute([$recipeId]); $attached=array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));
                if ($storage===false || !in_array($viewHousehold,$attached,true)) throw new HttpError(404,'recipe_not_found','Recipe not in this household.');
                if (count($attached)>1 && ($body['confirm_shared_edit'] ?? false)!==true) throw new HttpError(409,'shared_confirmation_required','This recipe is shared. Confirm that your changes will affect every household.');
                $householdId=(string)$storage;
            }
            if (!empty($body['_import_method_choice_required'])) $this->invalid('Choose which cooking method to import before saving this recipe.');
            $parent = $body['parent_recipe_id'] ?? null;
            if ($parent === '') $parent = null;
            if ($parent !== null) {
                if ((!is_string($parent) && !is_int($parent)) || !preg_match('/^[1-9][0-9]{0,18}$/D', (string) $parent)) $this->invalid('Choose a live original recipe.');
                $parent = (string) $parent;
            }
            if ($recipeId === '') {
                $source = $this->fields($body, ['source_text'=>'60000','import_key'=>'191']);
                if ($source['import_key'] !== null && !preg_match('/^[a-f0-9]{64}$/', $source['import_key'])) $this->invalid('Invalid import identifier.');
                $variation = $this->fields($body, ['source_type'=>'40'])['source_type'] === 'ai_variation';
                if ($variation && ($parent === null || $source['import_key'] === null)) $this->invalid('A variation needs an original recipe and its draft identifier.');
                if ($variation) {
                    // A successful save whose response was lost is safe to retry.
                    // Return the committed result, never apply a retry's content
                    // to either it or the original. Ordinary imports retain their
                    // existing duplicate-import conflict behavior.
                    $q = $this->pdo->prepare('SELECT id,parent_recipe_id,source_type,deleted_at FROM recipes WHERE household_id=? AND import_key=? FOR UPDATE');
                    $q->execute([$householdId,$source['import_key']]);
                    $saved = $q->fetch(PDO::FETCH_ASSOC);
                    if ($saved) {
                        if ($saved['source_type'] !== 'ai_variation' || (string) $saved['parent_recipe_id'] !== $parent) throw new HttpError(409, 'recipe_already_imported', 'This draft identifier is already used by a different recipe. Start a new variation.');
                        if ($saved['deleted_at'] !== null) throw new HttpError(409, 'recipe_already_imported', 'This variation was already saved and moved to Recently deleted. Restore it or start a new variation.');
                        $saved = (new RecipeService($this->pdo,$this->households))->detail($userId,$householdId,(string)$saved['id']);
                        $receipt->record($saved);
                        $this->pdo->commit();
                        return $saved;
                    }
                }
                if ($source['import_key'] !== null) {
                    // Re-importing must not be blocked by recoverable trash. Transfer
                    // only the duplicate reservation, in the same save transaction.
                    $q = $this->pdo->prepare('UPDATE recipes SET import_key=NULL WHERE household_id=? AND import_key=? AND deleted_at IS NOT NULL');
                    $q->execute([$householdId,$source['import_key']]);
                }
                $recipeId = $this->insert('recipes', ['household_id'=>$householdId,'title'=>'New recipe','created_by_user_id'=>$userId,'updated_by_user_id'=>$userId] + $source);
                $this->pdo->prepare('INSERT INTO recipe_households(recipe_id,household_id) VALUES (?,?)')->execute([$recipeId,$householdId]);
                $body['revision'] = 1;
            }
            $q = $this->pdo->prepare('SELECT revision FROM recipes WHERE deleted_at IS NULL AND household_id=? AND id=? FOR UPDATE');
            $q->execute([$householdId, $recipeId]);
            $revision = $q->fetchColumn();
            if ($revision === false) throw new HttpError(404, 'recipe_not_found', 'Recipe not found.');
            if ((string) ($body['revision'] ?? '') !== (string) $revision) throw new HttpError(409, 'recipe_changed', 'This recipe was changed by someone else. Close the editor, reload the recipe, and try again.');
            $recipe = $this->fields($body, [
                'title'=>'!300','description'=>'60000','original_language'=>'!35','measurement_system'=>'!enum:metric,us,mixed,unspecified',
                'source_type'=>'40','source_url'=>'4000','source_title'=>'500','source_author'=>'300','source_reference'=>'60000',
                'yield_text'=>'200','servings'=>'positive','prep_minutes'=>'integer','cook_minutes'=>'integer','total_minutes'=>'integer','notes'=>'60000',
            ]);
            if ($recipe['source_url'] !== null && !preg_match('#^https?://[^\s]+$#i', $recipe['source_url'])) $this->invalid('Source URL must start with http:// or https://.');
            if ($parent !== null) {
                // Locking reads see the latest committed ancestry even under
                // MySQL REPEATABLE READ, and keep the selected parent live until
                // this save commits. A deleted ancestor is traversed for cycles,
                // but only the directly selected parent must still be live.
                $ancestor = (string) $parent; $visited = [$recipeId => true];
                while ($ancestor !== '') {
                    if (isset($visited[$ancestor])) $this->invalid('The original recipe cannot form a circular relationship.');
                    $visited[$ancestor] = true;
                    $q = $this->pdo->prepare('SELECT parent_recipe_id,deleted_at FROM recipes WHERE id=? FOR UPDATE');
                    $q->execute([$ancestor]); $r = $q->fetch(PDO::FETCH_ASSOC);
                    if (!$r || ($ancestor === $parent && $r['deleted_at'] !== null)) $this->invalid('Choose a live original recipe.');
                    $ancestor = (string) ($r['parent_recipe_id'] ?? '');
                }
            }
            $recipe['parent_recipe_id'] = $parent;
            $existing = [];
            foreach (['groups'=>'recipe_ingredient_groups','ingredients'=>'recipe_ingredients','steps'=>'recipe_steps','actions'=>'recipe_step_actions','photos'=>'recipe_photos'] as $key=>$table) {
                $q = $this->pdo->prepare("SELECT * FROM $table WHERE household_id=? AND recipe_id=?"); $q->execute([$householdId,$recipeId]);
                $existing[$key] = array_column($q->fetchAll(PDO::FETCH_ASSOC), null, 'id');
            }
            $lists = [];
            foreach (['groups','ingredients','steps','actions','photos','tags','category_assignments','pairings'] as $key) $lists[$key] = $this->list($body, $key, 300);
            if ($recipe['source_type'] === 'blank') {
                if (!$lists['category_assignments']) $this->invalid('Choose at least one category.');
                foreach (['ingredients','groups','steps','actions','pairings'] as $key) if ($lists[$key]) $this->invalid('Import the blank recipe before adding cooking content.');
                if (($body['nutrition'] ?? null) !== null) $this->invalid('Import the blank recipe before adding nutrition.');
            }
            foreach (['recipe_step_actions','recipe_ingredients','recipe_ingredient_groups','recipe_steps','recipe_photos','recipe_tags','recipe_category_assignments','recipe_nutrition'] as $table) {
                $this->pdo->prepare("DELETE FROM $table WHERE household_id=? AND recipe_id=?")->execute([$householdId,$recipeId]);
            }
            $base = ['household_id'=>$householdId,'recipe_id'=>$recipeId];
            $groups = []; $steps = [];
            foreach (['groups','steps'] as $key) {
                $seen = [];
                foreach ($lists[$key] as $position=>$row) {
                    $values = $this->fields($row, $key === 'groups' ? ['title'=>'!200'] : ['section_title'=>'200','instruction'=>'!60000']);
                    $id = $this->insert($key === 'groups' ? 'recipe_ingredient_groups' : 'recipe_steps', $base + $this->ownedId($row,$existing[$key],$seen) + ['position'=>$position+1] + $values);
                    if ($key === 'groups') $groups[(string)$row['id']] = $id; else $steps[(string)$row['id']] = $id;
                }
            }
            $seen = [];
            foreach ($lists['ingredients'] as $position=>$row) {
                $this->validationContext='Ingredient '.($position+1).' ('.mb_substr((string)($row['original_text'] ?? $row['ingredient_name'] ?? ''),0,100).'): ';
                $values = $this->fields($row, ['original_text'=>'!60000','ingredient_name'=>'!300','quantity_text'=>'120','quantity_decimal'=>'nonnegative',
                    'quantity_numerator'=>'integer','quantity_denominator'=>'integer','quantity_max_decimal'=>'nonnegative','original_unit'=>'80','unit_code'=>'40',
                    'preparation'=>'300','is_optional'=>'bool','is_main_ingredient'=>'bool','scale_quantity'=>'bool']);
                $n = $values['quantity_numerator']; $d = $values['quantity_denominator'];
                if (($n === null) !== ($d === null) || ($d !== null && ((float)$d <= 0 || $values['quantity_decimal'] !== null))) $this->invalid('Use either a decimal quantity or a fraction with a positive denominator.');
                if ($values['quantity_max_decimal'] !== null && ($values['quantity_decimal'] === null || (float)$values['quantity_max_decimal'] < (float)$values['quantity_decimal'])) $this->invalid('The maximum quantity must be at least the decimal quantity.');
                $group = $row['group_id'] ?? null;
                if ($group !== null && $group !== '' && !isset($groups[(string)$group])) $this->invalid('Invalid ingredient group.');
                $this->insert('recipe_ingredients', $base + $this->ownedId($row,$existing['ingredients'],$seen) + ['position'=>$position+1,'group_id'=>$groups[(string)$group] ?? null] + $values);
            }
            $seen = []; $positions = [];
            $this->validationContext='';
            foreach ($lists['actions'] as $actionPosition=>$row) {
                $this->validationContext='Action '.($actionPosition+1).' ('.mb_substr((string)($row['label'] ?? ''),0,100).'): ';
                $step = $steps[(string)($row['step_id'] ?? '')] ?? null;
                if ($step === null) $this->invalid('Every action must belong to a step.');
                $values = $this->fields($row, ['action_type'=>'!enum:timer,appliance_temperature,internal_temperature,rest,reminder','label'=>'300','duration_seconds'=>'integer','temperature_value'=>'number','temperature_unit'=>'enum:C,F','appliance'=>'80']);
                // Also repair already reviewed imports that described heat without a numeric target.
                if ($values['action_type']==='appliance_temperature' && $values['temperature_value']===null && trim((string)($values['label'] ?? ''))!=='') {
                    $values['action_type']='reminder';
                    $values['temperature_unit']=null;
                }
                if (in_array($values['action_type'], ['timer','rest'], true) && (int)($values['duration_seconds'] ?? 0) <= 0) $this->invalid('Timers and rests need a positive duration.');
                if (($values['temperature_value'] === null) !== ($values['temperature_unit'] === null) || (in_array($values['action_type'], ['appliance_temperature','internal_temperature'], true) && $values['temperature_value'] === null)) $this->invalid('Temperature actions need a temperature and unit.');
                $positions[$step] = ($positions[$step] ?? 0)+1;
                $this->insert('recipe_step_actions', $base + $this->ownedId($row,$existing['actions'],$seen) + ['step_id'=>$step,'position'=>$positions[$step]] + $values);
            }
            $this->validationContext='';
            $seen = []; $covers = 0; $uploadBytes = 0;
            foreach ($lists['photos'] as $position=>$row) {
                $id = $this->ownedId($row,$existing['photos'],$seen);
                $values = $this->fields($row, ['caption'=>'60000','attribution'=>'60000','is_cover'=>'bool']);
                if (($covers += $values['is_cover']) > 1) $this->invalid('Choose only one cover photo.');
                if (isset($row['image_base64'])) {
                    if (!is_string($row['image_base64']) || strlen($row['image_base64']) > 7000000) $this->invalid('Each photo must be at most 5 MB.');
                    $bytes = base64_decode($row['image_base64'], true);
                    $info = $bytes === false ? false : @getimagesizefromstring($bytes);
                    $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                    if (!$info || !isset($extensions[$info['mime']]) || strlen($bytes) > 5*1024*1024 || $info[0]*$info[1] > 24000000) $this->invalid('Choose a JPEG, PNG, or WebP image up to 5 MB and 24 megapixels.');
                    $uploadBytes += strlen($bytes);
                    if (!is_dir($this->mediaDirectory) && !mkdir($this->mediaDirectory,0755,true)) throw new RuntimeException('Cannot create media directory.');
                    $filename = bin2hex(random_bytes(24)).'.'.$extensions[$info['mime']];
                    $file = $this->mediaDirectory.'/'.$filename; $createdFiles[] = $file;
                    if (file_put_contents($file,$bytes,LOCK_EX) !== strlen($bytes)) throw new RuntimeException('Cannot save photo.');
                    chmod($file,0644);
                    $values += ['source_url'=>rtrim($this->mediaUrl,'/').'/'.$filename,'storage_key'=>'vps/recipes/'.$filename,'mime_type'=>$info['mime'],'width_pixels'=>$info[0],'height_pixels'=>$info[1]];
                } elseif (isset($id['id'])) {
                    // Existing storage metadata is never accepted from the client.
                    $old = $existing['photos'][$id['id']];
                    foreach (['source_url','storage_key','mime_type','width_pixels','height_pixels','created_at'] as $field) $values[$field] = $old[$field];
                } else $this->invalid('New photos need an uploaded image.');
                $this->insert('recipe_photos',$base + $id + ['position'=>$position+1] + $values);
            }
            $seen = [];
            foreach ($lists['tags'] as $row) {
                $values = $this->fields($row,['kind'=>'!enum:cuisine,category,technique,equipment','label'=>'!120']);
                $key = $values['kind'].'|'.$values['label'];
                if (isset($seen[$key])) $this->invalid('Remove duplicate tags.'); $seen[$key] = true;
                $this->insert('recipe_tags',$base + $values);
            }
            $seen = [];
            foreach ($lists['category_assignments'] as $row) {
                $id = (string)($row['category_id'] ?? '');
                if ($id === '' && isset($row['label'])) {
                    $labels = $this->fields($row,['label'=>'!120','parent_label'=>'120']);
                    $parent = null;
                    if ($labels['parent_label'] !== null) {
                        $q = $this->pdo->prepare('SELECT id,image_key FROM recipe_categories WHERE parent_id IS NULL AND label=?');
                        $q->execute([$labels['parent_label']]); $parent = $q->fetch(PDO::FETCH_ASSOC);
                        if (!$parent) $this->invalid('Choose an existing parent category.');
                    }
                    if ($parent && mb_strtolower($labels['label'])===mb_strtolower($labels['parent_label'])) {
                        $id=(string)$parent['id'];
                    } else {
                        $q = $this->pdo->prepare('SELECT id FROM recipe_categories WHERE label=? AND parent_id <=> ?');
                        $q->execute([$labels['label'],$parent['id'] ?? null]); $id = (string)($q->fetchColumn() ?: '');
                    }
                    if ($id === '') {
                        if (!$parent) $this->invalid('New categories need an existing parent category.');
                        $slug = 'import-'.hash('sha256',$parent['id'].'|'.mb_strtolower($labels['label']));
                        $q = $this->pdo->prepare('INSERT INTO recipe_categories (parent_id,slug,label,image_key,position) VALUES (?,?,?,?,100) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
                        $q->execute([$parent['id'],$slug,$labels['label'],$parent['image_key']]);
                        $id = $this->pdo->lastInsertId();
                    }
                }
                $q = $this->pdo->prepare('SELECT id FROM recipe_categories WHERE id=?'); $q->execute([$id]);
                if (!$q->fetchColumn() || isset($seen[$id])) $this->invalid('Invalid or duplicate category.'); $seen[$id] = true;
                $this->insert('recipe_category_assignments',$base + ['category_id'=>$id]);
            }
            $this->pdo->prepare('DELETE FROM recipe_pairings WHERE recipe_id=? OR paired_recipe_id=?')->execute([$recipeId,$recipeId]);
            $seen = [];
            foreach ($lists['pairings'] as $row) {
                $id = (string)($row['id'] ?? '');
                $q = $this->pdo->prepare('SELECT id FROM recipes WHERE deleted_at IS NULL AND id=?'); $q->execute([$id]);
                if ($id === $recipeId || !$q->fetchColumn() || isset($seen[$id])) $this->invalid('Invalid or duplicate pairing.'); $seen[$id] = true;
                $values = $this->fields($row,['notes'=>'60000']);
                $this->insert('recipe_pairings',['household_id'=>$householdId,'recipe_id'=>min((int)$recipeId,(int)$id),'paired_recipe_id'=>max((int)$recipeId,(int)$id)] + $values);
            }
            $nutrition = $body['nutrition'] ?? null;
            if ($nutrition !== null) {
                if (!is_array($nutrition)) $this->invalid('Invalid nutrition.');
                $spec = ['basis'=>'!enum:per_serving,whole_recipe','method'=>'!100','source_reference'=>'60000','is_estimate'=>'bool'];
                foreach (['calories_kcal','protein_g','carbohydrate_g','fat_g','saturated_fat_g','fiber_g','sugar_g','sodium_mg'] as $field) $spec[$field] = 'nonnegative';
                $values = $this->fields($nutrition,$spec);
                // Preserve stale status when nutrition was not updated for the edited ingredients.
                $nutritionRevision = ($body['nutrition_confirmed'] ?? false) === true ? (int)$revision+1 : min((int)($nutrition['recipe_revision'] ?? $revision),(int)$revision);
                $this->insert('recipe_nutrition',$base + ['recipe_revision'=>$nutritionRevision] + $values);
            }
            $recipe['revision'] = (int)$revision+1; $recipe['updated_by_user_id'] = $userId;
            $q = $this->pdo->prepare('UPDATE recipes SET '.implode(',',array_map(static fn($key)=>"$key=?",array_keys($recipe))).' WHERE household_id=? AND id=?');
            $q->execute([...array_values($recipe),$householdId,$recipeId]);
            if ($recipe['source_type']==='blank' && array_key_exists('favorite_people',$body)) {
                $people=$this->list($body,'favorite_people',100); $seen=[];
                $service=new RecipeService($this->pdo,$this->households);
                foreach ($people as $person) {
                    $personId=$person['person_id'] ?? null;
                    if (!is_string($personId) || isset($seen[$personId])) $this->invalid('Choose each household member only once.');
                    $seen[$personId]=true;
                    $service->setFavorite($userId,$viewHousehold,$recipeId,$person);
                }
            } elseif ($creating && $recipe['source_type'] === 'blank' && ($body['favorite_on_create'] ?? false) === true) {
                (new RecipeService($this->pdo,$this->households))->setFavorite($userId,$viewHousehold,$recipeId,['favorite'=>true]);
            }
            $saved = (new RecipeService($this->pdo,$this->households))->detail($userId,$viewHousehold,$recipeId);
            $receipt->record($saved);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            foreach ($createdFiles as $file) if (is_file($file)) unlink($file);
            if ($creating && $error instanceof PDOException && (int)($error->errorInfo[1] ?? 0) === 1062) throw new HttpError(409, 'recipe_already_imported', 'This import has already been saved. Find it in Recipes before trying again.');
            if ($error instanceof PDOException && in_array($error->getCode(),['23000','22003','22001'],true)) $this->invalid('Some recipe values are invalid or too large. Please check your entries.');
            throw $error;
        }
        return $saved;
    }
}
