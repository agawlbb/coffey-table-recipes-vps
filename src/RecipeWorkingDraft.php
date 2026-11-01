<?php
declare(strict_types=1);
require_once __DIR__.'/RecipeImportService.php';

/** Cooking data only. No database identifiers, provenance or photographs. */
final class RecipeWorkingDraft {
    private const META=['title','description','original_language','measurement_system','yield_text','servings','prep_minutes','cook_minutes','total_minutes','notes','variation_label','change_description'];
    private const INGREDIENT=['original_text','ingredient_name','group_title','quantity_text','quantity_decimal','quantity_numerator','quantity_denominator','quantity_max_decimal','original_unit','unit_code','preparation','is_optional','is_main_ingredient','scale_quantity'];
    private const STEP=['instruction','section_title','actions'];
    private const ACTION=['action_type','label','duration_seconds','temperature_value','temperature_unit','appliance'];

    private static function ordered(array $row,array $fields): array { $result=[];foreach($fields as $field)if(array_key_exists($field,$row))$result[$field]=$row[$field];return $result; }

    public static function empty(): array { return ['title'=>'','description'=>null,'original_language'=>null,'measurement_system'=>'unspecified','yield_text'=>null,'servings'=>null,'prep_minutes'=>null,'cook_minutes'=>null,'total_minutes'=>null,'notes'=>null,'variation_label'=>null,'change_description'=>null,'ingredients'=>[],'steps'=>[],'categories'=>[]]; }
    private static function invalid(): never { throw new HttpError(502,'invalid_ai_reply','AI returned an invalid recipe change. Your working draft is unchanged; please try again.'); }

    public static function fromSource(array $source,array $catalog=[]): array {
        $recipe=array_replace(self::empty(),array_intersect_key($source,array_flip(self::META)));
        $recipe['measurement_system']??='unspecified';
        foreach(['servings','prep_minutes','cook_minutes','total_minutes'] as $field)if(is_numeric($recipe[$field]))$recipe[$field]=$field==='servings'?(float)$recipe[$field]:(int)$recipe[$field];
        $groups=[];foreach($source['groups']??[] as $g)$groups[(string)$g['id']]=$g['title'];
        foreach($source['ingredients']??[] as $row) {
            $item=array_intersect_key($row,array_flip(self::INGREDIENT));
            foreach(['quantity_numerator','quantity_denominator'] as $field)if(isset($item[$field]) && is_numeric($item[$field]))$item[$field]=(int)$item[$field];
            foreach(['is_optional','is_main_ingredient','scale_quantity'] as $flag)if(isset($item[$flag]))$item[$flag]=(int)$item[$flag];
            $item['group_title']=$groups[(string)($row['group_id']??'')]??($row['group_title']??null);$recipe['ingredients'][]=$item;
        }
        foreach($source['steps']??[] as $row) {
            $item=array_intersect_key($row,array_flip(self::STEP));$item['actions']=$row['actions']??[];
            foreach($source['actions']??[] as $a)if((string)($a['step_id']??'')===(string)($row['id']??''))$item['actions'][]=array_intersect_key($a,array_flip(self::ACTION));
            $recipe['steps'][]=$item;
        }
        foreach($source['category_assignments']??[] as $assignment) {
            if(isset($assignment['label'],$assignment['parent_label']) && $assignment['parent_label']!==''){$recipe['categories'][]=array_intersect_key($assignment,array_flip(['label','parent_label']));continue;}
            if(!isset($assignment['category_id']))continue;
            foreach($catalog as $category)if((string)($category['id']??'')===(string)$assignment['category_id'] && !empty($category['parent_label']))$recipe['categories'][]=array_intersect_key($category,array_flip(['label','parent_label']));
        }
        return self::normalize($recipe,$catalog);
    }

