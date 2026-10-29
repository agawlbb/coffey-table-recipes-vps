<?php
declare(strict_types=1);
require __DIR__.'/fixtures/data.php';
function checkData(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejectData(callable $call,int $status): void {
    try { $call(); throw new RuntimeException('Expected rejection'); }
    catch (HttpError $e) { checkData($e->status===$status,$e->getMessage()); }
}
$root='/households/1';
$call=fn($path,$body=null)=>$socket->handle('alice','1',$root.$path,$body,'household');
$actor=$socket->authenticate('alice','1','household');
$ready=$socket->snapshot($actor);
checkData(isset($ready['shopping']['items'],$ready['library']['responses']), 'Combined durable initial snapshot');
checkData($call('/recipes/1')['recipe']['title']==='Soup','Authenticated recipe read');
checkData(count($call('/recipes?q=Soup')['recipes'])===1,'Query preserved');
rejectData(fn()=>$call('/recipes?offset[]=1'),400);
rejectData(fn()=>$socket->handle('bob','1',$root.'/recipes',null,'household'),404);
rejectData(fn()=>$socket->handle('disabled','1',$root.'/recipes',null,'household'),403);
rejectData(fn()=>$socket->handle('alice','1','/households/2/recipes',null,'household'),404);
$token=$connections->create('1')['token'];
rejectData(fn()=>$socket->authenticate($token,'1','household'),403);
rejectData(fn()=>$socket->handle('alice',null,'/me/timers',null,'public'),404);
checkData($socket->handle('',null,'/recipes/1',null,'public')['recipe']['title']==='Soup','Public shared recipe over socket');
rejectData(fn()=>$socket->handle('',null,'/recipes/1',['title'=>'forged'],'public'),404);
checkData($call('/name',['name'=>'New table'])['household']['name']==='New table','Household rename');
checkData($call('/people/me/name',['display_name'=>'Alice'])['person']['display_name']==='Alice','Own-person rename');
checkData($call('/people',['display_name'=>'Sam'])['person']['display_name']==='Sam','Add household person');
checkData(count($call('/people')['people'])===2,'Household people read');
rejectData(fn()=>$call('/people',['display_name'=>'']),422);
$blank=$call('/recipe-blank',[])['recipe'];
$body=$blank+['client_save_id'=>str_repeat('a',32),'description'=>'Simple soup','groups'=>[],'ingredients'=>[],
    'steps'=>[],'actions'=>[],'photos'=>[],'tags'=>[],'category_assignments'=>[['category_id'=>'11']],'pairings'=>[],'nutrition'=>null];
$body['title']='New soup';
$saved=$call('/recipes',$body)['recipe'];
checkData($saved['title']==='New soup','Recipe add');
checkData($call('/recipes',$body)['recipe']===$saved,'Lost response replays immutable save receipt');
$edit=$saved; $edit['title']='Edited soup';$edit['client_save_id']=str_repeat('b',32);
$edited=$call('/recipes/'.$saved['id'],$edit)['recipe'];
checkData($edited['title']==='Edited soup','Recipe edit');
$edit['client_save_id']=str_repeat('c',32);
rejectData(fn()=>$call('/recipes/'.$saved['id'],$edit),409);
checkData($call('/recipes/'.$saved['id'])['recipe']['title']==='Edited soup','Stale edit cannot overwrite');
$plan=['revision'=>0,'operation'=>'add','id'=>'meal1','date'=>'2099-01-01','kind'=>'recipe','recipe_id'=>$saved['id']];
checkData($call('/meal-plan',$plan)['revision']===1,'Meal plan write');
checkData(count($call('/meal-plan?from=2099-01-01&to=2099-01-02')['entries'])===1,'Meal plan read');
rejectData(fn()=>$call('/meal-plan',$plan),409);
$plan['revision']=1;$plan['id']='meal2';unset($plan['date']);
rejectData(fn()=>$call('/meal-plan',$plan),422);
$device=str_repeat('d',32);
$socket->handle('alice',null,'/me/timer-devices',['installation_id'=>$device,'platform'=>'android','receive_timers'=>false,'push_token'=>null],'timers');
$timer=['installation_id'=>$device,'timer_id'=>$device.':soup','operation_id'=>str_repeat('e',32),
    'revision'=>0,'title'=>'Soup','deadline_ms'=>(int)(microtime(true)*1000)+600000,'cancelled'=>false];
$sent=$socket->handle('alice',null,'/me/timers',$timer,'timers');
checkData($sent['timer']['revision']===1,'Timer write');
checkData($socket->handle('alice',null,'/me/timers',$timer,'timers')['timer']===$sent['timer'],'Timer response replay');
checkData(count($socket->snapshot($socket->authenticate('alice',null,'timers'))['timers'])===1,'Account timer snapshot');
checkData($socket->handle('bob',null,'/me/timers',null,'timers')['timers']===[],'No other-account timer exposure');
$pdo->exec('DELETE FROM household_memberships WHERE user_id=1');
rejectData(fn()=>$call('/recipes'),404);
echo "Data socket: recipes/add/edit/replay, meal dates/conflicts, timer isolation/replay and access revocation passed\n";
