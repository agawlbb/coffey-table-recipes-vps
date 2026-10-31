<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/HttpError.php';
require_once dirname(__DIR__).'/src/RecipeShare.php';
require dirname(__DIR__).'/src/RecipeSharePage.php';
$checks=0;
function checkShare(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
checkShare(RecipeShare::recipeId('9') === '9', 'Database ID preserved');
foreach ([null, [], '', '0', '-1', '9 OR 1=1', '1.2', str_repeat('9',20)] as $bad) {
    try { RecipeShare::recipeId($bad); throw new RuntimeException('Invalid ID accepted'); }
    catch (HttpError $error) { checkShare($error->status===404,'Invalid ID status'); }
}
$recipe=['title'=>'Crêpes <script>alert(1)</script>','description'=>'Family favourite','original_language'=>'fr','servings'=>'4.0000',
    'tags'=>[['label'=>'Dessert']],
    'groups'=>[['id'=>'4','title'=>'Batter']], 'ingredients'=>[['group_id'=>'4','original_text'=>'250 g flour']],
    'steps'=>[['id'=>'5','position'=>1,'instruction'=>'Bake <b>carefully</b>']],
    'actions'=>[['step_id'=>'5','label'=>'Oven','temperature_value'=>'180.0000','temperature_unit'=>'C']],
    'photos'=>[['source_url'=>'javascript:alert(1)'],['source_url'=>'https://example.com/crepes.jpg','caption'=>'Crêpes']],
    'nutrition'=>['is_estimate'=>1,'basis'=>'per_serving','calories_kcal'=>'123.0000','stale'=>true,'method'=>'Calculated'],
    'notes'=>'Rest the batter','source_text'=>'PRIVATE IMPORT','edit_options'=>['recipes'=>[['title'=>'PRIVATE RECIPE']]],
];
$html=recipeSharePage($recipe);
checkShare(str_contains($html,'<strong>Family favourite</strong>'),'Description is not bold');
checkShare(str_contains($html,'<h3 class="ingredient-group">Batter</h3>'),'Ingredient group heading is missing');
checkShare(str_contains($html,'class="ingredient group-member">• 250 g flour'),'Grouped ingredient is not indented');
checkShare(str_contains($html,'.ingredient-group{font-weight:700}') && str_contains($html,'.ingredient.group-member{margin-left:16px}'),'Group styling missing');
$plain=$recipe; $plain['ingredients'][]=['group_id'=>null,'original_text'=>'Water'];
checkShare(str_contains(recipeSharePage($plain),'class="ingredient">• Water'),'Ungrouped ingredient must stay unindented');
preg_match('~<script type="application/ld\+json">(.*?)</script>~s',$html,$json);
$structured=json_decode($json[1],true,512,JSON_THROW_ON_ERROR);
checkShare($structured['@type']==='Recipe' && $structured['@context']==='https://schema.org','Recipe schema missing');
checkShare($structured['name']===$recipe['title'] && $structured['description']===$recipe['description'],'Structured text changed');
checkShare($structured['recipeIngredient']===['250 g flour'] && $structured['recipeInstructions'][0]['text']==='Bake <b>carefully</b>','Ingredients or instructions missing');
checkShare($structured['image']===['https://example.com/crepes.jpg'] && $structured['recipeYield']==='4 servings','Pictures or yield incorrect');
checkShare(!isset($structured['nutrition']) && !isset($structured['household_id']) && !isset($structured['source_text']),'Private/stale data exposed');
$complete=recipeStructuredData($recipe+['prep_minutes'=>10,'cook_minutes'=>0,'total_minutes'=>90,'source_author'=>'Recipe author']);
checkShare($complete['prepTime']==='PT10M' && $complete['cookTime']==='PT0M' && $complete['totalTime']==='PT90M','Invalid structured durations');
checkShare($complete['author']['name']==='Recipe author','Author missing');
$fresh=$recipe; $fresh['nutrition']['stale']=false;
checkShare(recipeStructuredData($fresh)['nutrition']['calories']==='123 kcal','Fresh nutrition missing units');
checkShare(recipeStructuredData(['title'=>'Recipe not found'])===null,'Error page advertised a recipe');
checkShare(str_contains($html,'noindex,nofollow'),'Existing indexing preference changed');
$sections=$recipe; $sections['steps']=[['section_title'=>'Batter','instruction'=>'Whisk'],['section_title'=>'Batter','instruction'=>'Rest'],['instruction'=>'Cook']];
$instructions=recipeStructuredData($sections)['recipeInstructions'];
checkShare($instructions[0]['@type']==='HowToSection' && $instructions[0]['name']==='Batter' && count($instructions[0]['itemListElement'])===2 && $instructions[1]['text']==='Cook','Instruction sections or sequence lost');
$injection=$recipe; $injection['description']='</script><script>alert("x")</script>';
$safe=recipeSharePage($injection);
checkShare(substr_count($safe,'</script>')===1 && !str_contains($safe,'<script>alert'),'JSON-LD allowed script injection');
foreach (['Batter','250 g flour','4 servings','Calories (kcal): 123','Rest the batter','needs recalculation','&lt;script&gt;','&lt;b&gt;carefully&lt;/b&gt;','assets/email/coffey_table.jpg','background:transparent','mix-blend-mode:multiply','recipe-header','max-width:900px','#f5eddf'] as $text) checkShare(str_contains($html,$text),'Missing '.$text);
foreach (['<script>','javascript:','PRIVATE IMPORT','PRIVATE RECIPE','Oven','Original language:','Original measurements','Dessert'] as $text) checkShare(!str_contains($html,$text),'Leaked '.$text);
if (isset($argv[1])) file_put_contents($argv[1],$html);
echo "Recipe sharing: $checks checks passed.\n";
