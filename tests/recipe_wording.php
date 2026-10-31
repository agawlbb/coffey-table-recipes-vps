<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/HttpError.php';
require dirname(__DIR__).'/src/RecipeImportService.php';
$checks=0;
function wordingExpect(bool $ok,string $message): void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
$reply=['recipe'=>['title'=>'Soup','original_language'=>'en','ingredients'=>[['original_text'=>'About 2 cups broth','ingredient_name'=>'broth','quantity_decimal'=>'2','original_unit'=>'cups']],'steps'=>[['instruction'=>'Heat the broth for 5 minutes.','actions'=>[['action_type'=>'timer','duration_seconds'=>300]]]],'notes'=>'Add water gradually to adjust the consistency.'],'questions'=>[]];
$source='I use about 2 cups broth. I heat the whole thing for 5 minutes. Sometimes I add water a little at a time for the consistency.';
$payloads=[];
$service=new RecipeImportService(['api_key'=>'fixture','model'=>'gpt-5.4-mini'],sys_get_temp_dir(),static function(array $payload) use(&$payloads,&$reply):array {
 $payloads[]=$payload;
 $message=['type'=>'output_text','text'=>json_encode($reply)];
 return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['content'=>[$message]]]])];
});
$draft=$service->analyze(['kind'=>'text','text'=>$source],'wording-fixture');
wordingExpect($draft['recipe']['source_text']===$source,'Raw conversational source was rewritten.');
wordingExpect($draft['recipe']['steps'][0]['instruction']==='Heat the broth for 5 minutes.','Polished method was replaced with transcript wording.');
wordingExpect($draft['recipe']['ingredients'][0]['original_text']==='About 2 cups broth','Approximate ingredient qualifier was lost.');
wordingExpect($draft['recipe']['actions'][0]['duration_seconds']===300,'Wording polish changed timer facts.');
$reply['recipe']['original_language']='es';$reply['recipe']['title']='Sopa';$reply['recipe']['steps'][0]['instruction']='Caliente el caldo durante 5 minutos.';
$draft=$service->analyze(['kind'=>'text','text'=>'Uso caldo. Lo caliento todo durante 5 minutos.'],'wording-fixture');
wordingExpect($draft['recipe']['original_language']==='es' && $draft['recipe']['steps'][0]['instruction']==='Caliente el caldo durante 5 minutos.','Source language or polished method changed.');
foreach($payloads as $payload) {
 $schema=$payload['text']['format']['schema']['properties']['recipe']['properties'];
 $method=$schema['steps']['items']['properties']['instruction']['description'];
 wordingExpect($method===RecipeImportService::methodWritingInstruction() && str_contains($payload['instructions'],$method),'Prompt and strict method schema disagree.');
 foreach(['direct imperative sentences','not as a transcript','Microwave for 5 minutes','Preserve every cooking fact','Do not merge independently verifiable cooking stages','ask a concise clarification question'] as $rule) wordingExpect(str_contains($method,$rule),'Missing method editing rule: '.$rule);
 wordingExpect(str_contains($schema['notes']['description'],'impersonal recipe notes'),'Notes still encourage conversational anecdotes.');
 wordingExpect(str_contains($schema['ingredients']['items']['properties']['original_text']['description'],'approximate qualifiers'),'Ingredient wording does not preserve meaningful qualifiers.');
 wordingExpect($payload['model']==='gpt-5.4-mini' && $payload['store']===false,'Wording polish changed model or privacy setting.');
}
echo "$checks recipe wording checks passed.\n";
