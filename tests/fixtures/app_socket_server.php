<?php
declare(strict_types=1);
require __DIR__.'/data.php';
$pdo->exec("ALTER TABLE users ADD COLUMN email TEXT;
ALTER TABLE users ADD COLUMN email_verified INTEGER DEFAULT 1;
ALTER TABLE users ADD COLUMN account_role TEXT DEFAULT 'member';
ALTER TABLE users ADD COLUMN child_mode_pin_hash TEXT;
ALTER TABLE users ADD COLUMN child_mode_attempts INTEGER DEFAULT 0;
ALTER TABLE users ADD COLUMN child_mode_blocked_until INTEGER;
ALTER TABLE users ADD COLUMN child_mode_reset_after INTEGER;
ALTER TABLE household_invitations ADD COLUMN id INTEGER;
ALTER TABLE household_invitations ADD COLUMN household_id INTEGER;
ALTER TABLE household_invitations ADD COLUMN created_at TEXT;
CREATE UNIQUE INDEX app_users_uid ON users(firebase_uid);
UPDATE users SET email=firebase_uid||'@example.test';");
$factory=static fn()=>$socket;
$accountJobs=new AiJobs([PHP_BINARY,__DIR__.'/app_worker.php'],'Account',85);
$httpFactory=static fn()=>new HttpService($pdo,static function(){throw new RuntimeException('No HTTP accounts');},static fn()=>['api_key'=>'fixture'],CTR_STORAGE.'/media/recipes',dirname(__DIR__,2).'/associations');
require dirname(__DIR__,2).'/server.php';
