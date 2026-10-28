<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2).'/bootstrap.php';
require_once dirname(__DIR__, 2).'/src/ShoppingSocket.php';
final class WireDatabase extends PDO {
    public function prepare(string $query, array $options=[]): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
$file=getenv('CTR_TEST_DATABASE');
if (!$file || !str_starts_with(basename($file), 'ctr-wire-')) throw new RuntimeException('Test database required.');
$db=new WireDatabase('sqlite:'.$file);
$db->exec("CREATE TABLE households(id INTEGER PRIMARY KEY);
CREATE TABLE users(id INTEGER PRIMARY KEY,firebase_uid TEXT,disabled INTEGER);
CREATE TABLE household_memberships(household_id INTEGER,user_id INTEGER,role TEXT);
CREATE TABLE shopping_lists(household_id INTEGER PRIMARY KEY,revision INTEGER,items TEXT);
CREATE TABLE shopping_connections(token_hash TEXT PRIMARY KEY,household_id INTEGER,created_at TEXT);
INSERT INTO households VALUES(1),(2);
INSERT INTO users VALUES(1,'alice',0),(2,'bob',0);
INSERT INTO household_memberships VALUES(1,1,'owner'),(2,2,'owner');");
$q=$db->prepare('INSERT INTO shopping_connections VALUES(?,?,?)');
$q->execute([hash('sha256','ctrha_'.str_repeat('a',64)),1,gmdate('Y-m-d H:i:s')]);
$factory=static fn(): ShoppingSocket => new ShoppingSocket($db, static function(string $token): array {
    if (!in_array($token,['alice','bob'],true)) throw new HttpError(401,'invalid_token','Invalid token.');
    return ['uid'=>$token];
},new HomeAssistantConnections($db),new ShoppingSyncRequests($file.'-requests'));
require_once dirname(__DIR__, 2).'/server.php';
