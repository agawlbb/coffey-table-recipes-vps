<?php
declare(strict_types=1);

/** Same authenticated transport and SQL services as shopping; no HTTP data proxy. */
final class DataSocket
{
    public function __construct(private PDO $pdo, private Closure $verify,
        private ShoppingSocket $shopping, private ?Closure $push = null,
        private ?Closure $categorize = null) {}

    public function authenticate(string $token, ?string $household, string $scope = 'shopping'): array
    {
        if ($scope === 'shopping' || $scope === 'household') {
            $actor = $this->shopping->authenticate($token, $household);
            if ($scope === 'household' && $actor['ha']) throw new HttpError(403,'permission_denied','Shopping access only.');
        } elseif ($scope === 'public') {
            $actor = ['household'=>null,'user'=>'','ha'=>false];
        } elseif ($scope === 'timers' || $scope === 'ai') {
            $identity = ($this->verify)($token);
            $q=$this->pdo->prepare('SELECT id,disabled FROM users WHERE firebase_uid=?'); $q->execute([$identity['uid']]);
            $user=$q->fetch(PDO::FETCH_ASSOC);
            if (!$user || $user['disabled']) throw new HttpError(403,'account_disabled','Account unavailable.');
            $actor=['household'=>null,'user'=>(string)$user['id'],'uid'=>$identity['uid'],'ha'=>false];
        } else throw new HttpError(422,'invalid_scope','Unknown connection scope.');
        if ($scope==='ai' && $household!==null) {
            (new HouseholdService($this->pdo))->membership($actor['user'],$household);
            $actor['household']=$household;
        }
        return $actor + ['scope'=>$scope];
    }

    public function snapshot(array $actor): array
    {
        return match ($actor['scope']) {
            'shopping'=>$this->shopping->snapshot($actor),
            'household'=>['shopping'=>$this->shopping->snapshot($actor),
                'library'=>(new HouseholdSnapshot($this->pdo,new HouseholdService($this->pdo),
                    'https://coffeybean.com/CoffeyTableRecipes/media/recipes'))->read($actor['user'],$actor['household'])],
            'timers'=>(new TimerSyncService($this->pdo))->list($actor['user']),
            default=>[],
        };
    }

