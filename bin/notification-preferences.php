<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
if ($argc!==2 || $argv[1]!=='apply') { fwrite(STDERR,"Usage: php bin/notification-preferences.php apply\n"); exit(2); }
// Explicit one-time setup, never part of routine deployment.
$pdo=runtimeDatabase();
if ($pdo->query("SHOW COLUMNS FROM timer_devices LIKE 'receive_cooking_invitations'")->fetch()) {
    echo "Invitation preference column already exists.\n"; exit;
}
$pdo->exec('ALTER TABLE timer_devices ADD COLUMN receive_cooking_invitations BOOLEAN NOT NULL DEFAULT TRUE');
echo "Invitation preference column installed; existing devices remain opted in.\n";
