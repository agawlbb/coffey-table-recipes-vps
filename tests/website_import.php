<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/HttpError.php';
require dirname(__DIR__).'/src/RecipeImportService.php';
$checks=0;
function webExpect(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function webReject(callable $call): void { try { $call(); } catch (HttpError $e) { webExpect($e->status===422,'Unexpected error'); return; } throw new RuntimeException('Unsafe website accepted'); }
$config=['api_key'=>'fixture','model'=>'gpt-5.4-mini'];
$base='https://93.184.216.34';
$source=$base.'/recipe3-12345';
$reply=['recipe'=>['title'=>'Fixture soup','ingredients'=>[['original_text'=>'1 cup water','ingredient_name'=>'water']],'steps'=>[['instruction'=>'Simmer 2 minutes','actions'=>[['action_type'=>'timer','duration_seconds'=>120]]]]],'questions'=>[],'website_source_url'=>$source];
$answer=static fn($r,$sources=[])=>['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[
    ['type'=>'web_search_call','status'=>'completed','action'=>['sources'=>array_map(fn($url)=>['url'=>$url],$sources)]],
    ['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode($r)]]]
]])];
webExpect(RecipeImportService::websiteUrl('http://93.184.216.34:80/recipe3.html#top')===$base.'/recipe3.html','HTTP did not upgrade to HTTPS');
foreach (['file:///etc/passwd','http://127.0.0.1','https://93.184.216.34:8080/','https://user:password@93.184.216.34/'] as $url) webReject(fn()=>RecipeImportService::websiteUrl($url));
$calls=[]; $aiCalls=0;
$service=new RecipeImportService($config,sys_get_temp_dir(),function($payload) use (&$aiCalls,$answer,$reply) {
    $aiCalls++; webExpect(!isset($payload['tools']),'Direct extraction unexpectedly uses search'); return $answer($reply);
},function($url) use (&$calls,$base) {
    $calls[]=$url;
    return count($calls)===1 ? ['status'=>301,'redirect'=>$base.'/final'] : ['status'=>200,'type'=>'text/html','html'=>'<script type="application/ld+json">{"@type":"Recipe","name":"Fixture soup","recipeIngredient":["1 cup water"],"recipeInstructions":["Simmer 2 minutes"]}</script>'];
});
$draft=$service->analyze(['kind'=>'website','url'=>'http://93.184.216.34/old'],'fixture');
webExpect(count($calls)===2 && $draft['recipe']['source_url']===$base.'/final' && $aiCalls===1,'Redirect did not preserve final provenance');
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAEklEQVR4nGP4z8DAAMIM/4EAAB/uBfsL2WiLAAAAAElFTkSuQmCC');
$service=new RecipeImportService($config,sys_get_temp_dir(),fn()=>$answer($reply),fn()=>[
    'status'=>200,'type'=>'text/html','html'=>'<script type="application/ld+json">{"@type":"Recipe","name":"Fixture soup","image":"/soup.png","recipeIngredient":["1 cup water"],"recipeInstructions":["Simmer 2 minutes"]}</script>'
],fn()=>['bytes'=>$png]);
$withPhoto=$service->analyze(['kind'=>'website','url'=>$base.'/recipe3.html'],'fixture');
webExpect(count($withPhoto['recipe']['photos'])===1 && $withPhoto['recipe']['photos'][0]['is_cover']===1,'Website photo not added to review draft');
$pictured=$reply; $pictured['website_image_url']=$base.'/soup.png';
$service=new RecipeImportService($config,sys_get_temp_dir(),fn()=>$answer($pictured,[$source]),fn()=>['status'=>403],fn()=>['bytes'=>$png]);
$withPhoto=$service->analyze(['kind'=>'website','url'=>$base.'/recipe3.html'],'fixture');
webExpect(count($withPhoto['recipe']['photos'])===1,'Cached website image not added to draft');
$service=new RecipeImportService($config,sys_get_temp_dir(),fn()=>throw new RuntimeException('AI must not run'),fn()=>['status'=>302,'redirect'=>'http://127.0.0.1/secret']);
webReject(fn()=>$service->analyze(['kind'=>'website','url'=>$base.'/old'],'fixture'));
$blocked=static fn()=>['status'=>403,'type'=>'text/html','html'=>'Access denied'];
$service=new RecipeImportService($config,sys_get_temp_dir(),function($payload) use ($answer,$reply,$source) {
    webExpect($payload['tools'][0]['external_web_access']===false,'Fallback must use cached public content');
    webExpect($payload['tools'][0]['filters']['allowed_domains']===['93.184.216.34'],'Fallback escaped source domain');
    webExpect($payload['max_tool_calls']===3 && $payload['tool_choice']==='required','Search budget or required tool missing');
    return $answer($reply,[$source]);
},$blocked);
$draft=$service->analyze(['kind'=>'website','url'=>$base.'/recipe3.html'],'fixture');
webExpect($draft['recipe']['source_url']===$source && $draft['preprocessing']==='cached website search','Verified cached source not retained');
$service=new RecipeImportService($config,sys_get_temp_dir(),fn()=>$answer($reply,['http://93.184.216.34/recipe3-12345/']),$blocked);
$equivalent=$service->analyze(['kind'=>'website','url'=>$base.'/recipe3.html'],'fixture');
webExpect($equivalent['recipe']['source_url']===$source.'/','Equivalent source protocol/trailing slash failed verification');
foreach (['no_sources','wrong_variant','wrong_domain','no_ingredients'] as $failure) {
    $bad=$reply; $sources=[$source];
    if ($failure==='no_sources') $sources=[];
    if ($failure==='wrong_variant') $sources=[$bad['website_source_url']=$base.'/recipe2-12345'];
    if ($failure==='wrong_domain') $sources=[$bad['website_source_url']='https://example.com/recipe3-12345'];
    if ($failure==='no_ingredients') $bad['recipe']['ingredients']=[];
    $service=new RecipeImportService($config,sys_get_temp_dir(),fn()=>$answer($bad,$sources),$blocked);
    webReject(fn()=>$service->analyze(['kind'=>'website','url'=>$base.'/recipe3.html'],'fixture'));
}
echo "$checks website import checks passed.\n";
