<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/HttpError.php';
require dirname(__DIR__).'/src/RecipeImportService.php';
require dirname(__DIR__).'/src/RecipeVariationService.php';
$checks=0;
function expectVariation(bool $value,string $message): void { global $checks; if (!$value) throw new RuntimeException($message); $checks++; }
function variationError(callable $operation,string $code): void { try { $operation(); } catch (HttpError $error) { expectVariation($error->errorCode===$code,'Expected '.$code.', got '.$error->errorCode); return; } throw new RuntimeException('Expected '.$code); }
expectVariation(RecipeVariationService::accessPolicy([])==='all_members','Every household member has access by default');
variationError(fn()=>RecipeVariationService::accessPolicy(['variation_access'=>'disabled']),'variation_unavailable');
variationError(fn()=>RecipeVariationService::accessPolicy(['variation_access'=>'typo']),'variation_unavailable');
expectVariation(RecipeVariationService::accessPolicy(['variation_access'=>'all_members'])==='all_members','Missing billing does not restrict membership access');
expectVariation(RecipeVariationService::accessPolicy(['variation_access'=>'all_members','variation_billing'=>'paid'])==='all_members','Paid billing does not restrict membership access');
expectVariation(RecipeVariationService::accessPolicy(['variation_access'=>'all_members','variation_billing'=>'complimentary_verified'])==='all_members','Explicit verified complimentary config');
expectVariation(RecipeVariationService::accessPolicy(['variation_access'=>'ai_images','variation_billing'=>'complimentary_verified'])==='ai_images','Narrow policy preserved');
$directory=sys_get_temp_dir().'/variation-tests-'.bin2hex(random_bytes(8)); mkdir($directory,0700);
$payloads=[];
$config=['api_key'=>'mock-only','model'=>'gpt-5.4-mini','variation_model'=>'fixture-model','variation_access'=>'ai_images'];
$transport=static function(array $payload) use (&$payloads): array {
    $payloads[]=$payload;
    $result=['recipe'=>['title'=>'Chickpea curry','description'=>'A mild chickpea curry.','original_language'=>'en','prep_minutes'=>5,'cook_minutes'=>10,'total_minutes'=>15,'ingredients'=>[['original_text'=>'1 can chickpeas','ingredient_name'=>'chickpeas']],'steps'=>[['instruction'=>'Simmer the chickpeas for 10 minutes.','actions'=>[['action_type'=>'timer','duration_seconds'=>600]]]]],
        'variation_label'=>'chickpeas','change_description'=>'Replaced raw chicken with canned chickpeas, renamed the curry and shortened simmering to 10 minutes because the chickpeas are already cooked.',
        'questions'=>count($payloads)===1 ? ['How spicy?'] : []];
    return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode($result)]]]]])];
};
$service=new RecipeVariationService($config,$directory,$transport);
$source=['id'=>'4','title'=>'Chicken curry','household_id'=>'secret-household','created_by_user_id'=>'secret-person','related'=>[['title'=>'secret-other-recipe']],'photos'=>[['source_url'=>'secret-url']],'nutrition'=>['calories_kcal'=>999],'ingredients'=>[['id'=>'secret-ingredient-id','original_text'=>'1 chicken','ingredient_name'=>'chicken']],'steps'=>[['id'=>'secret-step-id','instruction'=>'Cook chicken.']]];
$source['groups']=[['id'=>'secret-group-1','title'=>'Sauce'],['id'=>'secret-group-2','title'=>'Topping']];
$source['ingredients'][0]['group_id']='secret-group-1';
$source['ingredients'][]=['id'=>'secret-ingredient-2','group_id'=>'secret-group-2','original_text'=>'1 chicken','ingredient_name'=>'chicken'];
$source['actions']=[
    ['id'=>'secret-action-1','step_id'=>'secret-step-id','action_type'=>'appliance_temperature','label'=>'Preheat','temperature_value'=>180,'temperature_unit'=>'C','appliance'=>'oven'],
    ['id'=>'secret-action-2','step_id'=>'secret-step-id','action_type'=>'timer','label'=>'Cook','duration_seconds'=>1200],
];
$body=['changes'=>'Use chickpeas instead of chicken','request_id'=>str_repeat('a',32),'parent_recipe_id'=>'999','recipe'=>['id'=>'999']];
$source['original_language']='en';
try {
    $concurrentBody=array_replace($body,['request_id'=>str_repeat('7',32)]);
    $concurrentCalls=0;
    $concurrent=null;
    $concurrentTransport=static function(array $payload) use (&$concurrent,&$concurrentCalls,$source,$concurrentBody,$transport): array {
        $concurrentCalls++;
        variationError(fn()=>$concurrent->analyze($source,$concurrentBody,'user-a','house-a'),'variation_in_progress');
        return $transport($payload);
    };
    $concurrent=new RecipeVariationService($config,$directory,$concurrentTransport);
    $completed=$concurrent->analyze($source,$concurrentBody,'user-a','house-a');
    $retryService=new RecipeVariationService($config,$directory,$concurrentTransport);
    expectVariation($retryService->analyze($source,$concurrentBody,'user-a','house-a')===$completed,'A fresh request worker recovers the same completed draft after a lost response');
    expectVariation($concurrentCalls===1,'Concurrent and completed retries never invoke a second provider call');
    $events=file_get_contents($directory.'/variation-events.log');
    expectVariation(str_contains($events,'"event":"recovered"') && str_contains($events,'"event":"busy"'),'Private diagnostics distinguish ongoing and recovered requests');
    expectVariation(!str_contains($events,'chickpeas') && !str_contains($events,'user-a') && !str_contains($events,'house-a'),'Diagnostics never include input or account identities');
    $payloads=[];
    $timedOut=new RecipeVariationService($config,$directory,static fn($payload)=>['status'=>0,'body'=>false,'error'=>28]);
    variationError(fn()=>$timedOut->analyze($source,array_replace($body,['request_id'=>str_repeat('8',32)]),'user-a','house-a'),'ai_timeout');
    $draft=$service->analyze($source,$body,'user-a','house-a');
    expectVariation(count($payloads)===1,'One mock call');
    expectVariation($payloads[0]['model']==='fixture-model' && $payloads[0]['store']===false,'Configured model and no response storage');
    expectVariation(str_contains($payloads[0]['instructions'],'Create ONE practical recipe variation'),'Variation task instructions');
    $format=$payloads[0]['text']['format']['schema'];
    expectVariation(in_array('change_description',$format['required'],true) && !isset($format['properties']['recipe']['properties']['change_description']),'Explanation required outside recipe schema');
    expectVariation(!isset(RecipeImportService::responseFormat()['schema']['properties']['change_description']),'Ordinary imports keep their response contract');
    expectVariation(str_contains($format['properties']['change_description']['description'],'Required output language for this request: en.'),'Explanation schema pins English for English recipe');
    expectVariation($format['properties']['recipe']['properties']['original_language']['enum']===['en'],'Recipe language is constrained to the source language');
    expectVariation(str_contains($payloads[0]['instructions'],'Write change_description and follow-up questions in the SAME language'),'Explanation language follows source prose');
    expectVariation($draft['recipe']['original_language']==='en','English source retains English recipe language');
    expectVariation(str_contains($payloads[0]['instructions'],'if it conflicts with the actual recipe prose, follow the prose'),'Stale language metadata must not override recipe prose');
    $serialized=json_encode($payloads[0]);
    foreach(['secret-household','secret-person','secret-other-recipe','secret-url','secret-ingredient-id','secret-step-id','secret-group-1','secret-group-2','secret-ingredient-2','secret-action-1','secret-action-2'] as $secret) expectVariation(!str_contains($serialized,$secret),'Only cooking content sent');
    $content=$payloads[0]['input'][0]['content'][0]['text'];
    $submitted=json_decode(substr($content,strpos($content,'{')),true)['original_recipe'];
    expectVariation(array_column($submitted['ingredients'],'group_title')===['Sauce','Topping'],'Duplicate ingredient group context retained');
    expectVariation($submitted['steps'][0]['actions'][0]['temperature_value']===180 && $submitted['steps'][0]['actions'][0]['temperature_unit']==='C','Structured-only oven temperature retained');
    expectVariation($submitted['steps'][0]['actions'][1]['duration_seconds']===1200,'Structured-only timer retained');
    expectVariation($draft['recipe']['parent_recipe_id']==='4' && !isset($draft['recipe']['id']),'Server owns relationship; new draft');
    expectVariation($draft['recipe']['source_type']==='ai_variation' && $draft['recipe']['is_variation']===true,'Explicit variation provenance');
    expectVariation($draft['recipe']['photos']===[] && $draft['recipe']['nutrition']===null,'No copied photos or nutrition');
    expectVariation(str_contains($draft['change_description'],'already cooked') && !isset($draft['recipe']['change_description']),'Explanation returned separately');
    expectVariation($draft['recipe']['description']==='A mild chickpea curry.' && ($draft['recipe']['notes'] ?? null)===null,'Recipe description and notes do not contain comparison');
    expectVariation($draft['recipe']['title']==='Chicken curry - chickpeas' && $draft['recipe']['cook_minutes']===10 && $draft['recipe']['total_minutes']===15,'Revised title and timing survive normalization');
    expectVariation(in_array('variation_label',$format['required'],true) && !isset(RecipeImportService::responseFormat()['schema']['properties']['variation_label']),'Short variation label is required only for variations');
    expectVariation($draft['recipe']['steps'][0]['instruction']==='Simmer the chickpeas for 10 minutes.' && $draft['recipe']['actions'][0]['duration_seconds']===600,'Revised method and timer survive normalization');
    expectVariation($service->analyze($source,$body,'user-a','house-a')===$draft && count($payloads)===1,'Retry reuses draft');
    $answered=$body+['answers'=>[['question'=>'How spicy?','answer'=>'Mild']]];
    $ready=$service->analyze($source,$answered,'user-a','house-a');
    expectVariation($ready['questions']===[] && count($payloads)===2,'Answers produce next draft');
    expectVariation($ready['change_description']===$draft['change_description'],'Clarification result retains explanation');
    expectVariation($ready['recipe']['import_key']===$draft['recipe']['import_key'],'Stable save identity across clarification');
    expectVariation($service->analyze($source,$body,'user-a','house-a')===$draft && count($payloads)===2,'Earlier round retry cached');
    $other=$service->analyze($source,$body,'user-b','house-a');
    expectVariation($other['recipe']['import_key']!==$draft['recipe']['import_key'],'User cache isolation');
    $other=$service->analyze($source,$body,'user-a','house-b');
    expectVariation($other['recipe']['import_key']!==$draft['recipe']['import_key'],'Household cache isolation');
    variationError(fn()=>$service->analyze($source,array_replace($body,['changes'=>'Different']),'user-a','house-a'),'variation_changed');
    foreach([['request_id'=>'../unsafe'],['changes'=>''],['answers'=>[['question'=>[], 'answer'=>'x']]],['changes'=>str_repeat('x',4001)]] as $invalid) variationError(fn()=>$service->analyze($source,array_replace($body,$invalid),'user-a','house-a'),'invalid_variation');
    $failed=new RecipeVariationService($config,$directory,static fn()=>['status'=>429,'body'=>'{}']);
    $retryBody=array_replace($body,['request_id'=>str_repeat('b',32)]);
    variationError(fn()=>$failed->analyze($source,$retryBody,'user-a','house-a'),'ai_unavailable');
    $retry=$service->analyze($source,$retryBody,'user-a','house-a');
    expectVariation(isset($retry['recipe']['import_key']),'Failure does not poison retry');
    foreach ([null,[], '', '   ',str_repeat('x',4001)] as $invalidDescription) {
        $invalidService=new RecipeVariationService($config,$directory,static fn()=>['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode([
            'recipe'=>['title'=>'Invalid explanation','ingredients'=>[],'steps'=>[]], 'questions'=>[], 'variation_label'=>'chickpeas','change_description'=>$invalidDescription,
        ])]]]]])]);
        variationError(fn()=>$invalidService->analyze($source,array_replace($body,['request_id'=>bin2hex(random_bytes(16))]),'user-a','house-a'),'invalid_ai_reply');
    }
    $roundBody=array_replace($body,['request_id'=>str_repeat('c',32)]);
    for ($round=0;$round<8;$round++) $service->analyze($source,$roundBody+['answers'=>[['question'=>'Preference?','answer'=>(string)$round]]],'user-a','house-a');
    variationError(fn()=>$service->analyze($source,$roundBody+['answers'=>[['question'=>'Preference?','answer'=>'ninth']]],'user-a','house-a'),'variation_limit');
    $spanishSource=array_replace($source,['title'=>'Curry de pollo','original_language'=>'es',
        'steps'=>[['id'=>'spanish-step','instruction'=>'Cocina el pollo.']]]);
    $spanishService=new RecipeVariationService($config,$directory,static function(array $payload): array {
        expectVariation(str_contains($payload['text']['format']['schema']['properties']['change_description']['description'],'Required output language for this request: es.'),'Spanish explanation receives its own explicit language rule');
        $content=$payload['input'][0]['content'][0]['text'];
        $data=json_decode(substr($content,strpos($content,'{')),true);
        expectVariation($data['original_recipe']['title']==='Curry de pollo' && $data['original_recipe']['original_language']==='es','Spanish source language reaches provider even with English request');
        return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode([
            'recipe'=>['title'=>'Curry de garbanzos','original_language'=>'es','ingredients'=>[['original_text'=>'1 lata de garbanzos']],'steps'=>[['instruction'=>'Cocina los garbanzos.']]],
            'questions'=>[], 'variation_label'=>'garbanzos','change_description'=>'Se sustituye el pollo por garbanzos cocidos y se reduce el tiempo de cocción.',
        ])]]]]])];
    });
    $spanish=$spanishService->analyze($spanishSource,array_replace($body,['request_id'=>str_repeat('d',32)]),'user-a','house-a');
    expectVariation($spanish['recipe']['original_language']==='es' && str_starts_with($spanish['change_description'],'Se sustituye'),'Spanish recipe explanation is preserved rather than forced into English');
    $unchangedTitle=new RecipeVariationService($config,$directory,static fn()=>['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode([
        'recipe'=>['title'=>'Chicken curry','ingredients'=>[],'steps'=>[]], 'questions'=>[], 'variation_label'=>'','change_description'=>'Changed ingredients.',
    ])]]]]])]);
    variationError(fn()=>$unchangedTitle->analyze($source,array_replace($body,['request_id'=>str_repeat('e',32)]),'user-a','house-a'),'invalid_ai_reply');
    $imageBytes=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGNoaGgAAAMEAYFL09IQAAAAAElFTkSuQmCC');
    $coverSource=$source;
    $coverSource['photos']=[
        ['id'=>'not-cover','storage_key'=>'media/recipes/'.str_repeat('a',48).'.png','is_cover'=>0],
        ['id'=>'parent-photo','storage_key'=>'media/recipe-photos/'.str_repeat('b',64).'.png','is_cover'=>1,'caption'=>'Parent cover','attribution'=>'Original photographer','source_url'=>'https://example.test/private-image'],
    ];
    $sourceBefore=$coverSource; $coverCalls=[]; $readKeys=[]; $decision=true;
    $coverTransport=static function(array $payload) use (&$coverCalls,&$decision): array {
        $coverCalls[]=$payload;
        return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode([
            'recipe'=>['title'=>'Mild chickpea curry','ingredients'=>[],'steps'=>[]], 'questions'=>[],
            'variation_label'=>'chickpeas','change_description'=>$decision ? 'The seasoning changes; the cover still represents the dish and is reused.' : 'The appearance changes, so the parent cover is omitted.',
            'parent_cover_appropriate'=>$decision,'parent_cover_reason'=>'Visual comparison with the revised dish.',
        ])]]]]])];
    };
    $coverReader=static function(string $key) use (&$imageBytes,&$readKeys): string { $readKeys[]=$key; return $imageBytes; };
    $withCover=new RecipeVariationService($config,$directory,$coverTransport,$coverReader);
    $coverBody=array_replace($body,['request_id'=>str_repeat('f',32)]);
    $approved=$withCover->analyze($coverSource,$coverBody,'user-a','house-a');
    expectVariation($readKeys===[$coverSource['photos'][1]['storage_key']],'Explicit parent cover selected rather than first photo');
    $images=array_values(array_filter($coverCalls[0]['input'][0]['content'],fn($part)=>$part['type']==='input_image'));
    expectVariation(count($images)===1 && $images[0]['image_url']==='data:image/png;base64,'.base64_encode($imageBytes),'Provider receives actual parent cover pixels');
    expectVariation(!str_contains(json_encode($coverCalls[0]),'parent-photo') && !str_contains(json_encode($coverCalls[0]),'private-image'),'Photo IDs and source URLs remain private');
    expectVariation(in_array('parent_cover_appropriate',$coverCalls[0]['text']['format']['schema']['required'],true),'Visual decision is required in image-enabled response');
    expectVariation(count($approved['recipe']['photos'])===1 && $approved['recipe']['photos'][0]['image_base64']===base64_encode($imageBytes),'Approved parent image is reused in draft');
    expectVariation($approved['recipe']['photos'][0]['id']==='new-parent-cover' && $approved['recipe']['photos'][0]['is_cover']===1,'Reused photo has a fresh record identity');
    expectVariation($approved['recipe']['photos'][0]['caption']==='Parent cover' && $approved['recipe']['photos'][0]['attribution']==='Original photographer','Caption and attribution preserved');
    expectVariation(!isset($approved['recipe']['photos'][0]['storage_key']) && !isset($approved['recipe']['parent_cover_review']),'No borrowed database storage identity or review metadata');
    expectVariation($coverSource===$sourceBefore,'Cover review never mutates the source');
    $localDirectory=$directory.'/media/recipes';
    mkdir($localDirectory,0755,true);
    $localKey='vps/recipes/'.str_repeat('e',48).'.png';
    file_put_contents($localDirectory.'/'.basename($localKey),$imageBytes);
    $localSource=$coverSource;
    $localSource['photos'][1]['storage_key']=$localKey;
    $localService=new RecipeVariationService($config,$directory,$coverTransport);
    $localDraft=$localService->analyze($localSource,array_replace($coverBody,['request_id'=>str_repeat('7',32)]),'user-a','house-a');
    expectVariation($localDraft['recipe']['photos'][0]['image_base64']===base64_encode($imageBytes),'VPS cover bytes are read from persistent media');
    unlink($localDirectory.'/'.basename($localKey)); rmdir($localDirectory); rmdir($directory.'/media');
    array_pop($coverCalls);
    expectVariation($withCover->analyze($coverSource,$coverBody,'user-a','house-a')===$approved && count($coverCalls)===1,'Image review retry reuses cached decision');
    $decision=false;
    $rejected=$withCover->analyze($coverSource,array_replace($coverBody,['request_id'=>str_repeat('1',32)]),'user-a','house-a');
    expectVariation($rejected['recipe']['photos']===[] && $rejected['parent_cover_review']['appropriate']===false,'Unsuitable cover is omitted');
    $imageBytes.='changed cover';
    $changed=$withCover->analyze($coverSource,$coverBody,'user-a','house-a');
    expectVariation(count($coverCalls)===3 && $changed['recipe']['photos']===[],'Changed cover invalidates cached visual decision');
    $unsafe=$coverSource; $unsafe['photos'][1]['storage_key']='media/recipes/../../config/local.php';
    $readsBefore=count($readKeys);
    $withoutCover=$withCover->analyze($unsafe,array_replace($coverBody,['request_id'=>str_repeat('2',32)]),'user-a','house-a');
    expectVariation(count($readKeys)===$readsBefore && $withoutCover['recipe']['photos']===[],'Unsafe storage key is never read or reused');
    $invalidReview=new RecipeVariationService($config,$directory,static fn()=>['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode([
        'recipe'=>['title'=>'Mild curry','ingredients'=>[],'steps'=>[]], 'questions'=>[], 'variation_label'=>'chickpeas','change_description'=>'A variation.',
        'parent_cover_appropriate'=>'yes','parent_cover_reason'=>'Looks similar.',
    ])]]]]])],$coverReader);
    variationError(fn()=>$invalidReview->analyze($coverSource,array_replace($coverBody,['request_id'=>str_repeat('3',32)]),'user-a','house-a'),'invalid_ai_reply');
    expectVariation($withCover->analyze($coverSource,array_replace($coverBody,['request_id'=>str_repeat('3',32)]),'user-a','house-a')['recipe']['photos']===[],'Invalid cover assessment does not poison a successful retry');
    $unreadable=new RecipeVariationService($config,$directory,$coverTransport,static fn()=>false);
    $missing=$unreadable->analyze($coverSource,array_replace($coverBody,['request_id'=>str_repeat('4',32)]),'user-a','house-a');
    expectVariation($missing['recipe']['photos']===[] && !array_filter(end($coverCalls)['input'][0]['content'],fn($part)=>$part['type']==='input_image'),'Unreadable parent covers are omitted without pretending to review pixels');
    $nestedSource=$source;
    $nestedSource['title']='Main recipe - existing variation';
    $nestedSource['variation_base_title']='Main recipe';
    $nested=$service->analyze($nestedSource,array_replace($body,['request_id'=>str_repeat('5',32)]),'user-a','house-a');
    expectVariation($nested['recipe']['title']==='Main recipe - chickpeas','Nested variation uses the main title without stacking parent titles');
    $nestedSource['variation_base_title']='Main recipe - house style';
    $withDash=$service->analyze($nestedSource,array_replace($body,['request_id'=>str_repeat('6',32)]),'user-a','house-a');
    expectVariation($withDash['recipe']['title']==='Main recipe - house style - chickpeas','Dashes belonging to the real main title are preserved');
    foreach ([null,[], '   ',str_repeat('x',121)] as $invalidLabel) {
        $invalidLabelService=new RecipeVariationService($config,$directory,static fn()=>['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode([
            'recipe'=>['title'=>'Any title','ingredients'=>[],'steps'=>[]], 'questions'=>[], 'variation_label'=>$invalidLabel,'change_description'=>'Changed ingredients.',
        ])]]]]])]);
        variationError(fn()=>$invalidLabelService->analyze($source,array_replace($body,['request_id'=>bin2hex(random_bytes(16))]),'user-a','house-a'),'invalid_ai_reply');
    }
} finally { foreach(glob($directory.'/variation-drafts/*') ?: [] as $file) unlink($file); if(is_dir($directory.'/variation-drafts'))rmdir($directory.'/variation-drafts'); if(is_file($directory.'/variation-events.log'))unlink($directory.'/variation-events.log'); rmdir($directory); }
echo "$checks recipe variation checks passed.\n";
