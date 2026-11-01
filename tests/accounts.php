<?php
declare(strict_types=1);
require __DIR__.'/fixtures/account.php';
$pdo=new AccountFixturePDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); accountSchema($pdo);
$sent=[]; $service=accountFixture($pdo,static function(...$args) use (&$sent) { $sent[]=$args; });
$checks=0;
function checkAccount(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function accountError(callable $call,int $status,string $code): void {
    try { $call(); throw new RuntimeException('Expected '.$code); }
    catch (HttpError $error) { checkAccount($error->status===$status && $error->errorCode===$code,'Wrong account failure: '.$error->errorCode); }
}
$first=$service->handle('new','/me',null); $again=$service->handle('new','/me',null);
checkAccount($first===$again && $first['households']===[],'Account bootstrap is not stable.');
checkAccount($pdo->query("SELECT COUNT(*) FROM users WHERE firebase_uid='new'")->fetchColumn()===1,'Duplicate bootstrap.');
accountError(fn()=>$service->handle('deleted','/me',null),401,'account_deleted');
checkAccount($pdo->query("SELECT COUNT(*) FROM users WHERE firebase_uid='deleted'")->fetchColumn()===0,'Deleted identity was recreated.');
accountError(fn()=>$service->handle('disabled','/me',null),403,'account_disabled');
accountError(fn()=>$service->handle('bad','/me',null),401,'invalid_token');
checkAccount($service->handle('child','/me',null)['user']['child_mode']===true,'Child policy missing.');
accountError(fn()=>$service->handle('child','/households',['name'=>'No','person_name'=>'No']),403,'child_mode');
$house=$service->handle('new','/households',['name'=>'Family','person_name'=>'Owner'])['household'];
checkAccount($house['name']==='Family','Household setup failed.');
accountError(fn()=>$service->handle('new','/households',['name'=>'Duplicate','person_name'=>'Owner']),409,'household_already_exists');
checkAccount((int)$pdo->query('SELECT COUNT(*) FROM households')->fetchColumn()===1,'Failed setup was not rolled back.');
$service->handle('new','/me/verification-email',['email'=>'attacker@example.test','uid'=>'guest']);
checkAccount($sent[0]===['new@example.test','https://example.test/verify?uid=new'],'Verification trusted request identity.');
$pdo->exec("INSERT INTO household_people(household_id,display_name) VALUES(1,'Guest')");
$person=(string)$pdo->lastInsertId();
$invite=$service->handle('new',"/households/1/people/$person/invitations",['email'=>'guest@example.test'])['invitation'];
checkAccount($invite['status']==='pending' && count($sent)===2,'Invitation was not sent.');
accountError(fn()=>$service->handle('new',"/households/1/people/$person/invitations",['email'=>'guest@example.test']),409,'invitation_pending');
accountError(fn()=>$service->handle('guest',"/households/1/people/$person/invitations",['email'=>'other@example.test']),404,'household_not_found');
checkAccount($service->handle('unverified','/me',null)['invitation_verification_required']===true,'Unverified invitation not gated.');
accountError(fn()=>$service->handle('unverified','/invitations/'.$invite['id'].'/respond',['decision'=>'accept']),403,'email_verification_required');
accountError(fn()=>$service->handle('child','/invitations/'.$invite['id'].'/respond',['decision'=>'accept']),403,'child_mode');
$accepted=$service->handle('guest','/invitations/'.$invite['id'].'/respond',['decision'=>'accept']);
checkAccount($accepted['invitation']['status']==='accepted','Invitation acceptance failed.');
checkAccount(count($service->handle('guest','/me',null)['households'])===1,'Accepted membership missing.');
accountError(fn()=>$service->handle('guest','/invitations/'.$invite['id'].'/respond',['decision'=>'accept']),409,'invitation_not_pending');
accountError(fn()=>$service->handle('new','/admin/users',null),403,'administrator_required');
$pdo->exec("INSERT INTO household_people(household_id,display_name) VALUES(1,'Another guest')");
$person=(string)$pdo->lastInsertId();
$failing=accountFixture($pdo,static function() { throw new RuntimeException('Simulated SMTP outage'); });
accountError(fn()=>$failing->handle('new',"/households/1/people/$person/invitations",['email'=>'another@example.test']),503,'invitation_email_failed');
checkAccount($pdo->query("SELECT status FROM household_invitations WHERE email='another@example.test'")->fetchColumn()==='failed','SMTP failure left a pending invitation.');
$verificationFailure=accountFixture($pdo,static function() { throw new HttpError(503,'verification_email_failed','Simulated SMTP outage'); });
accountError(fn()=>$verificationFailure->handle('new','/me/verification-email',[]),503,'verification_email_failed');
echo "$checks account checks passed. No external services or mail used.\n";

// Account full names and per-household display names stay independent.
$owner=$service->handle('new','/me',null)['user'];
checkAccount($owner['display_name']==='New','Household setup overwrote the full name.');
$pdo->exec("INSERT INTO households(id,name) VALUES(99,'Second table')");
$pdo->prepare('INSERT INTO household_memberships VALUES(?,?,?)')->execute([99,$owner['id'],'member']);
$pdo->prepare('INSERT INTO household_people(household_id,user_id,display_name) VALUES(?,?,?)')->execute([99,$owner['id'],'Other display']);
$changed=$service->handle('new','/me/name',['display_name'=>'  Alex Example  '])['user'];
checkAccount($changed['display_name']==='Alex Example','Full name not trimmed.');
checkAccount($service->handle('new','/me',null)['user']['display_name']==='Alex Example','Stale Firebase metadata restored the old full name.');
$names=$pdo->query('SELECT display_name FROM household_people WHERE user_id='.$owner['id'].' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
checkAccount($names===['Owner','Other display'],'Full name changed household display names.');
$ownId=(string)$pdo->query('SELECT id FROM household_people WHERE household_id=1 AND user_id='.$owner['id'])->fetchColumn();
$path="/households/1/people/$ownId/name";
checkAccount($service->handle('new',$path,['display_name'=>'  Alex  '])['person']['display_name']==='Alex','Own display name did not save.');
$names=$pdo->query('SELECT display_name FROM household_people WHERE user_id='.$owner['id'].' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
checkAccount($names===['Alex','Other display'],'Display name leaked across households.');
checkAccount($service->handle('new','/me',null)['user']['display_name']==='Alex Example','Display name changed full name.');
accountError(fn()=>$service->handle('new','/me/name',['display_name'=>' ']),422,'invalid_name');
accountError(fn()=>$service->handle('new',$path,['display_name'=>str_repeat('x',201)]),422,'invalid_name');
accountError(fn()=>$service->handle('child','/me/name',['display_name'=>'No']),403,'child_mode');
$child=$service->handle('child','/me',null)['user'];
$pdo->prepare('INSERT INTO household_memberships VALUES(?,?,?)')->execute([1,$child['id'],'member']);
$pdo->prepare('INSERT INTO household_people(household_id,user_id,display_name) VALUES(?,?,?)')->execute([1,$child['id'],'Kid']);
$childPerson=(string)$pdo->lastInsertId();
$childPath="/households/1/people/$childPerson/name";
checkAccount($service->handle('new',$childPath,['display_name'=>'Jo'])['person']['display_name']==='Jo','Owner cannot rename child.');
checkAccount($service->handle('child','/me',null)['user']['display_name']==='Child','Child display edit changed child full name.');
accountError(fn()=>$service->handle('child',$childPath,['display_name'=>'No']),403,'child_mode');
accountError(fn()=>$service->handle('child',$path,['display_name'=>'No']),403,'child_mode');
accountError(fn()=>$service->handle('guest',$childPath,['display_name'=>'No']),403,'permission_denied');
accountError(fn()=>$service->handle('unverified',$childPath,['display_name'=>'No']),404,'household_not_found');
$guestId=(string)$pdo->query("SELECT id FROM household_people WHERE household_id=1 AND user_id=(SELECT id FROM users WHERE firebase_uid='guest')")->fetchColumn();
accountError(fn()=>$service->handle('new',"/households/1/people/$guestId/name",['display_name'=>'No']),403,'permission_denied');
checkAccount($service->handle('guest',"/households/1/people/$guestId/name",['display_name'=>'Guest nickname'])['person']['display_name']==='Guest nickname','Ordinary adult member cannot rename their own household person.');
$pdo->prepare("UPDATE household_memberships SET role='admin' WHERE household_id=1 AND user_id=(SELECT id FROM users WHERE firebase_uid='guest')")->execute();
checkAccount($service->handle('guest',$childPath,['display_name'=>'Joey'])['person']['display_name']==='Joey','Household admin cannot rename child.');
accountError(fn()=>$service->handle('new',"/households/99/people/$childPerson/name",['display_name'=>'No']),404,'person_not_found');
accountError(fn()=>$service->handle('new',"/households/1/people/$person/name",['display_name'=>'No']),403,'permission_denied');
$households=new HouseholdService($pdo);
try { $households->renameOwnPerson($child['id'],'1','No'); throw new RuntimeException('Child bypassed legacy rename route.'); }
catch (HttpError $error) { checkAccount($error->errorCode==='child_mode','Wrong child rejection.'); }
$pdo->exec("CREATE TRIGGER fail_display_update BEFORE UPDATE OF display_name ON household_people WHEN NEW.display_name='Fail' BEGIN SELECT RAISE(ABORT,'Simulated write failure'); END");
try { $service->handle('new',$path,['display_name'=>'Fail']); throw new RuntimeException('Expected write failure.'); }
catch (PDOException $error) {}
checkAccount($households->people($owner['id'],'1')[0]['display_name']==='Alex','Failed save changed display name.');
checkAccount($service->handle('new','/me',null)['user']['display_name']==='Alex Example','Failed save changed full name.');
echo "Independent full/display names, household isolation, child permissions, legacy protection and rollback passed.\n";
