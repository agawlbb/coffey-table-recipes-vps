<?php
declare(strict_types=1);

/** Bounded, local extraction of source pictures; no image-generation request. */
final class SourcePhotoService {
    public static function images(string $bytes,bool $pdf): array {
        if (!$pdf) return self::valid($bytes) ? [$bytes] : [];
        $images=[]; $total=0;
        // JPEG streams used by most photographed recipe PDFs need no PDF renderer.
        $offset=0; $attempts=0;
        while (++$attempts<=24 && count($images)<6 && ($start=strpos($bytes,"\xff\xd8\xff",$offset))!==false) {
            $image=self::jpeg($bytes,$start);
            $offset=$start+3;
            if ($image===null) continue;
            $offset=$start+strlen($image);
            self::add($images,$total,$image);
        }
        // Common RGB/grayscale Flate PDF images can be wrapped as PNG locally.
        $scan=0; $attempts=0;
        if (count($images)<6) {
            while (++$attempts<=256 && preg_match('#\b\d+\s+\d+\s+obj\s*<<#',$bytes,$header,PREG_OFFSET_CAPTURE,$scan)) {
                $scan=$header[0][1]+strlen($header[0][0]);
                $window=substr($bytes,$scan,8192);
                if (!preg_match('#^(.*?)>>\s*stream\r?\n#s',$window,$match) || str_contains($match[1],'endobj')) continue;
                $dict=$match[1]; $dataStart=$scan+strlen($match[0]);
                if (!preg_match('#/Subtype\s*/Image\b#',$dict) || !preg_match('#/Filter\s*/FlateDecode\b#',$dict)
                    || !preg_match('#/BitsPerComponent\s+8\b#',$dict)
                    || !preg_match('#/ColorSpace\s*/(DeviceRGB|DeviceGray)\b#',$dict,$color)
                    || !preg_match('#/Width\s+(\d+)#',$dict,$width) || !preg_match('#/Height\s+(\d+)#',$dict,$height)
                    || !preg_match('#/Length\s+(\d+)\b(?!\s+\d+\s+R)#',$dict,$length)) continue;
                $w=(int)$width[1]; $h=(int)$height[1]; $channels=$color[1]==='DeviceRGB' ? 3 : 1;
                if ($w<1 || $h<1 || $w*$h>4000000 || (int)$length[1]>5000000) continue;
                $raw=@gzuncompress(substr($bytes,$dataStart,(int)$length[1]),$h*($w*$channels+1));
                if ($raw===false) continue;
                preg_match('#/Predictor\s+(\d+)#',$dict,$predictor); $predictor=(int)($predictor[1] ?? 1);
                if ($predictor===1 && strlen($raw)===$w*$h*$channels) {
                    $rows=''; for ($row=0;$row<$h;$row++) $rows.="\0".substr($raw,$row*$w*$channels,$w*$channels);
                } elseif ($predictor>=10 && $predictor<=15 && strlen($raw)===$h*($w*$channels+1)) {
                    $rows=$raw; $valid=true;
                    for ($row=0;$row<$h;$row++) if (ord($rows[$row*($w*$channels+1)])>4) { $valid=false; break; }
                    if (!$valid) continue;
                } else continue;
                $png="\x89PNG\r\n\x1a\n".self::chunk('IHDR',pack('NNCCCCC',$w,$h,8,$channels===3 ? 2 : 0,0,0,0)).self::chunk('IDAT',gzcompress($rows)).self::chunk('IEND','');
                self::add($images,$total,$png);
                if (count($images)>=6) break;
            }
        }
        return $images;
    }
    private static function jpeg(string $bytes,int $start): ?string {
        $position=$start+2; $size=strlen($bytes); $markers=0;
        while (++$markers<=1000 && ($position=strpos($bytes,"\xff",$position))!==false && $position+1<$size) {
            $marker=ord($bytes[$position+1]);
            if ($marker===217) return substr($bytes,$start,$position+2-$start);
            if ($marker===255) { $position++; continue; }
            if ($marker===0 || $marker===1 || ($marker>=208 && $marker<=215)) { $position+=2; continue; }
            if ($marker===216 || $position+3>=$size) return null;
            $length=unpack('n',substr($bytes,$position+2,2))[1];
            if ($length<2 || $position+2+$length>$size) return null;
            $position+=2+$length;
        }
        return null;
    }
    private static function chunk(string $type,string $data): string {
        return pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
    }
    private static function valid(string $bytes): bool {
        $info=@getimagesizefromstring($bytes);
        return $info && strlen($bytes)<=5*1024*1024 && $info[0]*$info[1]<=24000000 && in_array($info['mime'],['image/jpeg','image/png','image/webp'],true);
    }
    private static function add(array &$images,int &$total,string $bytes): void {
        if (!self::valid($bytes) || $total+strlen($bytes)>5*1024*1024 || in_array($bytes,$images,true)) return;
        $images[]=$bytes; $total+=strlen($bytes);
    }
    public static function suggestions(array $regions,array $images,bool $pdf): array {
        $result=[];
        foreach (array_slice($regions,0,6) as $region) {
            if (!is_array($region) || !is_numeric($region['image_index'] ?? null) || (float)$region['image_index']!==floor((float)$region['image_index']) || !isset($images[(int)$region['image_index']])) continue;
            foreach (['left','top','right','bottom'] as $key) if (!is_numeric($region[$key] ?? null) || !is_finite((float)$region[$key]) || $region[$key]<0 || $region[$key]>1) continue 2;
            if ($region['right']<=$region['left'] || $region['bottom']<=$region['top']) continue;
            $result[]=['image_base64'=>base64_encode($images[(int)$region['image_index']]),'crop'=>array_intersect_key($region,array_flip(['left','top','right','bottom'])),
                'caption'=>'Picture from imported recipe','attribution'=>$pdf ? 'From imported PDF' : 'From imported image'];
        }
        return $result;
    }
}
