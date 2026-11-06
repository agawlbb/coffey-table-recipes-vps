<?php
declare(strict_types=1);
$directory=sys_get_temp_dir().'/ctr-cooking-'.bin2hex(random_bytes(8));
putenv('CTR_STORAGE='.$directory);
require __DIR__.'/fixtures/data.php';
function expectCooking(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
function rejectCooking(callable $call,int $status): void { try { $call(); throw new RuntimeException('Expected rejection'); } catch(HttpError $e) { expectCooking($e->status===$status,$e->getMessage()); } }
$now=1800000000000; $pushes=[];
$service=new CookingSessions($pdo,function($d,$p)use(&$pushes){$pushes[]=[$d,$p];return true;},$directory.'/cooking',function()use(&$now){return $now;});
$pdo->exec("INSERT INTO household_memberships VALUES(1,2,'member'); INSERT INTO household_people VALUES(20,1,2,'Bob');
INSERT INTO recipe_step_actions(id,household_id,recipe_id,step_id,position,label,duration_seconds) VALUES(10,1,1,1,1,'Simmer',60);
INSERT INTO recipe_steps(id,household_id,recipe_id,position,instruction) VALUES(3,1,1,2,'Serve');
UPDATE recipes SET prep_minutes=10,cook_minutes=20,total_minutes=40 WHERE id=1;");
$a=str_repeat('a',32);$b=str_repeat('b',32);$c=str_repeat('c',32);$id=str_repeat('1',32);
$call=function($action,$body=[],$user='1',$device=null)use($service,$a,$id){return $service->request($user,$user==='1'?'alice':'bob','/me/cooking-sessions/'.$id.'/'.$action,$body+['installation_id'=>$device??$a,'operation_id'=>bin2hex(random_bytes(16))]);};
$create=['session_id'=>$id,'household_id'=>'1','recipe_id'=>'1','installation_id'=>$a,'operation_id'=>str_repeat('f',32)];
try {
 $s=$service->request('1','alice','/me/cooking-sessions',$create)['session'];
 expectCooking($s['idle_ms']===6000000,'Total time used without double-counting prep/cook');
 expectCooking($s['joined_devices']===[$a],'Origin automatically joins');
 expectCooking(!isset($s['recipe']['source_text'],$s['operations'],$s['users']),'Snapshot contains cooking content only');
 expectCooking($service->request('1','alice','/me/cooking-sessions',$create)['session']['revision']===$s['revision'],'Create replay does not create a second session');
 expectCooking(count($service->snapshot('1')['sessions'])===1,'Same-account other devices discover sessions');
 expectCooking($service->snapshot('2')['sessions']===[],'Household membership alone does not expose sessions');
 rejectCooking(fn()=>$call('join',[],'2',$c),404);
 $pdo->prepare('INSERT INTO timer_devices(installation_id,user_id,platform,push_token,receive_timers,updated_at) VALUES(?,?,?,?,?,CURRENT_TIMESTAMP)')->execute([$c,'2','ios',str_repeat('token',8),0]);
 $call('share',['person_id'=>'20']);
 expectCooking(count($pushes)===1 && $pushes[0][1]['kind']==='cooking_invitation','Share sends an invitation even before device joins');
 expectCooking(count($service->snapshot('2')['sessions'])===1,'Invited session remains discoverable');
 $pdo->prepare('UPDATE timer_devices SET receive_cooking_invitations=0 WHERE installation_id=?')->execute([$c]);
 $call('share',['person_id'=>'20']);
 expectCooking(count($pushes)===1,'Disabled invitation preference suppresses background push');
 expectCooking(count($service->snapshot('2')['sessions'])===1,'Disabling alerts preserves access to shared sessions');
 // Keep the invitation preference disabled: joined timer updates must still arrive.

 $call('join',[],'2',$c); $call('join',[],'1',$b);
 expectCooking($service->snapshot('2')['sessions'][0]['joined_devices']===[$c],'Joined device list is account private');
 $call('step',['step_id'=>'1','checked'=>true,'revision'=>0]);
 $call('step',['step_id'=>'3','checked'=>true,'revision'=>0],'2',$c);
 expectCooking(count($service->snapshot('1')['sessions'][0]['completed'])===2,'Different steps merge independently');
 $call('step',['step_id'=>'1','checked'=>false,'revision'=>0]);
 $call('step',['step_id'=>'1','checked'=>false,'revision'=>1],'2',$c);
 expectCooking($service->snapshot('1')['sessions'][0]['completed']===['3'],'Unchecking propagates');
 $timer=['action_id'=>'10','deadline_ms'=>$now+9000000,'cancelled'=>false,'revision'=>0];
 $call('timer',$timer);
 expectCooking(count($pushes)===1,'Initial timer uses WebSocket, not push');
 $timer['deadline_ms']=$now+10000000;$timer['revision']=1;$call('timer',$timer);
 expectCooking(count($pushes)===2 && $pushes[1][1]['session_id']===$id,'Timer edits silently target joined devices');
 $call('timer',$timer);
 // Both owner and invited participants keep the session alive.
 $call('presence',['open'=>false]);$call('presence',['open'=>false],'1',$b);$call('presence',['open'=>false],'2',$c);
 $now+=6000001; expectCooking(count($service->snapshot('1')['sessions'])===1,'Running timer prevents automatic expiry');
 $now+=3999999;expectCooking($service->snapshot('1')['sessions']===[],'Expiry waits until latest timer deadline');
 expectCooking($service->snapshot('2')['ended_sessions'][0]['timers'][0]['cancelled'],'Expiry records timer cancellation');
 rejectCooking(fn()=>$call('join'),409);
 // Missing estimate falls back to an hour, foreground leases protect against a crashed app.
 $id2=str_repeat('2',32);$pdo->exec('UPDATE recipes SET total_minutes=NULL,prep_minutes=NULL,cook_minutes=NULL WHERE id=1');
 $create['session_id']=$id2;$create['operation_id']=str_repeat('e',32);
 $new=$service->request('1','alice','/me/cooking-sessions',$create)['session'];expectCooking($new['idle_ms']===3600000,'Missing estimate uses one hour');
 $now+=3500000;
 $service->request('1','alice','/me/cooking-sessions/'.$id2.'/presence',['installation_id'=>$a,'operation_id'=>bin2hex(random_bytes(16)),'open'=>true]);
 $now+=3500000;expectCooking(count($service->snapshot('1')['sessions'])===1,'Reopening resets expiry');
 $service->request('1','alice','/me/cooking-sessions/'.$id2.'/end',['installation_id'=>$a,'operation_id'=>bin2hex(random_bytes(16))]);
 expectCooking($service->snapshot('1')['sessions']===[],'Explicit end removes session');
 // Range metadata survives session snapshots, and only one device can consume it.
 $rangeId=str_repeat('3',32);$create['session_id']=$rangeId;$create['operation_id']=bin2hex(random_bytes(16));
 $service->request('1','alice','/me/cooking-sessions',$create);
 $range=function($action,$body=[],$device=null)use($service,$rangeId,$a){return $service->request('1','alice','/me/cooking-sessions/'.$rangeId.'/'.$action,$body+['installation_id'=>$device??$a,'operation_id'=>bin2hex(random_bytes(16))]);};
 $range('join',[],$b);
 $range('timer',['action_id'=>'10','deadline_ms'=>$now+60000,'extension_seconds'=>120,'cancelled'=>false,'revision'=>0]);
 $now+=60001;
 $snapshot=$service->snapshot('1')['sessions'][0];
 expectCooking($snapshot['timers'][0]['extension_seconds']===120,'Expired session range retains its unused extension');
 $extend=['action_id'=>'10','deadline_ms'=>$now+120000,'extension_seconds'=>0,'extend'=>true,'cancelled'=>false,'revision'=>1,'operation_id'=>bin2hex(random_bytes(16))];
 $extended=$range('timer',$extend)['session']['timers'][0];
 expectCooking($extended['extension_seconds']===0 && $extended['revision']===2,'Session extension consumed once');
 expectCooking($range('timer',$extend)['session']['timers'][0]===$extended,'Extension retry is idempotent');
 $competing=$extend;$competing['operation_id']=bin2hex(random_bytes(16));
 rejectCooking(fn()=>$range('timer',$competing,$b),409);
 $competing['revision']=2;rejectCooking(fn()=>$range('timer',$competing,$b),409);
 $range('timer',['action_id'=>'10','cancelled'=>true,'revision'=>2]);
 expectCooking($service->snapshot('1')['sessions'][0]['timers'][0]['extension_seconds']===0,'Cancellation cannot restore the range');
 // Notification actions bind to the old deadline, not a possibly unacknowledged local revision.
 $nativeId=str_repeat('4',32);$create['session_id']=$nativeId;$create['operation_id']=bin2hex(random_bytes(16));
 $service->request('1','alice','/me/cooking-sessions',$create);
 $native=function($body)use($service,$nativeId,$a){return $service->request('1','alice','/me/cooking-sessions/'.$nativeId.'/timer',$body+['installation_id'=>$a,'operation_id'=>bin2hex(random_bytes(16))]);};
 $minimum=$now+1000;
 $native(['action_id'=>'10','deadline_ms'=>$minimum,'extension_seconds'=>120,'cancelled'=>false,'revision'=>0]);$now+=1001;
 $extended=$native(['action_id'=>'10','deadline_ms'=>$now+120000,'extension_seconds'=>0,'extend'=>true,'cancelled'=>false,'revision'=>0,'expected_deadline_ms'=>$minimum])['session']['timers'][0];
 expectCooking($extended['revision']===2 && $extended['extension_seconds']===0,'Native extension survives an unacknowledged start');
 rejectCooking(fn()=>$native(['action_id'=>'10','deadline_ms'=>$now+120000,'extension_seconds'=>0,'extend'=>true,'cancelled'=>false,'revision'=>0,'expected_deadline_ms'=>$minimum]),409);
 $stopped=$native(['action_id'=>'10','cancelled'=>true,'revision'=>0,'expected_deadline_ms'=>$extended['deadline_ms']])['session']['timers'][0];
 expectCooking($stopped['cancelled'] && $stopped['extension_seconds']===0,'Notification Stop clears timer and remainder');
 $native(['action_id'=>'10','cancelled'=>false,'deadline_ms'=>$now+300000,'revision'=>3]);
 $native(['action_id'=>'10','cancelled'=>true,'revision'=>0,'expected_deadline_ms'=>$extended['deadline_ms']]);
 // The menu can end a discoverable session without joining the current device.
 foreach ([['1',$b],['2',$c]] as $index=>[$ender,$device]) {
  $swipeId=str_repeat((string)($index+5),32);$create['session_id']=$swipeId;$create['operation_id']=bin2hex(random_bytes(16));
  $service->request('1','alice','/me/cooking-sessions',$create);
  $swipe=function($action,$body=[],$user='1',$device=null)use($service,$swipeId,$a){return $service->request($user,$user==='1'?'alice':'bob','/me/cooking-sessions/'.$swipeId.'/'.$action,$body+['installation_id'=>$device??$a,'operation_id'=>bin2hex(random_bytes(16))]);};
  rejectCooking(fn()=>$swipe('end',[],'2',$c),404);
  $swipe('share',['person_id'=>'20']);
  rejectCooking(fn()=>$swipe('timer',['action_id'=>'10','cancelled'=>true,'revision'=>0],$ender,$device),409);
  $swipe('timer',['action_id'=>'10','deadline_ms'=>$now+60000,'cancelled'=>false,'revision'=>0]);
  $ended=$swipe('end',[],$ender,$device)['session'];
  expectCooking($ended['ended_at']!==null && $ended['timers'][0]['cancelled'],'Unjoined authorized device ends session and cancels timers');
  expectCooking(!in_array($device,$ended['joined_devices'],true),'Ending does not join or mirror alarms');
 }
 // Access is rechecked after household removal.
 $pdo->exec('DELETE FROM household_memberships WHERE user_id=2');expectCooking($service->snapshot('2')['sessions']===[] && $service->snapshot('2')['ended_sessions']===[],'Removed members lose access');
 echo "Cooking sessions: discovery, invitation, joins, steps, timers, replay, conflicts, expiry and membership passed.\n";
} finally {
 foreach(glob($directory.'/cooking/*')?:[] as $p) unlink($p);
 @rmdir($directory.'/cooking');@rmdir($directory);
}
