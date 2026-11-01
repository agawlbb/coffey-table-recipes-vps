<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/HttpError.php';require dirname(__DIR__).'/src/RecipeChatService.php';require __DIR__.'/fixtures/context_database.php';
$checks=0;function legacyCheck(bool $ok,string $text):void{global $checks;if(!$ok)throw new RuntimeException($text);$checks++;}
$root=sys_get_temp_dir().'/ctr-legacy-chat-'.bin2hex(random_bytes(6));mkdir($root,0700);mkdir($root.'/recipe-chats',0700);$pdo=contextTestDatabase($root.'/context.sqlite');
$b=['request_id'=>str_repeat('a',32),'turn_id'=>str_repeat('1',32),'mode'=>'create','action'=>'message','message'=>'Make soup','revision'=>0];$identity=hash('sha256',json_encode(['1','2',$b['request_id']]));$path=$root.'/recipe-chats/'.$identity.'.json';
$oldReply=['message'=>'Here is your soup','ready'=>true,'revision'=>1,'recipe_text'=>'Old soup. 2 cups water. Simmer for 2 minutes.'];
$old=['binding'=>['create',null],'revision'=>1,'source_hash'=>null,'recipe_text'=>$oldReply['recipe_text'],'ready'=>true,'messages'=>[['role'=>'user','content'=>'Make soup'],['role'=>'assistant','content'=>json_encode(['message'=>'Here is your soup','recipe_text'=>$oldReply['recipe_text']])]],'turns'=>[$b['turn_id']=>['fingerprint'=>hash('sha256',json_encode(['message',0,'Make soup'])),'response'=>$oldReply]]];
file_put_contents($path,json_encode($old));$calls=0;$payloads=[];$fail=false;
$transport=static function($payload)use(&$calls,&$payloads,&$fail){$calls++;$payloads[]=$payload;if($fail)return ['status'=>503,'body'=>'{}'];
 if($payload['text']['format']['name']==='recipe_chat')$data=['message'=>'We can keep this soup.','show_recipe'=>false,'recipe_action'=>'keep','changes'=>[],'context_updates'=>[]];
 else $data=['recipe'=>['title'=>'Old soup','ingredients'=>[['original_text'=>'2 cups water']],'steps'=>[['instruction'=>'Simmer for 2 minutes.','actions'=>[['action_type'=>'timer','duration_seconds'=>120]]]]],'questions'=>[]];
 return ['status'=>200,'body'=>json_encode(['status'=>'completed','usage'=>['input_tokens'=>100,'output_tokens'=>200,'input_tokens_details'=>['cached_tokens'=>0],'output_tokens_details'=>['reasoning_tokens'=>30]],'output'=>[['content'=>[['type'=>'output_text','text'=>json_encode($data)]]]]])];};
$s=new RecipeChatService($pdo,['api_key'=>'fixture-only','model'=>'fixture'],$root,$transport);
try{
 legacyCheck($s->handle($b,'1','2',null,[])===$oldReply && $calls===0,'Already completed old turns replay exactly without provider work');
 $prepare=array_replace($b,['turn_id'=>str_repeat('2',32),'revision'=>1,'action'=>'prepare','message'=>'']);$fail=true;
 try{$s->handle($prepare,'1','2',null,[]);throw new LogicException('Expected failure');}catch(HttpError $e){legacyCheck(str_contains($e->getMessage(),'chat and draft are still here'),'One-time conversion failure retains useful chat guidance');}
 legacyCheck(json_decode(file_get_contents($path),true)===$old,'A failed legacy conversion preserves the original text and conversation');
 $fail=false;$done=$s->handle($prepare,'1','2',null,[]);legacyCheck($done['draft']['recipe']['title']==='Old soup' && $done['working_recipe']['ingredients'][0]['original_text']==='2 cups water','Existing draft is converted once without losing cooking content');
 $before=$calls;$again=$s->handle(array_replace($prepare,['turn_id'=>str_repeat('3',32),'revision'=>2]),'1','2',null,[]);legacyCheck($calls===$before && $again['draft']['recipe']['steps']===$done['draft']['recipe']['steps'],'Subsequent reviews use local structured data, with no conversion call');
 $s->handle(array_replace($b,['turn_id'=>str_repeat('4',32),'revision'=>3,'message'=>'Just explain the soup.']),'1','2',null,[]);$input=json_decode(end($payloads)['input'][0]['content'],true);
 legacyCheck(!isset($input['legacy_draft'],$input['original_recipe'],$input['previous_recipe']) && $input['working_recipe']['title']==='Old soup','Converted sessions use the same compact provider input as new sessions');
 $state=json_decode(file_get_contents($path),true);legacyCheck(isset($state['working_recipe']) && $state['turns'][$b['turn_id']]['response']===$oldReply,'Original cached evidence is retained while structured state becomes authoritative');
 $metrics=array_map(static fn($line)=>json_decode($line,true),file($root.'/recipe-chat-metrics.log',FILE_IGNORE_NEW_LINES));$converted=array_values(array_filter($metrics,static fn($r)=>!empty($r['legacy_conversion']) && $r['status']==='completed'));
 legacyCheck(count($converted)===1 && $converted[0]['provider_calls']===1 && $converted[0]['input_tokens']===100,'Legacy conversion tokens/calls are measured separately and accurately');
 $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/report-recipe-chat-usage.php').' '.escapeshellarg($root.'/recipe-chat-metrics.log');$report=json_decode(shell_exec($cmd),true);
 legacyCheck($report['operations']['prepare']['provider_calls']===0 && $report['operations']['prepare_legacy']['provider_calls']===2,'Usage report separates local review from one-time/failed legacy conversions');
 legacyCheck($report['operations']['prepare_legacy']['unknown_usage_operations']===1,'Unknown failed-provider usage is reported as unknown, never a false zero');
}finally{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $p){if($p->isDir())rmdir($p->getPathname());else unlink($p->getPathname());}rmdir($root);}
echo "$checks legacy conversion checks passed\n";
