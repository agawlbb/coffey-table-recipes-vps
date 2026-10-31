<?php
declare(strict_types=1);

/** Private, durable cooking preferences. Never shared with another account. */
final class RecipeUserContext
{
    private $lock=null;
    private string $path;
    private array $state;

    public function __construct(private string $storage, string $user) {
        $directory=$storage.'/recipe-user-context';
        if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new RuntimeException('Cannot create cooking context storage.');
        $this->path=$directory.'/'.hash('sha256',$user).'.json';
        $lockPath=$directory.'/'.hash('sha256',$user).'.lock';
        if (is_link($this->path) || is_link($lockPath)) throw new RuntimeException('Invalid cooking context storage.');
        try {
            $this->lock=fopen($lockPath,'c');
            if ($this->lock===false) throw new RuntimeException('Cannot open cooking context lock.');
            chmod($lockPath,0600);
            // Serialize this account's chats so simultaneous sessions cannot lose corrections.
            if (!flock($this->lock,LOCK_EX|LOCK_NB)) throw new HttpError(409,'chat_in_progress','Another chat is updating your cooking context. Please try again shortly.');
            $this->state=is_file($this->path) ? json_decode(file_get_contents($this->path),true,128,JSON_THROW_ON_ERROR) : ['revision'=>0,'facts'=>[]];
            $this->validateState();
            // Complete a prior turn after a crash between its two atomic file replacements.
            // Recovery happens before ANY conversation for this account reads its context.
            if (isset($this->state['pending_chat'])) {
                $pending=$this->state['pending_chat'];
                if (!is_array($pending) || !is_string($pending['identity']??null) || !preg_match('/^[a-f0-9]{64}$/D',$pending['identity']) || !is_array($pending['state']??null)) throw new RuntimeException('Invalid pending cooking context update.');
                self::write($storage.'/recipe-chats/'.$pending['identity'].'.json',$pending['state']);
                unset($this->state['pending_chat']);
                self::write($this->path,$this->state);
            }
        } catch (Throwable $error) { $this->close(); throw $error; }
    }

    public function close(): void {
        if (is_resource($this->lock)) { flock($this->lock,LOCK_UN); fclose($this->lock); }
        $this->lock=null;
    }

    public function __destruct() { $this->close(); }

    private function validateState(): void {
        if (!is_int($this->state['revision']??null) || $this->state['revision']<0 || !is_array($this->state['facts']??null) || !array_is_list($this->state['facts']) || count($this->state['facts'])>100) throw new RuntimeException('Invalid stored cooking context.');
        $ids=[];
        foreach ($this->state['facts'] as $fact) {
            if (!is_array($fact) || !is_string($fact['id']??null) || !preg_match('/^[a-f0-9]{32}$/D',$fact['id']) || isset($ids[$fact['id']])
                || !is_string($fact['person']??null) || trim($fact['person'])==='' || mb_strlen($fact['person'])>80
                || !is_string($fact['preference']??null) || trim($fact['preference'])==='' || mb_strlen($fact['preference'])>500
                || !array_key_exists('household',$fact) || ($fact['person']==='self' ? $fact['household']!==null : !is_string($fact['household']))) throw new RuntimeException('Invalid stored cooking context fact.');
            $ids[$fact['id']]=true;
        }
    }

    public function forHousehold(string $household): array {
        $facts=[];
        foreach ($this->state['facts'] as $fact) {
            if ($fact['household']!==null && $fact['household']!==$household) continue;
            // Opaque fact IDs let AI correct facts without seeing account/household identifiers.
            $facts[]=array_intersect_key($fact,array_flip(['id','person','preference']));
        }
        return $facts;
    }

    public function revision(): int { return $this->state['revision']; }

