<?php
declare(strict_types=1);
require __DIR__.'/account.php';
$pdo=new AccountFixturePDO('sqlite:'.getenv('CTR_TEST_DATABASE'));
$verify=static function(string $token): array {
    if (!in_array($token,['alice','bob'],true)) throw new HttpError(401,'invalid_token','Invalid token.');
    return ['uid'=>$token,'name'=>ucfirst($token),'email'=>$token.'@example.test','email_verified'=>true];
};
$account=new AccountService($pdo,$verify,null,static fn()=>null,static fn()=>null,static fn()=>'',static fn()=>null);
require dirname(__DIR__,2).'/account-worker.php';
