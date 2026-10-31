<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/HttpError.php';
require dirname(__DIR__).'/src/RecipeChatService.php';

$checks=0;
function expectContext(bool $value,string $message): void { global $checks; if (!$value) throw new RuntimeException($message); $checks++; }
function contextError(callable $run,string $code): void {
    try { $run(); } catch (HttpError $error) { expectContext($error->errorCode===$code,'Expected '.$code.', got '.$error->errorCode); return; }
    throw new RuntimeException('Expected '.$code);
}
function contextAdd(string $person,string $preference,string $evidence): array { return ['action'=>'add','id'=>null,'person'=>$person,'preference'=>$preference,'evidence'=>$evidence]; }
function contextChange(string $action,string $id,?string $preference,string $evidence): array { return ['action'=>$action,'id'=>$id,'person'=>null,'preference'=>$preference,'evidence'=>$evidence]; }
$directory=sys_get_temp_dir().'/ctr-context-'.bin2hex(random_bytes(6)); mkdir($directory,0700);
$payloads=[]; $updates=[]; $omitUpdates=false; $calls=0; $session=0;
$config=['api_key'=>'fixture-only','model'=>'fixture-model'];
$transport=static function(array $payload) use (&$payloads,&$updates,&$omitUpdates,&$calls): array {
    $calls++; $payloads[]=$payload;
    $reply=['message'=>'We can keep discussing your cooking preferences.','ready'=>false,'recipe_text'=>''];
    if (!$omitUpdates) $reply['context_updates']=$updates;
    return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode($reply)]]]]])];
};
$service=new RecipeChatService($config,$directory,$transport);
$body=static function(string $message) use (&$session): array { return ['request_id'=>sprintf('%032x',++$session),'turn_id'=>str_repeat('1',32),'revision'=>0,'mode'=>'create','action'=>'message','message'=>$message]; };
$send=static function(string $message,string $user='alice',string $household='house-one') use ($service,$body): array { return $service->handle($body($message),$user,$household,null,[]); };
$input=static function() use (&$payloads): array { return json_decode(end($payloads)['input'][0]['content'],true); };
$profile=static fn(string $user)=>$directory.'/recipe-user-context/'.hash('sha256',$user).'.json';
$chatPath=static fn(array $request,string $user='alice',string $household='house-one')=>$directory.'/recipe-chats/'.hash('sha256',json_encode([$user,$household,$request['request_id']])).'.json';
try {
    $first=$body('I dislike mushrooms. My son Alex dislikes cilantro.');
    $updates=[contextAdd('self','Dislikes mushrooms.','I dislike mushrooms.'),contextAdd('my son Alex','Dislikes cilantro.','My son Alex dislikes cilantro.')];
    $learned=$service->handle($first,'alice','house-one',null,[]);
    expectContext($learned['context_revision']===1 && count($learned['context_updates'])===2,'A successful chat learns self and attributed family context without recipe Save');
    $self=$learned['context_updates'][0]['id']; $alex=$learned['context_updates'][1]['id'];
    expectContext((bool)preg_match('/^[a-f0-9]{32}$/D',$self) && $self!==$alex,'Server allocates opaque stable fact IDs');
    expectContext($input()['cooking_context']===[],'First chat begins without invented preferences');
    $schema=end($payloads)['text']['format']['schema'];
    expectContext(in_array('context_updates',$schema['required'],true) && $schema['properties']['context_updates']['items']['additionalProperties']===false,'AI must return explicit, strictly structured deltas');
    $serialized=file_get_contents($profile('alice'));
    expectContext(!str_contains($serialized,'evidence') && !str_contains($serialized,'pending_chat'),'Durable context contains compact facts, not raw evidence or retained chat history');
    $chat=json_decode(file_get_contents($chatPath($first)),true);
    expectContext(!str_contains($chat['messages'][1]['content'],'context_updates'),'Historical assistant messages do not replay stale preference deltas');

    $updates=[contextAdd('self','Prefers roasting.','I prefer roasting.')];
    $fresh=new RecipeChatService($config,$directory,$transport);
    $fresh->handle($body('I prefer roasting.'),'alice','house-one',null,[]);
    expectContext(count($input()['cooking_context'])===2,'A fresh worker receives facts learned in a previous conversation');
    expectContext(array_column($input()['cooking_context'],'person')===['self','my son Alex'],'Family preference stays attributed to Alex');
    foreach ($input()['cooking_context'] as $fact) expectContext(!array_key_exists('household',$fact),'Provider sees no internal household binding');
    expectContext(!str_contains(json_encode(end($payloads)),'house-one') && !str_contains(json_encode(end($payloads)),'alice'),'Context input omits account and household identifiers');

    $updates=[];
    $hypothetical=$send('What if I remove the mushrooms just for tonight?');
    expectContext($hypothetical['context_updates']===[] && $hypothetical['context_revision']===2,'Discussion with no delta leaves enduring preferences unchanged');
    expectContext(str_contains(end($payloads)['instructions'],'one-off recipe instructions') && str_contains(end($payloads)['instructions'],'Current explicit requests override remembered preferences'),'Prompt separates enduring preferences from one-dish exceptions and hypotheticals');
    $send('Suggest something.','bob');
    expectContext($input()['cooking_context']===[],'Another user in the same household gets no personal or family context');
    $send('Suggest something.','alice','house-two');
    expectContext(count($input()['cooking_context'])===2 && array_unique(array_column($input()['cooking_context'],'person'))===['self'],'Own preferences follow the user; family details stay in their household');

    $updates=[contextChange('update',$self,'Likes mushrooms.','I like mushrooms now.')];
    $changed=$send('I like mushrooms now.');
    expectContext($changed['context_revision']===3 && $changed['context_updates'][0]['id']===$self,'A correction updates the same fact rather than retaining a contradiction');
    $callsBefore=$calls;
    expectContext($service->handle($first,'alice','house-one',null,[])===$learned && $calls===$callsBefore,'Lost-response replay returns its original response without another provider call');
    $updates=[]; $send('What shall I cook?');
    expectContext(in_array('Likes mushrooms.',array_column($input()['cooking_context'],'preference'),true) && !in_array('Dislikes mushrooms.',array_column($input()['cooking_context'],'preference'),true),'Replaying an old turn never overwrites a newer correction');

    $updates=[contextChange('remove',$alex,null,"Forget Alex's dislike of cilantro.")];
    $forgotten=$send("Forget Alex's dislike of cilantro.");
    expectContext($forgotten['context_revision']===4 && $forgotten['context_updates'][0]['action']==='remove','A forget request removes the attributed preference');
    $updates=[]; $send('What shall I cook?');
    expectContext(count($input()['cooking_context'])===2 && !str_contains(json_encode($input()['cooking_context']),'cilantro'),'Future chats omit forgotten context');
    foreach (glob($directory.'/recipe-chats/*.json') as $file) unlink($file);
    (new RecipeChatService($config,$directory,$transport))->handle($body('A fresh conversation.'),'alice','house-one',null,[]);
    expectContext(count($input()['cooking_context'])===2,'Deleting working chat caches or restarting the app does not delete durable preferences');
    $spoof=$body('Suggest dinner.')+['user_id'=>'bob','cooking_context'=>[['person'=>'self','preference'=>'CLIENT INJECTION']]];
    $service->handle($spoof,'alice','house-one',null,[]);
    expectContext(!str_contains(json_encode($input()),'CLIENT INJECTION') && count($input()['cooking_context'])===2,'Client-supplied identity and context are not authoritative');

    $updates=[contextAdd('my partner Pat','Avoids peanuts.','My partner Pat avoids peanuts.')];
    $otherFamily=$send('My partner Pat avoids peanuts.','alice','house-two')['context_updates'][0]['id'];
    $before=file_get_contents($profile('alice'));
    foreach ([
        null,
        ['action'=>'add'],
        [contextAdd('self','Invented preference.','not in this message')],
        [contextChange('update',str_repeat('f',32),'Unknown fact.','Evidence')],
        [contextChange('remove',$otherFamily,null,'Evidence')],
        [contextAdd(str_repeat('a',81),'Too long.','Evidence')],
        [contextAdd('self',str_repeat('a',501),'Evidence')],
        [contextAdd('self','Unexpected field.','Evidence')+['extra'=>true]],
        [contextChange('update',$self,null,'Evidence')],
        [contextChange('remove',$self,'Not null.','Evidence')],
        array_fill(0,101,contextAdd('self','Too many.','Evidence')),
    ] as $invalid) {
        $updates=$invalid; $request=$body('Evidence');
        contextError(fn()=>$service->handle($request,'alice','house-one',null,[]),'invalid_ai_reply');
        expectContext(file_get_contents($profile('alice'))===$before && !is_file($chatPath($request)),'Invalid deltas change neither context nor chat revision');
    }
    $omitUpdates=true; $request=$body('Missing deltas');
    contextError(fn()=>$service->handle($request,'alice','house-one',null,[]),'invalid_ai_reply');
    $omitUpdates=false;
    $updates=[contextAdd('self','Likes soup.','I like soup.')];
    $denied=new RecipeChatService($config+['before_recipe_chat_commit'=>static function(){ throw new HttpError(403,'account_disabled','Account unavailable.'); }],$directory,$transport);
    contextError(fn()=>$denied->handle($body('I like soup.'),'alice','house-one',null,[]),'account_disabled');
    expectContext(file_get_contents($profile('alice'))===$before,'Live authorization rejection prevents durable learning');

    $updates=[];
    $held=new RecipeUserContext($directory,'alice');
    $callsBefore=$calls;
    contextError(fn()=>$send('Another simultaneous chat.'),'chat_in_progress');
    expectContext($calls===$callsBefore,'Simultaneous chats for one account wait without duplicate provider work');
    $send('Other accounts can keep chatting.','bob');
    expectContext($calls===$callsBefore+1,'One account lock never blocks another account');
    $held->close();

    // Simulate a crash/write failure AFTER preferences commit but BEFORE chat-cache commit.
    $recover=$body('I dislike peas.'); $recoverPath=$chatPath($recover,'recovery'); mkdir($recoverPath,0700);
    $updates=[contextAdd('self','Dislikes peas.','I dislike peas.')];
    contextError(fn()=>$service->handle($recover,'recovery','house-one',null,[]),'ai_interrupted');
    $pending=json_decode(file_get_contents($profile('recovery')),true);
    expectContext(isset($pending['pending_chat']) && $pending['revision']===1,'An interrupted two-file commit retains recoverable turn state');
    rmdir($recoverPath);
    $updates=[contextAdd('self','Likes roasting.','I like roasting.')];
    $send('I like roasting.','recovery');
    expectContext(count($input()['cooking_context'])===1 && $input()['cooking_context'][0]['preference']==='Dislikes peas.','A different conversation repairs the earlier turn before reading context');
    $callsBefore=$calls;
    $replayed=$service->handle($recover,'recovery','house-one',null,[]);
    expectContext($calls===$callsBefore && $replayed['context_revision']===1,'Retry recovers the completed original response, never regenerates AI');
    $recovered=json_decode(file_get_contents($profile('recovery')),true);
    expectContext(!isset($recovered['pending_chat']) && $recovered['revision']===2 && count($recovered['facts'])===2,'Recovery and stale replay preserve newer facts without duplicates');

    $bounded=new RecipeUserContext($directory,'bounded');
    for ($start=0;$start<100;$start+=12) {
        $batch=[];
        for ($i=$start;$i<min(100,$start+12);$i++) $batch[]=contextAdd('self','Cooking preference '.$i,'Evidence');
        $next=$bounded->propose($batch,'Evidence','house-one');
        $bounded->commit($next,hash('sha256','bounded-chat'),['revision'=>1]);
    }
    expectContext(count($bounded->forHousehold('house-one'))===100,'The full bounded context remains intact');
    contextError(fn()=>$bounded->propose([contextAdd('self','One too many.','Evidence')],'Evidence','house-one'),'invalid_recipe_chat');
    $firstFact=$bounded->forHousehold('house-one')[0];
    $next=$bounded->propose([contextChange('remove',$firstFact['id'],null,'Evidence'),contextAdd('self','Replacement.','Evidence')],'Evidence','house-one');
    expectContext(count($next['facts'])===100,'Forgetting can make room without silently dropping unrelated facts');
    $clear=array_map(static fn($fact)=>contextChange('remove',$fact['id'],null,'Forget all my preferences.'),$bounded->forHousehold('house-one'));
    $empty=$bounded->propose($clear,'Forget all my preferences.','house-one');
    expectContext($empty['facts']===[] && count($empty['updates'])===100,'A single explicit forget request can remove the full visible context');
    $bounded->close();

    file_put_contents($profile('corrupt'),'{invalid');
    $callsBefore=$calls;
    try { $send('Do not overwrite corrupt storage.','corrupt'); throw new LogicException('Corrupt context accepted'); }
    catch (JsonException $error) { expectContext(file_get_contents($profile('corrupt'))==='{invalid' && $calls===$callsBefore,'Corrupt stored context is preserved, never silently reset'); }
    foreach (glob($directory.'/recipe-user-context/*') as $file) expectContext((fileperms($file)&0777)===0600 || str_contains($file,hash('sha256','corrupt')),'Context and lock files are private');
    expectContext((fileperms($directory.'/recipe-user-context')&0777)===0700,'Context directory is private');
} finally {
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($directory);
}
echo "$checks cooking context checks passed\n";
