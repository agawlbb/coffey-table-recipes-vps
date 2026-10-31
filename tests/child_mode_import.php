<?php
declare(strict_types=1);
require __DIR__.'/fixtures/account.php';
require dirname(__DIR__).'/src/ChildModeImport.php';
function importFixture(): PDO {
    $pdo=new AccountFixturePDO('sqlite::memory:'); accountSchema($pdo);
    $pdo->exec("UPDATE users SET child_mode=0,child_mode_pin_hash=NULL; INSERT INTO users(firebase_uid,display_name) VALUES('new','New')");
    return $pdo;
}
$pin=password_hash('1234',PASSWORD_DEFAULT);
$legacy=[hash('sha256','child')=>['enabled'=>true,'pin_hash'=>$pin,'attempts'=>4,'blocked_until'=>time()+60,'reset_after'=>time()-10],
    hash('sha256','deleted-history')=>['enabled'=>false]];
$pdo=importFixture(); $result=importChildModeState($pdo,$legacy);
if ($result['imported']!==1 || $result['unmatched_historical_files']!==1) throw new RuntimeException('Incorrect import counts');
$row=$pdo->query("SELECT * FROM users WHERE firebase_uid='child'")->fetch(PDO::FETCH_ASSOC);
if ($row['child_mode']!=1 || $row['child_mode_pin_hash']!==$pin || $row['child_mode_attempts']!=4 || $row['child_mode_blocked_until']!=$legacy[hash('sha256','child')]['blocked_until']) throw new RuntimeException('Policy state was lost');
if ($pdo->query("SELECT child_mode FROM users WHERE firebase_uid='new'")->fetchColumn()!=0) throw new RuntimeException('New users must default disabled');
try { importChildModeState($pdo,$legacy); throw new LogicException('Unsafe repeat accepted'); } catch(RuntimeException $error) { if($error instanceof LogicException) throw $error; }
$fresh=importFixture();
try { importChildModeState($fresh,[hash('sha256','child')=>['enabled'=>true,'pin_hash'=>'1234']]); throw new LogicException('Plaintext PIN accepted'); } catch(RuntimeException $error) { if($error instanceof LogicException) throw $error; }
if ($fresh->query("SELECT child_mode FROM users WHERE firebase_uid='child'")->fetchColumn()!=0) throw new RuntimeException('Invalid import changed state');
echo "Policy import preserves PIN hashes, lockouts, resets and defaults; malformed/repeated imports rejected.\n";
