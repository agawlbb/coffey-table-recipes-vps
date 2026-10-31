<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/HttpError.php';
require dirname(__DIR__).'/src/RecipeImportService.php';
$checks = 0;
function aiExpect(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function aiReject(callable $action, int $status): void {
    try { $action(); } catch (HttpError $e) { aiExpect($e->status === $status, 'Unexpected status.'); return; }
    throw new RuntimeException('Unsafe input was accepted.');
}
aiExpect(str_contains(RecipeImportService::groupingInstruction(),'conversational input has no headings'),'Conversational component grouping policy missing.');
$html = '<html><script type="application/ld+json">{"@graph":[{"@type":["Recipe"],"name":"Soup","recipeIngredient":["2 cups water"],"recipeInstructions":[{"@type":"HowToStep","text":"Boil"}],"advertising":"junk"}]}</script><nav>Ads</nav><main>Body</main></html>';
$text = RecipeImportService::websiteText($html);
aiExpect(str_contains($text,'Soup') && str_contains($text,'2 cups water') && !str_contains($text,'advertising'), 'Structured recipe extraction failed.');
aiExpect(RecipeImportService::websiteText('<nav>Noise</nav><article>Recipe instructions</article><script>tracking()</script>') === 'Recipe instructions','Fallback retained page noise.');
aiExpect(RecipeImportService::cleanText("  mix\x00   well\n\n\nserve  ") === "mix well\n\nserve",'Text cleanup failed.');
foreach (['127.0.0.1','10.0.0.1','192.168.1.5','169.254.169.254','::1','::ffff:127.0.0.1','100.64.0.1','224.0.0.1','198.18.0.1','ff02::1'] as $ip) aiReject(fn()=>RecipeImportService::publicAddresses($ip),422);
aiExpect(RecipeImportService::cleanText('&#x20;1/3 cup &amp; lime')==='1/3 cup & lime','Pasted HTML entities were not decoded.');
$input = ['recipe'=>['title'=>'Soup','ingredients'=>[['original_text'=>'Water','ingredient_name'=>'water','id'=>'999','scale_quantity'=>1]],'steps'=>[['instruction'=>'Boil']],'household_id'=>'another','nutrition'=>['calories'=>99]],'questions'=>['How much water?']];
$draft = RecipeImportService::draft($input);
$planned=$input; $planned['recipe']['component_titles']=['Dough','Sauce'];
aiExpect(!isset(RecipeImportService::draft($planned)['recipe']['component_titles']),'AI component planning leaked into the saved draft.');
$timed = $input;
$timed['recipe']['steps'][0]['actions'] = [['id'=>'999','step_id'=>'999','action_type'=>'timer','label'=>'Simmer','duration_seconds'=>120]];
$timed['recipe']['categories'] = [['label'=>'Cookies','parent_label'=>'Desserts','category_id'=>'999']];
$prepared = RecipeImportService::draft($timed)['recipe'];
aiExpect($prepared['actions'][0]['duration_seconds']===120 && $prepared['actions'][0]['step_id']==='new-steps-0' && $prepared['actions'][0]['id']==='new-actions-0','Timed action not associated with normalized step.');
aiExpect($prepared['category_assignments']===[['label'=>'Cookies','parent_label'=>'Desserts']],'Category proposal accepted untrusted ID.');
$pie=$input; $pie['recipe']['categories']=[['label'=>'Desserts','parent_label'=>''],['label'=>'Pies','parent_label'=>'Desserts']];
aiExpect(RecipeImportService::draft($pie)['recipe']['category_assignments']===[['label'=>'Pies','parent_label'=>'Desserts']],'Redundant parent category assignment remained.');
$catalog=[['id'=>'42','label'=>'Cookies','parent_label'=>'Desserts']];
aiExpect(RecipeImportService::draft($timed,$catalog)['recipe']['category_assignments'][0]['category_id']==='42','Existing category not resolved.');
foreach (['10 to 15 minutes','10–15 minutes','10-15 minutes','10 minutes to 15 minutes','15 to 10 minutes'] as $range) {
    $ranged=$input;
    $ranged['recipe']['steps'][0]['instruction']='Cook for '.$range.'.';
    $ranged['recipe']['steps'][0]['actions']=[['action_type'=>'timer','duration_seconds'=>900],['action_type'=>'timer','duration_seconds'=>600]];
    $actions=RecipeImportService::draft($ranged)['recipe']['actions'];
    aiExpect(count($actions)===1 && $actions[0]['duration_seconds']===600,'Wrong range timer: '.$range);
}
$selfCategory=$input;
$selfCategory['recipe']['categories']=[['label'=>' Main Dish ','parent_label'=>'main dish']];
$selfDraft=RecipeImportService::draft($selfCategory,[['id'=>'10','label'=>'Main Dish','parent_label'=>null]])['recipe'];
aiExpect($selfDraft['category_assignments']===[['category_id'=>'10','label'=>'Main Dish','parent_label'=>'']],'Self-named child was not resolved to its root.');
$selfCategory['recipe']['categories'][]=['label'=>'Main Dish','parent_label'=>''];
aiExpect(count(RecipeImportService::draft($selfCategory)['recipe']['category_assignments'])===1,'Root and self-named child were not deduplicated.');
$heat=$input;
$heat['recipe']['steps'][0]['actions']=[['action_type'=>'appliance_temperature','label'=>'Simmer over low heat','temperature_value'=>null,'temperature_unit'=>'F']];
aiExpect(RecipeImportService::draft($heat)['recipe']['actions']===[], 'Qualitative heat should not create an action.');
$bad = $timed; $bad['recipe']['steps'][0]['actions'][0]['duration_seconds']=0;
aiExpect(RecipeImportService::draft($bad)['recipe']['actions']===[], 'Incomplete timers should be omitted, never turned into reminders.');
$bad['recipe']['steps'][0]['actions'][0]['action_type']='simmer';
aiExpect(RecipeImportService::draft($bad)['recipe']['actions']===[], 'Unknown action should be omitted.');
foreach (['reminder','internal_temperature'] as $type) {
    $extra=$timed; $extra['recipe']['steps'][0]['actions'][0]=['action_type'=>$type,'label'=>'Cook','temperature_value'=>165,'temperature_unit'=>'F'];
    aiExpect(RecipeImportService::draft($extra)['recipe']['actions']===[], 'Unwanted action type survived filtering.');
}
$rest=$timed; $rest['recipe']['steps'][0]['actions'][0]['action_type']='rest';
aiExpect(RecipeImportService::draft($rest)['recipe']['actions'][0]['action_type']==='timer', 'Explicit timed resting must be a timer.');
$oven=$timed; $oven['recipe']['steps'][0]['actions'][0]=['action_type'=>'appliance_temperature','label'=>'Preheat oven','temperature_value'=>300,'temperature_unit'=>'F'];
aiExpect(RecipeImportService::draft($oven)['recipe']['actions'][0]['temperature_value']===300, 'Explicit appliance temperature was lost.');
$bad=$timed; $bad['recipe']['steps'][0]['actions'][0]['temperature_unit']='F';
aiExpect(RecipeImportService::draft($bad)['recipe']['actions'][0]['temperature_unit']===null,'Stray timer temperature unit remained unsavable.');
$format=RecipeImportService::responseFormat();
aiExpect($format['strict']===true && $format['type']==='json_schema','Strict response format missing.');
function checkObjects(array $schema): void { if (($schema['type'] ?? '')==='object') aiExpect($schema['additionalProperties']===false && $schema['required']===array_keys($schema['properties']),'Object is not strict.'); foreach ($schema['properties'] ?? [] as $child) checkObjects($child); if (isset($schema['items'])) checkObjects($schema['items']); }
checkObjects($format['schema']);
aiExpect($format['schema']['properties']['recipe']['properties']['description']['type']==='string','Missing descriptions remain nullable.');
$bad = $timed; $bad['recipe']['categories'][0]['label']=['bad']; aiReject(fn()=>RecipeImportService::draft($bad),502);
aiExpect($draft['recipe']['ingredients'][0]['id']==='new-ingredients-0','AI supplied a database ID.');
aiExpect(!isset($draft['recipe']['household_id']) && !isset($draft['recipe']['nutrition']),'Untrusted database fields were accepted.');
aiExpect($draft['questions']===['How much water?'] && $draft['recipe']['measurement_system']==='unspecified','Questions or defaults lost.');
$bad = $input; $bad['questions']=[['not a question']]; aiReject(fn()=>RecipeImportService::draft($bad),502);
$bad = $input; $bad['recipe']['steps']=['unexpected'=>'value']; aiReject(fn()=>RecipeImportService::draft($bad),502);
$service = new RecipeImportService(['api_key'=>'','model'=>'gpt-5.4-mini'],sys_get_temp_dir());
aiReject(fn()=>$service->analyze(['kind'=>'file','file_base64'=>base64_encode('<?php bad();')],'1'),422);
aiReject(fn()=>$service->analyze(['kind'=>'text','text'=>''],'1'),422);
aiReject(fn()=>$service->analyze(['kind'=>'website','url'=>'http://127.0.0.1'],'1'),422);
$quantities=$input;
$quantities['recipe']['ingredients'][0]+= ['quantity_decimal'=>'0.5','quantity_numerator'=>1,'quantity_denominator'=>2];
$q=RecipeImportService::draft($quantities)['recipe']['ingredients'][0];
aiExpect($q['quantity_decimal']===null && $q['quantity_numerator']===1 && $q['quantity_denominator']===2,'Duplicate fraction and decimal not normalized.');
$quantities['recipe']['ingredients'][0]['quantity_denominator']=0;
$q=RecipeImportService::draft($quantities)['recipe']['ingredients'][0];
aiExpect($q['quantity_decimal']==='0.5' && $q['quantity_numerator']===null && $q['quantity_denominator']===null,'Placeholder fraction broke decimal.');
aiExpect(RecipeImportService::sourceType('text',[])==='Pasted text','Pasted source mislabeled.');
aiExpect(RecipeImportService::sourceType('text',['input_source'=>'written_description'])==='Written description of a dish','Written description mislabeled.');
aiExpect(RecipeImportService::sourceType('file',[],true)==='Uploaded PDF','PDF source mislabeled.');
aiExpect(RecipeImportService::sourceType('file',[])==='Uploaded image','Image source mislabeled.');
aiExpect(RecipeImportService::sourceType('answers',['recipe'=>['source_type'=>'Camera photo']])==='Camera photo','Clarification lost original source.');

// Exercise more than the former hourly quota without real upstream API calls.
$limitStorage = sys_get_temp_dir().'/coffey-ai-limit-test-'.bin2hex(random_bytes(6));
mkdir($limitStorage.'/recipe-ai',0700,true);
$legacyCounter = $limitStorage.'/recipe-ai/'.hash('sha256','repeat-user').'.json';
file_put_contents($legacyCounter,json_encode(['start'=>time(),'count'=>20]));
$calls = 0;
$repeatService = new RecipeImportService(['api_key'=>'test-fixture','model'=>'test-model'],$limitStorage,
    static function(array $payload) use (&$calls,$input,$catalog): array {
        $calls++;
        aiExpect(($payload['reasoning']['effort'] ?? null)==='medium','Conversational extraction reasoning budget missing.');
        aiExpect(isset($payload['text']['format']['schema']['properties']['recipe']['properties']['ingredient_groups']),'Component membership schema missing.');
        aiExpect(str_contains($payload['instructions'],'Conversational amounts:'),'Qualitative amount policy missing.');
        aiExpect(str_contains($payload['instructions'],'exactly ONE timer') && str_contains($payload['instructions'],'full parent and child category catalog'),'Missing range/category prompt rules.');
        aiExpect(str_contains(json_encode($payload['input']), 'Cookies'),'Category catalog missing from AI request.');
        return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[
            ['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode($input)]]],
        ]])];
    });
