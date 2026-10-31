<?php
declare(strict_types=1);

/** Detect complete alternative appliance methods from structured Recipe sections. */
final class RecipeImportMethods
{
    public static function options(string $text): array {
        $recipes=json_decode($text,true);
        if (!is_array($recipes)) return [];
        if (isset($recipes['recipeInstructions'])) $recipes=[$recipes];
        if (count($recipes)!==1 || !is_array($recipes[0] ?? null)) return [];
        $recipe=$recipes[0]; $sections=$recipe['recipeInstructions'] ?? null;
        if (!is_array($sections)) return [];
        $methods=[];
        foreach ($sections as $position=>$section) {
            if (!is_array($section) || !in_array('HowToSection',(array)($section['@type'] ?? []),true)
                || !is_string($section['name'] ?? null) || empty($section['itemListElement'])) continue;
            $name=trim(html_entity_decode($section['name'],ENT_QUOTES|ENT_HTML5,'UTF-8'));
            if (!preg_match('/^(?:for\s+)?(?:the\s+)?(stove[ -]?top|instant\s*pot|pressure\s*cooker|slow\s*cooker|crock[ -]?pot|air\s*fryer|oven|microwave)(?:\s+(?:method|version|instructions))?\s*:?$/iu',$name,$match)) continue;
            $key=preg_replace('/[^a-z]/','',strtolower($match[1]));
            $labels=['stovetop'=>'Stovetop','instantpot'=>'Instant Pot','pressurecooker'=>'Pressure cooker','slowcooker'=>'Slow cooker','crockpot'=>'Slow cooker','airfryer'=>'Air fryer','oven'=>'Oven','microwave'=>'Microwave'];
            $label=$labels[$key]; $id=strtolower(str_replace(' ','-',$label));
            if (isset($methods[$id])) return []; // Ambiguous repeated method sections require AI clarification.
            $methods[$id]=['id'=>$id,'label'=>$label,'position'=>$position];
        }
        if (count($methods)<2 || count($methods)>6) return [];
        $base=trim(preg_replace('/\b(?:stove[ -]?top|instant\s*pot|pressure\s*cooker|slow\s*cooker|crock[ -]?pot|air\s*fryer|oven|microwave)\b\s*[-:]?\s*/iu','',(string)($recipe['name'] ?? 'Recipe')));
        $positions=array_column($methods,'position');
        foreach ($methods as &$method) {
            $selected=$recipe;
            $selected['name']=($base ?: 'Recipe').' - '.$method['label'];
            $selected['recipeInstructions']=array_values(array_filter($sections,static fn($section,$position)=>!in_array($position,$positions,true) || $position===$method['position'],ARRAY_FILTER_USE_BOTH));
            // Shared page timings/descriptions can describe the other method.
            unset($selected['cookTime'],$selected['totalTime'],$selected['description']);
            $method['text']=json_encode([$selected],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $method['title']=$selected['name'];
        }
        unset($method);
        return array_values($methods);
    }

    public static function selection(array $body,array $options): ?string {
        $selected=$body['method_selection'] ?? null;
        if (is_string($selected) && ($selected==='both' || in_array($selected,array_column($options,'id'),true))) return $selected;
        if (($body['kind'] ?? '')!=='answers') return null;
        $answer=$body['answers'][0]['answer'] ?? '';
        if (!is_string($answer)) return null;
        $answer=trim(mb_strtolower($answer));
        if (preg_match('/\b(?:both|all)\b/u',$answer)) return 'both';
        $matches=[];
        foreach ($options as $option) {
            if ($answer===$option['id'] || str_contains($answer,mb_strtolower($option['label']))) $matches[]=$option['id'];
        }
        return count($matches)===1 ? $matches[0] : null;
    }
}
