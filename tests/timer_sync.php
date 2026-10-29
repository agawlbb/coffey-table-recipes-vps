<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

// Disposable in-memory SQL only. Never contacts Firebase or a persistent DB.
final class TimerTestDatabase extends PDO
{
    public function prepare(string $query, array $options=[]): PDOStatement|false
    {
        $query=str_replace(' FOR UPDATE','',$query);
        $query=str_replace('DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 60 DAY)',"datetime('now','-60 days')",$query);
        $query=str_replace('DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 7 DAY)',"datetime('now','-7 days')",$query);
        $query=str_replace('ON DUPLICATE KEY UPDATE','ON CONFLICT(installation_id) DO UPDATE SET',$query);
        $query=preg_replace('/VALUES\((\w+)\)/','excluded.$1',$query);
        return parent::prepare($query,$options);
    }
}
function expectTimer(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
function rejectTimer(callable $action, int $status): void {
    try { $action(); throw new RuntimeException('Expected timer rejection'); }
    catch (HttpError $error) { expectTimer($error->status===$status,'Wrong rejection status'); }
}
$pdo=new TimerTestDatabase('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE timer_devices (installation_id TEXT PRIMARY KEY,user_id TEXT,platform TEXT,push_token TEXT,receive_timers INTEGER,updated_at TEXT);
CREATE TABLE shared_timers (user_id TEXT,timer_id TEXT,source_device TEXT,title TEXT,deadline_ms INTEGER,cancelled INTEGER,revision INTEGER,last_operation TEXT,updated_at TEXT,PRIMARY KEY(user_id,timer_id));');
$deliveries=[];
$service=new TimerSyncService($pdo,static function($target,$timer) use (&$deliveries) { $deliveries[]=[$target,$timer]; return true; });
$a=str_repeat('a',32); $b=str_repeat('b',32); $c=str_repeat('c',32); $d=str_repeat('d',32);
$register=static fn($user,$id,$enabled)=>$service->device($user,['installation_id'=>$id,'platform'=>'ios','push_token'=>str_repeat($id[0],100),'receive_timers'=>$enabled]);
$register('1',$a,false); $register('1',$b,true); $register('2',$c,true); $register('1',$d,false);
$body=['installation_id'=>$a,'timer_id'=>$a.':42:step','operation_id'=>str_repeat('1',32),'title'=>'Soup','deadline_ms'=>(int)floor(microtime(true)*1000)+600000,'cancelled'=>false,'revision'=>0];
$result=$service->change('1','firebase-val',$body);
expectTimer($result['timer']['revision']===1,'First revision');
expectTimer(count($deliveries)===1 && $deliveries[0][0]['installation_id']===$b,'Only opted-in same-account destination');
expectTimer($deliveries[0][1]['account_uid']==='firebase-val' && $deliveries[0][1]['target_device']===$b,'Push account binding');
expectTimer($service->list('2')['timers']===[],'Private account timers');
$service->change('1','firebase-val',$body);
expectTimer(count($deliveries)===2 && $deliveries[1][1]['revision']===1,'Idempotent retry resends the same revision');
rejectTimer(fn()=>$service->change('2','other',$body),409);
$stale=$body; $stale['operation_id']=str_repeat('2',32);
rejectTimer(fn()=>$service->change('1','firebase-val',$stale),409);
$edit=$stale; $edit['installation_id']=$b; $edit['revision']=1; $edit['title']='Soup updated';
$service->change('1','firebase-val',$edit);
expectTimer(count($deliveries)===3 && $deliveries[2][0]['installation_id']===$a,'Origin receives edits even without opt-in');
$cancel=['timer_id'=>$body['timer_id'],'installation_id'=>$b,'operation_id'=>str_repeat('3',32),'revision'=>2,'cancelled'=>true];
$service->change('1','firebase-val',$cancel);
$timers=$service->list('1')['timers'];
expectTimer(count($timers)===1 && $timers[0]['cancelled'] && $timers[0]['revision']===3,'Missed cancellation tombstone');
$failing=new TimerSyncService($pdo,static function() { throw new RuntimeException('FCM unavailable'); });
$restart=$body; $restart['revision']=3; $restart['operation_id']=str_repeat('4',32);
expectTimer($failing->change('1','firebase-val',$restart)['delivery']==='pending','Push failure is reported');
expectTimer($service->list('1')['timers'][0]['revision']===4,'Push failure preserves committed timer');
$service->unregister('2',['installation_id'=>$b]);
expectTimer((int)$pdo->query("SELECT COUNT(*) FROM timer_devices WHERE installation_id='$b'")->fetchColumn()===1,'Cannot unregister other account');
$service->unregister('1',['installation_id'=>$b]);
expectTimer((int)$pdo->query("SELECT COUNT(*) FROM timer_devices WHERE installation_id='$b'")->fetchColumn()===0,'Sign-out registration removed');
rejectTimer(fn()=>$service->device('1',['installation_id'=>$a,'platform'=>'ios','receive_timers'=>'true']),422);
rejectTimer(fn()=>$service->device('1',['installation_id'=>$a,'platform'=>'ios','receive_timers'=>true]),422);
foreach (['../escape','',str_repeat('x',161)] as $invalid) rejectTimer(fn()=>TimerSyncService::identifier($invalid,true),422);
$invalid=$restart; $invalid['deadline_ms']=0; rejectTimer(fn()=>$service->change('1','firebase-val',$invalid),422);
$invalid=$restart; $invalid['deadline_ms']=(int)floor(microtime(true)*1000)+8*86400000; rejectTimer(fn()=>$service->change('1','firebase-val',$invalid),422);
// Rebinding an installation after an account switch cannot expose old account state.
$register('2',$a,false);
rejectTimer(fn()=>$service->change('1','firebase-val',$restart),409);
$expired=$body; $expired['deadline_ms']=(int)floor(microtime(true)*1000)-1;
$pdo->exec('UPDATE shared_timers SET deadline_ms=1');
expectTimer($service->list('1')['timers']===[],'Expired starts are not replayed');
$pdo->exec('UPDATE shared_timers SET cancelled=1');
expectTimer(count($service->list('1')['timers'])===1,'Recent cancellation remains available after deadline');
echo "Timer sync: account isolation, opt-in, revisions, retries, cancellation, sign-out and failure checks passed.\n";