try {
    for ($i=0;$i<25;$i++) {
        $repeatDraft = $repeatService->analyze(['kind'=>'text','text'=>'Soup: boil water.'],'repeat-user',$catalog);
        aiExpect($repeatDraft['recipe']['title']==='Soup','Repeated import did not produce the expected draft.');
    }
    aiExpect($calls===25,'Hourly restriction blocked a repeated import.');
    aiExpect(json_decode(file_get_contents($legacyCounter),true)['count']===20,'Legacy hourly counter was modified.');
} finally {
    unlink($legacyCounter); rmdir($limitStorage.'/recipe-ai'); rmdir($limitStorage);
}

// Familiar US fractions and explicit "each" quantities remain database-ready.
foreach (['.25'=>'1/4', '0.5'=>'1/2', '1.25'=>'1 1/4', '0.3333'=>'1/3'] as $decimal=>$display) {
    $r=$input;
    $r['recipe']['measurement_system']='us';
    $r['recipe']['ingredients']=[['original_text'=>$decimal.' cups sugar','ingredient_name'=>'sugar','quantity_decimal'=>(string)$decimal,'original_unit'=>'cups']];
    $q=RecipeImportService::draft($r)['recipe']['ingredients'][0];
    aiExpect($q['quantity_text']===$display && str_starts_with($q['original_text'],$display.' '),'US fraction display failed: '.$decimal);
    aiExpect($q['quantity_decimal']===null && $q['quantity_denominator']>0,'US fraction exact fields conflict.');
}
$r=$input;
$r['recipe']['measurement_system']='us';
$r['recipe']['ingredients']=[['original_text'=>'.25 cups each of x and y','ingredient_name'=>'x and y','quantity_decimal'=>'0.25','original_unit'=>'cups']];
$parts=RecipeImportService::draft($r)['recipe']['ingredients'];
aiExpect(array_column($parts,'original_text')===['1/4 cup x','1/4 cup y'],'Explicit each ingredients were not separated.');
aiExpect(array_column($parts,'id')===['new-ingredients-0','new-ingredients-1'],'Split ingredients need unique IDs.');
foreach ($parts as $part) aiExpect($part['quantity_numerator']===1 && $part['quantity_denominator']===4 && $part['quantity_decimal']===null,'Each amount was divided or invalid.');
foreach (['.25 cups x and y','.25 cups each of butter or margarine','.25 cups each of x and y, chopped','0.3 cups sugar'] as $line) {
    $r['recipe']['ingredients']=[['original_text'=>$line,'ingredient_name'=>'source','quantity_decimal'=>null]];
    $parts=RecipeImportService::draft($r)['recipe']['ingredients'];
    aiExpect(count($parts)===1,'Ambiguous or alternative ingredients were split.');
    if (str_starts_with($line,'0.3 ')) aiExpect($parts[0]['original_text']===$line,'Arbitrary decimal was rounded.');
}
$r['recipe']['measurement_system']='metric';
$r['recipe']['ingredients']=[['original_text'=>'0.25 liters water','ingredient_name'=>'water','quantity_decimal'=>'0.25','original_unit'=>'liters']];
aiExpect(RecipeImportService::draft($r)['recipe']['ingredients'][0]['original_text']==='0.25 liters water','Metric quantity changed.');
$r['recipe']['measurement_system']='us';
$r['recipe']['ingredients']=[['original_text'=>'0.25 cups sugar','ingredient_name'=>'sugar','quantity_decimal'=>'0.25','quantity_max_decimal'=>'0.5']];
aiExpect(RecipeImportService::draft($r)['recipe']['ingredients'][0]['quantity_max_decimal']==='0.5','Range endpoint lost.');


