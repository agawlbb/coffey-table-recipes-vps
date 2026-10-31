<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/HttpError.php';
require dirname(__DIR__).'/src/RecipeImportService.php';
require dirname(__DIR__).'/src/RecipeChatService.php';
require dirname(__DIR__).'/src/RecipeVariationService.php';

$checks=0;
function checkChat(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function chatError(callable $run,string $code): void {
    try { $run(); } catch (HttpError $e) { checkChat($e->errorCode===$code,'Expected '.$code.', got '.$e->errorCode); return; }
    throw new RuntimeException('Expected '.$code);
}
$directory=sys_get_temp_dir().'/ctr-chat-'.bin2hex(random_bytes(6)); mkdir($directory,0700);
$payloads=[]; $failure=false; $malformed=false; $clarify=false;
$transport=static function(array $payload) use (&$payloads,&$failure,&$malformed,&$clarify): array {
    $payloads[]=$payload;
    if ($failure) return ['status'=>503,'body'=>'{}'];
    if ($malformed) return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[]])];
    if (($payload['text']['format']['name']??'')==='recipe_chat') {
        $latest=$payload['input'][count($payload['input'])-1]['content'];
        $result=['message'=>str_contains($latest,'What if')?'Removing eggs would weaken the structure. I have kept them in the recipe.':'Here is a practical soup. You can keep refining it or review when ready.',
            'ready'=>true,'recipe_text'=>'Egg soup. 2 eggs and 2 cups water. Boil water, add beaten eggs, and simmer for 2 minutes. Serves 2.'];
    } else $result=['recipe'=>['title'=>'Egg soup','original_language'=>'en','ingredients'=>[['original_text'=>'2 eggs','ingredient_name'=>'eggs']],'steps'=>[['instruction'=>'Simmer for 2 minutes.','actions'=>[['action_type'=>'timer','duration_seconds'=>120]]]]],
        'variation_label'=>'refined','change_description'=>'Refined the soup as agreed.',
        'parent_cover_appropriate'=>true,'parent_cover_reason'=>'The pictured soup still matches.',
        'questions'=>$clarify?['How much salt?']:[]];
    return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode($result)]]]]])];
};
$service=new RecipeChatService(['api_key'=>'fixture-only','model'=>'fixture-model'],$directory,$transport);
$body=['request_id'=>str_repeat('a',32),'turn_id'=>str_repeat('1',32),'mode'=>'create','action'=>'message','message'=>'Create egg soup','revision'=>0];
$source=['id'=>'7','title'=>'Egg soup','revision'=>3,'household_id'=>'PRIVATE HOUSEHOLD','created_by_user_id'=>'PRIVATE USER','source_text'=>'PRIVATE SOURCE',
    'source_type'=>'ai_text','parent_recipe_id'=>'4','household_count'=>2,'category_assignments'=>[['category_id'=>'8']],
    'photos'=>[['id'=>'33','storage_key'=>'PRIVATE PHOTO']],'pairings'=>[['id'=>'9']],
    'ingredients'=>[['id'=>'PRIVATE INGREDIENT','original_text'=>'2 eggs','ingredient_name'=>'eggs','group_id'=>'PRIVATE GROUP']],
    'groups'=>[['id'=>'PRIVATE GROUP','title'=>'Soup']],
    'steps'=>[['id'=>'PRIVATE STEP','instruction'=>'Simmer.']],
    'actions'=>[['id'=>'PRIVATE ACTION','step_id'=>'PRIVATE STEP','action_type'=>'timer','duration_seconds'=>120]]];
