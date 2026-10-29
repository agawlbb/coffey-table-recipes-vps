<?php
declare(strict_types=1);

/** Account policy in protected storage, independent of household membership. */
final class ChildMode {
    public function __construct(private string $directory) {}
    private function locked(string $uid, callable $action): array {
        if ($uid === '') throw new HttpError(401,'authentication_required','Please sign in.');
        if (!is_dir($this->directory) && !mkdir($this->directory,0700,true) && !is_dir($this->directory)) throw new RuntimeException('Child mode storage unavailable');
        $file=fopen($this->directory.'/'.hash('sha256',$uid).'.json','c+');
        if (!$file || !flock($file,LOCK_EX)) throw new RuntimeException('Child mode lock unavailable');
        try {
            chmod($this->directory.'/'.hash('sha256',$uid).'.json',0600);
            $text=stream_get_contents($file);
            $state=$text===''?['enabled'=>false]:json_decode($text,true,16,JSON_THROW_ON_ERROR);
            $before=$state;
            $result=$action($state);
            if ($state!==$before) {
                rewind($file);$json=json_encode($state,JSON_THROW_ON_ERROR);
                if (fwrite($file,$json)!==strlen($json)||!ftruncate($file,strlen($json))||!fflush($file)) throw new RuntimeException('Child mode storage failed');
            }
            return $result;
        } finally {flock($file,LOCK_UN);fclose($file);}
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