$grouped=$input;
$grouped['recipe']['ingredients']=[
    ['original_text'=>'2 cups flour','ingredient_name'=>'flour','group_title'=>'Dough','group_id'=>'untrusted'],
    ['original_text'=>'1 cup milk','ingredient_name'=>'milk','group_title'=>' dough '],
    ['original_text'=>'1/2 cup sugar','ingredient_name'=>'sugar','group_title'=>'Sauce'],
    ['original_text'=>'Salt','ingredient_name'=>'salt','group_title'=>null],
];
$g=RecipeImportService::draft($grouped)['recipe'];
aiExpect($g['groups']===[['id'=>'new-groups-0','title'=>'Dough'],['id'=>'new-groups-1','title'=>'Sauce']],'Group titles were lost, duplicated or accepted untrusted IDs.');
aiExpect(array_column($g['ingredients'],'group_id')===['new-groups-0','new-groups-0','new-groups-1',null],'Ingredients assigned to wrong groups.');
aiExpect(!array_key_exists('group_title',$g['ingredients'][0]),'AI-only group field leaked into database draft.');
$grouped['recipe']['ingredients'][0]['group_title']=str_repeat('a',201);
aiReject(fn()=>RecipeImportService::draft($grouped),502);
$grouped['recipe']['ingredients']=[['original_text'=>'.25 cups each of x and y','ingredient_name'=>'x and y','group_title'=>'Sauce']];
$g=RecipeImportService::draft($grouped)['recipe'];
aiExpect(count($g['ingredients'])===2 && array_column($g['ingredients'],'group_id')===['new-groups-0','new-groups-0'],'Split ingredients lost their component.');
aiExpect(isset($format['schema']['properties']['recipe']['properties']['ingredient_groups']['items']['properties']['ingredient_positions']),'Strict AI schema lacks group membership lists.');