    /** A deliberately small patch language: metadata, lists, list rows and row fields. */
    public static function apply(array $recipe,array $changes,array $catalog=[]): array {
        if(!array_is_list($changes) || count($changes)>100)self::invalid();
        foreach($changes as $change) {
            if(!is_array($change) || count($change)!==3 || !in_array($change['op']??null,['set','insert','remove'],true) || !is_string($change['path']??null) || !array_key_exists('value_json',$change))self::invalid();
            $op=$change['op'];$value=null;
            if($op==='remove') {if($change['value_json']!==null)self::invalid();}
            else {if(!is_string($change['value_json']) || strlen($change['value_json'])>80000)self::invalid();try{$value=json_decode($change['value_json'],true,32,JSON_THROW_ON_ERROR);}catch(JsonException $e){self::invalid();}}
            if(!preg_match('#^/([a-z_]+)(?:/(0|[1-9][0-9]*|-)(?:/([a-z_]+))?)?$#D',$change['path'],$m))self::invalid();
            $field=$m[1];$index=$m[2]??null;$sub=$m[3]??null;
            if(in_array($field,self::META,true)) {if($index!==null || $op!=='set')self::invalid();$recipe[$field]=$value;continue;}
            if(!in_array($field,['ingredients','steps','categories'],true))self::invalid();
            if($index===null) {if($op!=='set' || !is_array($value) || !array_is_list($value))self::invalid();$recipe[$field]=$value;continue;}
            $count=count($recipe[$field]);$i=$index==='-'?$count:(int)$index;
            if($i>$count || ($op!=='insert' && $i===$count))self::invalid();
            if($sub!==null) {
                if($op!=='set' || !in_array($sub,$field==='ingredients'?self::INGREDIENT:($field==='steps'?self::STEP:['label','parent_label']),true))self::invalid();
                $recipe[$field][$i][$sub]=$value;
            } elseif($op==='insert')array_splice($recipe[$field],$i,0,[$value]);
            elseif($op==='remove')array_splice($recipe[$field],$i,1);
            else $recipe[$field][$i]=$value;
        }
        return self::normalize($recipe,$catalog);
    }

