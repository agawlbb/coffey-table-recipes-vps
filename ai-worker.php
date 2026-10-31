<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
// stdin/stdout are private pipes. Never include tokens, provider output or source text in logs.
$emit=static function(array $event): void { echo json_encode($event,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE)."\n"; flush(); };
try {
    $request=json_decode(stream_get_contents(STDIN,8*1024*1024+1),true,32,JSON_THROW_ON_ERROR);
    $pdo ??=runtimeDatabase(); $verify ??=runtimeVerifier(); $config ??=runtimeAiConfig(); $transport ??=null;
    $progress=static fn(string $message)=>$emit(['type'=>'progress','message'=>$message]);
    $categorize=static function($token,$household,$title) use ($pdo,$config,$progress,$verify,$transport): array {
        $identity=$verify($token);
        $q=$pdo->prepare('SELECT id FROM users WHERE firebase_uid=? AND disabled=0'); $q->execute([$identity['uid']]); $user=(string)$q->fetchColumn();
        $catalog=(new RecipeService($pdo,new HouseholdService($pdo)))->editOptions($user,$household)['categories'];
        return (new BlankRecipeCategoryService(array_replace($config,['progress'=>$progress]),CTR_STORAGE,$transport))->categorize($title,$catalog,$user);
    };
    $service=new DataSocket($pdo,$verify,new ShoppingSocket($pdo,$verify,new HomeAssistantConnections($pdo),new ShoppingSyncRequests(CTR_STORAGE.'/shopping-sync-requests')),null,$categorize);
    $actor=$service->authenticate($request['token'],$request['household'],$request['scope']);
    $data=AiService::matches($request['path'])
        ? (new AiService($pdo,$config,CTR_STORAGE,$progress,$transport??null))->handle($actor,$request['path'],$request['body'])
        : $service->handle($request['token'],$request['household'],$request['path'],$request['body'],$request['scope']);
    // Check live account/membership again before returning a long-running result.
    $service->authenticate($request['token'],$request['household'],$request['scope']);
    $emit(['type'=>'response','data'=>$data]);
} catch (HttpError $e) {
    $emit(['type'=>'error','status'=>$e->status,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]]);
} catch (Throwable $e) {
    $emit(['type'=>'error','status'=>503,'error'=>['code'=>'ai_unavailable','message'=>'AI could not complete this request. Your input is still here; please try again.']]);
}
