<?php
declare(strict_types=1);

/** Creates cached review drafts only; the ordinary editor is the sole save path. */
final class RecipeVariationService
{
    public function __construct(private array $config, private string $storage, private $transport = null, private $coverReader = null) {}

    /** Bounded private diagnostics: no prompts, recipe content or account IDs. */
    private function event(string $request, string $event, float $started, ?string $code = null): void {
        $line = json_encode(['at'=>gmdate('c'),'request'=>$request,'event'=>$event,
            'code'=>$code,'elapsed_ms'=>(int)((microtime(true)-$started)*1000)],JSON_UNESCAPED_SLASHES)."\n";
        $log = @fopen($this->storage.'/variation-events.log','c+');
        if ($log === false) return;
        try {
            if (!flock($log,LOCK_EX | LOCK_NB)) return;
            $size = fstat($log)['size'];
            if ($size > 1048576) { rewind($log); ftruncate($log,0); }
            else fseek($log,0,SEEK_END);
            fwrite($log,$line);
            chmod($this->storage.'/variation-events.log',0600);
        } finally { flock($log,LOCK_UN); fclose($log); }
    }

    /** Read only the authorized recipe's locally hosted cover, never arbitrary URLs. */
    private function parentCover(array $source): ?array {
        $photos=$source['photos'] ?? [];
        $cover=null;
        foreach ($photos as $photo) if (!empty($photo['is_cover'])) { $cover=$photo; break; }
        $cover ??= $photos[0] ?? null;
        if (!is_array($cover)) return null;
        $key=$cover['storage_key'] ?? '';
        if (!is_string($key) || !preg_match('#^(?:media/(?:recipes/[a-f0-9]{48}|recipe-photos/[a-f0-9]{64})|vps/recipes/[a-f0-9]{48})\.(?:jpg|png|webp)$#D',$key)) return null;
        if ($this->coverReader!==null) {
            $bytes=($this->coverReader)($key);
        } else {
            if (str_starts_with($key,'vps/recipes/')) {
                $path=$this->storage.'/media/recipes/'.basename($key);
                $bytes=is_readable($path) ? @file_get_contents($path,false,null,0,5*1024*1024+1) : false;
            } else {
                // Existing photos remain on shared hosting; never fetch a client-supplied URL.
                try { $bytes=RecipeImportTools::verifyImage('https://coffeybean.com/CoffeyTableRecipes/'.$key,true)['bytes']; }
                catch (Throwable $error) { return null; }
            }
        }
        if (!is_string($bytes) || strlen($bytes)>5*1024*1024) return null;
        $info=@getimagesizefromstring($bytes);
        if (!$info || !in_array($info['mime'],['image/jpeg','image/png','image/webp'],true) || $info[0]*$info[1]>24000000) return null;
        return ['id'=>'new-parent-cover','image_base64'=>base64_encode($bytes),'is_cover'=>1,
            'caption'=>is_string($cover['caption'] ?? null) ? $cover['caption'] : null,
            'attribution'=>is_string($cover['attribution'] ?? null) ? $cover['attribution'] : null,
            '_mime'=>$info['mime']];
    }

    public static function accessPolicy(array $config): string {
        $policy = $config['variation_access'] ?? 'all_members';
        if (!in_array($policy, ['disabled', 'ai_images', 'all_members'], true)) $policy = 'disabled';
        if ($policy === 'disabled') throw new HttpError(503, 'variation_unavailable', 'Recipe variations are not enabled yet.');
        // Household membership is enforced by the endpoint, independently of billing.
        return $policy;
    }

