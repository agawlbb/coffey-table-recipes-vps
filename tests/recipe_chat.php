<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/HttpError.php';
require dirname(__DIR__).'/src/RecipeChatService.php';
require __DIR__.'/fixtures/context_database.php';
$checks=0;
function checkChat(bool $ok,string $message): void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function chatError(callable $run,string $code): void {try{$run();}catch(HttpError $e){checkChat($e->errorCode===$code,'Expected '.$code.', got '.$e->errorCode);return;}throw new RuntimeException('Expected '.$code);}
function patch(string $path,mixed $value,string $op='set'): array {return ['op'=>$op,'path'=>$path,'value_json'=>$op==='remove'?null:json_encode($value,JSON_THROW_ON_ERROR)];}
$root=sys_get_temp_dir().'/ctr-structured-chat-'.bin2hex(random_bytes(6));mkdir($root,0700);$pdo=contextTestDatabase($root.'/context.sqlite');
$payloads=[];$reply=['message'=>'Here is a practical soup.','show_recipe'=>false,'recipe_action'=>'patch','changes'=>[patch('/title','Egg soup'),patch('/measurement_system','us'),patch('/ingredients',[['original_text'=>'2 eggs','quantity_numerator'=>2,'quantity_denominator'=>1],['original_text'=>'2 cups water','quantity_decimal'=>'2','original_unit'=>'cups']]),patch('/steps',[['instruction'=>'Simmer for 2 minutes.','actions'=>[['action_type'=>'timer','duration_seconds'=>120]]]])],'context_updates'=>[]];$calls=0;$failure=false;
$transport=static function($payload)use(&$payloads,&$reply,&$calls,&$failure){$calls++;$payloads[]=$payload;if($failure)return ['status'=>503,'body'=>'{}'];return ['status'=>200,'body'=>json_encode(['status'=>'completed','usage'=>['input_tokens'=>1000,'output_tokens'=>100,'input_tokens_details'=>['cached_tokens'=>128],'output_tokens_details'=>['reasoning_tokens'=>20]],'output'=>[['content'=>[['type'=>'output_text','text'=>json_encode($reply)]]]]])];};
$service=new RecipeChatService($pdo,['api_key'=>'fixture-only','model'=>'fixture-model'],$root,$transport);
$body=['request_id'=>str_repeat('a',32),'turn_id'=>str_repeat('1',32),'mode'=>'create','action'=>'message','message'=>'Make egg soup.','revision'=>0,'draft_view'=>true];
$source=['id'=>'7','revision'=>3,'title'=>'Original soup','variation_base_title'=>'Original soup','original_language'=>'en','measurement_system'=>'us','source_type'=>'personal','source_text'=>'PRIVATE SOURCE','photos'=>[['storage_key'=>'PRIVATE PHOTO']],'category_assignments'=>[['category_id'=>'8']],'parent_recipe_id'=>'5','household_count'=>2,'pairings'=>[['id'=>'6']], 'ingredients'=>[['id'=>'PRIVATE INGREDIENT','original_text'=>'2 eggs','group_id'=>'PRIVATE GROUP']], 'groups'=>[['id'=>'PRIVATE GROUP','title'=>'Soup']], 'steps'=>[['id'=>'PRIVATE STEP','instruction'=>'Simmer for 2 minutes.']], 'actions'=>[['id'=>'PRIVATE ACTION','step_id'=>'PRIVATE STEP','action_type'=>'timer','duration_seconds'=>120]]];
$statePath=static fn($b,$user='alice')=>$root.'/recipe-chats/'.hash('sha256',json_encode([$user,'house-one',$b['request_id']])).'.json';
try {
 chatError(fn()=>$service->handle(array_replace($body,['action'=>'prepare','message'=>'']),'alice','house-one',null,[]),'chat_not_ready');
 $first=$service->handle($body,'alice','house-one',null,[]);
 checkChat($first['ready'] && !isset($first['draft']) && $first['working_recipe']['ingredients'][0]['original_text']==='2 eggs','A first chat creates structured editor data, never saves or opens review');
 checkChat(str_contains($first['recipe_text'],'Ingredients') && str_contains($first['recipe_text'],'1. Simmer'),'Working text is derived from the structured document');
 checkChat($first['message']==='Here is a practical soup.','Chat prose stays separate');
 checkChat($first['working_recipe']['actions'][0]['duration_seconds']===120,'Structured timers are ready during chat');
 checkChat($payloads[0]['store']===false && $payloads[0]['reasoning']['effort']==='low','Provider does not store chats and uses the configured low-reasoning request');
 $before=$calls;checkChat($service->handle($body,'alice','house-one',null,[])===$first && $calls===$before,'Lost response replay makes no provider call');
 chatError(fn()=>$service->handle(array_replace($body,['message'=>'Different']),'alice','house-one',null,[]),'chat_turn_changed');
 $reply=['message'=>'I removed the eggs; the soup will be thinner.','show_recipe'=>false,'recipe_action'=>'patch','changes'=>[patch('/ingredients/0',null,'remove')],'context_updates'=>[]];
 $next=array_replace($body,['turn_id'=>str_repeat('2',32),'revision'=>1,'message'=>'What if I remove the eggs?']);$second=$service->handle($next,'alice','house-one',null,[]);
 checkChat(count($second['working_recipe']['ingredients'])===1 && !str_contains($second['recipe_text'],'2 eggs'),'A targeted row change updates the only working draft');
 $input=json_decode($payloads[1]['input'][0]['content'],true);
 checkChat(isset($input['working_recipe']) && !isset($input['original_recipe'],$input['previous_recipe'],$input['recipe_text']),'Provider gets one current recipe, not original/current/previous copies');
 checkChat($payloads[1]['input'][2]['content']==='Here is a practical soup.','History holds concise prose, not old structured drafts');
 $reply=['message'=>'A hotter pan gives better browning.','show_recipe'=>false,'recipe_action'=>'keep','changes'=>[],'context_updates'=>[]];
 $discussion=array_replace($body,['turn_id'=>str_repeat('3',32),'revision'=>2,'message'=>'Just explain browning.']);$kept=$service->handle($discussion,'alice','house-one',null,[]);
 checkChat($kept['working_recipe']===$second['working_recipe'],'Explanation needs no repeated recipe output and changes no recipe');
 $reply=['message'=>'Undone.','show_recipe'=>false,'recipe_action'=>'undo','changes'=>[],'context_updates'=>[]];
 $undo=array_replace($body,['turn_id'=>str_repeat('4',32),'revision'=>3,'message'=>'Undo that recipe change.']);$restored=$service->handle($undo,'alice','house-one',null,[]);
 checkChat($restored['working_recipe']===$first['working_recipe'],'Undo restores the exact server snapshot, across intervening discussion');
 $input=json_decode(end($payloads)['input'][0]['content'],true);checkChat($input['undo_available']===true && !isset($input['previous_recipe']),'Undo sends availability, not the previous recipe');
 $prepare=array_replace($body,['turn_id'=>str_repeat('5',32),'revision'=>4,'action'=>'prepare','message'=>'']);$before=$calls;$prepared=$service->handle($prepare,'alice','house-one',null,[]);
 checkChat($calls===$before && $prepared['draft']['recipe']['source_type']==='ai_created','Review uses local validation with ZERO provider calls');
 checkChat($prepared['draft']['recipe']['ingredients']===$restored['working_recipe']['ingredients'],'Review uses exactly the working ingredients');
 checkChat($service->handle($prepare,'alice','house-one',null,[])===$prepared && $calls===$before,'Review replay is also local');
 // Invalid patches are atomic and cannot write account, media or relationship data.
 $reply=['message'=>'Invalid change.','show_recipe'=>false,'recipe_action'=>'patch','changes'=>[],'context_updates'=>[]];
 $bad=array_replace($body,['turn_id'=>str_repeat('6',32),'revision'=>5]);$beforeState=file_get_contents($statePath($body));
 foreach([
  [patch('/id','999')],[patch('/photos',[])],[patch('/parent_recipe_id','123')],[patch('/ingredients/999',null,'remove')],
  [patch('/title','Changed'),patch('/steps/0/instruction','')],[patch('/ingredients/0/quantity_denominator',0)],
  [patch('/steps/0/actions',[['action_type'=>'reminder']])],[patch('/ingredients/0',['original_text'=>'Water','id'=>'SPOOF'])],
  [patch('/title',[])],[['op'=>'set','path'=>'/title','value_json'=>'{bad']],
 ] as $changes){$reply['changes']=$changes;chatError(fn()=>$service->handle($bad,'alice','house-one',null,[]),'invalid_ai_reply');checkChat(file_get_contents($statePath($body))===$beforeState,'Invalid patch never partially changes draft or revision');}
 $reply=['message'=>'No change.','show_recipe'=>false,'recipe_action'=>'keep','changes'=>[patch('/title','Spoof')],'context_updates'=>[]];chatError(fn()=>$service->handle($bad,'alice','house-one',null,[]),'invalid_ai_reply');
 $reply=['message'=>'Still here.','show_recipe'=>false,'recipe_action'=>'keep','changes'=>[],'context_updates'=>[]];$failure=true;chatError(fn()=>$service->handle($bad,'alice','house-one',null,[]),'ai_unavailable');$failure=false;
 checkChat($service->handle($bad,'alice','house-one',null,[])['revision']===6,'A failed provider response retains turn/revision for retry');
 chatError(fn()=>$service->handle(array_replace($bad,['turn_id'=>str_repeat('7',32),'revision'=>0]),'alice','house-one',null,[]),'chat_out_of_date');
 $denied=new RecipeChatService($pdo,['api_key'=>'fixture-only','model'=>'fixture-model','before_recipe_chat_commit'=>static function(){throw new HttpError(403,'account_disabled','Unavailable');}],$root,$transport);
 $beforeState=file_get_contents($statePath($body));chatError(fn()=>$denied->handle(array_replace($bad,['turn_id'=>str_repeat('7',32),'revision'=>6]),'alice','house-one',null,[]),'account_disabled');checkChat(file_get_contents($statePath($body))===$beforeState,'Live authorization rejection prevents all draft/context changes');
 // Existing source is sanitized once, then only the current canonical draft is sent.
 $existing=array_replace($body,['request_id'=>str_repeat('b',32),'mode'=>'existing']);$existingFirst=$service->handle($existing,'alice','house-one',$source,[]);
 $sent=json_encode(end($payloads));foreach(['PRIVATE SOURCE','PRIVATE PHOTO','PRIVATE INGREDIENT','PRIVATE GROUP','PRIVATE STEP','PRIVATE ACTION'] as $private)checkChat(!str_contains($sent,$private),'Original account/private metadata stays out of provider input');
 checkChat($existingFirst['working_recipe']['groups'][0]['title']==='Soup','Original components and actions retain cooking meaning');
 $basePrepare=array_replace($existing,['action'=>'prepare','message'=>'','revision'=>1,'turn_id'=>str_repeat('2',32),'save_as'=>'variation']);$before=$calls;
 $variation=$service->handle($basePrepare,'alice','house-one',$source,[])['draft'];
 checkChat($calls===$before && $variation['recipe']['parent_recipe_id']==='7' && !isset($variation['recipe']['id']),'A variation is assembled locally with its trusted parent');
 checkChat($variation['recipe']['photos']===[],'Variations omit the old photo rather than delaying Review with a visual AI call');
 chatError(fn()=>$service->handle(array_replace($basePrepare,['save_as'=>'edit']),'alice','house-one',$source,[]),'chat_turn_changed');
 $edit=$service->handle(array_replace($basePrepare,['turn_id'=>str_repeat('3',32),'revision'=>2,'save_as'=>'edit']),'alice','house-one',$source,[])['draft']['recipe'];
 checkChat($calls===$before && $edit['id']==='7' && $edit['revision']===3,'Edit remains local and retains save-time revision safeguards');
 foreach(['photos','source_type','source_text','category_assignments','parent_recipe_id','pairings','household_count'] as $field)checkChat($edit[$field]===$source[$field],'Edit preserves '.$field);
 chatError(fn()=>$service->handle(array_replace($basePrepare,['turn_id'=>str_repeat('4',32),'revision'=>3]),'alice','house-one',array_replace($source,['revision'=>4]),[]),'chat_recipe_changed');
 // Category edits must agree with the same canonical data used by Review.
 $catCatalog=[['id'=>'1','label'=>'Meals','parent_label'=>null],['id'=>'8','label'=>'Soups','parent_label'=>'Meals'],['id'=>'9','label'=>'Stews','parent_label'=>'Meals']];
 $catBody=array_replace($body,['request_id'=>str_repeat('8',32),'mode'=>'existing']);
 $reply=['message'=>'Changed the category.','show_recipe'=>false,'recipe_action'=>'patch','changes'=>[patch('/categories',[['label'=>'Stews','parent_label'=>'Meals']])],'context_updates'=>[]];
 $service->handle($catBody,'alice','house-one',$source,$catCatalog);$before=$calls;
 $categoryEdit=$service->handle(array_replace($catBody,['action'=>'prepare','message'=>'','turn_id'=>str_repeat('2',32),'revision'=>1,'save_as'=>'edit']),'alice','house-one',$source,$catCatalog)['draft']['recipe'];
 checkChat($calls===$before && $categoryEdit['category_assignments'][0]['category_id']==='9','Explicit category changes survive local edit review instead of reverting to original categories');
 checkChat($categoryEdit['photos']===$source['photos'],'Category edits do not disturb original photos');
 $partialBody=array_replace($body,['request_id'=>str_repeat('9',32)]);$reply=['message'=>'We can develop soup.','show_recipe'=>false,'recipe_action'=>'patch','changes'=>[patch('/title','Soup')],'context_updates'=>[]];
 $partial=$service->handle($partialBody,'alice','house-one',null,[]);$before=$calls;
 $clarification=$service->handle(array_replace($partialBody,['turn_id'=>str_repeat('2',32),'revision'=>1,'action'=>'prepare','message'=>'']),'alice','house-one',null,[]);
 checkChat(!$partial['ready'] && !isset($clarification['draft']) && $calls===$before,'Incomplete proposals get local essential-field guidance without an AI call');
 checkChat(str_contains($clarification['message'],'ingredients') && str_contains($clarification['message'],'cooking steps') && !str_contains($clarification['message'],'a recipe title'),'Review asks only for fields actually missing, not already provided details');
 // Long conversations retain bounded human prose, not repeated full drafts.
 $long=array_replace($body,['request_id'=>str_repeat('c',32)]);$revision=0;
 for($i=1;$i<=18;$i++){$reply=['message'=>'Short reply '.$i,'show_recipe'=>false,'recipe_action'=>'keep','changes'=>[],'context_updates'=>[]];$service->handle(array_replace($long,['turn_id'=>sprintf('%032x',$i),'revision'=>$revision++,'message'=>'Question '.$i.' '.str_repeat('context ',300)]),'alice','house-one',null,[]);}
 $last=end($payloads);$history=array_slice($last['input'],1);checkChat(count($history)<=8 && mb_strlen(implode('',array_column($history,'content')))<=10000,'Provider history is bounded to eight messages and 10000 characters');
 checkChat(!str_contains(json_encode($history),'recipe_text') && !str_contains(json_encode($history),'changes'),'Old full recipes and patches are never in conversation history');
 $state=json_decode(file_get_contents($statePath($long)),true);checkChat(count($state['messages'])===36,'The full human transcript is retained locally while only bounded context goes to AI');
 // The limit still permits one final, local Review.
 $state['turns']=array_fill(0,60,['fingerprint'=>'fixture','response'=>[]]);$state['working_recipe']=RecipeWorkingDraft::fromSource($source);$state['revision']=60;file_put_contents($statePath($long),json_encode($state));
 chatError(fn()=>$service->handle(array_replace($long,['revision'=>60,'turn_id'=>str_repeat('f',32)]),'alice','house-one',null,[]),'chat_limit');
 checkChat(isset($service->handle(array_replace($long,['revision'=>60,'turn_id'=>str_repeat('f',32),'action'=>'prepare','message'=>'']),'alice','house-one',null,[])['draft']),'The final allowed Review remains available');
 // Optional preferences are ranked without dropping restrictions or unknown-language facts.
 $facts=[];for($i=0;$i<24;$i++)$facts[]=['id'=>md5('fact'.$i),'person'=>'self','preference'=>'Likes ingredient'.$i.'.','household'=>null];
 $facts[]=['id'=>md5('avoid'),'person'=>'self','preference'=>'Avoids peanuts.','household'=>null];$facts[]=['id'=>md5('unknown'),'person'=>'self','preference'=>'Evita gluten.','household'=>null];
 $q=$pdo->prepare('INSERT INTO user_recipe_context(user_id,state_json) VALUES (?,?)');$q->execute(['prefs',json_encode(['revision'=>1,'facts'=>$facts])]);
 $prefBody=array_replace($body,['request_id'=>str_repeat('d',32),'message'=>'Please use ingredient23.']);$reply=['message'=>'Understood.','show_recipe'=>false,'recipe_action'=>'keep','changes'=>[],'context_updates'=>[]];
 $service->handle($prefBody,'prefs','house-one',null,[]);$chosen=json_decode(end($payloads)['input'][0]['content'],true)['cooking_context'];$preferences=array_column($chosen,'preference');
 checkChat(count($chosen)===14 && in_array('Likes ingredient23.',$preferences,true),'Optional preferences are ranked by the current request within a small bound');
 checkChat(in_array('Avoids peanuts.',$preferences,true) && in_array('Evita gluten.',$preferences,true),'Restrictions and unknown preference kinds are never guessed irrelevant');
 $service->handle(array_replace($prefBody,['request_id'=>str_repeat('e',32),'message'=>'Forget all my preferences.']),'prefs','house-one',null,[]);
 checkChat(count(json_decode(end($payloads)['input'][0]['content'],true)['cooking_context'])===26,'Explicit memory management sees the full scoped context');
 // Undo snapshots are server-owned and bounded independently of short model history.
 $undoBody=array_replace($body,['request_id'=>str_repeat('f',32)]);$rev=0;
 for($i=1;$i<=6;$i++){$reply=['message'=>'Updated the title.','show_recipe'=>false,'recipe_action'=>'patch','changes'=>[patch('/title','Proposal '.$i)],'context_updates'=>[]];$service->handle(array_replace($undoBody,['turn_id'=>sprintf('%032x',$i),'revision'=>$rev++]),'undo-user','house-one',null,[]);}
 $saved=json_decode(file_get_contents($statePath($undoBody,'undo-user')),true);checkChat(count($saved['undo_recipes'])===5,'At most five full undo snapshots are retained on the server');
 $reply=['message'=>'Undone.','show_recipe'=>false,'recipe_action'=>'undo','changes'=>[],'context_updates'=>null];$undoTurn=array_replace($undoBody,['turn_id'=>sprintf('%032x',7),'revision'=>$rev,'message'=>'Undo.']);
 chatError(fn()=>$service->handle($undoTurn,'undo-user','house-one',null,[]),'invalid_ai_reply');
 checkChat(json_decode(file_get_contents($statePath($undoBody,'undo-user')),true)===$saved,'Invalid learning deltas cannot partially pop an undo snapshot');
 $reply['context_updates']=[];
 for($i=7;$i<=11;$i++)$lastUndo=$service->handle(array_replace($undoBody,['turn_id'=>sprintf('%032x',$i),'revision'=>$rev++,'message'=>'Undo.']),'undo-user','house-one',null,[]);
 checkChat($lastUndo['working_recipe']['title']==='Proposal 1','Five successive undos restore each available prior working draft');
 $noUndo=$service->handle(array_replace($undoBody,['turn_id'=>sprintf('%032x',12),'revision'=>$rev,'message'=>'Undo.']),'undo-user','house-one',null,[]);
 checkChat($noUndo['working_recipe']===$lastUndo['working_recipe'] && str_contains($noUndo['message'],'no earlier'),'No unavailable undo is fabricated or allowed to erase the draft');
 // Metadata is useful and never contains recipe, preference or chat content.
 $metrics=array_map(static fn($line)=>json_decode($line,true),file($root.'/recipe-chat-metrics.log',FILE_IGNORE_NEW_LINES));
 $reviews=array_filter($metrics,static fn($r)=>$r['operation']==='prepare');foreach($reviews as $r)checkChat($r['provider_calls']===0 && $r['input_tokens']===0 && $r['output_tokens']===0,'Structured review and replay metrics record zero AI tokens');
 $model=array_values(array_filter($metrics,static fn($r)=>$r['operation']==='message' && $r['status']==='completed' && !$r['cache_hit']));
 checkChat($model[0]['input_tokens']===1000 && $model[0]['output_tokens']===100 && $model[0]['reasoning_tokens']===20 && $model[0]['cached_input_tokens']===128,'Metrics preserve actual provider input/output/reasoning/cache counts');
 $encoded=file_get_contents($root.'/recipe-chat-metrics.log');checkChat(!str_contains($encoded,'egg') && !str_contains($encoded,'Question') && !str_contains($encoded,'alice'),'Metrics never log cooking content, transcripts or account IDs');
 checkChat((fileperms($root.'/recipe-chat-metrics.log')&0777)===0600,'Usage metadata stays private');
} finally {$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $p){if($p->isDir())rmdir($p->getPathname());else unlink($p->getPathname());}rmdir($root);}
echo "$checks structured chat checks passed\n";
