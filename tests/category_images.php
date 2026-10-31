<?php
declare(strict_types=1);
require __DIR__.'/fixtures/data.php';
function imageCheck(bool $value,string $message): void { if (!$value) throw new RuntimeException($message); }
$actor=$socket->authenticate('alice','1','household');
$data=['recipe'=>['id'=>'1','category_assignments'=>[['category_id'=>'11']]]];
$pdo->exec("UPDATE recipe_categories SET slug='import-soups',image_key='main-dishes' WHERE id=11");
imageCheck($socket->missingCategoryImages($actor,$data)===['11'],'Inherited artwork must be recognized as missing');
$pdo->exec("UPDATE recipe_categories SET image_key='ai-10' WHERE id=11");
imageCheck($socket->missingCategoryImages($actor,$data)===['11'],'An inherited AI image is not the child category own artwork');
$pdo->exec("UPDATE recipe_categories SET image_key='ai-11' WHERE id=11");
imageCheck($socket->missingCategoryImages($actor,$data)===[],'Existing own artwork must not be regenerated');
$images=new CategoryImageService($pdo,new RecipePhotoService(['api_key'=>'fixture-only'],CTR_STORAGE,static function(){throw new RuntimeException('Unexpected generation');}),CTR_STORAGE.'/media/recipes','https://ctr.coffeybean.com/media/recipes');
imageCheck($images->ensure('11','1','1')==='https://ctr.coffeybean.com/media/recipes/category-11.jpg','Existing artwork must use its VPS URL');
$pdo->exec("UPDATE recipe_categories SET image_key='' WHERE id=11; UPDATE households SET ai_images_enabled=0 WHERE id=1");
imageCheck($socket->missingCategoryImages($actor,$data)===[],'Disabled household must not generate automatic artwork');
imageCheck($socket->missingCategoryImages($actor,['recipe'=>[]])===[],'Unsaved AI drafts must not trigger artwork');
echo "Category ownership, inherited-image detection, VPS artwork reuse and permission checks passed\n";
