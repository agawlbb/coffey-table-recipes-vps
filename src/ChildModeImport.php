<?php
declare(strict_types=1);

/** One-time setup only. The input keys are the existing SHA-256 UID filenames. */
function importChildModeState(PDO $pdo,array $files): array {
    foreach ($files as $hash=>$state) {
        if (!preg_match('/^[a-f0-9]{64}$/D',(string)$hash) || !is_array($state) || !is_bool($state['enabled']??null)) throw new RuntimeException('Invalid legacy child-mode state.');
        if (($state['enabled'] || isset($state['pin_hash'])) && (!is_string($state['pin_hash']??null) || password_get_info($state['pin_hash'])['algoName']==='unknown')) throw new RuntimeException('An enabled policy is missing its PIN hash.');
        foreach (['attempts','blocked_until','reset_after'] as $key) {
            if (isset($state[$key]) && (!is_int($state[$key]) || $state[$key]<0)) throw new RuntimeException('Invalid legacy policy counter.');
        }
    }
    $pdo->beginTransaction();
    try {
        $lock=$pdo->prepare('SELECT id,firebase_uid,child_mode,child_mode_pin_hash,child_mode_attempts,child_mode_blocked_until,child_mode_reset_after FROM users FOR UPDATE');
        $lock->execute(); $users=$lock->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as $user) {
            if ($user['child_mode'] || $user['child_mode_pin_hash']!==null || $user['child_mode_attempts'] || $user['child_mode_blocked_until']!==null || $user['child_mode_reset_after']!==null) throw new RuntimeException('SQL policy already contains state; import must not overwrite it.');
        }
        $update=$pdo->prepare('UPDATE users SET child_mode=?,child_mode_pin_hash=?,child_mode_attempts=?,child_mode_blocked_until=?,child_mode_reset_after=? WHERE id=?');
        $verify=$pdo->prepare('SELECT child_mode,child_mode_pin_hash,child_mode_attempts,child_mode_blocked_until,child_mode_reset_after FROM users WHERE id=?');
        $matched=0;
        foreach ($users as $user) {
            $hash=hash('sha256',$user['firebase_uid']);
            if (!array_key_exists($hash,$files)) continue; // Missing file meant disabled in the existing implementation.
            $state=$files[$hash];
            $expected=[$state['enabled']?1:0,$state['pin_hash']??null,$state['attempts']??0,$state['blocked_until']??null,$state['reset_after']??null];
            $update->execute([...$expected,$user['id']]);
            $verify->execute([$user['id']]); $row=$verify->fetch(PDO::FETCH_NUM);
            foreach ([0,2,3,4] as $i) if ($row[$i]!==null) $row[$i]=(int)$row[$i];
            if ($row!==$expected) throw new RuntimeException('Policy import verification failed.');
            $matched++;
        }
        $pdo->commit();
        return ['users'=>count($users),'imported'=>$matched,'unmatched_historical_files'=>count($files)-$matched];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
