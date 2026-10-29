<?php
declare(strict_types=1);
require __DIR__.'/data.php';
$factory=static fn(): DataSocket => $socket;
require dirname(__DIR__,2).'/server.php';
