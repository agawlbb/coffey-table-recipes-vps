<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/SourcePhotoService.php';
$checks=0;
function sourceExpect(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
$jpeg=base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD3+iiigD//2Q==');
sourceExpect(SourcePhotoService::images($jpeg,false)===[$jpeg],'Image upload did not become a candidate.');
sourceExpect(SourcePhotoService::images('%PDF-1.4 random '.$jpeg.' end',true)===[$jpeg],'PDF JPEG not extracted.');
$raw=gzcompress("\xff\0\0");
$pdf='%PDF-1.4 1 0 obj << /Subtype /Image /Width 1 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceRGB /Filter /FlateDecode /Length '.strlen($raw).">> stream\n".$raw."\nendstream endobj";
$png=SourcePhotoService::images($pdf,true);
sourceExpect(count($png)===1 && getimagesizefromstring($png[0])['mime']==='image/png','Flate image not decoded locally.');
$region=['image_index'=>0,'left'=>0,'top'=>0,'right'=>1,'bottom'=>1];
$found=SourcePhotoService::suggestions([$region],[$jpeg],true);
sourceExpect(count($found)===1 && base64_decode($found[0]['image_base64'])===$jpeg && $found[0]['attribution']==='From imported PDF','Source provenance lost.');
$bad=$region; $bad['image_index']=999; sourceExpect(SourcePhotoService::suggestions([$bad],[$jpeg],false)===[],'Unknown image accepted.');
$bad=$region; $bad['left']=1; sourceExpect(SourcePhotoService::suggestions([$bad],[$jpeg],false)===[],'Empty crop accepted.');
$bad=$region; $bad['top']=-1; sourceExpect(SourcePhotoService::suggestions([$bad],[$jpeg],false)===[],'Negative crop accepted.');
sourceExpect(SourcePhotoService::images('%PDF-1.4 text only',true)===[],'Text PDF invented a photo.');
$floatIndex=$region; $floatIndex['image_index']=0.0;
sourceExpect(count(SourcePhotoService::suggestions([$floatIndex],[$jpeg],false))===1,'Integer-valued JSON number was lost.');
sourceExpect(SourcePhotoService::images('%PDF-1.4 '.substr($jpeg,0,-2),true)===[],'Truncated PDF JPEG became a suggestion.');
echo "$checks source photo checks passed.\n";
