<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
final class ChildModeTestPDO extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$query),$options); }
}
$pdo=new ChildModeTestPDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,firebase_uid TEXT UNIQUE,disabled INTEGER DEFAULT 0,child_mode INTEGER DEFAULT 0,child_mode_pin_hash TEXT,child_mode_attempts INTEGER DEFAULT 0,child_mode_blocked_until INTEGER,child_mode_reset_after INTEGER);
INSERT INTO users(firebase_uid) VALUES('child'),('other');");
$mode=new ChildMode($pdo);
$identity=['uid'=>'child','auth_time'=>time()-100];
function childCheck(bool $ok):void {if(!$ok) throw new RuntimeException('Child mode check failed');}
function childReject(callable $action,int $status):void {try{$action();}catch(HttpError $e){childCheck($e->status===$status);return;}throw new RuntimeException('Expected policy rejection');}
try {
    childCheck($mode->state($identity)===['enabled'=>false]);
    childReject(fn()=>$mode->change($identity,['enabled'=>true,'pin'=>'abc']),422);
    childCheck($mode->change($identity,['enabled'=>true,'pin'=>'1234'])['enabled']);
    childCheck(!(new ChildMode($pdo))->state(['uid'=>'other'])['enabled']);
    foreach(['/households/1/meal-plan','/households/1/shopping-list','/households/1/recipes','/admin/users/1/delete','/me/timers'] as $route) childReject(fn()=>$mode->guard($identity,$route,'POST'),403);
    $mode->guard($identity,'/households/1/recipes/2/favorites','POST');
    $mode->guard($identity,'/households/1/meal-plan','GET');
    childReject(fn()=>$mode->change($identity,['enabled'=>true,'pin'=>'5678']),403);
    childReject(fn()=>$mode->change($identity,['enabled'=>false,'pin'=>'5678']),403);
    childCheck($mode->state($identity)['enabled']);
    $mode->requestReset('child');
    childCheck($mode->state($identity)['enabled']); // Refreshing a token is not a new login.
    childCheck(!$mode->state(['uid'=>'child','auth_time'=>time()+1])['enabled']);
    childCheck($mode->change($identity,['enabled'=>true,'pin'=>'5678'])['enabled']);
    childReject(fn()=>$mode->change($identity,['enabled'=>false,'pin'=>'1234']),403); // Old PIN cleared.
    childCheck(!$mode->change($identity,['enabled'=>false,'pin'=>'5678'])['enabled']);
    $mode->change($identity,['enabled'=>true,'pin'=>'1234']);
    for($i=0;$i<5;$i++) childReject(fn()=>$mode->change($identity,['enabled'=>false,'pin'=>'0000']),403);
    childReject(fn()=>$mode->change($identity,['enabled'=>false,'pin'=>'1234']),429);
    $stored=json_encode($pdo->query("SELECT child_mode_pin_hash FROM users WHERE firebase_uid='child'")->fetch(PDO::FETCH_ASSOC));
    childCheck((int)$pdo->query("SELECT child_mode_blocked_until FROM users WHERE firebase_uid='child'")->fetchColumn()>time());
    childCheck(strpos($stored,'"pin":')===false && strpos($stored,'1234')===false);
    childReject(fn()=>$mode->state(['uid'=>'missing']),403);
    $pdo->exec("CREATE TABLE household_memberships(household_id INTEGER,user_id INTEGER); CREATE TABLE household_people(id INTEGER PRIMARY KEY,household_id INTEGER,user_id INTEGER);
    INSERT INTO household_memberships VALUES(10,1),(20,2); INSERT INTO household_people VALUES(100,10,1),(200,20,2);");
    childCheck($mode->peopleChildModes('1')===[100=>true]);
    childCheck($mode->peopleChildModes('2')===[200=>false]);
    $broken=new ChildModeTestPDO('sqlite::memory:');
    try { (new ChildMode($broken))->state($identity); throw new LogicException('Unavailable policy accepted'); }
    catch (PDOException $error) { childCheck(!$broken->inTransaction()); }
    echo "Child mode default, isolation, PIN, throttle, write policy and next-login recovery passed.\n";
} finally { $pdo=null; }
