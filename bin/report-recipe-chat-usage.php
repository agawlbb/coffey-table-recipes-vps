<?php
declare(strict_types=1);
// Private metadata only; never reads recipe caches or user cooking context.
$path=$argv[1]??((getenv('CTR_STORAGE')?:dirname(__DIR__).'/storage').'/recipe-chat-metrics.log');
if(!is_file($path)){fwrite(STDERR,"No chat usage log is available yet.\n");exit(1);}
$groups=[];$invalid=0;
foreach(file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
    $row=json_decode($line,true);if(!is_array($row) || !in_array($row['operation']??null,['message','prepare'],true)){$invalid++;continue;}
    $key=$row['operation'].(!empty($row['legacy_conversion'])?'_legacy':'');$g=$groups[$key]??['operations'=>0,'provider_calls'=>0,'input_tokens'=>0,'output_tokens'=>0,'reasoning_tokens'=>0,'cached_input_tokens'=>0,'cache_hits'=>0,'errors'=>0,'unknown_usage_operations'=>0,'uncached_elapsed_ms'=>0,'uncached_operations'=>0];
    $g['operations']++;$g['provider_calls']+=(int)$row['provider_calls'];$g['cache_hits']+=(int)$row['cache_hit'];$g['errors']+=(int)($row['status']!=='completed');$unknown=false;
    foreach(['input_tokens','output_tokens','reasoning_tokens','cached_input_tokens'] as $field){if(($row[$field]??null)===null)$unknown=true;else $g[$field]+=(int)$row[$field];}
    $g['unknown_usage_operations']+=(int)$unknown;
    if(!$row['cache_hit']){$g['uncached_operations']++;$g['uncached_elapsed_ms']+=(int)$row['elapsed_ms'];}
    $groups[$key]=$g;
}
foreach($groups as &$g)$g['average_uncached_elapsed_ms']=$g['uncached_operations']?round($g['uncached_elapsed_ms']/$g['uncached_operations'],1):null;
echo json_encode(['operations'=>$groups,'invalid_log_lines'=>$invalid,'note'=>'Reasoning tokens are included in output tokens; cached input is included in input. Missing usage is unknown, not zero. This is the retained bounded log, not lifetime billing.'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
