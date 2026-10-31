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
accountError(fn()=>$service->handle('policy-failed','/households',['name'=>'No','person_name'=>'No']),503,'account_policy_unavailable');
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
accountError(fn()=>$service->handle('new','/admin/users',null),404,'not_found');
$pdo->exec("INSERT INTO household_people(household_id,display_name) VALUES(1,'Another guest')");
$person=(string)$pdo->lastInsertId();
$failing=accountFixture($pdo,static function() { throw new RuntimeException('Simulated SMTP outage'); });
accountError(fn()=>$failing->handle('new',"/households/1/people/$person/invitations",['email'=>'another@example.test']),503,'invitation_email_failed');
checkAccount($pdo->query("SELECT status FROM household_invitations WHERE email='another@example.test'")->fetchColumn()==='failed','SMTP failure left a pending invitation.');
$verificationFailure=accountFixture($pdo,static function() { throw new HttpError(503,'verification_email_failed','Simulated SMTP outage'); });
accountError(fn()=>$verificationFailure->handle('new','/me/verification-email',[]),503,'verification_email_failed');
echo "$checks account checks passed. No external services or mail used.\n";
