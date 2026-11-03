<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require dirname(__DIR__).'/src/TimerRangeInventory.php';
// Explicit one-time maintenance. The file contains private recipe data, never commit it.
if ($argc!==3 || !in_array($argv[1],['survey','apply'],true)) { fwrite(STDERR,"Usage: php bin/timer-ranges.php survey|apply PRIVATE_JSON_PATH\n"); exit(2); }
$pdo=runtimeDatabase(); $path=$argv[2];
if ($argv[1]==='survey') {
    if (file_exists($path)) throw new RuntimeException('Refusing to replace survey snapshot.');
    $pdo->beginTransaction();
    $plan=TimerRangeInventory::survey($pdo); $pdo->commit();
    file_put_contents($path,json_encode($plan,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); chmod($path,0600);
    unset($plan['recipes']); echo json_encode($plan,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),"\n";
} else {
    $plan=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    echo json_encode(TimerRangeInventory::apply($pdo,$plan),JSON_THROW_ON_ERROR),"\n";
}
