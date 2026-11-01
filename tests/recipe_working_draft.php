<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/HttpError.php';require dirname(__DIR__).'/src/RecipeWorkingDraft.php';
$count=0;function wdCheck(bool $ok,string $message):void{global $count;if(!$ok)throw new RuntimeException($message);$count++;}
function wdPatch(string $path,mixed $value,string $op='set'):array{return ['op'=>$op,'path'=>$path,'value_json'=>$op==='remove'?null:json_encode($value)];}
function wdFails(callable $run):void{global $count;try{$run();}catch(HttpError $e){if($e->errorCode==='invalid_ai_reply'){$count++;return;}throw $e;}throw new RuntimeException('Invalid change accepted');}
$r=RecipeWorkingDraft::apply(RecipeWorkingDraft::empty(),[wdPatch('/title','Pudding'),wdPatch('/measurement_system','us'),wdPatch('/ingredients',[['original_text'=>'1/2 cup milk','ingredient_name'=>'milk','quantity_numerator'=>1,'quantity_denominator'=>2,'original_unit'=>'cup','group_title'=>'Pudding']]),wdPatch('/steps',[['instruction'=>'Simmer for 10 to 15 minutes.','actions'=>[['action_type'=>'timer','duration_seconds'=>600],['action_type'=>'timer','duration_seconds'=>900]]]])]);
wdCheck(RecipeWorkingDraft::ready($r),'A complete structured recipe is immediately ready');
wdCheck($r['ingredients'][0]['quantity_numerator']===1 && $r['ingredients'][0]['quantity_denominator']===2 && $r['ingredients'][0]['quantity_decimal']===null,'Fractions stay exact');
wdCheck(count($r['steps'][0]['actions'])===1 && $r['steps'][0]['actions'][0]['duration_seconds']===600,'Timer ranges normalize to one lower endpoint');
wdCheck(RecipeWorkingDraft::editor($r)['groups'][0]['title']==='Pudding','Component headings are editor-ready');
$inserted=RecipeWorkingDraft::apply($r,[wdPatch('/ingredients/-',['original_text'=>'Sugar to taste'],'insert'),wdPatch('/ingredients/1/preparation','finely ground'),wdPatch('/notes','Stir gently.')]);
wdCheck(count($inserted['ingredients'])===2 && $inserted['ingredients'][1]['preparation']==='finely ground','Small changes can append rows and update fields');
wdCheck(str_contains(RecipeWorkingDraft::text($inserted),'Sugar to taste') && str_contains(RecipeWorkingDraft::text($inserted),'Stir gently.'),'Rendered ingredients and notes derive from canonical data');
$removed=RecipeWorkingDraft::apply($inserted,[wdPatch('/ingredients/0',null,'remove'),wdPatch('/ingredients/0/original_text','1 teaspoon sugar')]);
wdCheck(count($removed['ingredients'])===1 && $removed['ingredients'][0]['original_text']==='1 teaspoon sugar','Sequential row operations use updated indices');
$source=RecipeWorkingDraft::editor($r);$source['photos']=[['id'=>'PRIVATE']];$source['source_text']='PRIVATE';$back=RecipeWorkingDraft::fromSource($source);
wdCheck($back['steps']===$r['steps'] && $back['ingredients']===$r['ingredients'],'Editor and working document round-trip cooking fields');
wdCheck(!str_contains(json_encode($back),'PRIVATE') && !isset($back['id']),'Provenance and photos cannot enter the provider working document');
$catalog=[['id'=>'1','label'=>'Desserts','parent_label'=>null],['id'=>'2','label'=>'Puddings','parent_label'=>'Desserts']];$source['category_assignments']=[['category_id'=>'2']];
wdCheck(RecipeWorkingDraft::fromSource($source,$catalog)['categories']===[['label'=>'Puddings','parent_label'=>'Desserts']],'Existing category IDs become cooking-only catalog labels');
$source['category_assignments']=[['label'=>'Custards','parent_label'=>'Desserts']];
wdCheck(RecipeWorkingDraft::fromSource($source,$catalog)['categories']===[['label'=>'Custards','parent_label'=>'Desserts']],'Proposed category labels survive legacy/editor conversion without database IDs');
foreach([
 wdPatch('/servings','two'),wdPatch('/servings',0),wdPatch('/prep_minutes',-1),wdPatch('/prep_minutes',2.5),
 wdPatch('/measurement_system','private'),wdPatch('/original_language','../../secret'),wdPatch('/title',null),
 wdPatch('/ingredients/0/ingredient_name',true),wdPatch('/ingredients/0/quantity_decimal','-0.5'),wdPatch('/ingredients/0/quantity_denominator',0),
 wdPatch('/ingredients/0/quantity_numerator','2'),wdPatch('/ingredients/0/scale_quantity',4),wdPatch('/steps/0/actions',[['action_type'=>'timer','duration_seconds'=>-1]]),
 wdPatch('/steps/0/actions',[['action_type'=>'appliance_temperature','temperature_value'=>180,'temperature_unit'=>'X']]),
 wdPatch('/categories',[['label'=>'Puddings','parent_label'=>'']]),wdPatch('/ingredients/999',null,'remove'),wdPatch('/ingredients/0/id','private'),
 wdPatch('/steps/0/instruction',''),wdPatch('/steps/0/actions',[['action_type'=>'timer','duration_seconds'=>60,'secret'=>'no']]),
 wdPatch('/ingredients/0/original_text',str_repeat('x',4001)),wdPatch('/ingredients',array_fill(0,301,['original_text'=>'Water'])),
] as $change)wdFails(fn()=>RecipeWorkingDraft::apply($r,[$change]));
wdFails(fn()=>RecipeWorkingDraft::apply($r,array_fill(0,101,wdPatch('/notes','Repeated'))));
wdCheck(RecipeWorkingDraft::text(RecipeWorkingDraft::empty())==='' && !RecipeWorkingDraft::ready(RecipeWorkingDraft::empty()),'An empty discussion does not invent a recipe');
$partial=RecipeWorkingDraft::apply(RecipeWorkingDraft::empty(),[wdPatch('/title','Soup')]);wdCheck(!RecipeWorkingDraft::ready($partial),'Partial proposals remain usable but need ingredients and steps for review');
try{RecipeWorkingDraft::apply($r,[wdPatch('/categories',[['label'=>'Mystery','parent_label'=>'Unknown']])],$catalog);throw new LogicException('Expected invalid catalog');}catch(HttpError $e){wdCheck($e->errorCode==='invalid_ai_reply' && !str_contains($e->getMessage(),'import') && str_contains($e->getMessage(),'working draft is unchanged'),'Shared validation errors are translated into clear chat guidance');}
wdCheck(RecipeWorkingDraft::apply($r,[wdPatch('/ingredients/0',array_reverse($r['ingredients'][0],true))])===$r,'JSON field order alone cannot create a different canonical recipe or consume undo history');
echo "$count working draft checks passed\n";