try {
    $concurrentBody=array_replace($body,['request_id'=>str_repeat('f',32)]);
    $concurrent=null; $concurrentCalls=0;
    $concurrentTransport=static function(array $payload) use (&$concurrent,&$concurrentCalls,$concurrentBody,$transport): array {
        $concurrentCalls++;
        chatError(fn()=>$concurrent->handle($concurrentBody,'1','2',null,[]),'chat_in_progress');
        return $transport($payload);
    };
    $concurrent=new RecipeChatService(['api_key'=>'fixture-only','model'=>'fixture-model'],$directory,$concurrentTransport);
    $completed=$concurrent->handle($concurrentBody,'1','2',null,[]);
    checkChat($concurrent->handle($concurrentBody,'1','2',null,[])===$completed && $concurrentCalls===1,'Concurrent/recovered requests never duplicate provider work');
    $payloads=[];
    chatError(fn()=>$service->handle(array_replace($body,['action'=>'prepare']),'1','2',null,[]),'chat_not_ready');
    $first=$service->handle($body,'1','2',null,[]);
    checkChat($first['ready'] && $first['revision']===1 && !isset($first['draft']),'Chat readiness does not generate or save a recipe');
    checkChat($payloads[0]['store']===false && $payloads[0]['model']==='fixture-model','Configured model with provider storage disabled');
    checkChat(str_contains($payloads[0]['instructions'],'NOT permission') && str_contains($payloads[0]['instructions'],'until the user accepts'),'Instructions distinguish exploration from agreement');
    checkChat($service->handle($body,'1','2',null,[])===$first && count($payloads)===1,'Lost-response retry is cached without duplicate provider work');
    chatError(fn()=>$service->handle(array_replace($body,['message'=>'Changed']),'1','2',null,[]),'chat_turn_changed');
    $second=array_replace($body,['turn_id'=>str_repeat('2',32),'revision'=>1,'message'=>'What if I remove the eggs?']);
    $reply=$service->handle($second,'1','2',null,[]);
    checkChat(str_contains($reply['message'],'kept them') && count($payloads[1]['input'])===4,'Follow-up keeps the earlier conversation and explains effects');
    $prepare=array_replace($body,['turn_id'=>str_repeat('3',32),'revision'=>2,'action'=>'prepare','message'=>'','recipe'=>['id'=>'999']]);
    $draft=$service->handle($prepare,'1','2',null,[]);
    checkChat($draft['draft']['recipe']['source_type']==='ai_created' && !isset($draft['draft']['recipe']['id']),'New dish has machine provenance and no saved identity');
    $importPayload=json_encode($payloads[2]);
    checkChat(str_contains($importPayload,'2 eggs') && !str_contains($importPayload,'What if'),'Preparation uses agreed specification, never speculative transcript');
    $before=count($payloads);
    checkChat($service->handle($prepare,'1','2',null,[])===$draft && count($payloads)===$before,'Repeated preparation preserves import identity');
    chatError(fn()=>$service->handle(array_replace($body,['turn_id'=>str_repeat('4',32)]),'1','2',null,[]),'chat_out_of_date');
    chatError(fn()=>$service->handle(array_replace($body,['mode'=>'edit']),'1','2',$source,[]),'chat_outcome_changed');
    foreach (['variation','edit'] as $mode) {
        $edit=array_replace($body,['request_id'=>str_repeat($mode==='edit'?'e':'b',32),'mode'=>$mode]);
        $service->handle($edit,'1','2',$source,[]);
        $sent=json_encode(end($payloads));
        foreach (['PRIVATE HOUSEHOLD','PRIVATE USER','PRIVATE SOURCE','PRIVATE PHOTO','PRIVATE INGREDIENT','PRIVATE STEP','PRIVATE GROUP','PRIVATE ACTION'] as $private) checkChat(!str_contains($sent,$private),'Only cooking content reaches provider');
        checkChat(str_contains($sent,'Soup') && str_contains($sent,'120'),'Group and structured timer context retained');
        $finish=array_replace($edit,['action'=>'prepare','message'=>'','revision'=>1,'turn_id'=>str_repeat('2',32)]);
        $result=$service->handle($finish,'1','2',$source,[])['draft']['recipe'];
        if ($mode==='variation') {
            checkChat($result['parent_recipe_id']==='7' && $result['source_type']==='ai_variation' && !isset($result['id']),'New variation is bound to authorized parent');
            checkChat($result['photos']===[],'Variation never blindly inherits the parent photograph');
        } else {
            checkChat($result['id']==='7' && $result['revision']===3 && $result['household_count']===2,'Edit retains revision and shared-edit safeguards');
            foreach (['photos','source_type','source_text','category_assignments','parent_recipe_id','pairings'] as $field) checkChat($result[$field]===$source[$field],'Edit preserves '.$field);
            checkChat($result['nutrition']===null && $result['steps'][0]['id']!==$source['steps'][0]['id'],'Changed cooking details replace stale actions and nutrition');
        }
    }
    $image=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGNoaGgAAAMEAYFL09IQAAAAAElFTkSuQmCC');
    $withCover=array_replace($source,['photos'=>[['id'=>'99','storage_key'=>'media/recipes/'.str_repeat('a',48).'.png','is_cover'=>1,'attribution'=>'Original photographer']]]);
    $coverService=new RecipeChatService(['api_key'=>'fixture-only','model'=>'fixture-model','variation_access'=>'disabled'],$directory,$transport,static fn($key)=>$image);
    $coverBody=array_replace($body,['request_id'=>str_repeat('6',32),'mode'=>'variation']);
    $coverService->handle($coverBody,'1','2',$withCover,[]);
    $covered=$coverService->handle(array_replace($coverBody,['action'=>'prepare','message'=>'','revision'=>1,'turn_id'=>str_repeat('2',32)]),'1','2',$withCover,[])['draft'];
    checkChat($covered['recipe']['photos'][0]['image_base64']===base64_encode($image),'Approved parent cover is retained through chat preparation');
    checkChat($covered['recipe']['photos'][0]['attribution']==='Original photographer','Cover attribution is preserved');
    checkChat($covered['recipe']['title']==='Egg soup - refined' && !empty($covered['change_description']),'Existing variation title and review explanation remain');
    checkChat(str_contains(end($payloads)['instructions'],'complete recipe already agreed'),'Variation preparation is constrained to accepted cooking decisions');
    $changed=array_replace($body,['request_id'=>str_repeat('c',32),'mode'=>'edit']);
    $service->handle($changed,'1','2',$source,[]);
    chatError(fn()=>$service->handle(array_replace($changed,['turn_id'=>str_repeat('2',32),'revision'=>1,'action'=>'prepare','message'=>'']),'1','2',array_replace($source,['revision'=>4]),[]),'chat_recipe_changed');
    $retry=array_replace($body,['request_id'=>str_repeat('d',32)]);
    $failure=true; chatError(fn()=>$service->handle($retry,'1','2',null,[]),'ai_unavailable');
    $failure=false; $malformed=true; chatError(fn()=>$service->handle($retry,'1','2',null,[]),'invalid_ai_reply');
    $malformed=false;
    checkChat($service->handle($retry,'1','2',null,[])['revision']===1,'Provider failures do not advance or duplicate the conversation');
    checkChat($service->handle($body,'other-user','2',null,[])['revision']===1,'Session IDs are isolated by actor');
    checkChat($service->handle($body,'1','other-household',null,[])['revision']===1,'Session IDs are isolated by household');
    $clarify=true;
    $question=$service->handle(array_replace($retry,['turn_id'=>str_repeat('2',32),'revision'=>1,'action'=>'prepare','message'=>'']),'1','2',null,[]);
    checkChat(!$question['ready'] && !isset($question['draft']) && str_contains($question['message'],'salt'),'Importer clarifications return to the chat without saving');
    foreach ([['message'=>str_repeat('x',4001)],['revision'=>-1],['mode'=>'unknown'],['request_id'=>'../bad'],['action'=>'save']] as $invalid) {
        chatError(fn()=>$service->handle(array_replace($body,$invalid),'1','2',null,[]),'invalid_recipe_chat');
    }
    $limited=array_replace($body,['request_id'=>str_repeat('9',32)]);
    $service->handle($limited,'1','2',null,[]);
    $limitPath=$directory.'/recipe-chats/'.hash('sha256',json_encode(['1','2',$limited['request_id']])).'.json';
    $state=json_decode(file_get_contents($limitPath),true);
    $state['turns']=array_fill(0,60,['fingerprint'=>'fixture','response'=>[]]);
    file_put_contents($limitPath,json_encode($state));
    chatError(fn()=>$service->handle(array_replace($limited,['turn_id'=>str_repeat('2',32),'revision'=>1]),'1','2',null,[]),'chat_limit');
    $clarify=false;
    $finalPrepare=array_replace($limited,['turn_id'=>str_repeat('2',32),'revision'=>1,'action'=>'prepare','message'=>'']);
    checkChat(isset($service->handle($finalPrepare,'1','2',null,[])['draft']),'A final preparation remains possible at the conversation limit');
    chatError(fn()=>$service->handle(array_replace($finalPrepare,['turn_id'=>str_repeat('3',32),'revision'=>2]),'1','2',null,[]),'chat_limit');
    foreach (glob($directory.'/recipe-chats/*') as $file) checkChat((fileperms($file)&0777)===0600,'Conversation storage is private');
} finally {
    foreach (glob($directory.'/recipe-chats/*') as $file) unlink($file);
    @rmdir($directory.'/recipe-chats');
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($directory);
}
echo "$checks recipe chat checks passed\n";
