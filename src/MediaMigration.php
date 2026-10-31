<?php
declare(strict_types=1);

/** One-time URL/key rewrite after verified file transfer; external images stay external. */
function migrateRecipeMedia(PDO $pdo,array $manifest,string $directory): array {
    $verified=static function(string $name) use($manifest,$directory): void {
        if (!preg_match('/^(?:(?:[a-f0-9]{48}|[a-f0-9]{64})\.(?:jpg|png|webp)|category-[1-9][0-9]*\.jpg)$/D',$name)) throw new RuntimeException('Unexpected media filename.');
        $entry=$manifest['files'][$name]??null; $path=$directory.'/'.$name;
        if (!is_array($entry) || !preg_match('/^[a-f0-9]{64}$/D',$entry['sha256']??'') || is_link($path) || !is_file($path) || hash_file('sha256',$path)!==$entry['sha256']) throw new RuntimeException('A referenced media file has not been verified.');
    };
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT id,source_url,storage_key FROM recipe_photos FOR UPDATE'); $q->execute(); $photos=$q->fetchAll(PDO::FETCH_ASSOC);
        $update=$pdo->prepare('UPDATE recipe_photos SET source_url=?,storage_key=? WHERE id=?');
        $count=0;
        foreach ($photos as $photo) {
            $name=null;
            if (preg_match('#^(?:(?:media|vps)/recipes|media/recipe-photos)/((?:[a-f0-9]{48}|[a-f0-9]{64})\.(?:jpg|png|webp))$#D',$photo['storage_key']??'',$match)) $name=$match[1];
            elseif (preg_match('#^https?://(?:www\.)?coffeybean\.com/CoffeyTableRecipes/media/(?:recipes|recipe-photos)/((?:[a-f0-9]{48}|[a-f0-9]{64})\.(?:jpg|png|webp))$#D',$photo['source_url']??'',$match)) $name=$match[1];
            elseif (preg_match('#^https?://(?:www\.)?coffeybean\.com/CoffeyTableRecipes/media/#i',$photo['source_url']??'')) throw new RuntimeException('An owned image URL requires review before migration.');
            if ($name===null) continue;
            $verified($name); $url='https://ctr.coffeybean.com/media/recipes/'.$name; $key='vps/recipes/'.$name;
            if ($photo['source_url']!==$url || $photo['storage_key']!==$key) { $update->execute([$url,$key,$photo['id']]); $count++; }
        }
        $categories=$pdo->query("SELECT id,image_key FROM recipe_categories WHERE image_key LIKE 'ai-%'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($categories as $category) {
            if ($category['image_key']!=='ai-'.$category['id']) throw new RuntimeException('Unexpected category artwork key.');
            $verified('category-'.$category['id'].'.jpg');
        }
        $pdo->commit();
        return ['updated_photos'=>$count,'verified_categories'=>count($categories)];
    } catch(Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
