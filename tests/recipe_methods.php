<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/HttpError.php';
require dirname(__DIR__).'/src/RecipeImportService.php';
$count=0;
function methodExpect(bool $ok,string $message):void {global $count;if(!$ok)throw new RuntimeException($message);$count++;}
$recipe=['@type'=>'Recipe','name'=>'Instant Pot Lentil Soup','prepTime'=>'PT10M','cookTime'=>'PT40M','totalTime'=>'PT50M','recipeIngredient'=>['1 cup lentils','2 cups stock'],
 'recipeInstructions'=>[
  ['@type'=>'HowToSection','name'=>'For the Stovetop:','itemListElement'=>[['@type'=>'HowToStep','text'=>'Simmer for 25 minutes.']]],
  ['@type'=>'HowToSection','name'=>'For the Instant Pot:','itemListElement'=>[['@type'=>'HowToStep','text'=>'Cook at high pressure for 15 minutes; release naturally.']]],
 ]];
$text=json_encode([$recipe]);$options=RecipeImportMethods::options($text);
methodExpect(array_column($options,'id')===['stovetop','instant-pot'],'Appliance method options not detected.');
foreach($options as $i=>$option) {
 $selected=json_decode($option['text'],true)[0];
 methodExpect(count($selected['recipeInstructions'])===1,'Alternative methods remained mixed.');
 methodExpect(!isset($selected['cookTime'],$selected['totalTime']) && $selected['prepTime']==='PT10M','Other-method cooking time remained.');
 methodExpect(str_ends_with($selected['name'],$option['label']),'Selected method is not clear in the title.');
}
$components=$recipe;$components['recipeInstructions'][0]['name']='Dough';$components['recipeInstructions'][1]['name']='Sauce';
methodExpect(RecipeImportMethods::options(json_encode([$components]))===[],'Recipe components mistaken for alternatives.');
$single=$recipe;$single['recipeInstructions']=[$single['recipeInstructions'][1]];
methodExpect(RecipeImportMethods::options(json_encode([$single]))===[],'Single method asked for a method choice.');
methodExpect(RecipeImportMethods::selection(['kind'=>'answers','answers'=>[['answer'=>'unknown']]],$options)===null,'Unknown answer silently selected a method.');
methodExpect(RecipeImportMethods::selection(['kind'=>'answers','answers'=>[['answer'=>'Instant Pot please']]],$options)==='instant-pot','Legacy text choice not understood.');
methodExpect(RecipeImportMethods::selection(['kind'=>'answers','answers'=>[['answer'=>'both']]],$options)==='both','Both text choice not understood.');
$calls=0;$sources=[];
$html='<script type="application/ld+json">'.json_encode($recipe).'</script>';
$service=new RecipeImportService(['api_key'=>'fixture','model'=>'gpt-5.4-mini'],sys_get_temp_dir(),static function(array $payload)use(&$calls,&$sources):array {
 $calls++;
 $source=$payload['input'][0]['content'][0]['text'];$sources[]=$source;
 $stove=str_contains($source,'Simmer for 25 minutes.');
 methodExpect(!($stove && str_contains($source,'Cook at high pressure')),'AI received both methods together.');
 $reply=['recipe'=>['title'=>'Lentil soup','ingredients'=>[['original_text'=>'1 cup lentils','ingredient_name'=>'lentils']],'steps'=>[['instruction'=>$stove?'Simmer for 25 minutes.':'Cook at high pressure for 15 minutes.']],'cook_minutes'=>$stove?25:15],'questions'=>[]];
 return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode($reply)]]]]])];
},static fn()=>['status'=>200,'type'=>'text/html','html'=>$html]);
$first=$service->analyze(['kind'=>'website','url'=>'https://93.184.216.34/soup','supports_related_imports'=>true],'fixture');
methodExpect($calls===0 && $first['requires_method_choice']===true && count($first['questions'])===1,'Choice did not precede AI extraction.');
methodExpect($first['recipe']['_import_method_choice_required']===true && count($first['method_choices'])===2,'Choice gate or available methods missing.');
$answer=['kind'=>'answers','text'=>$first['recipe']['source_text'],'url'=>$first['recipe']['source_url'],'recipe'=>$first['recipe'],'answers'=>[['question'=>$first['questions'][0],'answer'=>'Stovetop']]];
$one=$service->analyze($answer,'fixture');
methodExpect($calls===1 && !isset($one['related_imports']) && $one['recipe']['cook_minutes']===25,'Single selection generated extra or mixed recipes.');
methodExpect($one['recipe']['source_type']==='Website' && $one['recipe']['source_url']==='https://93.184.216.34/soup','Method provenance changed.');
$answer['answers'][0]['answer']='Both';
$old=$service->analyze($answer,'fixture');
methodExpect($calls===1 && $old['requires_method_choice']===true && str_contains($old['questions'][0],'updated app'),'Older client silently dropped one of both methods.');
$answer['supports_related_imports']=true;$answer['method_selection']='both';
$both=$service->analyze($answer,'fixture');
methodExpect($calls===3 && count($both['related_imports'])===1,'Both did not produce two independent drafts.');
$child=$both['related_imports'][0]['recipe'];
methodExpect($both['recipe']['cook_minutes']===25 && $child['cook_minutes']===15,'Method-specific cooking times were combined.');
methodExpect($both['recipe']['import_key']!==$child['import_key'],'Related imports reused an import identity.');
methodExpect(!isset($child['parent_recipe_id']) && !isset($both['recipe']['id']),'Draft generation prematurely linked or saved database identities.');
methodExpect($child['source_url']===$both['recipe']['source_url'] && str_ends_with($child['title'],'Instant Pot'),'Variation provenance/title missing.');
echo "$count alternative-method import checks passed.\n";
