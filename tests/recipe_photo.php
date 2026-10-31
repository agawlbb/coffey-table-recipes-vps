<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/HttpError.php';
require dirname(__DIR__).'/src/RecipePhotoService.php';
$checks=0;
function photoExpect(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function photoReject(callable $action,int $status): void { try { $action(); } catch (HttpError $e) { photoExpect($e->status===$status,'Wrong rejection.'); return; } throw new RuntimeException('Invalid request accepted.'); }
$recipe=['title'=>'Soup','photos'=>[],'ingredients'=>[['original_text'=>'2 cups water']],'steps'=>[['instruction'=>'Simmer 2 minutes']]];
$jpeg='/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD3+iiigD//2Q==';
$directory=sys_get_temp_dir().'/coffey-photo-test-'.bin2hex(random_bytes(6));
$calls=0;
$transport=function(array $payload) use (&$calls,$jpeg): array {
    $calls++;
    photoExpect($payload['model']==='gpt-5.4-mini' && $payload['store']===false && $payload['tool_choice']===['type'=>'image_generation'] && $payload['parallel_tool_calls']===false,'Recipe model or tool selection incorrect.');
    $tool=$payload['tools'][0];
    photoExpect(count($payload['tools'])===1 && $tool['model']==='gpt-image-2.5-flare' && $tool['size']==='816x816' && $tool['quality']==='low' && $tool['output_format']==='jpeg','Cost controls lost.');
    photoExpect(str_contains($payload['input'],'2 cups water') && !str_contains($payload['input'],'private note'),'Prompt content incorrect.');
    return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['type'=>'image_generation_call','status'=>'completed','result'=>$jpeg]]])];
};
try {
    $service=new RecipePhotoService(['api_key'=>'test-only'],$directory,$transport);
    $recipe['notes']='private note';
    $first=$service->generate($recipe,'user','household');
    photoExpect($first['photo']['is_cover']===1 && str_contains($first['photo']['attribution'],'AI-generated'),'Generated origin or cover missing.');
    photoExpect($service->generate($recipe,'user','household')===$first && $calls===2,'Another generation request was not allowed.');
    $bad=$recipe; $bad['photos']=[['source_url'=>'existing']]; photoReject(fn()=>$service->generate($bad,'user','household'),409);
    photoExpect($calls===2,'Existing photo incurred an AI call.');
    $bad=$recipe; $bad['ingredients']=[]; photoReject(fn()=>$service->generate($bad,'user','household'),422);
    $bad=$recipe; $bad['steps'][0]['instruction']=str_repeat('x',17000); photoReject(fn()=>$service->generate($bad,'user','household'),422);
    for ($i=0;$i<4;$i++) { $next=$recipe; $next['title']='Soup '.$i; $service->generate($next,'user','household'); }
    $next['title']='Sixth'; $service->generate($next,'user','household');
    photoExpect($calls===7,'Daily limit still blocks generation.');
    $failure=new RecipePhotoService(['api_key'=>'test-only'],$directory,fn($p)=>['status'=>404,'body'=>'{}']);
    photoReject(fn()=>$failure->generate($recipe,'other','household'),502);
    $invalid=new RecipePhotoService(['api_key'=>'test-only'],$directory,fn($p)=>['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['type'=>'image_generation_call','status'=>'completed','result'=>base64_encode('not an image')]]])]);
    photoReject(fn()=>$invalid->generate($recipe,'invalid','household'),502);
    echo "$checks recipe photo checks passed.\n";
} finally {
    foreach (glob($directory.'/*') ?: [] as $file) unlink($file);
    if (is_dir($directory)) rmdir($directory);
}
