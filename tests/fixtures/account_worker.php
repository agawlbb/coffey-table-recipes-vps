<?php
declare(strict_types=1);
require __DIR__.'/account.php';
$pdo=new AccountFixturePDO('sqlite:'.getenv('CTR_TEST_DATABASE'));
$verify=Closure::fromCallable('accountIdentity');
$account=accountFixture($pdo,static function() { usleep(1200000); });
require dirname(__DIR__,2).'/account-worker.php';
