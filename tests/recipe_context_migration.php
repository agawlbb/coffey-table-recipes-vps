<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/HttpError.php';
require dirname(__DIR__).'/src/RecipeContextMigration.php';
require __DIR__.'/fixtures/context_database.php';
$root=sys_get_temp_dir().'/ctr-context-migration-'.bin2hex(random_bytes(6));mkdir($root,0700);mkdir($root.'/files',0700);
$pdo=contextTestDatabase($root.'/db.sqlite',false);
$pdo->exec('PRAGMA foreign_keys=ON; CREATE TABLE users(id INTEGER PRIMARY KEY); INSERT INTO users VALUES(1),(2),(3); CREATE TABLE user_recipe_context(user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,state_json TEXT NOT NULL)');
$checks=0;
function checkMigration(bool $value,string $message): void { global $checks;if (!$value) throw new RuntimeException($message);$checks++; }
function migrationFails(callable $run): void { try {$run();}catch (RuntimeException|JsonException $e) {return;}throw new LogicException('Expected import failure'); }
$first=['revision'=>7,'facts'=>[['id'=>str_repeat('a',32),'person'=>'self','preference'=>'Dislikes peas.','household'=>null],['id'=>str_repeat('b',32),'person'=>'my son Alex','preference'=>'Likes roasting.','household'=>'12']]];
$second=['revision'=>2,'facts'=>[],'pending_chat'=>['identity'=>str_repeat('c',64),'state'=>['revision'=>3,'messages'=>[],'turns'=>[]]]];
$file=static fn($user)=>$root.'/files/'.hash('sha256',(string)$user).'.json';
try {
 file_put_contents($file(1),json_encode($first));file_put_contents($file(2),json_encode($second));
 $before=[file_get_contents($file(1)),file_get_contents($file(2))];
 $r=RecipeContextMigration::import($pdo,$root.'/files');
 checkMigration($r===['profiles'=>2,'facts'=>2,'verified'=>true],'Every account and fact is imported and verified');
 $rows=$pdo->query('SELECT user_id,state_json FROM user_recipe_context ORDER BY user_id')->fetchAll(PDO::FETCH_KEY_PAIR);
 checkMigration(json_decode($rows[1],true)===$first && json_decode($rows[2],true)===$second,'IDs, revisions, household attribution and interrupted reply snapshots survive exactly');
 checkMigration([file_get_contents($file(1)),file_get_contents($file(2))]===$before,'Import retains original private files as backup evidence');
 checkMigration(RecipeContextMigration::import($pdo,$root.'/files')===$r,'An exact import retry is idempotent');
 $pdo->exec('DELETE FROM user_recipe_context');
 file_put_contents($file(3),'{broken');migrationFails(fn()=>RecipeContextMigration::import($pdo,$root.'/files'));
 checkMigration((int)$pdo->query('SELECT COUNT(*) FROM user_recipe_context')->fetchColumn()===0,'Corrupt input leaves all SQL context untouched');unlink($file(3));
 file_put_contents($file(999),json_encode(['revision'=>0,'facts'=>[]]));migrationFails(fn()=>RecipeContextMigration::import($pdo,$root.'/files'));
 checkMigration((int)$pdo->query('SELECT COUNT(*) FROM user_recipe_context')->fetchColumn()===0,'Unmatched accounts stop migration without silently discarding data');unlink($file(999));
 $q=$pdo->prepare('INSERT INTO user_recipe_context VALUES(?,?)');$q->execute([2,json_encode(['revision'=>3,'facts'=>[]])]);
 migrationFails(fn()=>RecipeContextMigration::import($pdo,$root.'/files'));
 checkMigration((int)$pdo->query('SELECT COUNT(*) FROM user_recipe_context')->fetchColumn()===1,'A conflicting newer SQL record rolls back earlier imports in the same run');
 $pdo->exec('DELETE FROM user_recipe_context');RecipeContextMigration::import($pdo,$root.'/files');
 $pdo->exec('DELETE FROM users WHERE id=1');
 checkMigration((int)$pdo->query('SELECT COUNT(*) FROM user_recipe_context WHERE user_id=1')->fetchColumn()===0,'Deleting an account cascades its private context');
 checkMigration((int)$pdo->query('SELECT COUNT(*) FROM user_recipe_context WHERE user_id=2')->fetchColumn()===1,'Deleting one account retains other accounts');
 mkdir($root.'/recipe-chats',0700);
 $recovered=new RecipeUserContext($pdo,$root,'2');$recovered->close();
 $q=$pdo->query('SELECT state_json FROM user_recipe_context WHERE user_id=2');
 checkMigration(!isset(json_decode($q->fetchColumn(),true)['pending_chat']) && json_decode(file_get_contents($root.'/recipe-chats/'.str_repeat('c',64).'.json'),true)===$second['pending_chat']['state'],'An imported interrupted reply recovers from SQL without a file-context fallback');
 migrationFails(fn()=>RecipeContextMigration::import($pdo,$root.'/missing'));
 checkMigration(is_file($file(1)) && is_file($file(2)),'Failed validation never removes source backups');
} finally {
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $p) {if($p->isDir())rmdir($p->getPathname());else unlink($p->getPathname());}rmdir($root);
}
echo "$checks context migration checks passed\n";
