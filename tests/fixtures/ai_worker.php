<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/bootstrap.php';
final class AiFixturePDO extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        return parent::prepare(str_replace([' FOR UPDATE',' <=> '],['',' IS '],$query),$options);
    }
}
$file=getenv('CTR_TEST_DATABASE');
if (!$file || !str_starts_with(basename($file),'ctr-wire-')) throw new RuntimeException('Disposable test database required.');
$pdo=new AiFixturePDO('sqlite:'.$file);
$verify=static function(string $token): array {
    if (!in_array($token,['alice','bob','disabled'],true)) throw new HttpError(401,'invalid_token','Invalid token.');
    return ['uid'=>$token];
};
$config=['api_key'=>'fixture-only','model'=>'gpt-5.4-mini','variation_access'=>'all_members'];
$transport=static function(array $payload): array {
    // Deliberately slower than the wire test's ping deadline: proves provider work cannot block sockets.
    usleep(1200000);
    if (($payload['tools'][0]['type']??'')==='image_generation') return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['type'=>'image_generation_call','status'=>'completed','result'=>trim(file_get_contents(__DIR__.'/photo.base64'))]]])];
    if (($payload['text']['format']['name']??'')==='blank_recipe_category') return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode(['label'=>'Soups','parent_label'=>'Meals'])]]]]])];
    return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode([
        'recipe'=>['title'=>'Soup','ingredients'=>[['original_text'=>'Water']],'steps'=>[['instruction'=>'Simmer']]],'questions'=>['How much water?'],
    ])]]]]])];
};
require dirname(__DIR__,2).'/ai-worker.php';
