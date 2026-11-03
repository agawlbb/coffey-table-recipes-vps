<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
$optimizer=new RecipeImageOptimizer(CTR_STORAGE.'/media/recipes');
$failed=false;
foreach ($optimizer->pending() as $name) {
    try { echo json_encode($optimizer->optimize($name),JSON_THROW_ON_ERROR)."\n"; }
    catch (Throwable $error) { $failed=true; fwrite(STDERR,$name.': '.$error->getMessage()."\n"); }
}
exit($failed?1:0);
