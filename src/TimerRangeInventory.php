<?php
declare(strict_types=1);
require_once __DIR__.'/TimerRanges.php';

/** One-time reviewed data maintenance, separate from routine deployment. */
final class TimerRangeInventory
{
    public static function snapshot(PDO $pdo, string $id): array
    {
        $q=$pdo->prepare('SELECT * FROM recipes WHERE id=?'); $q->execute([$id]);
        $snapshot=['recipe'=>$q->fetch(PDO::FETCH_ASSOC)];
        foreach (['steps'=>'recipe_steps','actions'=>'recipe_step_actions'] as $key=>$table) {
            $q=$pdo->prepare("SELECT * FROM $table WHERE recipe_id=? ORDER BY id"); $q->execute([$id]); $snapshot[$key]=$q->fetchAll(PDO::FETCH_ASSOC);
        }
        return $snapshot;
    }

    public static function survey(PDO $pdo): array
    {
        $result=['created_at'=>gmdate('c'),'recipes'=>[],'candidates'=>[],'recipe_count'=>0,'step_count'=>0,'action_count'=>0];
        foreach ($pdo->query('SELECT id FROM recipes WHERE deleted_at IS NULL ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $snapshot=self::snapshot($pdo,(string)$id); $changes=[];
            $result['recipe_count']++; $result['step_count']+=count($snapshot['steps']); $result['action_count']+=count($snapshot['actions']);
            foreach ($snapshot['steps'] as $step) {
                $text=$step['instruction']; $ranges=TimerRanges::extract($text);
                if (!$ranges) continue;
                $actions=array_values(array_filter($snapshot['actions'],fn($a)=>(string)$a['step_id']===(string)$step['id']));
                foreach ($ranges as $range) {
                    $reason=null;
                    if (preg_match('/reviewers? reported|\b(?:storage|store for|keeps? for)\b|'.preg_quote($range['text'],'/').'\s+early/iu',$text)) $reason='Reported historical time, storage limit or early-check offset; leave unchanged.';
                    $timed=array_values(array_filter($actions,fn($a)=>in_array($a['action_type'],['timer','rest'],true)));
                    $matches=array_values(array_filter($timed,fn($a)=>in_array((int)$a['duration_seconds'],[$range['min'],$range['max']],true)));
                    if (!$reason && count($matches)>1) $reason='Multiple existing timers match; review separately without deleting user actions.';
                    if (!$reason && !$matches && $timed) $reason='Existing timer describes a different duration; review separately.';
                    if (!$reason && !$matches && count($ranges)>1) $reason='Multiple ranges without matching actions; review separately.';
                    $candidate=['recipe_id'=>(string)$id,'title'=>$snapshot['recipe']['title'],'step_id'=>(string)$step['id'],'instruction'=>$text,'range'=>$range,'decision'=>$reason??'apply'];
                    $result['candidates'][]=$candidate;
                    if ($reason) continue;
                    if ($matches && (int)($matches[0]['duration_max_seconds']??0)===$range['max'] && (int)$matches[0]['duration_seconds']===$range['min']) continue;
                    $qualifier=preg_match('/\b(per side|per batch)\b/i',$text,$m)?' · '.$m[1]:'';
                    $changes[]=['step_id'=>(string)$step['id'],'action_id'=>$matches?(string)$matches[0]['id']:null,
                        'minimum'=>$range['min'],'maximum'=>$range['max'],'label'=>'Cooking timer'.$qualifier];
                }
            }
            // Snapshot all active recipes so the private inventory is also recovery evidence.
            $result['recipes'][]=['before'=>$snapshot,'changes'=>$changes];
        }
        return $result;
    }

    public static function apply(PDO $pdo, array $plan): array
    {
        $counts=['recipes'=>0,'updated'=>0,'added'=>0];
        $pdo->beginTransaction();
        try {
            foreach ($plan['recipes'] as $entry) {
                if (!$entry['changes']) continue;
                $before=$entry['before']; $recipe=$before['recipe']; $id=(string)$recipe['id'];
                $lock=$pdo->prepare('SELECT id FROM recipes WHERE id=? FOR UPDATE'); $lock->execute([$id]);
                if (self::snapshot($pdo,$id)!==$before) throw new RuntimeException('Recipe changed since survey; regenerate inventory before applying.');
                foreach ($entry['changes'] as $change) {
                    $min=$change['minimum']; $max=$change['maximum'];
                    if (!is_int($min) || !is_int($max) || $min<=0 || $max<$min) throw new RuntimeException('Invalid reviewed range.');
                    $step=array_values(array_filter($before['steps'],fn($s)=>(string)$s['id']===$change['step_id']))[0]??null;
                    if (!$step) throw new RuntimeException('Unknown reviewed step.');
                    if ($change['action_id']!==null) {
                        $q=$pdo->prepare('UPDATE recipe_step_actions SET duration_seconds=?,duration_max_seconds=? WHERE id=? AND recipe_id=? AND step_id=?');
                        $q->execute([$min,$max,$change['action_id'],$id,$change['step_id']]);
                        if ($q->rowCount()!==1) throw new RuntimeException('Reviewed action missing.');
                        $counts['updated']++;
                    } else {
                        $q=$pdo->prepare('SELECT COALESCE(MAX(position),0)+1 FROM recipe_step_actions WHERE recipe_id=? AND step_id=?'); $q->execute([$id,$change['step_id']]);
                        $position=(int)$q->fetchColumn();
                        $q=$pdo->prepare('INSERT INTO recipe_step_actions (household_id,recipe_id,step_id,position,action_type,label,duration_seconds,duration_max_seconds) VALUES (?,?,?,?,?,?,?,?)');
                        $q->execute([$step['household_id'],$id,$change['step_id'],$position,'timer',$change['label'],$min,$max]);
                        $counts['added']++;
                    }
                }
                $after=self::snapshot($pdo,$id);
                if ($after['steps']!==$before['steps']) throw new RuntimeException('Instruction verification failed.');
                $afterActions=array_column($after['actions'],null,'id');
                foreach ($before['actions'] as $original) {
                    foreach ($entry['changes'] as $change) if ($change['action_id']===(string)$original['id']) {
                        $original['duration_seconds']=$change['minimum']; $original['duration_max_seconds']=$change['maximum'];
                    }
                    if (($afterActions[$original['id']]??null)!==$original) throw new RuntimeException('Action preservation verification failed.');
                }
                foreach ($entry['changes'] as $change) {
                    $matches=array_filter($after['actions'],fn($a)=>(string)$a['step_id']===$change['step_id'] && (int)$a['duration_seconds']===$change['minimum'] && (int)$a['duration_max_seconds']===$change['maximum']);
                    if (count($matches)!==1) throw new RuntimeException('Range readback verification failed.');
                }
                $pdo->prepare('UPDATE recipes SET revision=revision+1,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$id]);
                $counts['recipes']++;
            }
            $pdo->commit();
        } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
        return $counts;
    }
}
