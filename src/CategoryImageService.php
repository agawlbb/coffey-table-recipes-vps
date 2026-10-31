<?php
declare(strict_types=1);

/** Shared category artwork generated once, independently of recipe persistence. */
final class CategoryImageService {
    public static function imageUrl(string $id,string $fallback): string {
        $base='https://ctr.coffeybean.com/media/recipes';
        return $base.'/category-'.$id.'.jpg';
    }
    public function __construct(private PDO $pdo,private RecipePhotoService $photos,private string $directory,private string $url) {}
    public function ensure(string $id,string $userId,string $householdId): string {
        (new HouseholdService($this->pdo))->membership($userId, $householdId);
        $q=$this->pdo->prepare("SELECT c.label,c.slug,c.image_key,p.label AS parent_label FROM recipe_categories c JOIN recipe_categories p ON p.id=c.parent_id WHERE c.id=? AND EXISTS (SELECT 1 FROM recipe_category_assignments a WHERE a.category_id=c.id AND EXISTS (SELECT 1 FROM recipe_households h JOIN recipes r ON r.id=h.recipe_id AND r.deleted_at IS NULL WHERE h.recipe_id=a.recipe_id AND h.household_id=? AND h.removed_at IS NULL))");
        $q->execute([$id,$householdId]); $category=$q->fetch(PDO::FETCH_ASSOC);
        if (!$category || !str_starts_with($category['slug'],'import-')) throw new HttpError(404,'category_not_found','New category not found.');
        // An existing category owns its image even when it is still hosted on InMotion.
        if ($category['image_key']==='ai-'.$id) return self::imageUrl($id,'https://ctr.coffeybean.com/media/recipes');
        return $this->publish($id,$category,$userId,fn()=>(new HouseholdService($this->pdo))->requireAiImages($userId,$householdId));
    }

    public function ensureParent(string $id,string $actorId): string {
        (new AdministrationService($this->pdo,static function(){}))->requireAdministrator($actorId);
        if (!preg_match('/^[1-9][0-9]{0,18}$/',$id)) throw new HttpError(422,'invalid_category','Choose a parent category.');
        $q=$this->pdo->prepare('SELECT label,slug,image_key FROM recipe_categories WHERE id=? AND parent_id IS NULL');
        $q->execute([$id]); $category=$q->fetch(PDO::FETCH_ASSOC);
        if (!$category) throw new HttpError(404,'category_not_found','Parent category not found.');
        if ($category['image_key']!=='' && !str_starts_with($category['image_key'],'ai-')) throw new HttpError(409,'photo_exists','This category already has a cover.');
        $category['parent_label']='';
        return $this->publish($id,$category,$actorId,static function(){});
    }

    private function publish(string $id,array $category,string $userId,callable $authorizeGeneration): string {
        if (!is_dir($this->directory) && !mkdir($this->directory,0755,true)) throw new RuntimeException('Cannot create category image directory.');
        $filename='category-'.$id.'.jpg'; $path=$this->directory.'/'.$filename;
        $lock=fopen($path.'.lock','c+');
        if (!$lock) throw new RuntimeException('Cannot lock category image.');
        if (!flock($lock,LOCK_EX|LOCK_NB)) { fclose($lock); throw new HttpError(409,'photo_pending','Category image is being generated.'); }
        try {
            if (!is_file($path)) {
                $authorizeGeneration();
                $photo=$this->photos->generateCategory($category['label'],$category['parent_label'],$userId)['photo'];
                $bytes=base64_decode($photo['image_base64'],true);
                $temporary=$path.'.tmp';
                if (file_put_contents($temporary,$bytes,LOCK_EX)!==strlen($bytes)) throw new RuntimeException('Cannot save category image.');
                chmod($temporary,0644);
                if (!rename($temporary,$path)) throw new RuntimeException('Cannot publish category image.');
            }
            $q=$this->pdo->prepare('UPDATE recipe_categories SET image_key=? WHERE id=?');
            $q->execute(['ai-'.$id,$id]);
            if ($q->rowCount()===0) {
                $exists=$this->pdo->prepare('SELECT id FROM recipe_categories WHERE id=?');
                $exists->execute([$id]);
                if (!$exists->fetchColumn()) throw new HttpError(404,'category_not_found','Category was removed while its cover was being generated.');
            }
            return rtrim($this->url,'/').'/'.$filename;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
}