    /** Validate the entire delta before changing anything; evidence must quote this user turn. */
    public function propose(mixed $updates, string $message, string $household): array {
        $invalid=static function(): never { throw new HttpError(502,'invalid_ai_reply','AI returned an invalid cooking context update. Your message is still here; please try again.'); };
        if (!is_array($updates) || !array_is_list($updates) || count($updates)>100) $invalid();
        $facts=array_column($this->state['facts'],null,'id');
        $visible=array_column($this->forHousehold($household),null,'id');
        $seen=[]; $applied=[];
        foreach ($updates as $update) {
            if (!is_array($update) || count($update)!==5 || !in_array($update['action']??null,['add','update','remove'],true)
                || !array_key_exists('id',$update) || !array_key_exists('person',$update) || !array_key_exists('preference',$update)
                || !is_string($update['evidence']??null) || trim($update['evidence'])==='' || mb_strlen($update['evidence'])>1000 || !str_contains($message,trim($update['evidence']))) $invalid();
            $action=$update['action']; $id=$update['id'];
            if ($action==='add') {
                if ($id!==null || !is_string($update['person']) || trim($update['person'])==='' || mb_strlen($update['person'])>80) $invalid();
                $person=trim($update['person']);
                if (mb_strtolower($person)==='self') $person='self';
                $scope=$person==='self'?null:$household;
            } else {
                if (!is_string($id) || !isset($visible[$id]) || isset($seen[$id]) || $update['person']!==null) $invalid();
                $seen[$id]=true; $person=$visible[$id]['person']; $scope=$facts[$id]['household'];
            }
            if ($action==='remove') {
                if ($update['preference']!==null) $invalid();
                unset($facts[$id]);
                $applied[]=['action'=>'remove','id'=>$id,'person'=>$person,'preference'=>null];
                continue;
            }
            if (!is_string($update['preference']) || trim($update['preference'])==='' || mb_strlen($update['preference'])>500) $invalid();
            $preference=trim($update['preference']);
            if ($action==='add') {
                $duplicate=false;
                foreach ($facts as $existing) if ($existing['household']===$scope && mb_strtolower($existing['person'])===mb_strtolower($person) && mb_strtolower($existing['preference'])===mb_strtolower($preference)) { $duplicate=true; break; }
                if ($duplicate) continue;
                $id=bin2hex(random_bytes(16));
            } elseif ($facts[$id]['preference']===$preference) continue;
            $facts[$id]=['id'=>$id,'person'=>$person,'preference'=>$preference,'household'=>$scope];
            $applied[]=['action'=>$action,'id'=>$id,'person'=>$person,'preference'=>$preference];
        }
        if (count($facts)>100) throw new HttpError(422,'invalid_recipe_chat','Your cooking context is full. Ask me to forget an old preference before adding another.');
        return ['revision'=>$this->state['revision']+($applied===[]?0:1),'facts'=>array_values($facts),'updates'=>$applied];
    }

    /** Commit preferences and the successful chat turn together, with crash recovery. */
    public function commit(array $next, string $identity, array $chat): void {
        if (!preg_match('/^[a-f0-9]{64}$/D',$identity)) throw new RuntimeException('Invalid chat identity.');
        $state=['revision'=>$next['revision'],'facts'=>$next['facts']];
        self::write($this->path,$state+['pending_chat'=>['identity'=>$identity,'state'=>$chat]]);
        self::write($this->storage.'/recipe-chats/'.$identity.'.json',$chat);
        self::write($this->path,$state);
        $this->state=$state;
    }

    private static function write(string $path, array $value): void {
        $encoded=json_encode($value,JSON_THROW_ON_ERROR);
        $temporary=tempnam(dirname($path),'context-');
        try {
            if ($temporary===false || @file_put_contents($temporary,$encoded)!==strlen($encoded)) throw new HttpError(503,'ai_interrupted','Could not save your cooking context. Please retry the last request.');
            chmod($temporary,0600);
            if (!@rename($temporary,$path)) throw new HttpError(503,'ai_interrupted','Could not save your cooking context. Please retry the last request.');
        } finally { if ($temporary!==false && is_file($temporary)) unlink($temporary); }
    }
}
