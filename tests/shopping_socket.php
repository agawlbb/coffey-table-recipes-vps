<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/src/ShoppingSocket.php';
final class SocketDatabase extends PDO {
    public function prepare(string $query, array $options=[]): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
$db=new SocketDatabase('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE households(id INTEGER PRIMARY KEY);
CREATE TABLE users(id INTEGER PRIMARY KEY,firebase_uid TEXT,disabled INTEGER);
CREATE TABLE household_memberships(household_id INTEGER,user_id INTEGER,role TEXT);
CREATE TABLE shopping_lists(household_id INTEGER PRIMARY KEY,revision INTEGER,items TEXT);
CREATE TABLE shopping_connections(token_hash TEXT PRIMARY KEY,household_id INTEGER,created_at TEXT);
INSERT INTO households VALUES(1),(2);
INSERT INTO users VALUES(1,'alice',0),(2,'bob',0),(3,'disabled',1);
INSERT INTO household_memberships VALUES(1,1,'owner'),(2,2,'owner');");
$dir=sys_get_temp_dir().'/ctr-socket-'.bin2hex(random_bytes(8));
$connections=new HomeAssistantConnections($db);
$verifications=0;
$socket=new ShoppingSocket($db, static function(string $token) use (&$verifications): array {
    $verifications++;
    if (!in_array($token,['alice','bob','disabled'],true)) throw new HttpError(401,'invalid_token','Invalid.');
    return ['uid'=>$token];
},$connections,new ShoppingSyncRequests($dir));
function checkSocket(bool $ok): void { if (!$ok) throw new RuntimeException('Socket assertion failed.'); }
function rejectSocket(callable $call,int $status): void {
    try { $call(); throw new RuntimeException('Expected rejection.'); }
    catch(HttpError $e) { checkSocket($e->status===$status); }
}
$path='/households/1/shopping-list';
try {
    checkSocket($socket->authenticate('alice','1')['user']==='1');
    rejectSocket(fn()=>$socket->authenticate('bad','1'),401);
    rejectSocket(fn()=>$socket->authenticate('disabled','1'),403);
    rejectSocket(fn()=>$socket->authenticate('bob','1'),404);
    rejectSocket(fn()=>$socket->handle('alice','1','/households/2/shopping-list',null),404);
    $before=$verifications;
    $socket->handle('alice','1',$path,null);
    checkSocket($verifications-$before===1); // One verification per operation.
    $item=['id'=>'milk','name'=>'Milk','department'=>'','checked'=>false];
    $body=['revision'=>0,'items'=>[$item],'base_items'=>[],'client_change_id'=>'change1','edited_at_ms'=>(int)(microtime(true)*1000)];
    $saved=$socket->handle('alice','1',$path,$body);
    checkSocket($saved['acknowledged_change_id']==='change1' && count($saved['items'])===1);
    checkSocket($socket->handle('alice','1',$path,$body)['revision']===$saved['revision']);
    checkSocket(count($socket->handle('alice','1',$path,null)['items'])===1);
        $connection=$connections->create('1');$token=$connection['token'];
    checkSocket($socket->authenticate($token,null)['household']==='1');
    rejectSocket(fn()=>$socket->authenticate($token,'2'),404);
    rejectSocket(fn()=>$socket->handle($token,'1',$path,['departments'=>[]]),422);
    checkSocket(count($socket->handle($token,'1',$path,null)['items'])===1);
    $listed=$connections->list('1');
    checkSocket(count($listed)===1 && $listed[0]['id']===hash('sha256',$token) && !isset($listed[0]['token']));
    rejectSocket(fn()=>$connections->revokeById('2',$listed[0]['id']),404);
    $connections->revoke($token);
    rejectSocket(fn()=>$socket->authenticate($token,'1'),401);
    $second=$connections->create('1');
    $connections->revokeById('1',hash('sha256',$second['token']));
    rejectSocket(fn()=>$socket->authenticate($second['token'],'1'),401);
    $departments=$saved['departments'];
    $config=['revision'=>1,'items'=>[$item],'base_items'=>[$item],
        'departments'=>['Farm stand'],'base_departments'=>$departments,
        'client_change_id'=>'department1','edited_at_ms'=>(int)(microtime(true)*1000)];
    checkSocket($socket->handle('alice','1',$path,$config)['departments']===['Farm stand']);
    $stale=array_replace($config,['departments'=>['Supermarket'],'client_change_id'=>'department2']);
    rejectSocket(fn()=>$socket->handle('alice','1',$path,$stale),409);
    checkSocket($socket->handle('alice','1',$path,$config)['departments']===['Farm stand']);
    $db->exec('DELETE FROM household_memberships WHERE user_id=1');
    rejectSocket(fn()=>$socket->handle('alice','1',$path,null),404);
    echo "Socket identity, isolation, receipts and shared HA revocation passed\n";
} finally {
    foreach(glob($dir.'/*') ?: [] as $file) unlink($file);
    if(is_dir($dir)) rmdir($dir);
}
