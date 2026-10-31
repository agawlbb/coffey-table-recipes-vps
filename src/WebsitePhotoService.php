<?php
declare(strict_types=1);
require_once __DIR__.'/RecipeImportTools.php';
require_once __DIR__.'/SourcePhotoService.php';

final class WebsitePhotoService {
    public static function candidates(string $html,string $page): array {
        $doc=new DOMDocument(); $previous=libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        $pictures=[];
        $walk=static function($node) use (&$walk,&$pictures,$page): void {
            if (!is_array($node)) return;
            if (in_array('Recipe',(array)($node['@type'] ?? []),true)) {
                $pictures=[...$pictures,...RecipeImportTools::images($node['image'] ?? [],$page)];
            }
            foreach ($node as $value) if (is_array($value)) $walk($value);
        };
        foreach ($doc->getElementsByTagName('script') as $script) {
            if (strtolower($script->getAttribute('type'))==='application/ld+json') $walk(json_decode($script->textContent,true));
        }
        if (!$pictures) foreach ($doc->getElementsByTagName('meta') as $meta) {
            if (in_array(strtolower($meta->getAttribute('property') ?: $meta->getAttribute('name')),['og:image','twitter:image'],true)) {
                $pictures=[...$pictures,...RecipeImportTools::images($meta->getAttribute('content'),$page)];
            }
        }
        $unique=[];
        foreach ($pictures as $picture) {
            if (preg_match('~(?:logo|favicon|avatar|placeholder)~i',$picture['source_url'])) continue;
            $unique[$picture['source_url']]=$picture;
        }
        return array_slice(array_values($unique),0,2);
    }

    public static function photo(array $candidates,string $page,?callable $reader=null): ?array {
        foreach (array_slice($candidates,0,2) as $candidate) {
            try {
                $url=$candidate['source_url'] ?? '';
                if (!is_string($url) || RecipeImportTools::resolveUrl($url,$page)!==$url) continue;
                $result=$reader ? $reader($url) : RecipeImportTools::verifyImage($url,true,6);
                $bytes=$result['bytes'] ?? '';
                if (!is_string($bytes) || SourcePhotoService::images($bytes,false)===[]) continue;
                return ['id'=>'new-website-photo','image_base64'=>base64_encode($bytes),'is_cover'=>1,
                    'caption'=>$candidate['caption'] ?? null,'attribution'=>'Photo from '.$page];
            } catch (Throwable $error) { /* A blocked image must not lose a readable recipe. */ }
        }
        return null;
    }
}
