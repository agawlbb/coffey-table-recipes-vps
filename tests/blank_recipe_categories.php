<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/HttpError.php';
require dirname(__DIR__).'/src/BlankRecipeCategoryService.php';
$checks=0;
function expectCategory(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function rejectCategory(callable $action,int $status): void { try { $action(); } catch (HttpError $e) { expectCategory($e->status===$status,'Unexpected category rejection.'); return; } throw new RuntimeException('Invalid category accepted.'); }
$catalog=[['id'=>'1','label'=>'Desserts','parent_label'=>null],['id'=>'2','label'=>'Cakes','parent_label'=>'Desserts']];
$directory=sys_get_temp_dir().'/coffey-blank-category-test-'.bin2hex(random_bytes(6));
$calls=0;
function categoryReply(mixed $value): array { return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode($value)]]]]])]; }
try {
    $service=new BlankRecipeCategoryService(['api_key'=>'fixture-key','model'=>'gpt-5.4-mini'],$directory,function($payload) use (&$calls) {
        $calls++;
        expectCategory($payload['store']===false && !isset($payload['tools']) && $payload['max_output_tokens']<=2000,'Category request exceeded scope.');
        $data=json_decode($payload['input'][0]['content'][0]['text'],true);
        expectCategory(array_keys($data)===['name','categories'] && $data['name']==='Birthday cake','Unexpected private input.');
        expectCategory($payload['text']['format']['strict']===true && $payload['text']['format']['schema']['properties']['parent_label']['enum']===['Desserts'],'Parent schema not constrained.');
        return categoryReply(['label'=>'Cakes','parent_label'=>'Desserts']);
    });
    $category=$service->categorize('Birthday cake',$catalog,'1');
    expectCategory($category===['label'=>'Cakes','parent_label'=>'Desserts'],'Chosen category changed.');
    expectCategory($service->categorize('Birthday cake',$catalog,'1')===$category && $calls===1,'Retry did not reuse cached classification.');
    rejectCategory(fn()=>$service->categorize('', $catalog,'1'),422);
    rejectCategory(fn()=>$service->categorize('Birthday cake', [],'1'),503);
    foreach ([null,[],['label'=>'','parent_label'=>'Desserts'],['label'=>'Cakes','parent_label'=>'Unknown'],['label'=>str_repeat('x',121),'parent_label'=>'Desserts']] as $i=>$value) {
        $invalid=new BlankRecipeCategoryService(['api_key'=>'fixture-key'],$directory,fn($p)=>categoryReply($value));
        rejectCategory(fn()=>$invalid->categorize('Cake',$catalog,'invalid-'.$i),502);
    }
    foreach ([['status'=>429,'body'=>'{}'],['status'=>0,'body'=>''],['status'=>200,'body'=>'broken'],['status'=>200,'body'=>json_encode(['status'=>'incomplete','output'=>[]])],['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'refusal','refusal'=>'No']]]]])]] as $i=>$reply) {
        $failure=new BlankRecipeCategoryService(['api_key'=>'fixture-key'],$directory,fn($p)=>$reply);
        rejectCategory(fn()=>$failure->categorize('Cake',$catalog,'failure-'.$i),502);
    }
    $proposal=new BlankRecipeCategoryService(['api_key'=>'fixture-key'],$directory,fn($p)=>categoryReply(['label'=>'Cookies','parent_label'=>'Desserts']));
    expectCategory($proposal->categorize('Cookies',$catalog,'proposal')['label']==='Cookies','Valid subtype proposal rejected.');
    echo "Blank category AI: $checks checks passed.\n";
} finally {
    foreach (glob($directory.'/*') ?: [] as $file) unlink($file);
    if (is_dir($directory)) rmdir($directory);
}
