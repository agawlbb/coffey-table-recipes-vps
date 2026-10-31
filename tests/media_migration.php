<?php
declare(strict_types=1);
require __DIR__.'/fixtures/account.php';
require dirname(__DIR__).'/src/MediaMigration.php';
$pdo=new AccountFixturePDO('sqlite::memory:');
$pdo->exec('CREATE TABLE recipe_photos(id INTEGER PRIMARY KEY,source_url TEXT,storage_key TEXT); CREATE TABLE recipe_categories(id INTEGER PRIMARY KEY,image_key TEXT)');
$directory=sys_get_temp_dir().'/ctr-migrate-media-'.bin2hex(random_bytes(8));mkdir($directory,0700);
$name=str_repeat('a',48).'.jpg';file_put_contents($directory.'/'.$name,'managed image');file_put_contents($directory.'/category-2.jpg','category');
$pdo->prepare('INSERT INTO recipe_photos VALUES(?,?,?)')->execute([1,'https://coffeybean.com/CoffeyTableRecipes/media/recipes/'.$name,'media/recipes/'.$name]);
$legacy=str_repeat('b',64).'.png';file_put_contents($directory.'/'.$legacy,'legacy image');
$pdo->prepare('INSERT INTO recipe_photos VALUES(?,?,?)')->execute([3,'https://coffeybean.com/CoffeyTableRecipes/media/recipe-photos/'.$legacy,'media/recipe-photos/'.$legacy]);
$pdo->exec("INSERT INTO recipe_photos VALUES(2,'https://external.test/image.jpg',NULL); INSERT INTO recipe_categories VALUES(2,'ai-2')");
$illustration='crab-mushroom-wine-sauce-ai-v1.jpg';file_put_contents($directory.'/'.$illustration,'illustration');
$pdo->prepare('INSERT INTO recipe_photos VALUES(?,?,NULL)')->execute([4,'https://coffeybean.com/CoffeyTableRecipes/media/recipe-illustrations/'.$illustration]);
$manifest=['files'=>[$name=>['sha256'=>hash_file('sha256',$directory.'/'.$name)],'category-2.jpg'=>['sha256'=>hash_file('sha256',$directory.'/category-2.jpg')]]];
try {
    $manifest['files'][$legacy]=['sha256'=>hash_file('sha256',$directory.'/'.$legacy)];
    $manifest['files'][$illustration]=['sha256'=>hash_file('sha256',$directory.'/'.$illustration)];
    $bad=$manifest; $bad['files']['category-2.jpg']['sha256']=str_repeat('0',64);
    try {migrateRecipeMedia($pdo,$bad,$directory); throw new LogicException('Unverified category accepted');} catch(RuntimeException $error) {}
    if (!str_contains($pdo->query('SELECT source_url FROM recipe_photos WHERE id=1')->fetchColumn(),'coffeybean.com/CoffeyTableRecipes')) throw new RuntimeException('Failed migration was not rolled back');
    $result=migrateRecipeMedia($pdo,$manifest,$directory);
    if ($result!==['updated_photos'=>3,'verified_categories'=>1]) throw new RuntimeException('Wrong migration result');
    $rows=$pdo->query('SELECT * FROM recipe_photos ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    if ($rows[0]['source_url']!=='https://ctr.coffeybean.com/media/recipes/'.$name || $rows[0]['storage_key']!=='vps/recipes/'.$name || $rows[1]['source_url']!=='https://external.test/image.jpg') throw new RuntimeException('Incorrect media ownership rewrite');
    if ($rows[2]['storage_key']!=='vps/recipes/'.$legacy || $rows[2]['source_url']!=='https://ctr.coffeybean.com/media/recipes/'.$legacy) throw new RuntimeException('Legacy stored photo did not migrate');
    if ($rows[3]['storage_key']!=='vps/recipes/'.$illustration) throw new RuntimeException('Named illustration did not migrate');
    if (migrateRecipeMedia($pdo,$manifest,$directory)['updated_photos']!==0) throw new RuntimeException('Repeat changed media');
    echo "Media migration validates files, rolls back incomplete sets, preserves external URLs and is idempotent.\n";
} finally {foreach(glob($directory.'/*') as $file)unlink($file);rmdir($directory);}
