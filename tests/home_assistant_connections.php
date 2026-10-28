<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE shopping_connections(token_hash TEXT PRIMARY KEY,household_id INTEGER,created_at TEXT)');
$service = new HomeAssistantConnections($pdo);
function checkHa(bool $ok): void { if (!$ok) throw new RuntimeException('Home Assistant connection assertion failed'); }
function rejectHa(callable $call, int $status): void {
    try { $call(); throw new RuntimeException('Expected rejection'); }
    catch (HttpError $error) { checkHa($error->status === $status); }
}
try {
    $one = $service->create('1'); $two = $service->create('2'); $other = $service->create('1');
    checkHa($one['token'] !== $other['token']);
    checkHa($service->household($one['token']) === '1');
    checkHa(count($service->list('1')) === 2 && count($service->list('2')) === 1);
    $contents = json_encode($pdo->query('SELECT * FROM shopping_connections')->fetchAll(PDO::FETCH_ASSOC));
    checkHa(!str_contains($contents, $one['token']) && str_contains($contents, hash('sha256', $one['token'])));
    rejectHa(fn()=>$service->household('../../etc/passwd'), 401);
    rejectHa(fn()=>$service->household('ctrha_'.str_repeat('0',64)), 401);
    rejectHa(fn()=>$service->revokeById('2', hash('sha256',$one['token'])), 404);
    checkHa($service->household($one['token']) === '1');
    $service->revoke($one['token']);
    rejectHa(fn()=>$service->household($one['token']),401);
    $service->revoke($one['token']); // Repeated deletion is harmless.
    $service->revokeById('1', hash('sha256',$other['token']));
    checkHa($service->list('1') === []);
    checkHa($service->household($two['token']) === '2');
    echo "Home Assistant connection fixtures passed\n";
} finally { $pdo = null; }
