<?php
declare(strict_types=1);

/** Account policy and PIN hashes in the existing users table. Row locks protect attempts/resets. */
final class ChildMode {
    public function __construct(private PDO $pdo) {}
    private function locked(string $uid, callable $action): array {
        if ($uid === '') throw new HttpError(401,'authentication_required','Please sign in.');
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare('SELECT child_mode,child_mode_pin_hash,child_mode_attempts,child_mode_blocked_until,child_mode_reset_after FROM users WHERE firebase_uid=? FOR UPDATE');
            $q->execute([$uid]); $row=$q->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new HttpError(403,'account_disabled','Account unavailable.');
            $state=['enabled'=>(bool)$row['child_mode']];
            foreach (['pin_hash','attempts','blocked_until','reset_after'] as $field) {
                $value=$row['child_mode_'.$field];
                if ($value!==null) $state[$field]=$field==='pin_hash' ? $value : (int)$value;
            }
            $before=$state; $result=$action($state);
            if ($state!==$before) {
                $q=$this->pdo->prepare('UPDATE users SET child_mode=?,child_mode_pin_hash=?,child_mode_attempts=?,child_mode_blocked_until=?,child_mode_reset_after=? WHERE firebase_uid=?');
                $q->execute([$state['enabled']?1:0,$state['pin_hash']??null,$state['attempts']??0,$state['blocked_until']??null,$state['reset_after']??null,$uid]);
            }
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
    public function peopleChildModes(string $userId): array {
        $q=$this->pdo->prepare('SELECT p.id,u.child_mode FROM household_people p JOIN household_memberships m ON m.household_id=p.household_id JOIN users u ON u.id=p.user_id WHERE m.user_id=?');
        $q->execute([$userId]); $flags=[];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $flags[(string)$row['id']]=(bool)$row['child_mode'];
        return $flags;
    }
    public function state(array $identity): array {
        return $this->locked($identity['uid'],static function(array &$s) use($identity): array {
            if (isset($s['reset_after']) && ($identity['auth_time']??0)>$s['reset_after']) $s=['enabled'=>false];
            return ['enabled'=>$s['enabled']===true];
        });
    }
    public function change(array $identity,array $body): array {
        $pin=$body['pin']??null;$enabled=$body['enabled']??null;
        if (!is_bool($enabled)||!is_string($pin)||!preg_match('/^[0-9]{4,8}$/D',$pin)) throw new HttpError(422,'invalid_pin','Enter a PIN of 4 to 8 digits.');
        $result=$this->locked($identity['uid'],static function(array &$s) use($enabled,$pin): array {
            if (($s['blocked_until']??0)>time()) return ['error'=>'pin_rate_limited'];
            if ($s['enabled']===true) {
                if ($enabled) return ['error'=>'child_mode_enabled'];
                if (!password_verify($pin,$s['pin_hash'])) {
                    $s['attempts']=($s['attempts']??0)+1;
                    if ($s['attempts']>=5) {$s['blocked_until']=time()+60;$s['attempts']=0;}
                    return ['error'=>'incorrect_pin'];
                }
                $s=['enabled'=>false];
            } elseif ($enabled) $s=['enabled'=>true,'pin_hash'=>password_hash($pin,PASSWORD_DEFAULT)];
            return ['enabled'=>$s['enabled']];
        });
        if (isset($result['error'])) throw new HttpError($result['error']==='pin_rate_limited'?429:403,$result['error'],'PIN not accepted. Try again, or ask an administrator to reset child mode.');
        return $result;
    }
    public function requestReset(string $uid): array {
        return $this->locked($uid,static function(array &$s): array {$s['reset_after']=time();return ['reset_pending'=>true];});
    }
    public static function allowed(string $route,string $method): bool {
        return in_array($method,['GET','OPTIONS'],true)||$route==='/me/child-mode'||preg_match('#^/households/[1-9][0-9]*/recipes/[1-9][0-9]*/favorites$#D',$route)===1;
    }
    public function guard(array $identity,string $route,string $method): void {
        if (!self::allowed($route,$method)&&$this->state($identity)['enabled']) throw new HttpError(403,'child_mode','Child mode allows viewing and favorites only.');
    }
}
