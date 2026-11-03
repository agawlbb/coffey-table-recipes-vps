<?php
declare(strict_types=1);

/** Derived images only: saving, SQL metadata and recoverable originals stay untouched. */
final class RecipeImageOptimizer {
    private const VERSION = 'webp-1600-q82-v1';
    public function __construct(private string $mediaDirectory) {}

    private function source(string $name): ?array {
        if (!preg_match('/^(?:(?:[a-f0-9]{48}|[a-f0-9]{64}|[a-z][a-z0-9-]*-ai-v[1-9][0-9]*)\.(?:jpg|png|webp)|category-[1-9][0-9]*\.jpg)$/D', $name)) return null;
        $path=$this->mediaDirectory.'/'.$name;
        clearstatcache(true,$path);
        if (is_link($path) || !is_file($path)) return null;
        $stat=stat($path);
        if ($stat===false) return null;
        $key=hash('sha256',self::VERSION.'|'.$name.'|'.$stat['size'].'|'.$stat['mtime'].'|'.$stat['ctime'].'|'.$stat['ino']);
        return ['path'=>$path,'key'=>$key,'bytes'=>$stat['size']];
    }
    private function cache(): string { return dirname($this->mediaDirectory).'/optimized'; }
    private function completed(array $source): bool {
        $receipt=$this->cache().'/'.$source['key'].'.done';
        if (!is_file($receipt)) return false;
        $result=json_decode(file_get_contents($receipt),true);
        return is_array($result) && (($result['status']??'')!=='optimized' || is_file($this->cache().'/'.$source['key'].'.webp'));
    }

    public function optimizedPath(string $name): ?string {
        $source=$this->source($name);
        if (!$source) return null;
        $path=$this->cache().'/'.$source['key'].'.webp';
        return !is_link($path) && is_file($path) ? $path : null;
    }

    /** Directory scanning happens in the background worker, never in an upload request. */
    public function pending(): array {
        $pending=[];
        foreach (scandir($this->mediaDirectory) ?: [] as $name) {
            $source=$this->source($name);
            if ($source && !$this->completed($source)) $pending[]=$name;
        }
        return $pending;
    }

    public function optimize(string $name): array {
        $source=$this->source($name);
        if (!$source) return ['file'=>$name,'status'=>'missing'];
        if (!is_dir($this->cache()) && !mkdir($this->cache(),0700,true) && !is_dir($this->cache())) throw new RuntimeException('Cannot create optimized media directory.');
        $lock=fopen($this->cache().'/worker.lock','c');
        if (!$lock) throw new RuntimeException('Cannot open image worker lock.');
        if (!flock($lock,LOCK_EX|LOCK_NB)) { fclose($lock); return ['file'=>$name,'status'=>'busy']; }
        $temporary=null; $image=null; $scaled=null;
        try {
            $receipt=$this->cache().'/'.$source['key'].'.done';
            if ($this->completed($source)) return ['file'=>$name,'status'=>'already_processed'];
            $result=['file'=>$name,'status'=>'unchanged','original_bytes'=>$source['bytes']];
            $info=@getimagesize($source['path']);
            if (!$info || !in_array($info['mime'],['image/jpeg','image/png','image/webp'],true) || $info[0]*$info[1]>24000000) {
                $result['status']='unsupported';
            } elseif (max($info[0],$info[1])>1600 || $source['bytes']>250000) {
                if (!function_exists('imagewebp')) throw new RuntimeException('Image worker requires GD with WebP support.');
                $image=match($info['mime']) {
                    'image/jpeg'=>@imagecreatefromjpeg($source['path']),
                    'image/png'=>@imagecreatefrompng($source['path']),
                    'image/webp'=>@imagecreatefromwebp($source['path']),
                };
                if (!$image) throw new RuntimeException('Cannot decode uploaded image.');
                if ($info['mime']==='image/jpeg') {
                    $exif=@exif_read_data($source['path']);
                    $orientation=(int)($exif['Orientation']??1);
                    if (in_array($orientation,[2,4,5,7],true)) imageflip($image,IMG_FLIP_HORIZONTAL);
                    $angle=match($orientation) {3,4=>180,6,7=>-90,5,8=>90,default=>0};
                    if ($angle) $image=imagerotate($image,$angle,0);
                }
                $width=imagesx($image); $height=imagesy($image);
                $ratio=min(1,1600/max($width,$height));
                $w=max(1,(int)round($width*$ratio)); $h=max(1,(int)round($height*$ratio));
                $scaled=imagecreatetruecolor($w,$h);
                imagealphablending($scaled,false); imagesavealpha($scaled,true);
                imagecopyresampled($scaled,$image,0,0,0,0,$w,$h,$width,$height);
                $temporary=tempnam($this->cache(),'.encoding-');
                if ($temporary===false || !imagewebp($scaled,$temporary,82)) throw new RuntimeException('Cannot encode optimized photo.');
                $output=@getimagesize($temporary);
                $bytes=filesize($temporary);
                if (!$output || $output['mime']!=='image/webp' || $output[0]!==$w || $output[1]!==$h || !$bytes) throw new RuntimeException('Invalid optimized photo.');
                // A changed/deleted source must never publish a stale result.
                if (($this->source($name)['key']??null)!==$source['key']) return ['file'=>$name,'status'=>'source_changed'];
                if ($bytes<$source['bytes'] && ($ratio<1 || $bytes<=$source['bytes']*.9)) {
                    chmod($temporary,0600);
                    if (!rename($temporary,$this->cache().'/'.$source['key'].'.webp')) throw new RuntimeException('Cannot publish optimized photo.');
                    $temporary=null;
                    $result+=['optimized_bytes'=>$bytes,'width'=>$w,'height'=>$h];
                    $result['status']='optimized';
                }
            }
            if (file_put_contents($receipt,json_encode($result,JSON_THROW_ON_ERROR),LOCK_EX)===false) throw new RuntimeException('Cannot record image optimization.');
            return $result;
        } finally {
            if ($temporary && is_file($temporary)) unlink($temporary);
            if ($scaled instanceof GdImage) imagedestroy($scaled);
            if ($image instanceof GdImage) imagedestroy($image);
            flock($lock,LOCK_UN); fclose($lock);
        }
    }
}
