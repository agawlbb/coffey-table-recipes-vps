<?php
declare(strict_types=1);

final class RecipeService
{
    public function __construct(private PDO $pdo, private HouseholdService $households, private string $mediaUrl = 'https://coffeybean.com/CoffeyTableRecipes/media/recipes') {}

    private function rows(string $sql, array $parameters): array {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return array_map(static function(array $row): array {
            foreach ($row as $key => $value) {
                if (($key === 'id' || str_ends_with($key, '_id') || $key === 'revision' || str_ends_with($key, '_revision')) && $value !== null) $row[$key] = (string) $value;
            }
            return $row;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Include deleted links when finding a family, but never return deleted members. */
    private function variationFamilies(string $householdId, bool $allMembers = false): array {
        $rows=$this->rows('SELECT id,title,parent_recipe_id,deleted_at,EXISTS (SELECT 1 FROM recipe_households h WHERE h.recipe_id=recipes.id AND h.household_id=? AND h.removed_at IS NULL) AS in_household FROM recipes ORDER BY id',[$householdId]);
        $byId=array_column($rows,null,'id'); $roots=[]; $families=[];
        foreach ($rows as $row) {
            $id=$row['id']; $path=[];
            while (isset($byId[$id]) && !isset($roots[$id]) && !isset($path[$id])) {
                $path[$id]=true;
                $parent=$byId[$id]['parent_recipe_id'];
                if ($parent===null || !isset($byId[$parent])) break;
                $id=$parent;
            }
            $root=$roots[$id] ?? $id;
            foreach ($path as $member=>$_) $roots[$member]=$root;
            if ($row['deleted_at']!==null || (!$allMembers && !$row['in_household'])) continue;
            unset($row['deleted_at']);
            $row['in_household']=(bool)$row['in_household'];
            $row['is_variation']=$row['parent_recipe_id']!==null;
            $row['variation_base_title']=$byId[$root]['title'] ?? $row['title'];
            $families[$root][]=$row;
        }
        // Prefer the original; if it is deleted, keep one live family member visible.
        foreach ($families as $root=>&$members) {
            usort($members,static fn($a,$b)=>(($a['id']==$root ? 0 : 1)<=>($b['id']==$root ? 0 : 1)) ?: (strlen($a['id'])<=>strlen($b['id']) ?: strcmp($a['id'],$b['id'])));
        } unset($members);
        return $families;
    }

    public function newRecipeFavorites(string $userId,string $householdId): array {
        $this->households->membership($userId,$householdId);
        $people=$this->rows('SELECT id,display_name,user_id FROM household_people WHERE household_id=? ORDER BY id',[$householdId]);
        $current=null;
        foreach ($people as &$person) {
            $person['favorite']=false;
            if ($person['user_id']===$userId) $current=$person['id'];
        } unset($person);
        return ['people'=>$people,'current_person_id'=>$current];
    }

    public function favorites(string $userId, string $householdId, string $recipeId): array {
        $this->households->membership($userId,$householdId);
        if (!$this->rows('SELECT r.id FROM recipes r JOIN recipe_households h ON h.recipe_id=r.id WHERE r.id=? AND h.household_id=? AND h.removed_at IS NULL AND r.deleted_at IS NULL',[$recipeId,$householdId])) throw new HttpError(404,'recipe_not_found','Recipe not in this household.');
        return ['people'=>$this->rows('SELECT p.id,p.display_name,p.user_id,EXISTS (SELECT 1 FROM recipe_favorites f WHERE f.household_id=p.household_id AND f.person_id=p.id AND f.recipe_id=?) AS favorite FROM household_people p WHERE p.household_id=? ORDER BY p.id',[$recipeId,$householdId]),'current_person_id'=>$this->rows('SELECT id FROM household_people WHERE household_id=? AND user_id=?',[$householdId,$userId])[0]['id'] ?? null];
    }

    public function setFavorite(string $userId, string $householdId, string $recipeId, array $body): array {
        $state=$this->favorites($userId,$householdId,$recipeId);
        $person=$body['person_id'] ?? $state['current_person_id'];
        if (!is_string($person) || !in_array($person,array_column($state['people'],'id'),true) || !isset($body['favorite']) || !is_bool($body['favorite'])) throw new HttpError(400,'invalid_favorite','Choose a household member and favorite state.');
        $q=$this->pdo->prepare($body['favorite'] ? 'INSERT IGNORE INTO recipe_favorites (household_id,recipe_id,person_id) VALUES (?,?,?)' : 'DELETE FROM recipe_favorites WHERE household_id=? AND recipe_id=? AND person_id=?');
        $q->execute([$householdId,$recipeId,$person]);
        return $this->favorites($userId,$householdId,$recipeId);
    }

    public function browse(string $userId, string $householdId, string $query = '', int $offset = 0, string $category = '', string $scope = '', bool $tree = false, bool $familiesOnly = false, bool $publicRead = false, string $favoritePeople = '', bool $blankOnly = false): array {
        if (!$publicRead) $this->households->membership($userId, $householdId);
        if (mb_strlen($query) > 200 || mb_strlen($category) > 120 || $offset < 0 || $offset > 100000) throw new HttpError(400, 'invalid_filter', 'Invalid recipe search.');
        $parameters = [$householdId];
        $where = 'EXISTS (SELECT 1 FROM recipe_households h WHERE h.recipe_id=r.id AND h.household_id=? AND h.removed_at IS NULL) AND r.deleted_at IS NULL';
        if ($favoritePeople !== '') {
            $this->households->membership($userId,$householdId);
            if (!preg_match('/^[1-9][0-9]{0,18}(,[1-9][0-9]{0,18}){0,99}$/',$favoritePeople)) throw new HttpError(400,'invalid_filter','Invalid favorite members.');
            $allowed=array_column($this->households->people($userId,$householdId),'id');
            foreach (array_unique(explode(',',$favoritePeople)) as $person) {
                if (!in_array($person,$allowed,true)) throw new HttpError(400,'invalid_filter','Choose members of this household.');
                $where.=' AND EXISTS (SELECT 1 FROM recipe_favorites f WHERE f.recipe_id=r.id AND f.household_id=? AND f.person_id=?)';
                array_push($parameters,$householdId,$person);
            }
            $tree=false; $familiesOnly=false;
        }
        if ($blankOnly) {
            $where.=" AND r.source_type='blank'";
            $tree=false; $familiesOnly=false;
        }
        $representatives=[];
        foreach ($this->variationFamilies($householdId) as $members) {
            foreach ($members as $member) $representatives[$member['id']]=$members[0]['id'];
        }
        $node = null;
        if ($scope !== '') {
            if (!preg_match('/^[1-9][0-9]{0,18}$/', $scope)) throw new HttpError(400, 'invalid_filter', 'Invalid category scope.');
            $node = $this->rows('SELECT id,parent_id,label FROM recipe_categories WHERE id=?', [$scope])[0] ?? null;
            if (!$node) throw new HttpError(404, 'category_not_found', 'Category not found.');
            $where .= ' AND EXISTS (SELECT 1 FROM recipe_category_assignments a JOIN recipe_categories c ON c.id=a.category_id WHERE a.household_id=r.household_id AND a.recipe_id=r.id AND (c.id=? OR c.parent_id=?))';
            array_push($parameters, $scope, $scope);
        }
        if ($tree && $query === '' && ($node === null || $node['parent_id'] === null)) {
            $count='COUNT(DISTINCT active.id)'; $countParameters=[]; $cases=[];
            if ($familiesOnly) {
                foreach ($representatives as $id=>$representative) {
                    if ((string)$id===$representative) continue;
                    $cases[]='WHEN ? THEN ?'; array_push($countParameters,(string)$id,$representative);
                }
                if ($cases) $count='COUNT(DISTINCT CASE active.id '.implode(' ',$cases).' ELSE active.id END)';
            }
            $nodes = $this->rows("SELECT c.id,c.parent_id,c.slug,c.label,c.image_key,$count AS recipe_count
                FROM recipe_categories c LEFT JOIN recipe_categories child ON child.parent_id=c.id
                LEFT JOIN recipe_category_assignments a ON (a.category_id=c.id OR a.category_id=child.id) AND EXISTS (SELECT 1 FROM recipe_households h WHERE h.recipe_id=a.recipe_id AND h.household_id=? AND h.removed_at IS NULL)
                LEFT JOIN recipes active ON active.id=a.recipe_id AND active.household_id=a.household_id AND active.deleted_at IS NULL
                WHERE c.parent_id <=> ? GROUP BY c.id,c.parent_id,c.slug,c.label,c.image_key,c.position
                HAVING c.parent_id IS NULL OR recipe_count>0 ORDER BY c.position,c.label,c.id", [...$countParameters, $householdId, $scope === '' ? null : $scope]);
            foreach ($nodes as &$categoryNode) {
                $categoryNode['image_url']=str_starts_with($categoryNode['image_key'],'ai-') ? rtrim($this->mediaUrl,'/').'/category-'.$categoryNode['id'].'.jpg' : null;
                $categoryNode['needs_ai_image']=str_starts_with($categoryNode['slug'],'import-') && $categoryNode['image_url']===null && !$publicRead && $this->households->aiImagesEnabled($userId, $householdId);
            } unset($categoryNode);
            return ['recipes' => [], 'category_nodes' => $nodes, 'view' => 'categories', 'next_offset' => null];
        }
        if ($category !== '') {
            $where .= " AND EXISTS (SELECT 1 FROM recipe_tags c WHERE c.recipe_id=r.id AND c.kind='category' AND c.label=?)";
            $parameters[] = $category;
        }
        if ($query !== '') {
            // Escape LIKE wildcards so searches are literal, not user-supplied patterns.
            $like = '%' . strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $where .= " AND (r.title LIKE ? ESCAPE '!' OR r.description LIKE ? ESCAPE '!' OR EXISTS (SELECT 1 FROM recipe_ingredients i WHERE i.recipe_id=r.id AND i.ingredient_name LIKE ? ESCAPE '!') OR EXISTS (SELECT 1 FROM recipe_tags t WHERE t.recipe_id=r.id AND t.label LIKE ? ESCAPE '!') OR EXISTS (SELECT 1 FROM recipe_category_assignments a JOIN recipe_categories c ON c.id=a.category_id LEFT JOIN recipe_categories p ON p.id=c.parent_id WHERE a.household_id=r.household_id AND a.recipe_id=r.id AND (c.label LIKE ? ESCAPE '!' OR p.label LIKE ? ESCAPE '!')))";
            array_push($parameters, $like, $like, $like, $like, $like, $like);
        }
        if ($familiesOnly) {
            // Search/filter all members first, then paginate their family representatives.
            $matching=$this->rows("SELECT r.id FROM recipes r WHERE $where",$parameters);
            $ids=array_values(array_unique(array_map(static fn($row)=>$representatives[$row['id']] ?? $row['id'],$matching)));
            $where='EXISTS (SELECT 1 FROM recipe_households h WHERE h.recipe_id=r.id AND h.household_id=? AND h.removed_at IS NULL) AND r.deleted_at IS NULL'.($ids ? ' AND r.id IN ('.implode(',',array_fill(0,count($ids),'?')).')' : ' AND 1=0');
            $parameters=[$householdId,...$ids];
        }
        $items = $this->rows("SELECT r.id,r.title,r.description,r.original_language,r.servings,r.yield_text,r.prep_minutes,r.cook_minutes,r.total_minutes,r.parent_recipe_id,
            (SELECT source_url FROM recipe_photos p WHERE p.recipe_id=r.id ORDER BY p.is_cover DESC,p.position LIMIT 1) AS photo_url,
            (SELECT storage_key FROM recipe_photos p WHERE p.recipe_id=r.id ORDER BY p.is_cover DESC,p.position LIMIT 1) AS photo_storage_key
            FROM recipes r WHERE $where ORDER BY r.title,r.id LIMIT 31 OFFSET $offset", $parameters);
        foreach ($items as &$item) {
            $item['family_representative_id']=$representatives[$item['id']] ?? $item['id'];
            $item['is_variation'] = $item['parent_recipe_id'] !== null;
            if (preg_match('#^media/recipes/([a-f0-9]{48}\.(?:jpg|png|webp))$#',(string)($item['photo_storage_key'] ?? ''),$match)) $item['photo_url']=rtrim($this->mediaUrl,'/').'/'.$match[1];
            unset($item['photo_storage_key']);
        } unset($item);
        $hasMore = count($items) > 30;
        if ($hasMore) array_pop($items);
        $categories = $this->rows("SELECT DISTINCT t.label FROM recipe_tags t JOIN recipes r ON r.id=t.recipe_id AND r.household_id=t.household_id AND r.deleted_at IS NULL WHERE EXISTS (SELECT 1 FROM recipe_households h WHERE h.recipe_id=r.id AND h.household_id=? AND h.removed_at IS NULL) AND t.kind='category' ORDER BY t.label", [$householdId]);
        return ['recipes' => $items, 'categories' => array_column($categories, 'label'), 'category_nodes' => [], 'view' => 'recipes', 'next_offset' => $hasMore ? $offset + count($items) : null];
    }

    public function detail(string $userId, string $householdId, string $recipeId, bool $publicRead = false): array {
        if (!$publicRead) $this->households->membership($userId, $householdId);
        $viewHousehold=$householdId;
        $rows = $this->rows('SELECT * FROM recipes WHERE deleted_at IS NULL AND id=?', [$recipeId]);
        if (!$publicRead && !$this->rows('SELECT recipe_id FROM recipe_households WHERE recipe_id=? AND household_id=? AND removed_at IS NULL',[$recipeId,$viewHousehold])) throw new HttpError(404,'recipe_not_found','Recipe not in this household.');
        if (!$rows) throw new HttpError(404, 'recipe_not_found', 'Recipe not found.');
        $recipe = $rows[0];
        $householdId=$recipe['household_id'];
        $recipe['in_household']=(bool)$this->rows('SELECT recipe_id FROM recipe_households WHERE recipe_id=? AND household_id=? AND removed_at IS NULL',[$recipeId,$viewHousehold]);
        $recipe['household_count']=(int)$this->rows('SELECT COUNT(*) AS total FROM recipe_households WHERE recipe_id=? AND removed_at IS NULL',[$recipeId])[0]['total'];
        unset($recipe['created_by_user_id'],$recipe['updated_by_user_id'],$recipe['deleted_by_user_id'],$recipe['trash_household_id']);
        $recipe['is_variation'] = $recipe['parent_recipe_id'] !== null;
        // Keep raw extracted source text and import bookkeeping out of the display response.
        unset($recipe['source_text'], $recipe['import_key']);
        foreach (['ingredients' => 'recipe_ingredients', 'groups' => 'recipe_ingredient_groups', 'steps' => 'recipe_steps', 'photos' => 'recipe_photos'] as $key => $table) {
            $recipe[$key] = $this->rows("SELECT * FROM $table WHERE household_id=? AND recipe_id=? ORDER BY position,id", [$householdId, $recipeId]);
        }
        foreach ($recipe['photos'] as &$photo) {
            $storage=$photo['storage_key'] ?? '';
            if (preg_match('#^media/recipes/([a-f0-9]{48}\.(?:jpg|png|webp))$#',$storage,$match)) $photo['source_url']=rtrim($this->mediaUrl,'/').'/'.$match[1];
        } unset($photo);
        $recipe['actions'] = $this->rows('SELECT * FROM recipe_step_actions WHERE household_id=? AND recipe_id=? ORDER BY step_id,position,id', [$householdId, $recipeId]);
        $recipe['tags'] = $this->rows('SELECT kind,label FROM recipe_tags WHERE household_id=? AND recipe_id=? ORDER BY kind,label', [$householdId, $recipeId]);
        $nutrition = $this->rows('SELECT * FROM recipe_nutrition WHERE household_id=? AND recipe_id=?', [$householdId, $recipeId]);
        $recipe['nutrition'] = $nutrition[0] ?? null;
        if ($recipe['nutrition'] !== null) $recipe['nutrition']['stale'] = $recipe['nutrition']['recipe_revision'] !== $recipe['revision'];
        $parent = $recipe['parent_recipe_id'];
        $recipe['related'] = $this->rows('SELECT id,title,parent_recipe_id FROM recipes WHERE deleted_at IS NULL AND id<>? AND (parent_recipe_id=? OR id=? OR (? IS NOT NULL AND parent_recipe_id=?)) ORDER BY title,id', [$recipeId, $recipeId, $parent, $parent, $parent]);
        foreach ($recipe['related'] as &$related) {
            $related['is_variation'] = $related['parent_recipe_id'] !== null;
            $related['relationship'] = $related['id'] === $parent ? 'parent' : ($related['parent_recipe_id'] === $recipeId ? 'child' : 'sibling');
        } unset($related);
        $recipe['pairings'] = $this->rows('SELECT r.id,r.title,p.notes FROM recipe_pairings p JOIN recipes r ON r.deleted_at IS NULL AND r.id=CASE WHEN p.recipe_id=? THEN p.paired_recipe_id ELSE p.recipe_id END WHERE (p.recipe_id=? OR p.paired_recipe_id=?) ORDER BY r.title,r.id', [$recipeId, $recipeId, $recipeId]);
        $recipe['variation_family']=[];
        foreach ($this->variationFamilies($viewHousehold, true) as $members) {
            if (in_array($recipeId,array_column($members,'id'),true)) {
                $recipe['variation_family']=$members;
                $recipe['family_representative_id']=$members[0]['id'];
                $recipe['variation_base_title']=$members[0]['variation_base_title'];
                break;
            }
        }
        $recipe['category_assignments'] = $this->rows('SELECT category_id FROM recipe_category_assignments WHERE household_id=? AND recipe_id=? ORDER BY category_id', [$householdId, $recipeId]);
        $recipe['edit_options'] = $this->editOptions($userId, $viewHousehold, $recipeId, true);
        return $recipe;
    }

    public function editOptions(string $userId, string $householdId, string $recipeId = '0', bool $publicRead = false): array {
        if (!$publicRead) $this->households->membership($userId, $householdId);
        return [
            'ai_images_enabled' => !$publicRead && $this->households->aiImagesEnabled($userId, $householdId),
            'categories' => $this->rows('SELECT c.id,c.label,p.label AS parent_label FROM recipe_categories c LEFT JOIN recipe_categories p ON p.id=c.parent_id ORDER BY COALESCE(p.position,c.position),c.position,c.label', []),
            'recipes' => $this->rows('SELECT id,title FROM recipes WHERE deleted_at IS NULL AND id<>? AND (EXISTS (SELECT 1 FROM recipe_households h WHERE h.recipe_id=recipes.id AND h.household_id=? AND h.removed_at IS NULL) OR id=(SELECT parent_recipe_id FROM recipes WHERE id=?) OR id IN (SELECT CASE WHEN recipe_id=? THEN paired_recipe_id ELSE recipe_id END FROM recipe_pairings WHERE recipe_id=? OR paired_recipe_id=?)) ORDER BY title,id', [$recipeId,$householdId,$recipeId,$recipeId,$recipeId,$recipeId]),
        ];
    }
}
