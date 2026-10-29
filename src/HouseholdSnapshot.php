<?php
declare(strict_types=1);

/** A bounded number of SQL reads, never an HTTP/SQL request per recipe. */
final class HouseholdSnapshot {
    public function __construct(private PDO $pdo, private HouseholdService $households, private string $mediaUrl) {}
    private function rows(string $sql,array $args=[]): array {
        $q=$this->pdo->prepare($sql); $q->execute($args);
        return array_map(static function($row) {
            foreach ($row as $key=>$value) if ($value!==null && ($key==='id' || str_ends_with($key,'_id') || $key==='revision' || str_ends_with($key,'_revision'))) $row[$key]=(string)$value;
            return $row;
        },$q->fetchAll(PDO::FETCH_ASSOC));
    }
    public function read(string $user,string $household): array {
        $this->households->membership($user,$household);
        $this->pdo->beginTransaction();
        try {
            $all=$this->rows('SELECT id,title,parent_recipe_id,deleted_at FROM recipes ORDER BY id');
            $byId=array_column($all,null,'id');
            $recipes=$this->rows('SELECT r.*,(SELECT COUNT(*) FROM recipe_households x WHERE x.recipe_id=r.id AND x.removed_at IS NULL) AS household_count FROM recipes r JOIN recipe_households h ON h.recipe_id=r.id WHERE h.household_id=? AND h.removed_at IS NULL AND r.deleted_at IS NULL ORDER BY r.title,r.id',[$household]);
            $owned=array_fill_keys(array_column($recipes,'id'),true);
            $children=[];
            foreach (['ingredients'=>'recipe_ingredients','groups'=>'recipe_ingredient_groups','steps'=>'recipe_steps','photos'=>'recipe_photos','actions'=>'recipe_step_actions','tags'=>'recipe_tags','nutrition'=>'recipe_nutrition','category_assignments'=>'recipe_category_assignments'] as $key=>$table) {
                $order=in_array($key,['tags','nutrition','category_assignments'],true) ? 'c.recipe_id' : 'c.recipe_id,c.position,c.id';
                $children[$key]=[];
                foreach ($this->rows("SELECT c.* FROM $table c JOIN recipes r ON r.id=c.recipe_id AND r.household_id=c.household_id JOIN recipe_households h ON h.recipe_id=r.id WHERE h.household_id=? AND h.removed_at IS NULL AND r.deleted_at IS NULL ORDER BY $order",[$household]) as $row) $children[$key][$row['recipe_id']][]=$row;
            }
            $categories=$this->rows('SELECT * FROM recipe_categories ORDER BY position,label,id');
            foreach ($categories as &$category) $category['image_url']=str_starts_with($category['image_key'] ?? '', 'ai-') ? rtrim($this->mediaUrl,'/').'/category-'.$category['id'].'.jpg' : null;
            unset($category);
            $categoryMap=array_column($categories,null,'id');
            $families=[];
            foreach ($all as $row) {
                if ($row['deleted_at']!==null) continue;
                $root=$row['id']; $seen=[];
                while (isset($byId[$root]) && $byId[$root]['parent_recipe_id']!==null && isset($byId[$byId[$root]['parent_recipe_id']]) && !isset($seen[$root])) {
                    $seen[$root]=true; $root=$byId[$root]['parent_recipe_id'];
                }
                unset($row['deleted_at']);
                $row['in_household']=isset($owned[$row['id']]);
                $row['is_variation']=$row['parent_recipe_id']!==null;
                $row['variation_base_title']=$byId[$root]['title'];
                $families[$root][]=$row;
            }
            $familyById=[];
            foreach ($families as $root=>$members) {
                usort($members,static fn($a,$b)=>(($a['id']==$root?0:1)<=>($b['id']==$root?0:1)) ?: (strlen($a['id'])<=>strlen($b['id']) ?: strcmp($a['id'],$b['id'])));
                foreach ($members as $member) $familyById[$member['id']]=$members;
            }
            $pairs=$this->rows('SELECT recipe_id,paired_recipe_id,notes FROM recipe_pairings');
            $people=$this->households->people($user,$household);
            $favorites=$this->rows('SELECT recipe_id,person_id FROM recipe_favorites WHERE household_id=?',[$household]);
            $favoriteMap=[];
            foreach ($favorites as $favorite) $favoriteMap[$favorite['recipe_id']][$favorite['person_id']]=true;
            $current=null;
            foreach ($people as $person) if (($person['user_id'] ?? null)===$user) $current=$person['id'];
            $options=['ai_images_enabled'=>$this->households->aiImagesEnabled($user,$household),'categories'=>array_map(static fn($c)=>['id'=>$c['id'],'label'=>$c['label'],'parent_label'=>$categoryMap[$c['parent_id']]['label'] ?? null],$categories),'recipes'=>array_map(static fn($r)=>['id'=>$r['id'],'title'=>$r['title']],$recipes)];
            $responses=[]; $rootPath="/households/$household";
            foreach ($recipes as &$recipe) {
                $id=$recipe['id'];
                foreach (['created_by_user_id','updated_by_user_id','deleted_by_user_id','trash_household_id','source_text','import_key'] as $key) unset($recipe[$key]);
                $recipe['in_household']=true; $recipe['household_count']=(int)$recipe['household_count'];
                $recipe['is_variation']=$recipe['parent_recipe_id']!==null;
                foreach ($children as $key=>$grouped) $recipe[$key]=$grouped[$id] ?? [];
                $recipe['nutrition']=$recipe['nutrition'][0] ?? null;
                if ($recipe['nutrition']!==null) $recipe['nutrition']['stale']=$recipe['nutrition']['recipe_revision']!==$recipe['revision'];
                foreach ($recipe['photos'] as &$photo) if (preg_match('#^media/recipes/([a-f0-9]{48}\\.(?:jpg|png|webp))$#',$photo['storage_key'] ?? '',$m)) $photo['source_url']=rtrim($this->mediaUrl,'/').'/'.$m[1];
                unset($photo);
                $recipe['photo_url']=null;
                foreach ($recipe['photos'] as $photo) {
                    if ($recipe['photo_url']===null || ($photo['is_cover'] ?? false)) $recipe['photo_url']=$photo['source_url'];
                    if ($photo['is_cover'] ?? false) break;
                }
                $family=$familyById[$id] ?? [];
                $localFamily=array_values(array_filter($family,static fn($m)=>$m['in_household']));
                $recipe['variation_family']=$family;
                $recipe['family_representative_id']=$localFamily[0]['id'] ?? $id;
                $recipe['variation_base_title']=$family[0]['variation_base_title'] ?? $recipe['title'];
                $recipe['related']=[];
                foreach ($family as $member) if ($member['id']!==$id && ($member['id']===$recipe['parent_recipe_id'] || $member['parent_recipe_id']===$id || ($recipe['parent_recipe_id']!==null && $member['parent_recipe_id']===$recipe['parent_recipe_id']))) {
                    $member['relationship']=$member['id']===$recipe['parent_recipe_id']?'parent':($member['parent_recipe_id']===$id?'child':'sibling'); $recipe['related'][]=$member;
                }
                $recipe['pairings']=[];
                foreach ($pairs as $pair) {
                    $other=$pair['recipe_id']===$id?$pair['paired_recipe_id']:($pair['paired_recipe_id']===$id?$pair['recipe_id']:null);
                    if ($other!==null && isset($byId[$other]) && $byId[$other]['deleted_at']===null) $recipe['pairings'][]=['id'=>$other,'title'=>$byId[$other]['title'],'notes'=>$pair['notes'],'in_household'=>isset($owned[$other])];
                }
                $recipe['edit_options']=['ai_images_enabled'=>$options['ai_images_enabled']];
                $responses["$rootPath/recipes/$id"]=['recipe'=>$recipe];
                $members=[];
                foreach ($people as $person) $members[]=['id'=>$person['id'],'display_name'=>$person['display_name'],'user_id'=>$person['user_id'],'favorite'=>isset($favoriteMap[$id][$person['id']])];
                $responses["$rootPath/recipes/$id/favorites"]=['people'=>$members,'current_person_id'=>$current];
            } unset($recipe);
            $responses["$rootPath/restaurants"]=(new RestaurantService($this->pdo,$this->households))->read($user,$household);
            $responses["$rootPath/people"]=['people'=>$people];
            if (class_exists('MealPlanService')) {
                $plans=new MealPlanService($this->pdo,$this->households,$this->mediaUrl);
                $responses["$rootPath/meal-plan"]=$plans->read($user,$household,'2000-01-01','2199-12-31');
                $responses["$rootPath/meal-plan/history"]=$plans->history($user,$household,'2000-01-01','2199-12-31');
            }
            $blankOptions=$options; $blankOptions['favorites']=['people'=>array_map(static fn($p)=>['id'=>$p['id'],'display_name'=>$p['display_name'],'user_id'=>$p['user_id'],'favorite'=>false],$people),'current_person_id'=>$current];
            $responses["$rootPath/recipe-blank"]=['recipe'=>['title'=>'','source_type'=>'blank','original_language'=>'en','measurement_system'=>'unspecified','edit_options'=>$blankOptions]];
            $result=['schema'=>1,'household_id'=>$household,'recipes'=>array_map(static fn($r)=>['id'=>$r['id'],'title'=>$r['title'],'description'=>$r['description'],'parent_recipe_id'=>$r['parent_recipe_id'],'family_representative_id'=>$r['family_representative_id'],'is_variation'=>$r['is_variation'],'photo_url'=>$r['photos'][0]['source_url'] ?? null],$recipes),'categories'=>$categories,'responses'=>$responses,'edit_options'=>$options];
            $result['version']=hash('sha256',json_encode($result,JSON_THROW_ON_ERROR));
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }
}
