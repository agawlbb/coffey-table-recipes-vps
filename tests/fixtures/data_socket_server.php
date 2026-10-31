<?php
declare(strict_types=1);
require __DIR__.'/data.php';
$factory=static fn(): DataSocket => $socket;
$httpFactory=static fn()=>new HttpService($pdo,static function(){throw new RuntimeException('Not an account fixture');},static fn()=>['api_key'=>'public-fixture'],CTR_STORAGE.'/media/recipes',dirname(__DIR__,2).'/associations');
require dirname(__DIR__,2).'/server.php';
