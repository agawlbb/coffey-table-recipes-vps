<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/WebsitePhotoService.php';
$checks=0;
function photoExpect(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
$page='https://example.com/recipes/soup';
$html='<script type="application/ld+json">{"@graph":[{"@type":"Recipe","image":[{"@type":"ImageObject","contentUrl":"/soup.png","caption":"Soup"},"https://cdn.example.com/soup.jpg"]}]}</script><meta property="og:image" content="/logo.png">';
$images=WebsitePhotoService::candidates($html,$page);
photoExpect(count($images)===2 && $images[0]['source_url']==='https://example.com/soup.png' && $images[0]['caption']==='Soup','Recipe image objects/relative URLs not extracted');
photoExpect(WebsitePhotoService::candidates('<meta property="og:image" content="//cdn.example.com/dish.webp">',$page)[0]['source_url']==='https://cdn.example.com/dish.webp','Open Graph fallback failed');
photoExpect(WebsitePhotoService::candidates('<meta property="og:image" content="/favicon.png">',$page)===[],'Publisher icon should not be a recipe photo');
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAEklEQVR4nGP4z8DAAMIM/4EAAB/uBfsL2WiLAAAAAElFTkSuQmCC');
$photo=WebsitePhotoService::photo($images,$page,fn()=>['bytes'=>$png]);
photoExpect($photo['is_cover']===1 && $photo['image_base64']===base64_encode($png),'Verified source image bytes not added as cover');
photoExpect($photo['attribution']==='Photo from '.$page && $photo['caption']==='Soup','Source credit or caption lost');
photoExpect(WebsitePhotoService::photo($images,$page,fn()=>throw new RuntimeException('blocked'))===null,'Blocked picture must not block import');
photoExpect(WebsitePhotoService::photo($images,$page,fn()=>['bytes'=>'Not an image'])===null,'Invalid image bytes accepted');
photoExpect(WebsitePhotoService::photo([['source_url'=>'http://127.0.0.1/picture.png']],$page)===null,'Private image destination accepted');
echo "$checks website photo checks passed.\n";
