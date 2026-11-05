<?php
declare(strict_types=1);

/** Small, durable session store. A separate lock protects atomic file replacement. */
final class CookingSessions
{
    private static array $pending=[];
    private bool $changed=false;
    public function __construct(private PDO $pdo, private $push = null, private ?string $directory = null, private $clock = null, private bool $deferPush=false) {}
    public static function flushPushes(): void {
        $pending=self::$pending; self::$pending=[];
        foreach($pending as $send) { try { $send(); } catch(Throwable $e) { /* Reconnect reconciles missed background delivery. */ } }
    }
    private function now(): int { return $this->clock ? ($this->clock)() : (int)floor(microtime(true)*1000); }
    private function store(callable $action): mixed {
        $dir=$this->directory ?? CTR_STORAGE.'/cooking';
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Session storage unavailable.');
        $lock=fopen($dir.'/sessions.lock','c');
        if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Session storage unavailable.');
        $expired=[]; $saved=false;
        try {
            $file=$dir.'/sessions.json';
            $state=is_file($file) ? json_decode(file_get_contents($file),true,128,JSON_THROW_ON_ERROR) : [];
            $before=json_encode($state,JSON_THROW_ON_ERROR);
            $now=$this->now();
            foreach ($state as $id=>&$s) {
                if ($s['ended_at']!==null) { if ($s['ended_at']<$now-7*86400000) unset($state[$id]); continue; }
                $last=$s['last_open']; $latest=0;
                foreach ($s['devices'] as &$d) {
                    $last=max($last,$d['open_until']);
                    if ($d['open_until']>0 && $d['open_until']<=$now) {
                        $s['last_open']=max($s['last_open'],$d['open_until']);
                        $d['open_until']=0;
                    }
                }
                unset($d);
                foreach ($s['timers'] as $t) if (!$t['cancelled']) $latest=max($latest,$t['deadline_ms']);
                if ($now>=max($last+$s['idle_ms'],$latest)) { $this->end($s,$now); $expired[]=$id; }
            }
            unset($s);
            $result=$action($state);
            $encoded=json_encode($state,JSON_THROW_ON_ERROR);
            $this->changed=$encoded!==$before;
            if (!$this->changed && is_file($file)) { $saved=true; return $result; }
            $temporary=tempnam($dir,'sessions-');
            try {
                if (file_put_contents($temporary,$encoded,LOCK_EX)===false || !rename($temporary,$file)) throw new RuntimeException('Session could not be saved.');
                chmod($file,0600);
            } finally { if (is_file($temporary)) unlink($temporary); }
            $saved=true;
            return $result;
        } finally {
            flock($lock,LOCK_UN); fclose($lock);
            if($saved) foreach($expired as $id) {
                $send=fn()=>$this->deliver(['id'=>$id],null,'',null);
                if($this->deferPush) self::$pending[]=$send; else $send();
            }
        }
    }
    public function sweep(): bool { $this->store(static fn(&$state)=>null); return $this->changed; }
    private function member(string $user,string $household): bool {
        $q=$this->pdo->prepare('SELECT role FROM household_memberships WHERE household_id=? AND user_id=?'); $q->execute([$household,$user]);
        return $q->fetchColumn()!==false;
    }
    private function accessible(array $s,string $user): bool {
        return isset($s['users'][$user]) && $this->member($user,$s['household_id']);
    }
    public function snapshot(string $user): array {
        return $this->store(function(&$state) use($user) {
            $sessions=[]; $ended=[];
            foreach ($state as $s) if ($this->accessible($s,$user)) {
                if ($s['ended_at']!==null) $ended[]=$this->view($s,$user); else $sessions[]=$this->view($s,$user);
            }
            return ['sessions'=>$sessions,'ended_sessions'=>$ended];
        });
    }
    private function view(array $s,string $user): array {
        unset($s['operations']);
        $s['step_revisions']=(object)$s['step_revisions'];
        $s['owned']=$s['owner']===$user;
        $s['invited']=$s['users'][$user]['invited'];
        unset($s['users'],$s['owner']);
        $s['timers']=array_values($s['timers']);
        // Do not disclose other participants' account identifiers.
        $s['joined_devices']=array_keys(array_filter($s['devices'],fn($d)=>$d['user']===$user));
        $s['open_elsewhere']=count(array_filter($s['devices'],fn($d)=>$d['open_until']>$this->now()))>0;
        unset($s['devices']);
        return $s;
    }
    private function end(array &$s,int $now): void {
        $s['ended_at']=$now; ++$s['revision'];
        foreach ($s['timers'] as &$timer) { $timer['cancelled']=true; $timer['extension_seconds']=0; ++$timer['revision']; }
        unset($timer);
    }
    private function id(mixed $id): string { return TimerSyncService::identifier($id); }
    private function joined(array $s,string $user,string $device): void {
        if (($s['devices'][$device]['user']??null)!==$user) throw new HttpError(409,'session_not_joined','Join this cooking session first.');
    }
    public function request(string $user,string $uid,string $path,?array $body): array {
        if ($body===null && $path==='/me/cooking-sessions') return $this->snapshot($user);
        if ($body===null && preg_match('#^/me/cooking-sessions/([a-f0-9]{32})/people$#D',$path,$m)) {
            return $this->store(function(&$state) use($user,$m) {
                $s=$state[$m[1]]??null;
                if (!$s || !$this->accessible($s,$user) || $s['ended_at']!==null) throw new HttpError(404,'session_missing','Cooking session ended.');
                $q=$this->pdo->prepare('SELECT p.id,p.display_name FROM household_people p JOIN users u ON u.id=p.user_id JOIN household_memberships m ON m.user_id=p.user_id AND m.household_id=p.household_id WHERE p.household_id=? AND p.user_id<>? AND u.disabled=0 ORDER BY p.id');
                $q->execute([$s['household_id'],$user]); return ['people'=>$q->fetchAll(PDO::FETCH_ASSOC)];
            });
        }
        if ($body===null || !preg_match('#^/me/cooking-sessions(?:/([a-f0-9]{32})/(join|step|timer|presence|end|share))?$#D',$path,$m)) throw new HttpError(404,'not_found','Cooking session not found.');
        $device=$this->id($body['installation_id']??null); $op=$this->id($body['operation_id']??null);
        $result=$this->store(function(&$state) use($user,$body,$m,$device,$op) {
            $now=$this->now(); $action=$m[2]??'create';
            $id=$action==='create' ? $this->id($body['session_id']??null) : $m[1];
            if ($action==='create' && !isset($state[$id])) {
                $household=(string)($body['household_id']??'');
                $recipe=(new RecipeService($this->pdo,new HouseholdService($this->pdo)))->detail($user,$household,(string)($body['recipe_id']??''));
                if (empty($recipe['steps'])) throw new HttpError(422,'no_steps','This recipe has no cooking steps.');
                $recipe=array_intersect_key($recipe,array_flip(['id','title','steps','actions','ingredients','groups','prep_minutes','cook_minutes','total_minutes']));
                $minutes=$recipe['total_minutes']??((float)($recipe['prep_minutes']??0)+(float)($recipe['cook_minutes']??0));
                $state[$id]=['id'=>$id,'owner'=>$user,'household_id'=>$household,'recipe'=>$recipe,'completed'=>[], 'step_revisions'=>[],
                    'timers'=>[],'devices'=>[],'users'=>[$user=>['invited'=>false]],'revision'=>0,'created_at'=>$now,'last_open'=>$now,
                    'idle_ms'=>3600000+(int)max(0,(float)$minutes*60000),'ended_at'=>null,'operations'=>[]];
            }
            if (!isset($state[$id]) || !$this->accessible($state[$id],$user)) throw new HttpError(404,'session_missing','Cooking session unavailable.');
            $s=&$state[$id];
            if (isset($s['operations'][$op])) {
                if ($s['operations'][$op]!==$user.':'.$device.':'.$action) throw new HttpError(409,'operation_conflict','Operation already used.');
                return ['session'=>$this->view($s,$user),'push'=>false];
            }
            if ($s['ended_at']!==null) throw new HttpError(409,'session_ended','Cooking session ended.');
            $push=false; $invite=null;
            if ($action==='create' || $action==='join') {
                $s['devices'][$device]=['user'=>$user,'open_until'=>$now+60000]; $s['last_open']=$now;
            } else {
                if (!in_array($action,['share','end'],true)) $this->joined($s,$user,$device);
                if ($action==='presence') {
                    if (!is_bool($body['open']??null)) throw new HttpError(422,'invalid_presence','Invalid cooking presence.');
                    $s['devices'][$device]['open_until']=$body['open'] ? $now+60000 : $now;
                    $s['last_open']=$now;
                } elseif ($action==='step') {
                    $step=(string)($body['step_id']??'');
                    if (!in_array($step,array_map(fn($r)=>(string)$r['id'],$s['recipe']['steps']),true) || !is_bool($body['checked']??null)) throw new HttpError(422,'invalid_step','Invalid cooking step.');
                    if (($body['revision']??null)!==($s['step_revisions'][$step]??0)) throw new HttpError(409,'step_conflict','This step changed on another device. Try again.');
                    $s['completed']=array_values(array_diff($s['completed'],[$step]));
                    if ($body['checked']) $s['completed'][]=$step;
                    $s['step_revisions'][$step]=($s['step_revisions'][$step]??0)+1;
                } elseif ($action==='timer') {
                    $key=(string)($body['action_id']??'');
                    if (!in_array($key,array_map(fn($r)=>(string)$r['id'],$s['recipe']['actions']),true)) throw new HttpError(422,'invalid_timer','Invalid recipe timer.');
                    $old=$s['timers'][$key]??null;
                    if (($body['revision']??null)!==($old['revision']??0) && !($old && ($body['expected_deadline_ms']??null)===$old['deadline_ms'] && (($body['cancelled']??false)===true || (($body['extend']??false)===true && ($old['extension_seconds']??0)>0)))) throw new HttpError(409,'timer_conflict','This timer changed on another device. Try again.');
                    $cancel=$body['cancelled']??null; $deadline=$body['deadline_ms']??null;
                    $extension=$body['extension_seconds']??0; $extend=$body['extend']??false;
                    if(!is_bool($extend) || !is_int($extension) || $extension<0 || $extension>604800 || ($extend && $cancel)) throw new HttpError(422,'invalid_timer','Invalid timer extension.');
                    if (!is_bool($cancel) || (!$cancel && (!is_int($deadline) || ($deadline<=$now && ($extension<=0 || $extend || $deadline<$now-7*86400000)) || $deadline>$now+7*86400000))) throw new HttpError(422,'invalid_timer','Choose a timer between now and seven days from now.');
                    if($extend) {
                        if(!$old || $old['cancelled'] || ($old['extension_seconds']??0)<=0 || $old['deadline_ms']>$now || $deadline>$now+$old['extension_seconds']*1000) throw new HttpError(409,'timer_extension_unavailable','The remaining time is no longer available.');
                        $extension=0;
                    }
                    if($cancel) $extension=0;
                    $s['timers'][$key]=['timer_id'=>'session:'.$id.':'.$key,'session_id'=>$id,'action_id'=>$key,'source_device'=>'session',
                        'deadline_ms'=>$cancel ? ($old['deadline_ms']??$now) : $deadline,'cancelled'=>$cancel,'extension_seconds'=>$extension,'revision'=>($old['revision']??0)+1,
                        'title'=>mb_substr($s['recipe']['title'].' · '.(array_values(array_filter($s['recipe']['actions'],fn($a)=>(string)$a['id']===$key))[0]['label']??'Cooking timer'),0,300)];
                    // Start delivery is WebSocket-only. Updates to an existing alarm need background delivery.
                    $push=$old!==null || $cancel;
                } elseif ($action==='end') { $this->end($s,$now); $push=true; }
                elseif ($action==='share') {
                    $q=$this->pdo->prepare('SELECT p.user_id FROM household_people p JOIN users u ON u.id=p.user_id JOIN household_memberships m ON m.user_id=p.user_id AND m.household_id=p.household_id WHERE p.id=? AND p.household_id=? AND u.disabled=0');
                    $q->execute([(string)($body['person_id']??''),$s['household_id']]); $target=$q->fetchColumn();
                    if (!$target || (string)$target===$user) throw new HttpError(422,'invalid_member','Choose a household member.');
                    $s['users'][(string)$target]=['invited'=>true]; $invite=(string)$target;
                }
            }
            ++$s['revision']; $s['operations'][$op]=$user.':'.$device.':'.$action;
            return ['session'=>$this->view($s,$user),'push'=>$push,'invite'=>$invite,'timer_key'=>$action==='timer'?$key:null];
        });
        $s=$result['session'];
        if ($result['push'] || isset($result['invite'])) {
            $send=fn()=>$this->deliver($s,$result['invite']??null,$device,$result['timer_key']??null);
            if($this->deferPush) self::$pending[]=$send; else $send();
        }
        return ['session'=>$s];
    }
    private function deliver(array $view,?string $invite,string $source,?string $timerKey): void {
        $s=$this->store(fn(&$state)=>$state[$view['id']]);
            $q=$this->pdo->prepare('SELECT d.installation_id,d.push_token,d.platform,d.user_id,d.receive_cooking_invitations,u.firebase_uid FROM timer_devices d JOIN users u ON u.id=d.user_id WHERE d.push_token IS NOT NULL AND u.disabled=0');
            $q->execute();
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $d) {
                $user=(string)$d['user_id'];
                if (!$this->accessible($s,$user)) continue;
                $payloads=[];
                if ($invite===$user && !$d['receive_cooking_invitations']) continue;
                if ($invite===$user) $payloads[]=['kind'=>'cooking_invitation','session_id'=>$s['id'],'title'=>$s['recipe']['title']];
                elseif (($s['devices'][$d['installation_id']]['user']??null)===$user && $d['installation_id']!==$source) $payloads=$timerKey===null?array_values($s['timers']):[$s['timers'][$timerKey]];
                foreach ($payloads as $payload) {
                    try {
                        if ($this->push && ($this->push)($d,$payload+['account_uid'=>$d['firebase_uid'],'target_device'=>$d['installation_id']])===false) {
                            $this->pdo->prepare('UPDATE timer_devices SET push_token=NULL,receive_timers=0 WHERE installation_id=? AND push_token=?')->execute([$d['installation_id'],$d['push_token']]);
                        }
                    }
                    catch (Throwable $e) { /* Snapshot reconciliation remains authoritative. */ }
                }
            }
    }
}
