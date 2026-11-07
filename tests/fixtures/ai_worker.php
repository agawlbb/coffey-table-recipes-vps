<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/bootstrap.php';
final class AiFixturePDO extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        return parent::prepare(str_replace([' FOR UPDATE',' <=> ','INSERT IGNORE'],['',' IS ','INSERT OR IGNORE'],$query),$options);
    }
}
$file=getenv('CTR_TEST_DATABASE');
if (!$file || !str_starts_with(basename($file),'ctr-wire-')) throw new RuntimeException('Disposable test database required.');
$pdo=new AiFixturePDO('sqlite:'.$file);
require __DIR__.'/context_database.php';
contextTestLocks($pdo,(getenv('CTR_STORAGE') ?: sys_get_temp_dir()).'/test-sql-locks');
$verify=static function(string $token): array {
    if (!in_array($token,['alice','bob','disabled'],true)) throw new HttpError(401,'invalid_token','Invalid token.');
    return ['uid'=>$token];
};
$config=['api_key'=>'fixture-only','model'=>'gpt-5.4-mini','variation_access'=>'all_members'];
$transport=static function(array $payload): array {
    // Deliberately slower than the wire test's ping deadline: proves provider work cannot block sockets.
    usleep(1200000);
    if (is_string($payload['input']??null) && str_contains($payload['input'],'"category":"Failure"')) return ['status'=>503,'body'=>'{}'];
    if (($payload['text']['format']['name']??'')==='cooking_plan') {
        $input=json_decode($payload['input'][0]['content'],true);
        $timeline=[];
        foreach ($input['recipe']['steps'] as $step) $timeline[]=['step_id'=>(string)$step['id'],'offset_seconds'=>($input['mode']==='feedback'?-7200:-3600)];
        $updates=($input['message']??'')==='I chop slowly.' ? [['action'=>'add','id'=>null,'person'=>'self','preference'=>'Chops slowly.','evidence'=>'I chop slowly.']] : [];
        return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode([
            'message'=>'Allow time to prepare dinner.','timeline'=>$timeline,'context_updates'=>$updates,
        ])]]]]])];
    }
    if (($payload['text']['format']['name']??'')==='recipe_chat') {
        $input=json_decode($payload['input'][0]['content'],true);$context=$input['cooking_context'];
        $latest=$payload['input'][count($payload['input'])-1]['content'];
        $updates=[];
        if ($latest==='I dislike mushrooms.') $updates[]=['action'=>'add','id'=>null,'person'=>'self','preference'=>'Dislikes mushrooms.','evidence'=>$latest];
        if ($latest==='My son Alex dislikes cilantro.') $updates[]=['action'=>'add','id'=>null,'person'=>'my son Alex','preference'=>'Dislikes cilantro.','evidence'=>$latest];
        if (in_array($latest,['I like mushrooms now.','Forget my mushroom preference.'],true)) {
            foreach ($context as $fact) if ($fact['person']==='self' && str_contains($fact['preference'],'mushrooms')) {
                $remove=str_starts_with($latest,'Forget');
                $updates[]=['action'=>$remove?'remove':'update','id'=>$fact['id'],'person'=>null,'preference'=>$remove?null:'Likes mushrooms.','evidence'=>$latest];
            }
        }
        return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode([
            'message'=>'Remembered: '.implode(' | ',array_map(static fn($fact)=>$fact['person'].': '.$fact['preference'],$context)),
            'show_recipe'=>$latest==='Show the recipe again.','ready'=>true,'context_updates'=>$updates,
            'recipe'=>['title'=>'Agreed soup','ingredients'=>[['original_text'=>'2 cups water','ingredient_name'=>'water'],['original_text'=>'1 teaspoon salt','ingredient_name'=>'salt']],
                'steps'=>[['instruction'=>'Bring to a boil and simmer for 2 minutes.','actions'=>[['action_type'=>'timer','duration_seconds'=>120]]]]],
        ])]]]]])];
    }
    if (str_contains(json_encode($payload),'Agreed soup')) return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode([
        'recipe'=>['title'=>'Agreed soup','ingredients'=>[['original_text'=>'2 cups water','ingredient_name'=>'water']],'steps'=>[['instruction'=>'Simmer for 2 minutes.']]],'questions'=>[], 'variation_label'=>'refined','change_description'=>'Refined the soup as agreed.',
    ])]]]]])];
    if (($payload['tools'][0]['type']??'')==='image_generation') return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['type'=>'image_generation_call','status'=>'completed','result'=>trim(file_get_contents(__DIR__.'/photo.base64'))]]])];
    if (($payload['text']['format']['name']??'')==='blank_recipe_category') return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode(['label'=>'Soups','parent_label'=>'Meals'])]]]]])];
    return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode([
        'recipe'=>['title'=>'Soup','ingredients'=>[['original_text'=>'Water']],'steps'=>[['instruction'=>'Simmer']]],'questions'=>['How much water?'],
    ])]]]]])];
};
require dirname(__DIR__,2).'/ai-worker.php';
