<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/HttpError.php';
require dirname(__DIR__).'/src/RecipeChatService.php';
require __DIR__.'/fixtures/context_database.php';
$checks=0;
function checkChat(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function chatError(callable $run,string $code):void {try{$run();}catch(HttpError $e){checkChat($e->errorCode===$code,'Expected '.$code.', got '.$e->errorCode);return;}throw new RuntimeException('Expected '.$code);}
$root=sys_get_temp_dir().'/ctr-full-chat-'.bin2hex(random_bytes(6));mkdir($root,0700);$pdo=contextTestDatabase($root.'/context.sqlite');
$recipe=['title'=>'Egg soup','measurement_system'=>'us','ingredients'=>[['original_text'=>'2 eggs'],['original_text'=>'2 cups water']],'steps'=>[['instruction'=>'Simmer for 2 minutes.','actions'=>[['action_type'=>'timer','duration_seconds'=>120]]]]];
$reply=['message'=>'Here is a practical soup.','ready'=>true,'recipe'=>$recipe,'show_recipe'=>false,'context_updates'=>[]];$calls=0;$payloads=[];$failure=false;
$transport=static function($payload)use(&$payloads,&$reply,&$calls,&$failure){$calls++;$payloads[]=$payload;if($failure)return ['status'=>503,'body'=>'{}'];return ['status'=>200,'body'=>json_encode(['status'=>'completed','usage'=>['input_tokens'=>1000,'output_tokens'=>100,'input_tokens_details'=>['cached_tokens'=>128],'output_tokens_details'=>['reasoning_tokens'=>20]],'output'=>[['content'=>[['type'=>'output_text','text'=>json_encode($reply)]]]]])];};
$service=new RecipeChatService($pdo,['api_key'=>'fixture-only','model'=>'fixture-model'],$root,$transport);
$body=['request_id'=>str_repeat('a',32),'turn_id'=>str_repeat('1',32),'mode'=>'create','action'=>'message','message'=>'Make egg soup.','revision'=>0,'draft_view'=>true];
$source=['id'=>'7','revision'=>3,'title'=>'Original soup','original_language'=>'en','measurement_system'=>'us','source_type'=>'personal','source_text'=>'PRIVATE SOURCE','photos'=>[['storage_key'=>'PRIVATE PHOTO']],'category_assignments'=>[['category_id'=>'8']],'parent_recipe_id'=>'5','pairings'=>[['id'=>'6']],'ingredients'=>[['id'=>'PRIVATE INGREDIENT','original_text'=>'2 eggs','group_id'=>'PRIVATE GROUP']],'groups'=>[['id'=>'PRIVATE GROUP','title'=>'Soup']],'steps'=>[['id'=>'PRIVATE STEP','instruction'=>'Simmer for 2 minutes.']],'actions'=>[['id'=>'PRIVATE ACTION','step_id'=>'PRIVATE STEP','action_type'=>'timer','duration_seconds'=>120]]];
mkdir($root.'/media/recipes',0700,true);$photoBytes=base64_decode(trim(file_get_contents(__DIR__.'/fixtures/photo.base64')));$photoKey=str_repeat('f',48).'.jpg';file_put_contents($root.'/media/recipes/'.$photoKey,$photoBytes);
$source['photos']=[['id'=>'private-photo','storage_key'=>'vps/recipes/'.$photoKey,'is_cover'=>1,'caption'=>'Original cover','attribution'=>'Original photographer']];
$catalog=[['id'=>'1','label'=>'Meals','parent_label'=>null],['id'=>'8','label'=>'Soups','parent_label'=>'Meals']];
$statePath=static fn($b)=>$root.'/recipe-chats/'.hash('sha256',json_encode(['alice','house-one',$b['request_id']])).'.json';
try {
 chatError(fn()=>$service->handle(array_replace($body,['action'=>'prepare','message'=>'']),'alice','house-one',null,[]),'chat_not_ready');
 $first=$service->handle($body,'alice','house-one',null,[]);
 checkChat($first['ready'] && !isset($first['draft']) && $first['working_recipe']['ingredients'][0]['original_text']==='2 eggs','First reply maintains complete draft without opening editor');
 checkChat($first['message']===$reply['message'] && str_contains($first['recipe_text'],'1. Simmer'),'Conversation and recipe are displayed separately');
 checkChat($first['working_recipe']['actions'][0]['duration_seconds']===120,'Complete draft includes editor actions');
 $payload=$payloads[0];checkChat(!isset($payload['reasoning']) && $payload['max_output_tokens']===10000 && $payload['store']===false,'Original model reasoning defaults and output budget restored');
 checkChat(!str_contains(json_encode($payload['text']['format']),'Do not add techniques') && isset($payload['text']['format']['schema']['properties']['recipe']['anyOf'][0]['properties']['description']),'Editor schema retains field types without extraction instructions that constrain cooking');
 $original=explode("\n",trim(file_get_contents(__DIR__.'/fixtures/recipe-chat-pre-optimization-prompt.txt')));$current=explode("\n",$payload['instructions']);
 foreach([1,4,5,6] as $paragraph)checkChat($original[$paragraph]===$current[$paragraph],'Original cooking/context policy paragraph '.$paragraph.' restored verbatim');
 checkChat(str_contains($current[2],'complete recipe every turn') && !str_contains($payload['instructions'],'value_json'),'Whole recipes replace the fragile patch protocol');
 checkChat(str_starts_with($current[0],$original[0]) && str_contains($current[0],'SAME holistic look') && str_contains($current[0],'direct instruction deserves the same thoughtful explanation'),'Direct instructions and hypotheticals get holistic cooking evaluation and chatty explanations');
 $before=$calls;checkChat($service->handle($body,'alice','house-one',null,[])===$first && $calls===$before,'Lost reply replays without AI');
 chatError(fn()=>$service->handle(array_replace($body,['message'=>'Different']),'alice','house-one',null,[]),'chat_turn_changed');
 $recipe['ingredients']=[['original_text'=>'2 cups water']];$reply['recipe']=$recipe;$reply['message']='I removed the eggs; the soup will be thinner.';
 $second=$service->handle(array_replace($body,['turn_id'=>str_repeat('2',32),'revision'=>1,'message'=>'What if I remove the eggs?']),'alice','house-one',null,[]);
 checkChat(count($second['working_recipe']['ingredients'])===1 && !str_contains($second['recipe_text'],'2 eggs'),'Whole revised draft replaces earlier recipe');
 $input=json_decode($payloads[1]['input'][0]['content'],true);checkChat($input['working_recipe']['title']==='Egg soup' && array_key_exists('original_recipe',$input) && array_key_exists('previous_recipe',$input),'Full original/current/previous frame restored');
 checkChat(json_decode($payloads[1]['input'][2]['content'],true)['recipe']['ingredients'][0]['original_text']==='2 eggs','Complete earlier recipes remain in conversation history');
 $reply['message']='A hotter pan improves browning.';
 $third=$service->handle(array_replace($body,['turn_id'=>str_repeat('3',32),'revision'=>2,'message'=>'Just explain browning.']),'alice','house-one',null,[]);
 checkChat($third['working_recipe']===$second['working_recipe'],'Explanation-only reply retains complete draft');
 $prepare=array_replace($body,['turn_id'=>str_repeat('4',32),'revision'=>3,'action'=>'prepare','message'=>'']);$before=$calls;$prepared=$service->handle($prepare,'alice','house-one',null,[]);
 checkChat($calls===$before && $prepared['draft']['recipe']['ingredients']===$third['working_recipe']['ingredients'] && $prepared['draft']['recipe']['steps']===$third['working_recipe']['steps'],'Review imports exact agreed draft with zero AI requests');
 checkChat($service->handle($prepare,'alice','house-one',null,[])===$prepared && $calls===$before,'Review replay is local');
 $bad=array_replace($body,['turn_id'=>str_repeat('5',32),'revision'=>4]);$state=file_get_contents($statePath($body));
 $valid=$reply;foreach([['recipe'=>['title'=>[]]],['recipe'=>'invalid'],['ready'=>'yes'],['context_updates'=>null]] as $invalid){$reply=array_replace($valid,$invalid);chatError(fn()=>$service->handle($bad,'alice','house-one',null,[]),'invalid_ai_reply');checkChat(file_get_contents($statePath($body))===$state,'Invalid provider reply preserves draft and revision atomically');}
 $reply=$valid;$failure=true;chatError(fn()=>$service->handle($bad,'alice','house-one',null,[]),'ai_unavailable');$failure=false;
 checkChat($service->handle($bad,'alice','house-one',null,[])['revision']===5,'Failed turn can be retried');
 $reply['recipe']=null;$existing=array_replace($body,['request_id'=>str_repeat('b',32),'mode'=>'existing']);$seed=$service->handle($existing,'alice','house-one',$source,$catalog);
 $sent=json_encode(end($payloads));foreach(['PRIVATE SOURCE','PRIVATE PHOTO','PRIVATE INGREDIENT','PRIVATE GROUP','PRIVATE STEP','PRIVATE ACTION'] as $private)checkChat(!str_contains($sent,$private),'Private source field excluded: '.$private);
 checkChat($seed['working_recipe']['groups'][0]['title']==='Soup' && $seed['working_recipe']['actions'][0]['duration_seconds']===120,'Original component and action cooking facts retained');
 $base=array_replace($existing,['turn_id'=>str_repeat('2',32),'revision'=>1,'action'=>'prepare','message'=>'','save_as'=>'variation']);$before=$calls;$variation=$service->handle($base,'alice','house-one',$source,$catalog)['draft']['recipe'];
 checkChat($calls===$before && $variation['parent_recipe_id']==='7' && !isset($variation['id']) && count($variation['photos'])===1 && base64_decode($variation['photos'][0]['image_base64'])===$photoBytes && $variation['photos'][0]['attribution']==='Original photographer','Variation locally uses trusted parent, independent identity and a copy of the original picture');
 checkChat(!str_contains($sent,$photoKey) && !str_contains($sent,'Original photographer'),'Original picture metadata never reaches cooking AI');
 $missingSource=$source;$missingSource['photos'][0]['storage_key']='vps/recipes/'.str_repeat('a',48).'.jpg';
 $missing=array_replace($body,['request_id'=>str_repeat('d',32),'mode'=>'existing']);$service->handle($missing,'alice','house-one',$missingSource,$catalog);$before=$calls;
 chatError(fn()=>$service->handle(array_replace($missing,['turn_id'=>str_repeat('2',32),'revision'=>1,'action'=>'prepare','message'=>'','save_as'=>'variation']),'alice','house-one',$missingSource,$catalog),'photo_unavailable');
 checkChat($calls===$before,'Missing original picture fails without an image AI request or recipe save');
 $edit=$service->handle(array_replace($base,['turn_id'=>str_repeat('3',32),'revision'=>2,'save_as'=>'edit']),'alice','house-one',$source,$catalog)['draft']['recipe'];
 foreach(['id','revision','photos','source_type','source_text','parent_recipe_id','pairings'] as $field)checkChat($edit[$field]===$source[$field],'Local edit preserves '.$field);
 checkChat($edit['category_assignments'][0]['category_id']==='8','Unchanged original category survives normalization and review');
 $prepared=$service->handle(array_replace($base,['turn_id'=>str_repeat('4',32),'revision'=>3]),'alice','house-one',array_replace($source,['revision'=>4]),$catalog);
 checkChat(isset($prepared['draft']['recipe']),'A changed original does not block preparing the next save');
 // No context ranking or short-history optimization: all scoped facts/turns reach cooking reasoning.
 $facts=[];for($i=0;$i<26;$i++)$facts[]=['id'=>md5('fact'.$i),'person'=>'self','preference'=>'Likes ingredient'.$i.'.','household'=>null];
 $q=$pdo->prepare('INSERT INTO user_recipe_context(user_id,state_json) VALUES (?,?)');$q->execute(['prefs',json_encode(['revision'=>1,'facts'=>$facts])]);
 $long=array_replace($body,['request_id'=>str_repeat('c',32)]);$reply['ready']=false;
 for($i=1;$i<=12;$i++)$service->handle(array_replace($long,['turn_id'=>sprintf('%032x',$i),'revision'=>$i-1,'message'=>'Question '.$i.' '.str_repeat('context ',150)]),'prefs','house-one',null,[]);
 $last=end($payloads);checkChat(count($last['input'])===24 && count(json_decode($last['input'][0]['content'],true)['cooking_context'])===26,'Full conversational history and all scoped preferences supplied');
 $metrics=array_map(static fn($line)=>json_decode($line,true),file($root.'/recipe-chat-metrics.log',FILE_IGNORE_NEW_LINES));foreach($metrics as $row)if($row['operation']==='prepare')checkChat($row['provider_calls']===0,'Every new-session Review uses zero AI calls');
 checkChat($metrics[1]['input_tokens']===1000 && $metrics[1]['reasoning_tokens']===20,'Actual provider usage retained');
 checkChat(!str_contains(file_get_contents($root.'/recipe-chat-metrics.log'),'Egg soup'),'Usage metadata excludes recipe content');
} finally {$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $p){if($p->isDir())rmdir($p->getPathname());else unlink($p->getPathname());}rmdir($root);}
echo "$checks full recipe chat checks passed\n";
