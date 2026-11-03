<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
$root=sys_get_temp_dir().'/ctr-optimize-'.bin2hex(random_bytes(8));
$media=$root.'/media/recipes'; mkdir($media,0700,true);
$optimizer=new RecipeImageOptimizer($media);
$checks=0;
function imageCheck(bool $ok): void { global $checks; if (!$ok) throw new RuntimeException('Image regression at check '.($checks+1)); $checks++; }
function imageFixture(string $path,int $width,int $height): void {
    $im=imagecreatetruecolor($width,$height);
    imagealphablending($im,false); imagesavealpha($im,true);
    imagefilledrectangle($im,0,0,$width-1,$height-1,imagecolorallocatealpha($im,0,0,0,127));
    imagefilledrectangle($im,20,20,$width-21,$height-21,imagecolorallocate($im,190,70,30));
    imagepng($im,$path); imagedestroy($im);
}
function removeImageFixture(string $path): void {
    if (is_link($path) || !is_dir($path)) { unlink($path); return; }
    foreach (scandir($path) as $name) if ($name!=='.' && $name!=='..') removeImageFixture($path.'/'.$name);
    rmdir($path);
}
try {
    $name=str_repeat('a',48).'.png'; $path=$media.'/'.$name;
    imageFixture($path,2000,1000); $original=file_get_contents($path);
    imageCheck($optimizer->optimizedPath($name)===null);
    imageCheck($optimizer->pending()===[$name]);
    $result=$optimizer->optimize($name); $optimized=$optimizer->optimizedPath($name);
    imageCheck($result['status']==='optimized' && $result['width']===1600 && $result['height']===800);
    imageCheck($result['optimized_bytes']<strlen($original));
    imageCheck(file_get_contents($path)===$original);
    $info=getimagesize($optimized); imageCheck($info['mime']==='image/webp');
    $decoded=imagecreatefromwebp($optimized);
    imageCheck((imagecolorat($decoded,0,0)>>24)===127); imagedestroy($decoded);
    imageCheck($optimizer->pending()===[]);
    imageCheck((new RecipeImageOptimizer($media))->optimize($name)['status']==='already_processed');
    $http=new HttpService(static function(){throw new RuntimeException('Media must not open SQL');},null,null,$media,$root);
    $response=$http->handle('GET','/media/recipes/'.$name);
    imageCheck($response['status']===200 && $response['file']===$optimized && $response['headers']['Content-Type']==='image/webp');
    imageCheck($response['headers']['Access-Control-Allow-Origin']==='*');
    $head=$http->handle('HEAD','/media/recipes/'.$name);
    imageCheck($head['file']===$optimized && (int)$head['headers']['Content-Length']===filesize($optimized));
    imageCheck($http->handle('POST','/media/recipes/'.$name)['status']===405);
    unlink($optimized); // Cache loss gracefully falls back, without losing photos.
    imageCheck($http->handle('GET','/media/recipes/'.$name)['file']===$path);
    imageCheck($optimizer->pending()===[$name] && $optimizer->optimize($name)['status']==='optimized');
    imageFixture($path,1800,1200);
    imageCheck($optimizer->optimizedPath($name)===null && $optimizer->pending()===[$name]);
    imageCheck($optimizer->optimize($name)['status']==='optimized');
    unlink($path);
    imageCheck($optimizer->optimizedPath($name)===null);
    imageCheck($http->handle('GET','/media/recipes/'.$name)['status']===404);
    imageCheck($optimizer->optimize($name)['status']==='missing');
    imageFixture($path,400,200);
    imageCheck($optimizer->optimize($name)['status']==='unchanged');
    imageCheck($optimizer->optimizedPath($name)===null); // No needless recompression/upscaling.
    file_put_contents($path,'broken image');
    imageCheck($optimizer->optimize($name)['status']==='unsupported');
    imageCheck(file_get_contents($path)==='broken image');
    symlink($path,$media.'/category-1.jpg');
    imageCheck(!in_array('category-1.jpg',$optimizer->pending(),true));
    imageCheck($optimizer->optimize('../private.json')['status']==='missing');
    // JPEG EXIF orientation is baked into the optimized pixels before metadata is stripped.
    $jpg=str_repeat('b',48).'.jpg';
    $im=imagecreatetruecolor(2000,1000); imagejpeg($im,$media.'/'.$jpg,95); imagedestroy($im);
    $bytes=file_get_contents($media.'/'.$jpg);
    $exif="Exif\0\0".'II'.pack('vV',42,8).pack('v',1).pack('vvVv',0x0112,3,1,6)."\0\0".pack('V',0);
    file_put_contents($media.'/'.$jpg,substr($bytes,0,2)."\xff\xe1".pack('n',strlen($exif)+2).$exif.substr($bytes,2));
    $result=$optimizer->optimize($jpg);
    imageCheck($result['status']==='optimized' && $result['width']===800 && $result['height']===1600);
    // Large encoded PNG without oversized dimensions still benefits from re-encoding.
    $big=str_repeat('c',48).'.png'; imageFixture($media.'/'.$big,700,500);
    file_put_contents($media.'/'.$big,str_repeat("\0",300000),FILE_APPEND);
    $result=$optimizer->optimize($big);
    imageCheck($result['status']==='optimized' && $result['width']===700 && $result['height']===500);
    $lock=fopen($root.'/media/optimized/worker.lock','c'); flock($lock,LOCK_EX);
    imageCheck($optimizer->optimize($big)['status']==='busy'); flock($lock,LOCK_UN); fclose($lock);
} finally { removeImageFixture($root); }
echo "$checks photo resizing, alpha, orientation, HTTP, restart and failure checks passed.\n";
