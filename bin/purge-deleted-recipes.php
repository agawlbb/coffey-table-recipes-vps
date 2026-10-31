<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/bootstrap.php';
try {
    $pdo ??=runtimeDatabase();
    if ((int)$pdo->query("SELECT GET_LOCK('coffey_recipe_trash_cleanup',0)")->fetchColumn()!==1) exit;
    try {
        $service=new RecipeDeletionService($pdo,new HouseholdService($pdo)); $total=0;
        do { $removed=$service->purgeExpired(CTR_STORAGE.'/media/recipes'); $total+=$removed; } while($removed===100);
        echo "Removed $total expired recipes.\n";
    } finally { $pdo->query("SELECT RELEASE_LOCK('coffey_recipe_trash_cleanup')"); }
} catch(Throwable $error) { fwrite(STDERR,"Recipe cleanup failed; private details omitted.\n"); exit(1); }