    public static function normalize(array $recipe,array $catalog=[]): array {
        if(array_diff(array_keys($recipe),array_merge(self::META,['ingredients','steps','categories'])))self::invalid();
        $recipe=array_replace(self::empty(),$recipe);
        foreach(self::META as $field) {
            $v=$recipe[$field];
            if(in_array($field,['servings','prep_minutes','cook_minutes','total_minutes'],true)) {
                if($v!==null && ((!is_int($v) && !is_float($v)) || !is_finite((float)$v) || (float)$v<0 || (float)$v>1000000000 || ($field==='servings' && (float)$v<=0) || ($field!=='servings' && floor((float)$v)!=(float)$v)))self::invalid();
            } elseif($v!==null && (!is_string($v) || mb_strlen($v)>($field==='title'?500:($field==='variation_label'?120:4000))))self::invalid();
        }
        if(!is_string($recipe['title']) || !in_array($recipe['measurement_system'],['metric','us','mixed','unspecified'],true) || ($recipe['original_language']!==null && !preg_match('/^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8})*$/D',$recipe['original_language'])))self::invalid();
        foreach(['ingredients','steps','categories'] as $list) {
            if(!is_array($recipe[$list]) || !array_is_list($recipe[$list]) || count($recipe[$list])>($list==='categories'?8:300))self::invalid();
            foreach($recipe[$list] as $row) {
                $allowed=$list==='ingredients'?self::INGREDIENT:($list==='steps'?self::STEP:['label','parent_label']);
                if(!is_array($row) || array_diff(array_keys($row),$allowed))self::invalid();
                $required=$list==='ingredients'?'original_text':($list==='steps'?'instruction':'label');
                if(!is_string($row[$required]??null) || trim($row[$required])==='' || mb_strlen($row[$required])>4000)self::invalid();
                foreach($row as $k=>$v) {
                    if($k==='actions')continue;
                    if($v!==null && (!is_scalar($v) || (is_string($v) && mb_strlen($v)>4000)))self::invalid();
                    if(!in_array($k,['quantity_numerator','quantity_denominator','is_optional','is_main_ingredient','scale_quantity'],true) && $v!==null && !is_string($v))self::invalid();
                    if(in_array($k,['quantity_decimal','quantity_max_decimal'],true) && $v!==null && (!is_string($v) || !preg_match('/^[0-9]{1,9}(\.[0-9]{1,8})?$/D',$v)))self::invalid();
                    if(in_array($k,['quantity_numerator','quantity_denominator'],true) && $v!==null && (!is_int($v) || (float)$v<0 || floor((float)$v)!=(float)$v || ($k==='quantity_denominator' && (float)$v==0)))self::invalid();
                    if(in_array($k,['is_optional','is_main_ingredient','scale_quantity'],true) && !in_array($v,[0,1],true))self::invalid();
                }
                if($list==='steps') {
                    $actions=$row['actions']??[];if(!is_array($actions) || !array_is_list($actions) || count($actions)>20)self::invalid();
                    foreach($actions as $a) {
                        if(!is_array($a) || array_diff(array_keys($a),self::ACTION) || !in_array($a['action_type']??null,['timer','appliance_temperature'],true))self::invalid();
                        if($a['action_type']==='timer' && (!is_numeric($a['duration_seconds']??null) || (float)$a['duration_seconds']<=0 || (float)$a['duration_seconds']>1000000000 || floor((float)$a['duration_seconds'])!=(float)$a['duration_seconds']))self::invalid();
                        if($a['action_type']==='appliance_temperature' && (!is_numeric($a['temperature_value']??null) || !is_finite((float)$a['temperature_value']) || !in_array($a['temperature_unit']??null,['C','F'],true)))self::invalid();
                    }
                }
                if($list==='categories' && (!is_string($row['parent_label']??null) || trim($row['parent_label'])===''))self::invalid();
            }
        }
        if(strlen(json_encode($recipe,JSON_THROW_ON_ERROR))>80000)self::invalid();
        // Reuse the editor's existing quantity/group/action normalization, then strip
        // generated IDs before this single canonical cooking document goes to AI.
        try{$draft=RecipeImportService::draft(['recipe'=>$recipe,'questions'=>[]],$catalog)['recipe'];}
        catch(HttpError $error){self::invalid();}
        $groups=array_column($draft['groups'],'title','id');
        $recipe['ingredients']=[];$recipe['steps']=[];
        foreach($draft['ingredients'] as $i=>$row) {
            $item=array_intersect_key($row,array_flip(self::INGREDIENT));$item['group_title']=$groups[$row['group_id']??'']??null;$recipe['ingredients'][$i]=self::ordered($item,self::INGREDIENT);
        }
        foreach($draft['steps'] as $i=>$row) {
            $recipe['steps'][$i]=array_intersect_key($row,array_flip(self::STEP));$recipe['steps'][$i]['actions']=[];
            foreach($draft['actions'] as $a)if((string)$a['step_id']===(string)$row['id'])$recipe['steps'][$i]['actions'][]=self::ordered($a,self::ACTION);
        }
        $recipe['categories']=array_map(static fn($row)=>['label'=>$row['label'],'parent_label'=>$row['parent_label']],$draft['category_assignments']);
        return $recipe;
    }

    public static function ready(array $recipe): bool { return trim($recipe['title'])!=='' && $recipe['ingredients']!==[] && $recipe['steps']!==[]; }
    public static function editor(array $recipe,array $catalog=[]): array { return RecipeImportService::draft(['recipe'=>$recipe,'questions'=>[]],$catalog)['recipe']; }
    public static function text(array $recipe): string {
        if(trim($recipe['title'])==='' && !$recipe['ingredients'] && !$recipe['steps'])return '';
        $lines=[$recipe['title'],'','Ingredients'];$last=null;
        foreach($recipe['ingredients'] as $row) { $group=$row['group_title']??null;if($group!==null && $group!==$last)$lines[]=$group.':';$last=$group;$lines[]=$row['original_text']; }
        $lines[]='';$lines[]='Method';foreach($recipe['steps'] as $i=>$row) {if(!empty($row['section_title']))$lines[]=$row['section_title'];$lines[]=($i+1).'. '.$row['instruction'];}
        if(!empty($recipe['notes'])){$lines[]='';$lines[]='Notes';$lines[]=$recipe['notes'];}
        return implode("\n",$lines);
    }
}
