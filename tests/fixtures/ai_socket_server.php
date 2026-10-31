<?php
declare(strict_types=1);
require __DIR__.'/data.php';
$pdo->exec("ALTER TABLE users ADD COLUMN account_role TEXT DEFAULT 'member'");
$factory=static fn(): DataSocket => $socket;
$aiJobs=new AiJobs([PHP_BINARY,__DIR__.'/ai_worker.php']);
require dirname(__DIR__,2).'/server.php';
