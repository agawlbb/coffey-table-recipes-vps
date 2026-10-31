<?php
declare(strict_types=1);
require __DIR__.'/account.php';
$pdo=new AccountFixturePDO('sqlite:'.getenv('CTR_TEST_DATABASE'));
accountSchema($pdo);
$verify=Closure::fromCallable('accountIdentity');
$socket=new DataSocket($pdo,$verify,new ShoppingSocket($pdo,$verify,new HomeAssistantConnections($pdo),new ShoppingSyncRequests(getenv('CTR_TEST_DATABASE').'-requests')));
$factory=static fn()=>$socket;
$httpFactory=static fn()=>new HttpService($pdo,static fn()=>accountFixture($pdo),static fn()=>['api_key'=>'public-fixture'],getenv('CTR_STORAGE').'/media/recipes',dirname(__DIR__,2).'/associations');
$accountJobs=new AiJobs([PHP_BINARY,__DIR__.'/account_worker.php']);
require dirname(__DIR__,2).'/server.php';
