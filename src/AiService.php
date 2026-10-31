<?php
declare(strict_types=1);

final class AiService {
    public function __construct(private PDO $pdo,private array $config,private string $storage,private $progress,private $transport=null) {}

    public static function matches(string $path): bool {
        $route=parse_url($path,PHP_URL_PATH);
        return is_string($route) && (preg_match('#^/households/[1-9][0-9]*/(?:recipe-import|recipe-photo|recipe-category|category-image|recipes/[1-9][0-9]*/variation)$#D',$route)
            || preg_match('#^/admin/parent-categories(?:/[1-9][0-9]*/category-image)?$#D',$route));
    }

    public static function categorizesSave(string $path,?array $body): bool {
        return $body!==null && ($body['source_type']??null)==='blank' && ($body['category_assignments']??null)===[]
            && (bool)preg_match('#^/households/[1-9][0-9]*/recipes(?:/[1-9][0-9]*)?$#D',$path);
    }

    public function handle(array $actor,string $path,?array $body): array {
        $user=$actor['user']; $h=new HouseholdService($this->pdo); $recipes=new RecipeService($this->pdo,$h);
        $url='https://ctr.coffeybean.com/media/recipes';
        $config=$this->config; $config['progress']=$this->progress;
        $photos=new RecipePhotoService($config,$this->storage,$this->transport);
        $images=new CategoryImageService($this->pdo,$photos,$this->storage.'/media/recipes',$url);
        if (str_starts_with($path,'/admin/')) {
            $admin=new AdministrationService($this->pdo,static function(){},null,$images,$url);
            $admin->requireAdministrator($user);
            if ($path==='/admin/parent-categories') return $admin->parentCategories($user,$body);
            if ($body!==null && preg_match('#^/admin/parent-categories/([1-9][0-9]*)/category-image$#D',$path,$m)) return ['image_url'=>$images->ensureParent($m[1],$user)];
            throw new HttpError(405,'method_not_allowed','Use a write request.');
        }
        if (!preg_match('#^/households/([1-9][0-9]*)/(.+)$#D',$path,$m) || $m[1]!==$actor['household']) throw new HttpError(404,'not_found','Household endpoint not found.');
        $household=$m[1]; $part=$m[2]; $h->membership($user,$household);
        if ($body===null) throw new HttpError(405,'method_not_allowed','Use a write request.');
        ($this->progress)('Preparing the request…');
        if ($part==='recipe-photo') {
            $h->requireAiImages($user,$household);
            $recipe=$body['recipe']??null;
            if (!is_array($recipe)) throw new HttpError(422,'invalid_recipe','Choose a recipe.');
            if (isset($recipe['id'])) {
                if (!is_string($recipe['id']) || !preg_match('/^[1-9][0-9]{0,18}$/D',$recipe['id'])) throw new HttpError(422,'invalid_recipe','Invalid recipe.');
                if ($recipes->detail($user,$household,$recipe['id'])['photos']!==[]) throw new HttpError(409,'photo_exists','This recipe already has photos.');
            }
            return $photos->generate($recipe,$user,$household);
        }
        if ($part==='category-image') {
            $id=$body['category_id']??null;
            if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,18}$/D',$id)) throw new HttpError(422,'invalid_category','Choose a category.');
            return ['image_url'=>$images->ensure($id,$user,$household)];
        }
        $options=$recipes->editOptions($user,$household);
        if ($part==='recipe-category') {
            if (!is_string($body['title']??null)) throw new HttpError(422,'invalid_recipe','Enter a recipe title.');
            return ['category'=>(new BlankRecipeCategoryService($config,$this->storage,$this->transport))->categorize($body['title'], $options['categories'],$user)];
        }
        if ($part==='recipe-import') {
            $draft=(new RecipeImportService($config,$this->storage,$this->transport))->analyze($body,$user,$options['categories']);
        } elseif (preg_match('#^recipes/([1-9][0-9]*)/variation$#D',$part,$variation)) {
            $policy=RecipeVariationService::accessPolicy($config);
            if ($policy==='ai_images') $h->requireAiImages($user,$household);
            $source=$recipes->detail($user,$household,$variation[1]);
            $draft=(new RecipeVariationService($config,$this->storage,$this->transport))->analyze($source,$body,$user,$household,$options['categories']);
        } else throw new HttpError(404,'not_found','AI endpoint not found.');
        $draft['recipe']['edit_options']=$options;
        foreach ($draft['related_imports']??[] as $index=>$related) $draft['related_imports'][$index]['recipe']['edit_options']=$options;
        ($this->progress)('Recipe draft ready.');
        return $draft;
    }
}
