<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
$directory = sys_get_temp_dir().'/ctr-sync-test-'.bin2hex(random_bytes(8));
function checkSync(bool $condition): void { if (!$condition) throw new RuntimeException('Sync request assertion failed'); }
try {
    $service = new ShoppingSyncRequests($directory);
    checkSync($service->read('1') === ['latest_id'=>0,'requests'=>[]]);
    $first = $service->request('1'); $second = $service->request('1');
    checkSync($first['requests'][0]['reason'] === 'shopping_list_opened');
    checkSync($second['latest_id'] === 2 && count($second['requests']) === 2);
    checkSync($service->read('2')['requests'] === []);
    checkSync((new ShoppingSyncRequests($directory))->read('1') === $second);
    for ($i=0; $i<130; $i++) $service->request('1');
    $state = $service->read('1');
    checkSync($state['latest_id'] === 132 && count($state['requests']) === 128);
    checkSync($state['requests'][0]['id'] === 5 && $state['requests'][127]['id'] === 132);
    checkSync($service->read('1') === $state); // Polling never requests a sync.
    try { $service->request('../1'); throw new RuntimeException('Expected rejection'); }
    catch (HttpError $error) { checkSync($error->status === 404); }
    echo "Shopping sync request persistence, isolation, ordering and retention fixtures passed\n";
} finally {
    foreach (glob($directory.'/*.json') ?: [] as $path) unlink($path);
    if (is_dir($directory)) rmdir($directory);
}
