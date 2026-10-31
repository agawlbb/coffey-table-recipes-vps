<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/HttpError.php';
require dirname(__DIR__).'/src/RecipeImportService.php';
$checks=0;
function descriptionExpect(bool $condition,string $message): void {
    global $checks;
    if(!$condition) throw new RuntimeException($message);
    $checks++;
}
$captured=[];
$reply=['recipe'=>['title'=>'Sopa de tomate','description'=>'Una sopa suave de tomate con un toque de albahaca.','original_language'=>'es','ingredients'=>[['original_text'=>'2 tomates','ingredient_name'=>'tomate']],'steps'=>[['instruction'=>'Cocer los tomates.']]],'questions'=>[]];
$service=new RecipeImportService(['api_key'=>'test-fixture','model'=>'gpt-5.4-mini'],sys_get_temp_dir(),
    static function(array $payload) use (&$captured,$reply): array {
        $captured[]=$payload;
        return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode($reply)]]]]])];
    });
foreach(['Sopa de tomate. Description: Cook the tomatoes and puree them. Ingredients: 2 tomates. Cocer los tomates.','Sopa de tomate. Ingredients: 2 tomates. Cocer los tomates.'] as $source) {
    $draft=$service->analyze(['kind'=>'text','text'=>$source],'description-policy-fixture');
    descriptionExpect($draft['recipe']['description']===$reply['recipe']['description'],'Description replacement was lost.');
    descriptionExpect($draft['recipe']['original_language']==='es','Recipe language changed.');
    descriptionExpect($draft['recipe']['ingredients'][0]['original_text']==='2 tomates' && $draft['recipe']['steps'][0]['instruction']==='Cocer los tomates.','Description handling changed other source content.');
}
foreach($captured as $payload) {
    descriptionExpect($payload['model']==='gpt-5.4-mini' && $payload['store']===false,'Configured model or privacy setting changed.');
    $instruction=$payload['instructions'];
    $schema=$payload['text']['format']['schema']['properties']['recipe']['properties']['description'];
    descriptionExpect(!str_contains($instruction,'Preserve a supplied recipe description') && !str_contains($schema['description'],'Preserve a supplied description'),'Old preservation instruction contradicts rewriting.');
    foreach(['even when the source supplies a description','recipe original language','optional ingredients or alternatives','Before returning it, check each claim'] as $requirement) {
        descriptionExpect(str_contains($instruction,$requirement) && str_contains($schema['description'],$requirement),'Description policy missing from prompt or schema.');
    }
}
echo "$checks description policy checks passed.\n";
