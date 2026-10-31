<?php
declare(strict_types=1);

/** Development import helpers; not an HTTP endpoint. */
final class RecipeImportTools
{
    public const BOOKKEEPING = 'Original JSON-LD retained. Uncertain units and serving interpretation left unstructured. Source nutrition retained in source_text, not validated.';

    public static function resolveUrl(string $reference, string $base): ?string
    {
        $reference = trim(html_entity_decode($reference, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($reference === '' || preg_match('/[\x00-\x20\\\\]/', $reference)) return null;
        $b = parse_url($base);
        if (!$b || !isset($b['scheme'], $b['host']) || !in_array(strtolower($b['scheme']), ['http','https'], true)) return null;
        if (str_starts_with($reference, '//')) $reference = $b['scheme'].':'.$reference;
        elseif (!preg_match('/^[a-z][a-z0-9+.-]*:/i', $reference)) {
            $origin = $b['scheme'].'://'.$b['host'].(isset($b['port'])?':'.$b['port']:'');
            if (str_starts_with($reference, '?')) $reference = $origin.($b['path']??'/').$reference;
            elseif (str_starts_with($reference, '#')) return null;
            else {
                $path = str_starts_with($reference, '/') ? $reference : preg_replace('~/[^/]*$~','/', $b['path']??'/').$reference;
                $reference = $origin.$path;
            }
        }
        $p = parse_url($reference);
        if (!$p || !isset($p['scheme'],$p['host']) || !in_array(strtolower($p['scheme']),['http','https'],true) || isset($p['user']) || isset($p['pass'])) return null;
        if (isset($p['port']) && !in_array($p['port'],[80,443],true)) return null;
        $segments=[];
        foreach(explode('/',$p['path']??'/') as $part) {
            if ($part==='.' || $part==='') continue;
            if ($part==='..') array_pop($segments); else $segments[]=$part;
        }
        return strtolower($p['scheme']).'://'.$p['host'].(isset($p['port'])?':'.$p['port']:'').'/'.implode('/',$segments).(isset($p['query'])?'?'.$p['query']:'');
    }

    /** Accept only Recipe.image values, never all images found on a page. */
    public static function images($value, string $page): array
    {
        $found=[];
        $visit = function($v) use (&$visit,&$found,$page): void {
            if (is_string($v)) { $url=self::resolveUrl($v,$page); if($url)$found[$url]=['source_url'=>$url,'caption'=>null]; return; }
            if (!is_array($v)) return;
            if (isset($v['contentUrl']) || isset($v['url'])) {
                $ref=$v['contentUrl']??$v['url'];
                if(is_string($ref) && ($url=self::resolveUrl($ref,$page))) $found[$url]=['source_url'=>$url,'caption'=>isset($v['caption'])&&is_string($v['caption'])?$v['caption']:null];
                return;
            }
            if(isset($v[0])) foreach($v as $item) $visit($item);
        };
        $visit($value);
        return array_values($found);
    }

    public static function publicIp(string $ip): bool
    {
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) return false;
        if (str_contains($ip,':')) {
            $packed=inet_pton($ip);
            if($packed===false || (ord($packed[0])&0xe0)!==0x20)return false;
            $hex=bin2hex($packed);
            return !str_starts_with($hex,'20010db8') && !str_starts_with($hex,'2002')
                && !(str_starts_with($hex,'2001') && hexdec(substr($hex,4,4))<0x0200)
                && !(str_starts_with($hex,'3fff') && hexdec(substr($hex,4,4))<0x1000);
        }
        $n=explode('.',$ip);
        $prefix=implode('.',array_slice($n,0,3));
        return !($n[0]==='100' && (int)$n[1]>=64 && (int)$n[1]<=127) && $n[0]!=='0' && (int)$n[0]<224
            && !in_array($prefix,['192.0.0','192.0.2','192.88.99','198.51.100','203.0.113'],true)
            && !($n[0]==='198' && in_array($n[1],['18','19'],true));
    }

    /** Pin DNS per request; manually validate every redirect. Bytes are memory-only. */
    public static function verifyImage(string $url, bool $includeBytes=false, int $timeout=20): array
    {
        $original=$url;
        $deadline=microtime(true)+$timeout;
        for($hop=0;$hop<5;$hop++) {
            if(self::resolveUrl($url,$url)!==$url) throw new RuntimeException('Invalid HTTP image URL');
            $p=parse_url($url); $host=$p['host']; $plain=trim($host,'[]');
            $ips=filter_var($plain,FILTER_VALIDATE_IP)?[$plain]:array_values(array_unique(array_merge(array_column(dns_get_record($plain,DNS_A)?:[],'ip'),array_column(dns_get_record($plain,DNS_AAAA)?:[],'ipv6'))));
            if(!$ips) throw new RuntimeException('DNS lookup failed');
            foreach($ips as $ip) if(!self::publicIp($ip)) throw new RuntimeException('Non-public image destination rejected');
            $port=$p['port']??($p['scheme']==='https'?443:80);
            $body=''; $location=null; $headerBytes=0;
            $curl=curl_init($url);
            curl_setopt_array($curl,[CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_PROXY=>'',CURLOPT_CONNECTTIMEOUT=>min(8,$timeout),CURLOPT_TIMEOUT=>max(1,(int)ceil($deadline-microtime(true))),CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_USERAGENT=>'CoffeyTableRecipes/1.0 source-image-verification',CURLOPT_HTTPHEADER=>['Accept: image/*'],
                CURLOPT_RESOLVE=>[$plain.':'.$port.':'.(str_contains($ips[0],':')?'['.$ips[0].']':$ips[0])],
                CURLOPT_HEADERFUNCTION=>static function($c,string $header) use (&$location,&$headerBytes): int {
                    $headerBytes+=strlen($header); if($headerBytes>65536)return 0;
                    if(stripos($header,'location:')===0)$location=trim(substr($header,9)); return strlen($header);
                },
                CURLOPT_WRITEFUNCTION=>static function($c,string $chunk) use (&$body,$deadline): int {
                    if(strlen($body)+strlen($chunk)>8*1024*1024 || microtime(true)>$deadline)return 0;
                    $body.=$chunk; return strlen($chunk);
                }]);
            $ok=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            $type=strtolower(trim(explode(';',(string)curl_getinfo($curl,CURLINFO_CONTENT_TYPE))[0])); $error=curl_error($curl); curl_close($curl);
            if($ok===false)throw new RuntimeException('Image fetch failed: '.$error);
            if(in_array($status,[301,302,303,307,308],true)) {
                $next=$location?self::resolveUrl($location,$url):null; if(!$next)throw new RuntimeException('Invalid image redirect'); $url=$next; continue;
            }
            if($status!==200 || !str_starts_with($type,'image/'))throw new RuntimeException('Image unavailable (HTTP '.$status.', '.$type.')');
            $info=@getimagesizefromstring($body);
            if(!$info || ($info['mime']??null)!==$type || !in_array($type,['image/jpeg','image/png','image/webp','image/gif'],true)) throw new RuntimeException('Invalid or unsupported image bytes');
            return ['source_url'=>$original,'verified_final_url'=>$url,'mime_type'=>$type,'width_pixels'=>$info[0],'height_pixels'=>$info[1],'verified_at_utc'=>gmdate('c')]+($includeBytes ? ['bytes'=>$body] : []);
        }
        throw new RuntimeException('Too many image redirects');
    }

    public static function excludedCategory(string $url, string $label): ?string
    {
        $p=parse_url($url);
        if(in_array(strtolower($p['host']??''),['bobsredmill.com','www.bobsredmill.com'],true) && rtrim($p['path']??'','/')==='/recipes/how-to-make/bran-flax-muffins') {
            return ["Bob & Charlee's Recipe Box"=>'publisher collection','Category'=>'taxonomy heading','Dietary Needs'=>'taxonomy heading','Soy Free'=>'unverified source dietary claim'][$label]??null;
        }
        return null;
    }

    public static function yieldValue($yield, array $steps, string $url): ?string
    {
        $text=$yield===null?null:(is_array($yield)?implode('; ',$yield):(string)$yield);
        if($text==='15 people' && self::excludedCategory($url,'Category') && preg_grep('/Yield:\s*15 medium muffins/i',$steps))return '15 medium muffins';
        if($text!==null && preg_match('/^(\d+)\s*;\s*(\d+)\s+(serves|servings|serving\(s\))$/i',$text,$m) && $m[1]===$m[2]) return $m[2].' '.$m[3];
        return $text;
    }

    public static function pendingPhotos(array $existing,array $verified,string $attribution): array
    {
        $urls=array_column($existing,'source_url'); $position=0; $cover=false;
        foreach($existing as $photo){$position=max($position,(int)$photo['position']);$cover=$cover || (bool)$photo['is_cover'];}
        $pending=[];
        foreach($verified as $photo){
            if(in_array($photo['source_url'],$urls,true))continue;
            $urls[]=$photo['source_url'];
            $pending[]=['position'=>++$position,'source_url'=>$photo['source_url'],'storage_key'=>null,'mime_type'=>$photo['mime_type'],'width_pixels'=>$photo['width_pixels'],'height_pixels'=>$photo['height_pixels'],'caption'=>$photo['caption']??null,'attribution'=>$attribution,'is_cover'=>$cover?0:1]; $cover=true;
        }
        return $pending;
    }
}
