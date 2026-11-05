<?php
declare(strict_types=1);
require __DIR__.'/data.php';
$calls=0;
$factory=static function(bool $reset=false) use (&$calls,$socket): ?DataSocket {
    if ($reset) return null;
    if (++$calls===2) throw new RuntimeException('private-fixture-marker-must-not-leak');
    return $socket;
};
$httpFactory=static fn()=>new HttpService($pdo,static function(){throw new RuntimeException('Not an account fixture');},static fn()=>['api_key'=>'public-fixture'],CTR_STORAGE.'/media/recipes',dirname(__DIR__,2).'/associations');
require dirname(__DIR__,2).'/server.php';