    public function analyze(array $source, array $body, string $userId, string $householdId, array $categories = [], bool $agreedRecipe = false): array {
        self::accessPolicy($this->config);
        $changes = $body['changes'] ?? null;
        $requestId = $body['request_id'] ?? null;
        $answers = $body['answers'] ?? [];
        if (!is_string($changes) || trim($changes) === '' || mb_strlen($changes) > ($agreedRecipe ? 40000 : 4000)
            || !is_string($requestId) || !preg_match('/^[a-f0-9]{32}$/D', $requestId)
            || !is_array($answers) || array_values($answers) !== $answers || count($answers) > 24) {
            throw new HttpError(422, 'invalid_variation', 'Describe the changes in at most 4,000 characters.');
        }
        foreach ($answers as $answer) {
            if (!is_array($answer) || !is_string($answer['question'] ?? null) || !is_string($answer['answer'] ?? null)
                || mb_strlen($answer['question']) > 1000 || mb_strlen($answer['answer']) > 2000) {
                throw new HttpError(422, 'invalid_variation', 'Use shorter clarification answers.');
            }
        }
        $parent = (string)$source['id'];
        $identity = hash('sha256', json_encode([$userId,$householdId,$parent,$requestId], JSON_THROW_ON_ERROR));
        // Only cooking content reaches the provider: no household/member IDs, media or related recipes.
        $original = array_intersect_key($source, array_flip(['title','description','original_language','measurement_system','yield_text','servings','prep_minutes','cook_minutes','total_minutes','notes']));
        foreach (['ingredients'=>['original_text','ingredient_name','quantity_text','quantity_decimal','quantity_numerator','quantity_denominator','quantity_max_decimal','original_unit','unit_code','preparation','is_optional','is_main_ingredient','scale_quantity'], 'steps'=>['instruction','section_title']] as $list=>$fields) {
            $original[$list] = array_map(static fn(array $row): array => array_intersect_key($row,array_flip($fields)), $source[$list] ?? []);
        }
        // Preserve cooking meaning carried only in group labels or structured
        // actions while keeping database identifiers out of provider input.
        $groupTitles = [];
        foreach ($source['groups'] ?? [] as $group) $groupTitles[(string)$group['id']] = $group['title'];
        foreach ($source['ingredients'] ?? [] as $index=>$ingredient) {
            $title = $groupTitles[(string)($ingredient['group_id'] ?? '')] ?? null;
            if ($title !== null) $original['ingredients'][$index]['group_title'] = $title;
        }
        foreach ($source['steps'] ?? [] as $index=>$step) {
            $original['steps'][$index]['actions'] = [];
            foreach ($source['actions'] ?? [] as $action) {
                if ((string)($action['step_id'] ?? '') !== (string)$step['id']) continue;
                $original['steps'][$index]['actions'][] = array_intersect_key($action,array_flip([
                    'action_type','label','duration_seconds','temperature_value','temperature_unit','appliance',
                ]));
            }
        }
        $baseTitle=(string)($source['variation_base_title'] ?? $source['title']);
        $input = ['main_recipe_title'=>$baseTitle,'original_recipe'=>$original,'requested_changes'=>trim($changes),'clarification_answers'=>$answers];
        $text = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($text)>60000) throw new HttpError(422,'invalid_variation','This recipe is too long to vary automatically.');
        $cover=$this->parentCover($source);
        $coverIdentity=$cover===null ? null : [hash('sha256',$cover['image_base64']),$cover['caption'],$cover['attribution']];
        $fingerprint = hash('sha256', json_encode([($agreedRecipe ? 'variation-agreed-recipe-v1' : 'variation-main-title-label-v6'),$text,$this->config['variation_model'] ?? $this->config['model'],$categories,$coverIdentity], JSON_THROW_ON_ERROR));
        $directory = $this->storage.'/variation-drafts';
        if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new RuntimeException('Cannot prepare variation draft storage.');
        $path = $directory.'/'.$identity.'.json';
        $file = fopen($path, 'c+');
        if ($file === false) throw new RuntimeException('Cannot open variation draft storage.');
        $started = microtime(true);
        $trace = substr($identity,0,16);
        try {
            // A lost HTTP response may be retried while its original worker is
            // still preparing the draft. Never queue another blocking worker.
            if (!flock($file,LOCK_EX | LOCK_NB)) {
                $this->event($trace,'busy',$started);
                throw new HttpError(503,'variation_in_progress','Your variation is still being prepared. Please wait a moment and try again.');
            }
            $stored = json_decode(stream_get_contents($file),true) ?? [];
            $base = hash('sha256', trim($changes));
            if (isset($stored['changes']) && !hash_equals($stored['changes'],$base)) throw new HttpError(409,'variation_changed','Start a new variation after changing the request.');
            if (isset($stored['drafts'][$fingerprint])) {
                $this->event($trace,'recovered',$started);
                return $stored['drafts'][$fingerprint];
            }
            if (count($stored['drafts'] ?? []) >= 8) throw new HttpError(429,'variation_limit','This variation has reached its clarification limit. Start a new request.');
            $config = $this->config;
            $config['model'] = $config['variation_model'] ?? $config['model'];
            $config['variation_agreed_recipe'] = $agreedRecipe;
            $language=$source['original_language'] ?? null;
            if (is_string($language) && preg_match('/^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8})*$/D',$language)) {
                $config['variation_language']=$language;
            }
            $config['variation_original_title']=(string)$source['title'];
            $config['variation_base_title']=$baseTitle;
            if ($cover!==null) $config['variation_cover_image']='data:'.$cover['_mime'].';base64,'.$cover['image_base64'];
            elseif (!empty($source['photos'])) $config['variation_cover_unavailable']=true;
            $this->event($trace,'started',$started);
            $draft = (new RecipeImportService($config,$this->storage,$this->transport))->analyze(['kind'=>'text','text'=>$text],$userId,$categories,true);
            $draft['recipe']['parent_recipe_id'] = $parent;
            $draft['recipe']['is_variation'] = true;
            $draft['recipe']['source_type'] = 'ai_variation';
            $draft['recipe']['source_title'] = 'Variation of '.$source['title'];
            $draft['recipe']['source_text'] = 'Requested variation: '.trim($changes);
            $draft['recipe']['source_url'] = null;
            $draft['recipe']['import_key'] = $identity;
            $draft['recipe']['tags'] = [['kind'=>'category','label'=>'Variation']];
            $draft['recipe']['photos'] = [];
            if ($cover!==null && ($draft['parent_cover_review']['appropriate'] ?? false)===true) {
                unset($cover['_mime']);
                $draft['recipe']['photos']=[$cover];
            }
            $draft['recipe']['nutrition'] = null;
            $draft['preprocessing'] = 'AI recipe variation';
            $stored['changes'] = $base;
            $stored['drafts'][$fingerprint] = $draft;
            $encoded = json_encode($stored,JSON_THROW_ON_ERROR);
            rewind($file); ftruncate($file,0);
            if (fwrite($file,$encoded) !== strlen($encoded)) throw new RuntimeException('Cannot save variation draft.');
            fflush($file); chmod($path,0600);
            $this->event($trace,'completed',$started);
            return $draft;
        } catch (Throwable $error) {
            $code = $error instanceof HttpError ? $error->errorCode : 'internal_error';
            $this->event($trace,'failed',$started,$code);
            throw $error;
        } finally { flock($file,LOCK_UN); fclose($file); }
    }
}