    public function handle(string $token, ?string $household, string $path, ?array $body, string $scope='shopping'): array
    {
        $actor=$this->authenticate($token,$household,$scope);
        if ($scope==='shopping') return $this->shopping->handle($token,$household,$path,$body);
        $route=parse_url($path,PHP_URL_PATH); parse_str(parse_url($path,PHP_URL_QUERY) ?? '',$query);
        $user=$actor['user']; $h=new HouseholdService($this->pdo); $recipes=new RecipeService($this->pdo,$h);
        if ($scope==='public') {
            if ($body===null && preg_match('#^/recipes/([1-9][0-9]{0,18})$#D',$route,$m)) return ['recipe'=>$recipes->detail('','0',$m[1],true)];
            throw new HttpError(404,'not_found','Public recipe not found.');
        }
        if ($scope==='timers') {
            $timers=new TimerSyncService($this->pdo,$this->push);
            if ($route==='/me/timers') return $body===null ? $timers->list($user) : $timers->change($user,$actor['uid'],$body);
            if ($body!==null && $route==='/me/timer-devices') return $timers->device($user,$body);
            if ($body!==null && $route==='/me/timer-devices/remove') return $timers->unregister($user,$body);
            throw new HttpError(404,'not_found','Timer endpoint not found.');
        }
        $root='/households/'.$household;
        if (!str_starts_with($route,$root.'/')) throw new HttpError(404,'not_found','Household endpoint not found.');
        $part=substr($route,strlen($root));
        if (preg_match('#^/shopping-list(?:/sync-request)?$#D',$part)) return $this->shopping->handle($token,$household,$route,$body);
        if ($part==='/snapshot' && $body===null) {
            $snapshot=$this->snapshot($actor)['library'];
            return ($query['version']??'')===$snapshot['version'] ? ['schema'=>1,'household_id'=>$household,'version'=>$snapshot['version'],'unchanged'=>true] : $snapshot;
        }
        if ($part==='/restaurants') {
            $service=new RestaurantService($this->pdo,$h);
            return $body===null ? $service->read($user,$household) : $service->save($user,$household,$body);
        }
        if (preg_match('#^/meal-plan(?:/(ingredients|history|pairings/([1-9][0-9]{0,18})))?$#D',$part,$m)) {
            $service=new MealPlanService($this->pdo,$h); $sub=$m[1]??'';
            if ($body!==null && $sub==='') return $service->change($user,$household,$body);
            if ($body!==null) throw new HttpError(405,'method_not_allowed','Use a read request.');
            if (str_starts_with($sub,'pairings/')) return $service->pairings($user,$household,$m[2]);
            $from=MealPlanService::date($query['from']??null); $to=MealPlanService::date($query['to']??null);
            return match($sub) {
                'ingredients'=>$service->ingredients($user,$household,$from,$to),
                'history'=>$service->history($user,$household,$from,$to),
                default=>$service->read($user,$household,$from,$to),
            };
        }
        if ($body!==null && in_array($part,['/name','/people/me/name'],true)) {
            $key=$part==='/name'?'name':'display_name'; $name=$this->name($body[$key]??null);
            return $part==='/name' ? ['household'=>$h->rename($user,$household,$name)] : ['person'=>$h->renameOwnPerson($user,$household,$name)];
        }
        if ($part==='/people') {
            if ($body===null) return ['people'=>$h->people($user,$household)];
            $h->membership($user,$household,true);
            return ['person'=>$h->addPerson($user,$household,$this->name($body['display_name']??null))];
        }
        if ($part==='/recipe-blank') {
            $options=$recipes->editOptions($user,$household); $options['ai_images_enabled']=false;
            $options['favorites']=$recipes->newRecipeFavorites($user,$household);
            return ['recipe'=>['title'=>'','source_type'=>'blank','original_language'=>'en','measurement_system'=>'unspecified','edit_options'=>$options]];
        }
        if ($part==='/recipes/deleted' && $body===null) return (new RecipeDeletionService($this->pdo,$h))->recentlyDeleted($user,$household,$this->offset($query));
        if (preg_match('#^/recipes(?:/([1-9][0-9]{0,18})(?:/(edit-options|share|favorites|attach|delete|restore))?)?$#D',$part,$m)) {
            $id=$m[1]??''; $action=$m[2]??'';
            if ($action==='favorites') return $body===null ? $recipes->favorites($user,$household,$id) : $recipes->setFavorite($user,$household,$id,$body);
            if ($body===null && $action==='edit-options') {
                $recipe=$recipes->detail($user,$household,$id); $options=$recipe['edit_options'];
                $options['ai_images_enabled']=$h->aiImagesEnabled($user,$household);
                if ($recipe['source_type']==='blank') $options['favorites']=$recipes->favorites($user,$household,$id);
                return ['edit_options'=>$options];
            }
            if ($body===null && $action==='share') {
                $recipe=$recipes->detail($user,$household,$id);
                if ($recipe['source_type']==='blank') throw new HttpError(422,'blank_recipe','Import this recipe before sharing it.');
                return ['url'=>'https://coffeybean.com/CoffeyTableRecipes/share.php?id='.$id];
            }
            if ($body!==null && $action==='attach') return (new RecipeMembership($this->pdo,$h))->attach($user,$household,$id);
            if ($body!==null && $action==='delete') return (new RecipeDeletionService($this->pdo,$h))->delete($user,$household,$id,$body);
            if ($body!==null && $action==='restore') return (new RecipeDeletionService($this->pdo,$h))->restore($user,$household,$id);
            if ($action!=='') throw new HttpError(405,'method_not_allowed','Unsupported recipe request.');
            if ($body!==null) {
                $categorize=$this->categorize===null ? null : fn($title,$catalog,$user)=>($this->categorize)($token,$household,$title);
                return ['recipe'=>(new RecipeEditorService($this->pdo,$h,CTR_STORAGE.'/media/recipes',
                    'https://ctr.coffeybean.com/media/recipes',$categorize))->save($user,$household,$id,$body)];
            }
            if ($id!=='') return ['recipe'=>$recipes->detail($user,$household,$id)];
            foreach (['q','category','scope','favorite_people'] as $key) if (isset($query[$key]) && !is_string($query[$key])) throw new HttpError(400,'invalid_filter','Invalid recipe filter.');
            foreach (['tree','families','blank'] as $key) if (isset($query[$key]) && !in_array($query[$key],['0','1'],true)) throw new HttpError(400,'invalid_filter','Invalid recipe filter.');
            return $recipes->browse($user,$household,trim($query['q']??''),$this->offset($query),trim($query['category']??''),$query['scope']??'',($query['tree']??'0')==='1',($query['families']??'0')==='1',false,$query['favorite_people']??'',($query['blank']??'0')==='1');
        }
        throw new HttpError(404,'not_found','Data endpoint not found.');
    }

    /** Post-save artwork must not depend on an app receiving server category IDs. */
    public function missingCategoryImages(array $actor,array $data): array {
        if ($actor['scope']!=='household' || !isset($data['recipe']['id'])) return [];
        $ids=[];
        foreach ($data['recipe']['category_assignments']??[] as $row) {
            $id=(string)($row['category_id']??'');
            if (preg_match('/^[1-9][0-9]*$/D',$id)) $ids[]=$id;
        }
        if (!$ids || !(new HouseholdService($this->pdo))->aiImagesEnabled($actor['user'],$actor['household'])) return [];
        $q=$this->pdo->prepare('SELECT id,slug,image_key FROM recipe_categories WHERE parent_id IS NOT NULL AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')');
        $q->execute($ids); $missing=[];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $category) {
            if (str_starts_with($category['slug']??'','import-') && $category['image_key']!=='ai-'.$category['id']) $missing[]=(string)$category['id'];
        }
        return $missing;
    }

    private function name(mixed $name): string {
        if (!is_string($name)||trim($name)===''||mb_strlen($name)>200) throw new HttpError(422,'invalid_name','Please enter a name of 1 to 200 characters.');
        return trim($name);
    }

    private function offset(array $query): int {
        $offset=$query['offset']??'0';
        if (!is_string($offset)||!preg_match('/^[0-9]{1,6}$/D',$offset)) throw new HttpError(400,'invalid_filter','Invalid offset.');
        return (int)$offset;
    }
}
