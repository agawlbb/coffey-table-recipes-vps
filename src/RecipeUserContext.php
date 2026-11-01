<?php
declare(strict_types=1);

/** Private, durable cooking preferences. Never shared with another account. */
final class RecipeUserContext
{
    private bool $locked=false;
    private string $lockName;
    private array $state;

    public function __construct(private PDO $pdo, private string $storage, private string $user) {
        $this->lockName='ctr-context-'.substr(hash('sha256',$user),0,52);
        try {
            $q=$pdo->prepare('SELECT GET_LOCK(?,0)'); $q->execute([$this->lockName]);
            if ((string)$q->fetchColumn()!=='1') throw new HttpError(409,'chat_in_progress','Another chat is updating your cooking context. Please try again shortly.');
            $this->locked=true;
            $q=$pdo->prepare('INSERT IGNORE INTO user_recipe_context (user_id,state_json) VALUES (?,?)');
            $q->execute([$user,json_encode(['revision'=>0,'facts'=>[]],JSON_THROW_ON_ERROR)]);
            $q=$pdo->prepare('SELECT state_json FROM user_recipe_context WHERE user_id=?'); $q->execute([$user]);
            $raw=$q->fetchColumn();
            if (!is_string($raw)) throw new RuntimeException('Cooking context account unavailable.');
            $this->state=json_decode($raw,true,128,JSON_THROW_ON_ERROR);
            self::validateState($this->state);
            // SQL is authoritative. Recover a cached reply after interruption
            // between committing SQL and replacing its temporary conversation file.
            if (isset($this->state['pending_chat'])) {
                $pending=$this->state['pending_chat'];
                self::write($storage.'/recipe-chats/'.$pending['identity'].'.json',$pending['state']);
                unset($this->state['pending_chat']);
                $this->save($this->state);
            }
        } catch (Throwable $error) { $this->close(); throw $error; }
    }

    public function close(): void {
        if (!$this->locked) return;
        $this->locked=false;
        try { $q=$this->pdo->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$this->lockName]); }
        catch (PDOException $error) { /* A disconnected worker releases its SQL locks. */ }
    }

    public function __destruct() { $this->close(); }

    public static function validateState(array $state): void {
        if (!is_int($state['revision']??null) || $state['revision']<0 || !is_array($state['facts']??null) || !array_is_list($state['facts']) || count($state['facts'])>100) throw new RuntimeException('Invalid stored cooking context.');
        if (isset($state['pending_chat'])) {
            $pending=$state['pending_chat'];
            if (!is_array($pending) || !is_string($pending['identity']??null) || !preg_match('/^[a-f0-9]{64}$/D',$pending['identity']) || !is_array($pending['state']??null)) throw new RuntimeException('Invalid pending cooking context update.');
        }
        $ids=[];
        foreach ($state['facts'] as $fact) {
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

    /** Commit preferences and the successful chat turn together, with SQL-backed crash recovery. */
    public function commit(array $next, string $identity, array $chat): void {
        if (!preg_match('/^[a-f0-9]{64}$/D',$identity)) throw new RuntimeException('Invalid chat identity.');
        $state=['revision'=>$next['revision'],'facts'=>$next['facts']];
        $this->save($state+['pending_chat'=>['identity'=>$identity,'state'=>$chat]]);
        self::write($this->storage.'/recipe-chats/'.$identity.'.json',$chat);
        $this->save($state);
        $this->state=$state;
    }

    private function save(array $state): void {
        try {
            $q=$this->pdo->prepare('UPDATE user_recipe_context SET state_json=? WHERE user_id=?');
            $q->execute([json_encode($state,JSON_THROW_ON_ERROR),$this->user]);
            if ($q->rowCount()!==1) throw new RuntimeException('Cooking context account unavailable.');
        } catch (PDOException $error) {
            throw new HttpError(503,'ai_interrupted','Could not save your cooking context. Please retry the last request.');
        }
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