// Explicit component membership is authoritative even with missing/contradictory legacy per-row labels.
$mapped=$input;
$mapped['recipe']['ingredients']=[
 ['original_text'=>'2 cups flour','ingredient_name'=>'flour','group_title'=>null],
 ['original_text'=>'Water as needed','ingredient_name'=>'water','group_title'=>'Wrong legacy label'],
 ['original_text'=>'1/4 cup butter','ingredient_name'=>'butter'],
 ['original_text'=>'1/4 cup sugar','ingredient_name'=>'sugar'],
 ['original_text'=>'Purchased pastry shell','ingredient_name'=>'pastry shell'],
];
$mapped['recipe']['ingredient_groups']=[['title'=>'Dough','ingredient_positions'=>[1,2]],['title'=>'Sauce','ingredient_positions'=>[3,4]]];
$m=RecipeImportService::draft($mapped)['recipe'];
aiExpect(array_column($m['groups'],'title')===['Dough','Sauce'],'Explicit components disappeared.');
aiExpect(array_column($m['ingredients'],'group_id')===['new-groups-0','new-groups-0','new-groups-1','new-groups-1',null],'Explicit memberships were not assigned exactly.');
aiExpect(!isset($m['ingredient_groups']),'AI membership plan leaked into review/save draft.');
// Re-normalizing an editor/clarification draft must preserve every group reference.
$again=RecipeImportService::draft(['recipe'=>$m,'questions'=>[]])['recipe'];
aiExpect($again['groups']===$m['groups'] && array_column($again['ingredients'],'group_id')===array_column($m['ingredients'],'group_id'),'Clarification draft lost groups.');
$split=$input;
$split['recipe']['ingredients']=[['original_text'=>'.25 cups each of x and y','ingredient_name'=>'x and y'],['original_text'=>'1 cup flour','ingredient_name'=>'flour']];
$split['recipe']['ingredient_groups']=[['title'=>'Sauce','ingredient_positions'=>[1]],['title'=>'Dough','ingredient_positions'=>[2]]];
$m=RecipeImportService::draft($split)['recipe'];
aiExpect(array_column($m['ingredients'],'group_id')===['new-groups-0','new-groups-0','new-groups-1'],'Quantity splitting shifted explicit group membership.');
foreach ([null,'invalid',[['title'=>'Dough','ingredient_positions'=>'invalid']],[['title'=>'Dough','ingredient_positions'=>[0,999,'1',true]]]] as $badGroups) {
 $bad=$mapped; $bad['recipe']['ingredient_groups']=$badGroups;
 $m=RecipeImportService::draft($bad)['recipe'];
 aiExpect(count($m['ingredients'])===5 && $m['groups']===[] && !empty($m['import_warnings']),'Invalid membership discarded the recipe or invented assignments.');
}
$overlap=$mapped;
$overlap['recipe']['ingredient_groups'][1]['ingredient_positions'][]=2;
$m=RecipeImportService::draft($overlap)['recipe'];
aiExpect($m['ingredients'][1]['group_id']===null && !empty($m['import_warnings']),'Conflicting membership guessed ownership.');
$single=$mapped; $single['recipe']['ingredient_groups']=[];
$m=RecipeImportService::draft($single)['recipe'];
aiExpect($m['groups']===[] && count(array_filter(array_column($m['ingredients'],'group_id')))===0,'Explicit single-mixture recipe retained stale groups.');
$finish=$mapped; $finish['recipe']['ingredient_groups']=[['title'=>'Decoration','ingredient_positions'=>[5]]];
aiExpect(RecipeImportService::draft($finish)['recipe']['groups']===[],'Explicit mapping bypassed conservative finishing-group rules.');

foreach (['Decoration','Garnish','For decoration','Assembly','Dry ingredients','Wet ingredients'] as $title) {
    $g=$input; $g['recipe']['ingredients'][0]['group_title']=$title;
    $draftGroup=RecipeImportService::draft($g)['recipe'];
    aiExpect($draftGroup['groups']===[] && $draftGroup['ingredients'][0]['group_id']===null,'Unnecessary stage/finishing group persisted: '.$title);
}
echo "$checks recipe AI preprocessing and validation checks passed.\n";
